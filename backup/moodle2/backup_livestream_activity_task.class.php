<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/livestream/backup/moodle2/backup_livestream_stepslib.php');

class backup_livestream_activity_task extends backup_activity_task {

    protected function define_my_settings(): void {
        // Aucun réglage spécifique pour cette activité.
    }

    protected function define_my_steps(): void {
        $this->add_step(new backup_livestream_activity_structure_step('livestream_structure', 'livestream.xml'));
    }

    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot . '/mod/livestream', '#');

        // Lien vers la liste des activités livestream du cours.
        $pattern = '#(' . $base . "\/index.php\?id\=)([0-9]+)#";
        $content = preg_replace($pattern, '$@LIVESTREAMINDEX*$2@$', $content);

        // Lien vers une activité livestream par id de course module.
        $pattern = '#(' . $base . "\/view.php\?id\=)([0-9]+)#";
        $content = preg_replace($pattern, '$@LIVESTREAMVIEWBYID*$2@$', $content);

        return $content;
    }
}
