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

use local_ltiaccountmerge\local\mode_applier;

/**
 * Every-minute task: bring the provisioning modes of the published resources in line with the live policies.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_modes extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_applymodes', 'local_ltiaccountmerge');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $summary = (new mode_applier())->apply_all(null, 'task');
        if ($summary['locked']) {
            mtrace('local_ltiaccountmerge: lock busy, will retry next minute');
        } else if ($summary['failed']) {
            mtrace("local_ltiaccountmerge: {$summary['failed']} resource(s) could not be updated, see the audit trail");
        } else if ($summary['changed'] || $summary['restored']) {
            mtrace("local_ltiaccountmerge: applied {$summary['changed']} change(s)");
        }
    }
}
