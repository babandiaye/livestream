<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/livestream/backup/moodle2/restore_livestream_stepslib.php');

class restore_livestream_activity_task extends restore_activity_task {

    protected function define_my_settings(): void {
        // Aucun réglage spécifique pour cette activité.
    }

    protected function define_my_steps(): void {
        $this->add_step(new restore_livestream_activity_structure_step('livestream_structure', 'livestream.xml'));
    }

    public static function define_decode_contents(): array {
        return [
            new restore_decode_content('livestream', ['intro'], 'livestream'),
        ];
    }

    public static function define_decode_rules(): array {
        return [
            new restore_decode_rule('LIVESTREAMVIEWBYID', '/mod/livestream/view.php?id=$1', 'course_module'),
            new restore_decode_rule('LIVESTREAMINDEX', '/mod/livestream/index.php?id=$1', 'course'),
        ];
    }

    public static function define_restore_log_rules(): array {
        return [
            new restore_log_rule('livestream', 'add', 'view.php?id={course_module}', '{livestream}'),
            new restore_log_rule('livestream', 'update', 'view.php?id={course_module}', '{livestream}'),
            new restore_log_rule('livestream', 'view', 'view.php?id={course_module}', '{livestream}'),
        ];
    }

    public static function define_restore_log_rules_for_course(): array {
        return [
            new restore_log_rule('livestream', 'view all', 'index.php?id={course}', null),
        ];
    }
}
