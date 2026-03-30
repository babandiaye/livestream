<?php
require_once('../../config.php');
defined('MOODLE_INTERNAL') || die();
require_once('lib.php');

$id = optional_param('id', 0, PARAM_INT);

if (empty($id)) {
    redirect(new moodle_url('/'));
}

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/livestream/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_livestream'));

$instances = get_all_instances_in_course('livestream', $course);

if (empty($instances)) {
    notice(get_string('norecordings', 'mod_livestream'), new moodle_url('/course/view.php', ['id' => $id]));
}

$table        = new html_table();
$table->head  = [get_string('sessionname', 'mod_livestream')];
$table->align = ['left'];

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/livestream/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name)
    );
    $table->data[] = [$link];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
