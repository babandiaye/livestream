<?php
defined('MOODLE_INTERNAL') || die();

class backup_livestream_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $livestream = new backup_nested_element('livestream', ['id'], [
            'course', 'name', 'intro', 'introformat', 'roomid', 'roomname',
            'timecreated', 'timemodified',
        ]);

        $livestream->set_source_table('livestream', ['id' => backup::VAR_ACTIVITYID]);

        // V12 — annotation de fichiers pour le champ intro (images/pièces jointes de la description).
        $livestream->annotate_files('mod_livestream', 'intro', null);

        return $this->prepare_activity_structure($livestream);
    }
}
