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

$joinError   = '';
$startError  = '';
$deleteError = '';

// ── JOIN ─────────────────────────────────────────────────────────────────────
if ($action === 'join' && !empty($instance->roomid)) {
    try {
        $api    = new mod_livestream_api();
        $result = $api->joinRoom($instance->roomid, $USER->email, fullname($USER));
        if (empty($result['url'])) {
            throw new moodle_exception('invalidresponse', 'mod_livestream');
        }
        $watchUrl = $result['url'] . '&returnUrl=' . urlencode($returnUrl);
        redirect($watchUrl);
    } catch (Exception $e) {
        $joinError = $e->getMessage();
        debugging($e->getMessage(), DEBUG_DEVELOPER);
    }
}

// ── START ────────────────────────────────────────────────────────────────────
if ($action === 'start' && $isModerator && !empty($instance->roomid)) {
    try {
        $api    = new mod_livestream_api();
        $result = $api->startRoom($instance->roomid, $USER->email, fullname($USER));
        if (empty($result['url'])) {
            throw new moodle_exception('invalidresponse', 'mod_livestream');
        }
        $hostUrl = $result['url'] . '&returnUrl=' . urlencode($returnUrl);
        redirect($hostUrl);
    } catch (Exception $e) {
        $startError = $e->getMessage();
        debugging($e->getMessage(), DEBUG_DEVELOPER);
    }
}

// ── DELETE RECORDING ─────────────────────────────────────────────────────────
if ($action === 'deleterecording' && $isModerator) {
    require_sesskey();
    $recordingId = required_param('recordingid', PARAM_ALPHANUMEXT);
    try {
        $api = new mod_livestream_api();
        $api->deleteRecording($recordingId);
        redirect(new moodle_url('/mod/livestream/view.php', ['id' => $cm->id]));
    } catch (Exception $e) {
        $deleteError = $e->getMessage();
        debugging($e->getMessage(), DEBUG_DEVELOPER);
    }
}

// ── API ──────────────────────────────────────────────────────────────────────
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
        debugging('LiveStream API error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

// ── OUTPUT ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('livestream', $instance, $cm->id), 'generalbox', 'intro');
}

// Erreurs
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
} elseif (!empty($instance->roomid) && $status) {
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
        $startUrl = new moodle_url('/mod/livestream/view.php', ['id' => $cm->id, 'action' => 'start']);
        echo html_writer::link($startUrl, '▶ Démarrer la session',
            ['style' => 'display:inline-block;padding:10px 24px;background:#0065b1;color:white;border-radius:8px;text-decoration:none;font-weight:600;']
        );
    }

    if ($roomStatus === 'LIVE') {
        $joinUrl = new moodle_url('/mod/livestream/view.php', ['id' => $cm->id, 'action' => 'join']);
        echo html_writer::link($joinUrl, '👁 Rejoindre la session',
            ['style' => 'display:inline-block;padding:10px 24px;background:#fff;color:#0065b1;border:1.5px solid #0065b1;border-radius:8px;text-decoration:none;font-weight:600;']
        );
    } elseif (!$isModerator) {
        echo html_writer::div('La session n\'est pas encore en direct.',
            '', ['style' => 'color:#9ca3af;font-size:0.85rem;padding:8px 0;']
        );
    }

    echo html_writer::end_div();
} else {
    echo $OUTPUT->notification('Aucune salle associée à cette activité.', 'warning');
}

echo html_writer::end_div();

// Enregistrements
echo $OUTPUT->heading(get_string('recordings', 'mod_livestream'), 3);

if (empty($recordings)) {
    echo html_writer::div('Aucun enregistrement disponible.',
        '', ['style' => 'color:#9ca3af;padding:8px 0;font-size:0.9rem;']
    );
} else {
    // Passer les URLs au JS via data attributes — pas d'injection inline
    $playerData = [];

    $table            = new html_table();
    $table->head      = ['Voir', 'Nom', 'Date', 'Durée', ''];
    $table->align     = ['left', 'left', 'left', 'left', 'center'];

    foreach ($recordings as $rec) {
        // Sanitiser l'ID pour usage HTML
        $safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $rec['id']);
        $playerId = 'ls-player-' . $safeId;

        // Stocker l'URL côté PHP pour injection JSON sécurisée
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

        $date = userdate(strtotime($rec['date']),
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

    // Injecter les URLs via JSON — aucune injection possible
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
