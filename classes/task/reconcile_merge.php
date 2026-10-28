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

use local_ltiaccountmerge\local\merge_service;

/**
 * Reconcile this plugin's state after an external account merge, outside the merging request.
 *
 * Queued by the observer (deduplicated per pair). The service is idempotent, so a retry by the task framework is safe: a
 * busy pair lock and an unexpected failure both throw, which makes core reschedule the task with back-off, until
 * {@see merge_service::MAX_ATTEMPTS} attempts are recorded in the journal.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_merge extends \core\task\adhoc_task {
    /**
     * Queue the reconciliation of one merge (the same pair is never queued twice).
     *
     * @param int $fromuserid Account that was merged away.
     * @param int $touserid Surviving account.
     * @return void
     */
    public static function queue(int $fromuserid, int $touserid): void {
        $task = new self();
        $task->set_component('local_ltiaccountmerge');
        $task->set_custom_data(['from' => $fromuserid, 'to' => $touserid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Run the reconciliation.
     *
     * @return void
     */
    public function execute() {
        $data = (array) $this->get_custom_data();
        $from = (int) ($data['from'] ?? 0);
        $to = (int) ($data['to'] ?? 0);
        if ($from <= 0 || $to <= 0) {
            return;
        }
        $result = merge_service::reconcile($from, $to);
        mtrace("local_ltiaccountmerge merge {$from} -> {$to}: {$result->status}");
        if ($result->status === merge_service::LOCKED && merge_service::attempts($from, $to) < merge_service::MAX_ATTEMPTS) {
            throw new \moodle_exception('err_busy', 'local_ltiaccountmerge');
        }
    }
}
