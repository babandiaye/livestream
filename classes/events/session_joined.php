<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_livestream\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when a user joins a LiveStream session.
 */
class session_joined extends \core\event\base {

    protected function init() {
        $this->data['crud']        = 'r';
        $this->data['edulevel']    = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'livestream';
    }

    public static function get_name(): string {
        return get_string('event_session_joined', 'mod_livestream');
    }

    public function get_description(): string {
        return "The user with id '{$this->userid}' joined the LiveStream session " .
               "with course module id '{$this->contextinstanceid}'.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/livestream/view.php', ['id' => $this->contextinstanceid]);
    }

    public static function get_objectid_mapping(): array {
        return ['db' => 'livestream', 'restore' => 'livestream'];
    }
}
