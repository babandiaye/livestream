<?php
namespace mod_livestream\event;

defined('MOODLE_INTERNAL') || die();

class recording_deleted extends \core\event\base {
    protected function init(): void {
        $this->data['crud']     = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->objecttable      = 'livestream';
    }

    public static function get_name(): string {
        return get_string('event_recording_deleted', 'mod_livestream');
    }

    public function get_description(): string {
        return "Le modérateur {$this->userid} a supprimé un enregistrement de la session livestream {$this->objectid}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/livestream/view.php', ['id' => $this->contextinstanceid]);
    }
}
