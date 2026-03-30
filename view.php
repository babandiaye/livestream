<?php
require_once('../../config.php');
require_once('lib.php');
require_once('classes/api.php');

$id = optional_param('id', 0, PARAM_INT);
$cm = get_coursemodule_from_id('livestream', $id, 0, false, MUST_EXIST);
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

$action = optional_param('action', '', PARAM_ALPHA);

$joinError   = '';
$startError  = '';
$deleteError = '';

if ($action === 'join' && !empty($instance->roomid)) {
    try {
        $api    = new mod_livestream_api();
        $result = $api->joinRoom($instance->roomid, $USER->email, fullname($USER));
        $watchUrl = $result['url'] . '&returnUrl=' . urlencode($returnUrl);
        redirect(new moodle_url($watchUrl));
    } catch (Exception $e) {
        $joinError = $e->getMessage();
    }
}

if ($action === 'start' && $isModerator && !empty($instance->roomid)) {
    try {
        $api    = new mod_livestream_api();
        $result = $api->startRoom($instance->roomid, $USER->email, fullname($USER));
        $hostUrl = $result['url'] . '&returnUrl=' . urlencode($returnUrl);
        redirect(new moodle_url($hostUrl));
    } catch (Exception $e) {
        $startError = $e->getMessage();
    }
}

if ($action === 'deleterecording' && $isModerator) {
    $recordingId = required_param('recordingid', PARAM_ALPHANUMEXT);
    try {
        $api = new mod_livestream_api();
        $api->deleteRecording($recordingId);
        redirect(new moodle_url('/mod/livestream/view.php', ['id' => $cm->id]));
    } catch (Exception $e) {
        $deleteError = $e->getMessage();
    }
}

// Récupérer statut et enregistrements depuis l'API
$status      = null;
$recordings  = [];
$apiError    = false;
$apiErrorMsg = '';

if (!empty($instance->roomid)) {
    try {
        $api        = new mod_livestream_api();
        $status     = $api->getRoomStatus($instance->roomid);
        $recData    = $api->getRecordings($instance->roomid);
        $recordings = $recData['recordings'] ?? [];
    } catch (Exception $e) {
        $apiError    = true;
        $apiErrorMsg = $e->getMessage();
        error_log('LiveStream API error: ' . $e->getMessage());
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('livestream', $instance, $cm->id), 'generalbox', 'intro');
}

// ── Affichage des erreurs d'action ──────────────────────────────────────────
if ($joinError) {
    echo html_writer::div(
        '<strong>Impossible de rejoindre la session :</strong> ' . htmlspecialchars($joinError),
        'alert alert-danger',
        ['style' => 'margin:12px 0;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c;font-size:0.9rem;']
    );
}
if ($startError) {
    echo html_writer::div(
        '<strong>Impossible de démarrer la session :</strong> ' . htmlspecialchars($startError),
        '',
        ['style' => 'margin:12px 0;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c;font-size:0.9rem;']
    );
}
if ($deleteError) {
    echo html_writer::div(
        '<strong>Erreur suppression :</strong> ' . htmlspecialchars($deleteError),
        '',
        ['style' => 'margin:12px 0;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c;font-size:0.9rem;']
    );
}

// ── Bloc statut + boutons ───────────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'margin:16px 0;padding:24px;background:#f8fafd;border-radius:12px;border:1px solid #e2e8f0;']);

if ($apiError) {
    echo html_writer::div(
        '<strong>⚠️ Erreur de connexion à la plateforme webinaire</strong><br>' .
        '<small style="color:#6b7280;">' . htmlspecialchars($apiErrorMsg) . '</small>',
        '',
        ['style' => 'padding:12px 16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;color:#92400e;font-size:0.88rem;']
    );
} elseif (!empty($instance->roomid) && $status) {
    $s = $status['status'] ?? 'SCHEDULED';

    // Forcer ENDED si la salle n'est plus LIVE selon LiveKit
    if ($s === 'LIVE') {
        $statusLabel = '<span style="display:inline-flex;align-items:center;gap:6px;color:#2fb344;font-weight:700;font-size:0.92rem;">
            <span style="width:8px;height:8px;border-radius:50%;background:#2fb344;display:inline-block;animation:blink 1.2s ease-in-out infinite;"></span>
            En direct</span>';
        $readyMsg = '<span style="color:#374151;font-weight:600;">Session en cours — rejoignez maintenant !</span>';
    } elseif ($s === 'ENDED') {
        $statusLabel = '<span style="color:#6b7280;font-size:0.9rem;">⏹ Session terminée</span>';
        $readyMsg = '';
    } else {
        $statusLabel = '<span style="color:#0065b1;font-size:0.9rem;font-weight:600;">⏳ Session planifiée</span>';
        $readyMsg = '<span style="color:#5f6368;font-size:0.88rem;">La session n\'a pas encore démarré.</span>';
    }

    echo html_writer::div($statusLabel, '', ['style' => 'margin-bottom:8px;']);
    if ($readyMsg) {
        echo html_writer::div($readyMsg, '', ['style' => 'margin-bottom:14px;']);
    }

    echo html_writer::div(
        userdate($instance->timecreated, get_string('strftimedateshort', 'langconfig')),
        '', ['style' => 'color:#9ca3af;font-size:0.8rem;margin-bottom:20px;']
    );

    // Boutons
    echo html_writer::start_div('', ['style' => 'display:flex;gap:10px;flex-wrap:wrap;']);

    if ($isModerator) {
        $startUrl = new moodle_url('/mod/livestream/view.php', ['id' => $cm->id, 'action' => 'start']);
        echo html_writer::link($startUrl,
            '▶ Démarrer la session',
            ['style' => 'display:inline-block;padding:10px 24px;background:#0065b1;color:white;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;transition:background .2s;',
             'onmouseover' => "this.style.background='#004d8c'",
             'onmouseout'  => "this.style.background='#0065b1'"]
        );
    }

    if ($s === 'LIVE') {
        $joinUrl = new moodle_url('/mod/livestream/view.php', ['id' => $cm->id, 'action' => 'join']);
        echo html_writer::link($joinUrl,
            '👁 Rejoindre la session',
            ['style' => 'display:inline-block;padding:10px 24px;background:#fff;color:#0065b1;border:1.5px solid #0065b1;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;']
        );
    } elseif ($s !== 'LIVE' && !$isModerator) {
        echo html_writer::div(
            'La session n\'est pas encore en direct.',
            '', ['style' => 'color:#9ca3af;font-size:0.85rem;padding:8px 0;']
        );
    }

    echo html_writer::end_div();
} else {
    echo html_writer::div(
        '⚠️ Aucune salle associée à cette activité. Contactez votre enseignant.',
        '', ['style' => 'color:#92400e;font-size:0.88rem;']
    );
}

echo html_writer::end_div();

// ── Enregistrements ─────────────────────────────────────────────────────────
echo $OUTPUT->heading('Enregistrements', 3);

if (empty($recordings)) {
    echo html_writer::div(
        'Aucun enregistrement disponible.',
        '', ['style' => 'color:#9ca3af;padding:8px 0;font-size:0.9rem;']
    );
} else {
    $table        = new html_table();
    $table->head  = ['Voir', 'Nom de la session', 'Date', 'Durée', ''];
    $table->align = ['left', 'left', 'left', 'left', 'center'];
    $table->attributes['style'] = 'width:100%;border-collapse:collapse;';

    foreach ($recordings as $rec) {
        $date     = userdate(strtotime($rec['date']), get_string('strftimedatefullshort', 'langconfig'));
        $duration = !empty($rec['duration'])
            ? round($rec['duration'] / 60) . ' min'
            : '—';

        $playerId = 'player-' . $rec['id'];
        $safeUrl  = htmlspecialchars($rec['playUrl'], ENT_QUOTES);

        $viewBtn = html_writer::tag('button',
            'Voir',
            [
                'onclick' => "togglePlayer('" . $playerId . "', '" . $safeUrl . "')",
                'style'   => 'color:#0065b1;background:none;border:none;cursor:pointer;text-decoration:underline;padding:0;font-size:0.9rem;',
            ]
        );

        $playerDiv = html_writer::div('', '', [
            'id'    => $playerId,
            'style' => 'display:none;margin-top:10px;',
        ]);

        $nameCell = format_string($rec['name']) . $playerDiv;

        $actions = '';
        if ($isModerator) {
            $deleteUrl = new moodle_url('/mod/livestream/view.php', [
                'id'          => $cm->id,
                'action'      => 'deleterecording',
                'recordingid' => $rec['id'],
            ]);
            $actions = html_writer::link($deleteUrl,
                'Supprimer',
                [
                    'style'   => 'color:#e53e3e;font-size:0.85rem;',
                    'onclick' => 'return confirm("Supprimer cet enregistrement ?")',
                ]
            );
        }

        $table->data[] = [$viewBtn, $nameCell, $date, $duration, $actions];
    }

    echo html_writer::table($table);
}

// ── Script lecteur inline ───────────────────────────────────────────────────
echo html_writer::tag('script', "
function togglePlayer(id, url) {
    var div = document.getElementById(id);
    if (!div) return;
    if (div.style.display === 'none' || div.style.display === '') {
        div.innerHTML = '<video controls autoplay style=\"width:100%;max-height:420px;border-radius:8px;background:#000;display:block;margin-top:8px;\"><source src=\"' + url + '\" type=\"video/mp4\">Votre navigateur ne supporte pas la lecture vidéo.</video>';
        div.style.display = 'block';
    } else {
        div.innerHTML = '';
        div.style.display = 'none';
    }
}
");

echo html_writer::tag('style', "
@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}
");

echo $OUTPUT->footer();
