<?php
defined('MOODLE_INTERNAL') || die();

function livestream_add_instance(stdClass $data, mod_livestream_mod_form $mform = null): int {
    global $DB, $USER;

    $data->timecreated  = time();
    $data->timemodified = time();

    $transaction = $DB->start_delegated_transaction();
    try {
        $id = $DB->insert_record('livestream', $data);

        $api    = new mod_livestream_api();
        $result = $api->createRoom(
            (string)$data->course,
            (string)$id,
            $data->name,
            $USER->email,
            $data->intro ?? ''
        );

        $DB->set_field('livestream', 'roomid',   $result['roomId'],   ['id' => $id]);
        $DB->set_field('livestream', 'roomname', $result['roomName'], ['id' => $id]);

        if (get_config('mod_livestream', 'autoenroll')) {
            livestream_sync_enrollments($data->course, $result['roomId']);
        }

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
        case FEATURE_GROUPS:           return true;  // Approche A — une salle par groupe
        case FEATURE_GROUPINGS:        return true;
        default:                       return null;
    }
}

/**
 * Approche A — identifiant de réunion (meetingId) qualifié par le groupe.
 *
 * Le groupe 0 (« tous les participants ») conserve le meetingId historique
 * (= id d'instance) pour rester compatible avec les salles existantes.
 * Chaque autre groupe obtient une salle distincte côté LiveStream via un
 * meetingId composite URL-safe « {instanceid}-g{groupid} ».
 *
 * @param int $instanceid Id de l'instance livestream
 * @param int $groupid    Id du groupe Moodle (0 = tous)
 * @return string
 */
function livestream_meetingid_for_group(int $instanceid, int $groupid): string {
    return $groupid > 0 ? $instanceid . '-g' . $groupid : (string)$instanceid;
}

/**
 * Serveur webinaire actuellement configuré (normalisé, sans slash final).
 * Sert de discriminant pour les enregistrements en cache.
 */
function livestream_current_server(): string {
    return rtrim((string)get_config('mod_livestream', 'apiurl'), '/');
}

/**
 * Synchronise le cache local des enregistrements d'une salle (pour le groupe
 * donné) avec le serveur courant. Les lignes sont taggées avec le serveur ;
 * les enregistrements disparus côté serveur sont élagués UNIQUEMENT pour ce
 * couple (serveur, salle) — les autres serveurs sont préservés.
 *
 * @param int    $livestreamid Id d'instance
 * @param int    $courseid     Id du cours
 * @param int    $groupid      Id du groupe (0 = tous)
 * @param string $roomid       Id de la salle côté backend
 * @param mod_livestream_api|null $api Client réutilisable (optionnel)
 */
function livestream_sync_room_recordings(int $livestreamid, int $courseid, int $groupid,
        string $roomid, ?mod_livestream_api $api = null): void {
    global $DB;

    if (empty($roomid)) {
        return;
    }

    $server = livestream_current_server();

    try {
        $api  = $api ?? new mod_livestream_api();
        $data = $api->getRecordings($roomid);
    } catch (Exception $e) {
        // Serveur injoignable ou salle inconnue sur ce serveur : on ne touche pas
        // au cache (ne pas masquer/élaguer sur une erreur transitoire).
        debugging('LiveStream sync recordings error', DEBUG_DEVELOPER);
        return;
    }

    // Réponse valide uniquement si la clé 'recordings' est présente.
    $hasvalidresponse = array_key_exists('recordings', $data);
    $recordings       = $hasvalidresponse ? ($data['recordings'] ?? []) : [];

    $now  = time();
    $seen = [];

    foreach ($recordings as $rec) {
        if (empty($rec['id'])) {
            continue;
        }
        $recordingid = (string)$rec['id'];
        $seen[]      = $recordingid;

        $record = (object)[
            'livestreamid' => $livestreamid,
            'courseid'     => $courseid,
            'groupid'      => $groupid,
            'serverurl'    => $server,
            'roomid'       => $roomid,
            'recordingid'  => $recordingid,
            'name'         => (string)($rec['name'] ?? ''),
            'duration'     => (int)($rec['duration'] ?? 0),
            'recdate'      => !empty($rec['date']) ? (int)strtotime($rec['date']) : $now,
            'playurl'      => (string)($rec['playUrl'] ?? ''),
            'timemodified' => $now,
        ];

        $existing = $DB->get_record('livestream_recordings', [
            'serverurl'   => $server,
            'recordingid' => $recordingid,
        ]);

        if ($existing) {
            $record->id          = $existing->id;
            $record->timecreated = $existing->timecreated;
            $DB->update_record('livestream_recordings', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('livestream_recordings', $record);
        }
    }

    // Élagage : seulement si la réponse est valide (sinon on conserve le cache).
    if ($hasvalidresponse) {
        $existingrows = $DB->get_records('livestream_recordings', [
            'livestreamid' => $livestreamid,
            'serverurl'    => $server,
            'groupid'      => $groupid,
            'roomid'       => $roomid,
        ], '', 'id, recordingid');

        foreach ($existingrows as $row) {
            if (!in_array($row->recordingid, $seen, true)) {
                $DB->delete_records('livestream_recordings', ['id' => $row->id]);
            }
        }
    }
}

/**
 * Synchronise toutes les salles connues (appelée par le cron) depuis le serveur
 * courant : salle de base (groupe 0) de chaque instance + salles de groupe déjà
 * présentes dans le cache.
 */
function livestream_sync_all_recordings(): void {
    global $DB;

    $instances = $DB->get_records('livestream');

    foreach ($instances as $inst) {
        // Salle de base (groupe 0).
        if (!empty($inst->roomid)) {
            livestream_sync_room_recordings((int)$inst->id, (int)$inst->course, 0, $inst->roomid);
        }

        // Salles de groupe déjà connues (tous serveurs confondus), resynchronisées
        // depuis le serveur courant. Une salle d'un autre serveur lèvera une erreur
        // (salle inconnue) et sera ignorée sans dommage.
        $known = $DB->get_records_sql(
            "SELECT DISTINCT groupid, roomid
               FROM {livestream_recordings}
              WHERE livestreamid = :lid AND groupid <> 0 AND roomid IS NOT NULL AND " .
              $DB->sql_isnotempty('livestream_recordings', 'roomid', true, false),
            ['lid' => $inst->id]
        );

        foreach ($known as $k) {
            livestream_sync_room_recordings((int)$inst->id, (int)$inst->course,
                (int)$k->groupid, (string)$k->roomid);
        }
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
