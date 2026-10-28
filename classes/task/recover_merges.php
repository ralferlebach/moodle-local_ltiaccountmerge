<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ltiaccountmerge\task;

use local_ltiaccountmerge\local\merge\executor;

/**
 * Crash recovery of the merge workflow: resolves jobs whose process died during a merge and expires old consents.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recover_merges extends \core\task\scheduled_task {
    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_recovermerges', 'local_ltiaccountmerge');
    }

    /**
     * Run the recovery.
     *
     * @return void
     */
    public function execute() {
        $counts = (new executor())->recover();
        mtrace('local_ltiaccountmerge merge recovery: ' . json_encode($counts));
    }
}
