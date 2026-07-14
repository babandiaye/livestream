<?php
defined('MOODLE_INTERNAL') || die();

class restore_livestream_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure(): array {
        $paths = [
            new restore_path_element('livestream', '/activity/livestream'),
        ];
        return $this->prepare_activity_structure($paths);
    }

    protected function process_livestream(array $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timecreated  = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // V12 — la salle (roomid/roomname) référence une session persistante sur la
        // plateforme LiveStream externe, propre au COUPLE cours+activité d'origine.
        // La conserver telle quelle ferait partager la même salle vidéo entre le cours
        // d'origine et le cours restauré (participants et enregistrements mélangés).
        // On la réinitialise : l'activité restaurée apparaît sans salle associée,
        // jusqu'à ce qu'une nouvelle soit (re)créée.
        $data->roomid   = null;
        $data->roomname = null;

        $newitemid = $DB->insert_record('livestream', $data);
        $this->set_mapping('livestream', $oldid, $newitemid);
        $this->apply_activity_instance($newitemid);
    }

    protected function after_execute(): void {
        $this->add_related_files('mod_livestream', 'intro', null);
    }
}
