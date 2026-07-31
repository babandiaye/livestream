<?php
require_once('../../config.php');
defined('MOODLE_INTERNAL') || die();
require_once('lib.php');
require_once('classes/api.php');

$id       = optional_param('id', 0, PARAM_INT);
$cm       = get_coursemodule_from_id('livestream', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('livestream', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/livestream:view', $context);

$PAGE->set_url('/mod/livestream/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($instance);

$isModerator = has_capability('mod/livestream:moderate', $context);
$returnUrl   = (new moodle_url('/mod/livestream/view.php', ['id' => $cm->id]))->out(false);
$action      = optional_param('action', '', PARAM_ALPHA);

// V16 — la suppression d'un enregistrement est réservée aux ADMINISTRATEURS DU
// SITE. Auparavant elle suivait mod/livestream:moderate, accordée à teacher,
// editingteacher et manager : tout enseignant du cours pouvait donc effacer
// définitivement un enregistrement (le fichier est supprimé de MinIO, pas
// seulement la ligne en base). is_siteadmin() est délibérément préféré à une
// capacité dédiée : il n'est pas délégable par attribution d'un rôle.
$canDeleteRecording = is_siteadmin();

$joinError       = '';
$startError      = '';
$deleteError     = '';
$createRoomError = '';
$roomReset       = ''; // V15 — motif d'oubli du lien de salle ('backendchanged' | 'roommissing')

// V15 — P1 : le roomid stocké appartient-il encore au backend configuré ? Si
// l'hôte de l'apiurl a changé depuis la création (ex. activité créée contre
// preprod-webinaire, désormais pointée sur webinaire), le roomid référence une
// salle d'un autre backend. On l'oublie pour forcer une recréation propre sur le
// backend courant — même logique que la réinitialisation à la restauration.
// Les salles créées avant la V15 n'ont pas d'estampille (roomapihost vide) : leur
// origine est inconnue ici, c'est P2 (404) qui les rattrapera au besoin.
if (!empty($instance->roomid) && !empty($instance->roomapihost)
        && $instance->roomapihost !== livestream_current_apihost()) {
    $DB->set_field('livestream', 'roomid',      null, ['id' => $instance->id]);
    $DB->set_field('livestream', 'roomname',    null, ['id' => $instance->id]);
    $DB->set_field('livestream', 'roomapihost', null, ['id' => $instance->id]);
    $instance->roomid = $instance->roomname = $instance->roomapihost = null;
    $roomReset = 'backendchanged';
}

// V01 + V06 — validation que l'URL retournée appartient au domaine autorisé
function livestream_validate_redirect_url(string $url): string {
    $allowedHost  = parse_url(get_config('mod_livestream', 'apiurl'), PHP_URL_HOST);
    $returnedHost = parse_url($url, PHP_URL_HOST);
    // Accepte le domaine exact et ses sous-domaines (CDN, load balancer)
    if (empty($returnedHost) || (
        $returnedHost !== $allowedHost &&
        !str_ends_with($returnedHost, '.' . $allowedHost)
    )) {
        throw new moodle_exception('invalidresponse', 'mod_livestream');
    }
    return $url;
}

// V14 — petites icônes SVG inline (habillage visuel), aucune dépendance externe.
function livestream_icon(string $name, int $size = 18): string {
    $paths = [
        'play'  => '<circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon>',
        'eye'   => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>',
        'check' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>',
        'clock' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'video' => '<polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>',
        'trash' => '<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>',
        'tool'  => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" ' .
        'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' .
        'style="flex-shrink:0;">' . $paths[$name] . '</svg>';
}

// V05 — rate limiting : max 5 tentatives par minute par utilisateur
function livestream_check_ratelimit(string $actionKey): void {
    global $USER;
    $cache    = cache::make('mod_livestream', 'ratelimit');
    $cacheKey = $actionKey . '_' . $USER->id;
    $count    = (int)($cache->get($cacheKey) ?: 0);
    if ($count >= 5) {
        // V11 — chaîne dédiée : ce n'est pas une erreur de la plateforme LiveStream,
        // c'est une limite locale Moodle.
        throw new moodle_exception('errorratelimit', 'mod_livestream');
    }
    $cache->set($cacheKey, $count + 1);
}

// ── JOIN ─────────────────────────────────────────────────────────────────────
if ($action === 'join' && !empty($instance->roomid)) {
    require_sesskey(); // V02 — protection CSRF
    livestream_check_ratelimit('join'); // V05
    try {
        $api    = new mod_livestream_api();
        $result = $api->joinRoom($instance->roomid, $USER->email, fullname($USER));
        if (empty($result['url'])) {
            throw new moodle_exception('invalidresponse', 'mod_livestream');
        }
        $validatedUrl = livestream_validate_redirect_url($result['url']); // V01+V06

        // V09 — journalisation audit : session rejointe
        \mod_livestream\event\session_joined::create([
            'context'  => $context,
            'objectid' => $instance->id,
        ])->trigger();

        $watchUrl     = $validatedUrl . '&returnUrl=' . urlencode($returnUrl);
        redirect($watchUrl);
    } catch (Exception $e) {
        $joinError = $e->getMessage();
        debugging('LiveStream join error', DEBUG_DEVELOPER);
    }
}

// ── START ────────────────────────────────────────────────────────────────────
if ($action === 'start' && $isModerator && !empty($instance->roomid)) {
    require_sesskey(); // V02 — protection CSRF
    livestream_check_ratelimit('start'); // V05
    try {
        $api    = new mod_livestream_api();
        $result = $api->startRoom($instance->roomid, $USER->email, fullname($USER));
        if (empty($result['url'])) {
            throw new moodle_exception('invalidresponse', 'mod_livestream');
        }
        $validatedUrl = livestream_validate_redirect_url($result['url']); // V01+V06

        // V09 — journalisation audit : session démarrée
        \mod_livestream\event\session_started::create([
            'context'  => $context,
            'objectid' => $instance->id,
        ])->trigger();

        $hostUrl      = $validatedUrl . '&returnUrl=' . urlencode($returnUrl);
        redirect($hostUrl);
    } catch (Exception $e) {
        $startError = $e->getMessage();
        debugging('LiveStream start error', DEBUG_DEVELOPER);
    }
}

// ── DELETE RECORDING ─────────────────────────────────────────────────────────
// V16 — le contrôle porte sur l'ACTION, pas seulement sur l'affichage du bouton :
// l'URL /view.php?action=deleterecording&recordingid=…&sesskey=… reste forgeable
// par tout enseignant, et sesskey ne prouve que l'origine de la requête, pas le
// droit de supprimer.
if ($action === 'deleterecording' && $canDeleteRecording) {
    require_sesskey();
    $recordingId = required_param('recordingid', PARAM_ALPHANUMEXT);
    try {
        $api = new mod_livestream_api();
        $api->deleteRecording($recordingId);

        // V09 — journalisation audit : enregistrement supprimé
        \mod_livestream\event\recording_deleted::create([
            'context'  => $context,
            'objectid' => $instance->id,
            'other'    => ['recordingid' => $recordingId],
        ])->trigger();

        redirect(new moodle_url('/mod/livestream/view.php', ['id' => $cm->id]));
    } catch (Exception $e) {
        $deleteError = $e->getMessage();
        debugging('LiveStream delete error', DEBUG_DEVELOPER);
    }
}

// ── CRÉER LA SALLE ──────────────────────────────────────────────────────────
// V13 — (re)création de salle quand roomid est vide : cas d'une activité
// restaurée, dupliquée ou importée (voir restore_livestream_stepslib.php), qui
// n'a volontairement pas conservé l'ancienne association pour éviter que deux
// cours partagent la même salle vidéo externe.
if ($action === 'createroom' && $isModerator && empty($instance->roomid)) {
    require_sesskey(); // V02 — protection CSRF
    livestream_check_ratelimit('createroom'); // V05
    try {
        livestream_create_room($instance, $course->id, $USER->email, fullname($USER));

        // V09 — journalisation audit : salle créée
        \mod_livestream\event\room_created::create([
            'context'  => $context,
            'objectid' => $instance->id,
        ])->trigger();

        redirect(new moodle_url('/mod/livestream/view.php', ['id' => $cm->id]));
    } catch (Exception $e) {
        $createRoomError = $e->getMessage();
        debugging('LiveStream createroom error', DEBUG_DEVELOPER);
    }
}

// ── API ──────────────────────────────────────────────────────────────────────
$status      = null;
$recordings  = [];
$apiError    = false;
$apiErrorMsg = '';

if (!empty($instance->roomid)) {
    try {
        $api    = new mod_livestream_api();
        $status = $api->getRoomStatusOrNull($instance->roomid);
        if ($status === null) {
            // V15 — P2 : la salle n'existe pas sur ce backend (roomid étranger non
            // rattrapé par P1 — ex. salle d'avant la V15 —, ou salle supprimée côté
            // plateforme). On oublie le lien pour proposer une recréation, au lieu
            // d'afficher une erreur technique.
            $DB->set_field('livestream', 'roomid',      null, ['id' => $instance->id]);
            $DB->set_field('livestream', 'roomname',    null, ['id' => $instance->id]);
            $DB->set_field('livestream', 'roomapihost', null, ['id' => $instance->id]);
            $instance->roomid = $instance->roomname = $instance->roomapihost = null;
            if ($roomReset === '') {
                $roomReset = 'roommissing';
            }
        } else {
            $recData    = $api->getRecordings($instance->roomid);
            $recordings = $recData['recordings'] ?? [];
        }
    } catch (Exception $e) {
        $apiError    = true;
        $apiErrorMsg = $e->getMessage();
        debugging('LiveStream API error', DEBUG_DEVELOPER);
    }
}

// ── OUTPUT ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('livestream', $instance, $cm->id), 'generalbox', 'intro');
}

if ($joinError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($joinError), 'error');
}
if ($startError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($startError), 'error');
}
if ($deleteError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($deleteError), 'error');
}
if ($createRoomError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($createRoomError), 'error');
}
// V15 — le lien de salle a été oublié (changement de backend ou salle absente) :
// on informe, la suite de la page proposera « Créer la salle » au modérateur.
if ($roomReset !== '') {
    echo $OUTPUT->notification(get_string('roomreset_' . $roomReset, 'mod_livestream'), 'info');
}

// V14 — Statut + boutons, en carte avec badge d'icône, titre/sous-titre et légende d'action.
echo html_writer::start_div('', ['style' =>
    'margin:16px 0;padding:24px;background:#f8fafd;border-radius:12px;border:1px solid #e2e8f0;'
]);

if ($apiError) {
    echo $OUTPUT->notification(s($apiErrorMsg), 'warning');
} elseif (!empty($instance->roomid) && $status) {
    $roomStatus = $status['status'] ?? 'SCHEDULED';

    $badges = [
        'LIVE'      => ['bg' => '#dcfce7', 'fg' => '#16a34a', 'icon' => null,
            'title' => 'En direct', 'subtitle' => 'Une session est en cours.'],
        'ENDED'     => ['bg' => '#e5e7eb', 'fg' => '#4b5563', 'icon' => 'check',
            'title' => 'Session terminée', 'subtitle' => 'Cette session est terminée.'],
        'SCHEDULED' => ['bg' => '#dbeafe', 'fg' => '#0065b1', 'icon' => 'clock',
            'title' => 'Session planifiée', 'subtitle' => 'Aucune session en cours pour le moment.'],
    ];
    $badge = $badges[$roomStatus] ?? $badges['SCHEDULED'];

    // Badge : pastille pulsante dédiée pour "En direct", icône sinon.
    if ($roomStatus === 'LIVE') {
        $badgeInner = '<span style="width:10px;height:10px;border-radius:50%;background:' . $badge['fg'] . ';display:inline-block;animation:ls-blink 1.2s ease-in-out infinite;"></span>';
    } else {
        $badgeInner = livestream_icon($badge['icon'], 20);
    }

    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:20px;flex-wrap:wrap;']);

    // Colonne gauche : badge + titre + sous-titre.
    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:14px;flex:1;min-width:220px;']);
    echo html_writer::div($badgeInner, '', ['style' =>
        'width:40px;height:40px;border-radius:50%;background:' . $badge['bg'] . ';color:' . $badge['fg'] . ';' .
        'display:flex;align-items:center;justify-content:center;flex-shrink:0;'
    ]);
    echo html_writer::start_div();
    echo html_writer::div(s($badge['title']), '', ['style' => 'font-weight:700;color:#111827;']);
    echo html_writer::div(s($badge['subtitle']), '', ['style' => 'color:#6b7280;font-size:0.85rem;']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Séparateur vertical (visible seulement s'il y a une action à droite).
    $hasAction = $isModerator || $roomStatus === 'LIVE';
    if ($hasAction) {
        echo html_writer::div('', '', ['style' => 'width:1px;align-self:stretch;background:#e2e8f0;']);
    }

    // Colonne droite : bouton principal + légende.
    echo html_writer::start_div('', ['style' => 'display:flex;flex-direction:column;align-items:flex-start;gap:4px;']);

    if ($isModerator) {
        // V02 — sesskey dans l'URL du bouton start
        $startUrl = new moodle_url('/mod/livestream/view.php', [
            'id'      => $cm->id,
            'action'  => 'start',
            'sesskey' => sesskey(),
        ]);
        echo html_writer::link($startUrl,
            livestream_icon('play', 16) . ' Démarrer la session',
            ['style' => 'display:inline-flex;align-items:center;gap:8px;padding:10px 24px;background:#0065b1;color:white;border-radius:8px;text-decoration:none;font-weight:600;']
        );
        echo html_writer::div(
            $roomStatus === 'LIVE' ? 'Relancez si nécessaire.' : 'Lancez une nouvelle session de webinaire.',
            '', ['style' => 'color:#9ca3af;font-size:0.8rem;']
        );
    } elseif ($roomStatus === 'LIVE') {
        // V02 — sesskey dans l'URL du bouton join
        $joinUrl = new moodle_url('/mod/livestream/view.php', [
            'id'      => $cm->id,
            'action'  => 'join',
            'sesskey' => sesskey(),
        ]);
        echo html_writer::link($joinUrl,
            livestream_icon('eye', 16) . ' Rejoindre la session',
            ['style' => 'display:inline-flex;align-items:center;gap:8px;padding:10px 24px;background:#fff;color:#0065b1;border:1.5px solid #0065b1;border-radius:8px;text-decoration:none;font-weight:600;']
        );
        echo html_writer::div('Rejoignez la session en cours.',
            '', ['style' => 'color:#9ca3af;font-size:0.8rem;']
        );
    } else {
        echo html_writer::div('La session n\'est pas encore en direct.',
            '', ['style' => 'color:#9ca3af;font-size:0.85rem;']
        );
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    echo $OUTPUT->notification('Aucune salle associée à cette activité.', 'warning');
    if ($isModerator) {
        // V13 — sesskey dans l'URL du bouton createroom
        $createRoomUrl = new moodle_url('/mod/livestream/view.php', [
            'id'      => $cm->id,
            'action'  => 'createroom',
            'sesskey' => sesskey(),
        ]);
        echo html_writer::link($createRoomUrl,
            livestream_icon('tool', 16) . ' Créer la salle',
            ['style' => 'display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:10px 24px;background:#0065b1;color:white;border-radius:8px;text-decoration:none;font-weight:600;']
        );
    }
}

echo html_writer::end_div();

// V14 — Enregistrements : en-tête avec icône + compteur.
echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:10px;margin:24px 0 12px;']);
echo html_writer::div(livestream_icon('video', 20), '', ['style' => 'color:#0065b1;']);
echo html_writer::tag('h3', s(get_string('recordings', 'mod_livestream')), ['style' => 'margin:0;']);
if (!empty($recordings)) {
    echo html_writer::div(count($recordings) . ' enregistrement(s)',
        '', ['style' => 'color:#9ca3af;font-size:0.85rem;']
    );
}
echo html_writer::end_div();

if (empty($recordings)) {
    echo html_writer::div('Aucun enregistrement disponible.',
        '', ['style' => 'color:#9ca3af;padding:8px 0;font-size:0.9rem;']
    );
} else {
    $playerData = [];

    $table            = new html_table();
    // V16 — la colonne « Actions » ne contient que la corbeille : inutile de la
    // laisser, vide, à ceux qui n'ont pas le droit de supprimer.
    $table->head      = ['Voir', 'Nom de l\'enregistrement', 'Date', 'Durée'];
    $table->align     = ['left', 'left', 'left', 'left'];
    if ($canDeleteRecording) {
        $table->head[]  = 'Actions';
        $table->align[] = 'center';
    }

    foreach ($recordings as $rec) {
        $safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $rec['id']);
        $playerId = 'ls-player-' . $safeId;

        $playerData[$playerId] = $rec['playUrl'];

        // V14 — bouton icône circulaire au lieu d'un lien texte.
        $viewBtn = html_writer::tag('button', livestream_icon('eye', 16), [
            'data-player' => $playerId,
            'class'       => 'ls-play-btn',
            'title'       => get_string('viewrecording', 'mod_livestream'),
            'style'       => 'display:flex;align-items:center;justify-content:center;width:34px;height:34px;' .
                'color:#0065b1;background:#eaf3fb;border:none;border-radius:8px;cursor:pointer;',
        ]);

        $playerDiv = html_writer::div('', '', [
            'id'    => $playerId,
            'style' => 'display:none;margin-top:10px;',
        ]);

        $duration = !empty($rec['duration'])
            ? round((int)$rec['duration'] / 60) . ' min'
            : '—';

        $date = userdate(strtotime($rec['date']),
            get_string('strftimedatefullshort', 'langconfig')
        );

        $actions = null;
        if ($canDeleteRecording) { // V16 — administrateurs du site uniquement
            $deleteUrl = new moodle_url('/mod/livestream/view.php', [
                'id'          => $cm->id,
                'action'      => 'deleterecording',
                'recordingid' => $rec['id'],
                'sesskey'     => sesskey(),
            ]);
            // V14 — bouton icône (corbeille) au lieu d'un lien texte.
            $actions = html_writer::link($deleteUrl, livestream_icon('trash', 15), [
                'title'   => get_string('deleterecording', 'mod_livestream'),
                'style'   => 'display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;' .
                    'color:#e53e3e;background:#fff;border:1.5px solid #fecaca;border-radius:8px;text-decoration:none;',
                'onclick' => 'return confirm("Supprimer cet enregistrement ?")',
            ]);
        }

        // V14 — petite icône vidéo devant le nom du fichier.
        $nameCell = html_writer::span(livestream_icon('video', 15), '', ['style' => 'color:#9ca3af;margin-right:8px;']) .
            format_string($rec['name']) . $playerDiv;

        $row = [$viewBtn, $nameCell, $date, $duration];
        if ($canDeleteRecording) {
            $row[] = $actions;
        }
        $table->data[] = $row;
    }

    echo html_writer::table($table);

    $jsonData = json_encode($playerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo html_writer::tag('script', "
(function() {
    var players = " . $jsonData . ";
    document.querySelectorAll('.ls-play-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id  = btn.getAttribute('data-player');
            var url = players[id];
            if (!url) return;
            var div = document.getElementById(id);
            if (!div) return;
            if (div.style.display === 'none' || div.style.display === '') {
                var video  = document.createElement('video');
                video.controls = true;
                video.autoplay = true;
                video.style.cssText = 'width:100%;max-height:420px;border-radius:8px;background:#000;display:block;margin-top:8px;';
                var source = document.createElement('source');
                source.setAttribute('src', url);
                source.setAttribute('type', 'video/mp4');
                video.appendChild(source);
                div.innerHTML = '';
                div.appendChild(video);
                div.style.display = 'block';
            } else {
                div.innerHTML = '';
                div.style.display = 'none';
            }
        });
    });
})();
");
}

echo html_writer::tag('style', "
@keyframes ls-blink { 0%,100%{opacity:1} 50%{opacity:0.3} }
.ls-play-btn:hover { background:#d7e9f7 !important; }
");

echo $OUTPUT->footer();
