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

namespace mod_livestream\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tâche planifiée : synchronise le cache local des enregistrements avec le
 * serveur webinaire actuellement configuré (mod_livestream/apiurl).
 *
 * Les enregistrements sont taggés par serveur : changer de serveur masque ceux
 * des autres serveurs (sans les détruire), revenir à un serveur les ré-affiche.
 */
class sync_recordings extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sync_recordings', 'mod_livestream');
    }

    public function execute(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/livestream/lib.php');
        require_once($CFG->dirroot . '/mod/livestream/classes/api.php');

        livestream_sync_all_recordings();
    }
}
