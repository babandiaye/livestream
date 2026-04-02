<?php
namespace mod_livestream\event;

defined('MOODLE_INTERNAL') || die();

class session_joined extends \core\event\base {
    protected function init(): void {
        $this->data['crud']     = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->objecttable      = 'livestream';
    }

    public static function get_name(): string {
        return get_string('event_session_joined', 'mod_livestream');
    }

    public function get_description(): string {
        return "L'utilisateur {$this->userid} a rejoint la session livestream {$this->objectid}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/livestream/view.php', ['id' => $this->contextinstanceid]);
    }
}
