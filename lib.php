<?php
defined('MOODLE_INTERNAL') || die();

// V13 — logique de (re)création de salle, partagée entre la création initiale de
// l'activité (livestream_add_instance) et l'action "créer la salle" de view.php
// (utilisée quand roomid est vide : après restauration, duplication ou import
// d'activité, cas où roomid/roomname sont volontairement réinitialisés — voir
// restore_livestream_stepslib.php).
// V15 — hôte de l'apiurl configuré (ex. "webinaire.unchk.sn"). Sert d'estampille
// de backend : deux backends (préprod / prod) ont des hôtes distincts. Renvoie
// une chaîne vide si l'apiurl est absente ou invalide.
function livestream_current_apihost(): string {
    $apiurl = (string)get_config('mod_livestream', 'apiurl');
    if ($apiurl === '') {
        return '';
    }
    $host = parse_url($apiurl, PHP_URL_HOST);
    return is_string($host) ? $host : '';
}

function livestream_create_room(stdClass $instance, int $courseId, string $moderatorEmail): array {
    global $DB;

    $api    = new mod_livestream_api();
    $result = $api->createRoom(
        (string)$courseId,
        (string)$instance->id,
        $instance->name,
        $moderatorEmail,
        $instance->intro ?? ''
    );

    $DB->set_field('livestream', 'roomid',      $result['roomId'],   ['id' => $instance->id]);
    $DB->set_field('livestream', 'roomname',    $result['roomName'], ['id' => $instance->id]);
    // V15 — estampille du backend d'origine (P1) : hôte de l'apiurl utilisé pour
    // créer la salle. Comparé plus tard au backend courant pour détecter un
    // changement d'URL qui rendrait roomid caduc.
    $DB->set_field('livestream', 'roomapihost', livestream_current_apihost(), ['id' => $instance->id]);

    if (get_config('mod_livestream', 'autoenroll')) {
        livestream_sync_enrollments($courseId, $result['roomId']);
    }

    return $result;
}

function livestream_add_instance(stdClass $data, mod_livestream_mod_form $mform = null): int {
    global $DB, $USER;

    $data->timecreated  = time();
    $data->timemodified = time();

    $transaction = $DB->start_delegated_transaction();
    try {
        $id = $DB->insert_record('livestream', $data);
        $data->id = $id;

        livestream_create_room($data, $data->course, $USER->email);

        // V09 — journalisation audit : salle créée
        // Note : le context module n'est pas encore disponible à ce stade (l'activité
        // vient d'être insérée), on utilise le context du cours.
        $coursecontext = context_course::instance($data->course);
        \mod_livestream\event\room_created::create([
            'context'  => $coursecontext,
            'objectid' => $id,
        ])->trigger();

        $transaction->allow_commit();
        return $id;

    } catch (Exception $e) {
        $transaction->rollback($e);
        throw $e;
    }
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
    if (!empty($instance->roomid)) {
        try {
            $api = new mod_livestream_api();
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
