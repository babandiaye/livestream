<?php
defined('MOODLE_INTERNAL') || die();

function livestream_add_instance(stdClass $data, mod_livestream_mod_form $mform = null): int {
    global $DB, $USER;

    $data->timecreated  = time();
    $data->timemodified = time();

    $id = $DB->insert_record('livestream', $data);

    try {
        $api            = new mod_livestream_api();
        $moderatorEmail = $USER->email;
        $result         = $api->createRoom(
            (string)$data->course,
            (string)$id,
            $data->name,
            $moderatorEmail,
            $data->intro ?? ''
        );
        $DB->set_field('livestream', 'roomid',   $result['roomId'],   ['id' => $id]);
        $DB->set_field('livestream', 'roomname', $result['roomName'], ['id' => $id]);

        if (get_config('mod_livestream', 'autoenroll')) {
            livestream_sync_enrollments($data->course, $result['roomId']);
        }
    } catch (Exception $e) {
        debugging('LiveStream createRoom error: ' . $e->getMessage());
    }

    return $id;
}

function livestream_update_instance(stdClass $data): bool {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;
    return $DB->update_record('livestream', $data);
}

function livestream_delete_instance(int $id): bool {
    global $DB;
    $instance = $DB->get_record('livestream', ['id' => $id]);
    if (!$instance) {
        return false;
    }
    // Supprimer la salle côté API si elle existe
    if (!empty($instance->roomid)) {
        try {
            $api = new mod_livestream_api();
            // On ignore l'erreur — la salle peut déjà ne plus exister
        } catch (Exception $e) {
            debugging('LiveStream delete error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    $DB->delete_records('livestream', ['id' => $id]);
    return true;
}

function livestream_supports(string $feature): ?bool {
    switch ($feature) {
        case FEATURE_MOD_INTRO:        return true;
        case FEATURE_BACKUP_MOODLE2:   return true;
        case FEATURE_SHOW_DESCRIPTION: return true;
        default:                       return null;
    }
}

function livestream_sync_enrollments(int $courseId, string $roomId): void {
    global $DB;

    $context = context_course::instance($courseId);
    $users   = get_enrolled_users($context, 'mod/livestream:view');

    $emails = [];
    foreach ($users as $user) {
        if (!empty($user->email)) {
            $emails[] = $user->email;
        }
    }

    if (empty($emails)) return;

    try {
        $api = new mod_livestream_api();
        $api->enrollUsers($roomId, $emails);
    } catch (Exception $e) {
        debugging('LiveStream enrollUsers error: ' . $e->getMessage());
    }
}
