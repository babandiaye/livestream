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
$action      = optional_param('action', '', PARAM_ALPHA);

$joinError   = '';
$startError  = '';
$deleteError = '';

// ── GROUPES (approche A) ─────────────────────────────────────────────────────
// Mode groupe de l'activité. Le 2e paramètre (true) demande à Moodle d'afficher
// le sélecteur et de restreindre le groupe actif à ceux visibles par l'utilisateur
// (en « groupes séparés », un étudiant est limité à son propre groupe).
$activegroup = (int)(groups_get_activity_group($cm, true) ?: 0);
$meetingid   = livestream_meetingid_for_group((int)$instance->id, $activegroup);

// URL de retour (conserve le groupe courant).
$returnparams = ['id' => $cm->id];
if ($activegroup > 0) {
    $returnparams['group'] = $activegroup;
}
$returnUrl = (new moodle_url('/mod/livestream/view.php', $returnparams))->out(false);

// Résolution de la salle du groupe courant.
//  - Groupe 0 avec salle de base déjà créée : on utilise le roomid stocké (aucun appel API).
//  - Sinon (groupe N, ou salle de base manquante) : on résout par meetingId (sans créer).
$activeroomid = '';
$groupstatus  = null;
if ($activegroup === 0 && !empty($instance->roomid)) {
    $activeroomid = $instance->roomid;
} else {
    try {
        $resolveapi = new mod_livestream_api();
        $resolved   = $resolveapi->getRoomStatusByMeeting($meetingid);
        if (!empty($resolved['exists'])) {
            $activeroomid = $resolved['roomId'];
            $groupstatus  = $resolved;
        }
    } catch (Exception $e) {
        debugging('LiveStream group resolve error', DEBUG_DEVELOPER);
    }
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

// V05 — rate limiting : max 5 tentatives par minute par utilisateur
function livestream_check_ratelimit(string $actionKey): void {
    global $USER;
    $cache    = cache::make('mod_livestream', 'ratelimit');
    $cacheKey = $actionKey . '_' . $USER->id;
    $count    = (int)($cache->get($cacheKey) ?: 0);
    if ($count >= 5) {
        throw new moodle_exception('apierror', 'mod_livestream', '',
            'Trop de tentatives. Veuillez patienter une minute.');
    }
    $cache->set($cacheKey, $count + 1);
}

// ── JOIN ─────────────────────────────────────────────────────────────────────
if ($action === 'join') {
    require_sesskey(); // V02 — protection CSRF
    livestream_check_ratelimit('join'); // V05
    try {
        if (empty($activeroomid)) {
            throw new moodle_exception('invalidresponse', 'mod_livestream');
        }
        $api    = new mod_livestream_api();
        $result = $api->joinRoom($activeroomid, $USER->email, fullname($USER));
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
if ($action === 'start' && $isModerator) {
    require_sesskey(); // V02 — protection CSRF
    livestream_check_ratelimit('start'); // V05
    try {
        $api = new mod_livestream_api();

        // Création paresseuse de la salle du groupe au premier démarrage (approche A).
        if (empty($activeroomid)) {
            $roomtitle = $instance->name;
            if ($activegroup > 0) {
                $groupname = groups_get_group_name($activegroup);
                if ($groupname) {
                    $roomtitle .= ' (' . $groupname . ')';
                }
            }
            $created      = $api->createRoom(
                (string)$course->id,
                $meetingid,
                $roomtitle,
                $USER->email,
                $instance->intro ?? ''
            );
            $activeroomid = $created['roomId'] ?? '';

            // Salle de base (groupe 0) : on persiste le roomid pour les prochains chargements.
            if ($activegroup === 0 && !empty($created['roomId'])) {
                $DB->set_field('livestream', 'roomid',   $created['roomId'],       ['id' => $instance->id]);
                $DB->set_field('livestream', 'roomname', $created['roomName'] ?? '', ['id' => $instance->id]);
            }
        }

        if (empty($activeroomid)) {
            throw new moodle_exception('invalidresponse', 'mod_livestream');
        }

        $result = $api->startRoom($activeroomid, $USER->email, fullname($USER));
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
if ($action === 'deleterecording' && $isModerator) {
    require_sesskey();
    $recordingId = required_param('recordingid', PARAM_ALPHANUMEXT);
    try {
        $api = new mod_livestream_api();
        $api->deleteRecording($recordingId);

        // Purge du cache local pour le serveur actif.
        $DB->delete_records('livestream_recordings', [
            'serverurl'   => livestream_current_server(),
            'recordingid' => $recordingId,
        ]);

        // V09 — journalisation audit : enregistrement supprimé
        \mod_livestream\event\recording_deleted::create([
            'context'  => $context,
            'objectid' => $instance->id,
            'other'    => ['recordingid' => $recordingId],
        ])->trigger();

        redirect(new moodle_url('/mod/livestream/view.php', $returnparams));
    } catch (Exception $e) {
        $deleteError = $e->getMessage();
        debugging('LiveStream delete error', DEBUG_DEVELOPER);
    }
}

// ── API ──────────────────────────────────────────────────────────────────────
$status      = null;
$recordings  = [];
$apiError    = false;
$apiErrorMsg = '';

if (!empty($activeroomid)) {
    try {
        $api    = new mod_livestream_api();
        // Réutilise le statut déjà résolu par meetingId (groupes), sinon interroge.
        $status = $groupstatus ?: $api->getRoomStatus($activeroomid);
    } catch (Exception $e) {
        $apiError    = true;
        $apiErrorMsg = $e->getMessage();
        debugging('LiveStream API error', DEBUG_DEVELOPER);
    }
    // Rafraîchit le cache des enregistrements de la salle courante (best-effort).
    livestream_sync_room_recordings((int)$instance->id, (int)$course->id, $activegroup, $activeroomid);
}

// Enregistrements visibles : uniquement ceux du serveur webinaire actif (modèle BBB).
// Changer de serveur masque les autres ; revenir à un serveur les ré-affiche.
$cachedrecs = $DB->get_records('livestream_recordings', [
    'livestreamid' => $instance->id,
    'serverurl'    => livestream_current_server(),
    'groupid'      => $activegroup,
], 'recdate DESC');
foreach ($cachedrecs as $cr) {
    $recordings[] = [
        'id'       => $cr->recordingid,
        'name'     => $cr->name,
        'duration' => $cr->duration,
        'date'     => (int)$cr->recdate,
        'playUrl'  => $cr->playurl,
    ];
}

// ── OUTPUT ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('livestream', $instance, $cm->id), 'generalbox', 'intro');
}

// Sélecteur de groupe (n'affiche rien si l'activité n'est pas en mode groupe).
groups_print_activity_menu($cm, new moodle_url('/mod/livestream/view.php', ['id' => $cm->id]));

if ($joinError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($joinError), 'error');
}
if ($startError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($startError), 'error');
}
if ($deleteError) {
    echo $OUTPUT->notification(get_string('error') . ': ' . s($deleteError), 'error');
}

// Statut + boutons
echo html_writer::start_div('', ['style' => 'margin:16px 0;padding:24px;background:#f8fafd;border-radius:12px;border:1px solid #e2e8f0;']);

if ($apiError) {
    echo $OUTPUT->notification(s($apiErrorMsg), 'warning');
} else {
    // Si la salle du groupe n'existe pas encore, statut « planifiée » par défaut ;
    // le modérateur peut la démarrer (création paresseuse via action=start).
    $roomStatus = $status['status'] ?? 'SCHEDULED';

    if ($roomStatus === 'LIVE') {
        echo html_writer::div(
            '<span style="display:inline-flex;align-items:center;gap:6px;color:#2fb344;font-weight:700;">
                <span style="width:8px;height:8px;border-radius:50%;background:#2fb344;display:inline-block;animation:ls-blink 1.2s ease-in-out infinite;"></span>
                En direct</span>',
            '', ['style' => 'margin-bottom:14px;']
        );
    } elseif ($roomStatus === 'ENDED') {
        echo html_writer::div(
            '<span style="color:#6b7280;">⏹ Session terminée</span>',
            '', ['style' => 'margin-bottom:14px;']
        );
    } else {
        echo html_writer::div(
            '<span style="color:#0065b1;font-weight:600;">⏳ Session planifiée</span>',
            '', ['style' => 'margin-bottom:14px;']
        );
    }

    echo html_writer::start_div('', ['style' => 'display:flex;gap:10px;flex-wrap:wrap;']);

    if ($isModerator) {
        // V02 — sesskey dans l'URL du bouton start
        $startUrl = new moodle_url('/mod/livestream/view.php', [
            'id'      => $cm->id,
            'action'  => 'start',
            'group'   => $activegroup,
            'sesskey' => sesskey(),
        ]);
        echo html_writer::link($startUrl, '▶ Démarrer la session',
            ['style' => 'display:inline-block;padding:10px 24px;background:#0065b1;color:white;border-radius:8px;text-decoration:none;font-weight:600;']
        );
    }

    if ($roomStatus === 'LIVE' && !empty($activeroomid)) {
        // V02 — sesskey dans l'URL du bouton join
        $joinUrl = new moodle_url('/mod/livestream/view.php', [
            'id'      => $cm->id,
            'action'  => 'join',
            'group'   => $activegroup,
            'sesskey' => sesskey(),
        ]);
        echo html_writer::link($joinUrl, '👁 Rejoindre la session',
            ['style' => 'display:inline-block;padding:10px 24px;background:#fff;color:#0065b1;border:1.5px solid #0065b1;border-radius:8px;text-decoration:none;font-weight:600;']
        );
    } elseif (!$isModerator) {
        echo html_writer::div('La session n\'est pas encore en direct.',
            '', ['style' => 'color:#9ca3af;font-size:0.85rem;padding:8px 0;']
        );
    }

    echo html_writer::end_div();
}

echo html_writer::end_div();

// Enregistrements
echo $OUTPUT->heading(get_string('recordings', 'mod_livestream'), 3);

if (empty($recordings)) {
    echo html_writer::div('Aucun enregistrement disponible.',
        '', ['style' => 'color:#9ca3af;padding:8px 0;font-size:0.9rem;']
    );
} else {
    $playerData = [];

    $table            = new html_table();
    $table->head      = ['Voir', 'Nom', 'Date', 'Durée', ''];
    $table->align     = ['left', 'left', 'left', 'left', 'center'];

    foreach ($recordings as $rec) {
        $safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $rec['id']);
        $playerId = 'ls-player-' . $safeId;

        $playerData[$playerId] = $rec['playUrl'];

        $viewBtn = html_writer::tag('button', 'Voir', [
            'data-player' => $playerId,
            'class'       => 'ls-play-btn',
            'style'       => 'color:#0065b1;background:none;border:none;cursor:pointer;text-decoration:underline;font-size:0.9rem;',
        ]);

        $playerDiv = html_writer::div('', '', [
            'id'    => $playerId,
            'style' => 'display:none;margin-top:10px;',
        ]);

        $duration = !empty($rec['duration'])
            ? round((int)$rec['duration'] / 60) . ' min'
            : '—';

        $rectimestamp = is_numeric($rec['date']) ? (int)$rec['date'] : strtotime($rec['date']);
        $date = userdate($rectimestamp,
            get_string('strftimedatefullshort', 'langconfig')
        );

        $actions = '';
        if ($isModerator) {
            $deleteUrl = new moodle_url('/mod/livestream/view.php', [
                'id'          => $cm->id,
                'action'      => 'deleterecording',
                'recordingid' => $rec['id'],
                'sesskey'     => sesskey(),
            ]);
            $actions = html_writer::link($deleteUrl, 'Supprimer', [
                'style'   => 'color:#e53e3e;font-size:0.85rem;',
                'onclick' => 'return confirm("Supprimer cet enregistrement ?")',
            ]);
        }

        $table->data[] = [
            $viewBtn,
            format_string($rec['name']) . $playerDiv,
            $date,
            $duration,
            $actions,
        ];
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
");

echo $OUTPUT->footer();
