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
 * Execute one consented merge job of the reviewed workflow (see {@see executor}).
 *
 * At most one runs at a time on the site (concurrency limit 1, plus the executor's lock). When another merge is running the
 * task throws, so core retries it later; every other outcome is final and recorded in the job. A failed merge is never
 * retried automatically: it needs a new preview and consent.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execute_merge extends \core\task\adhoc_task {
    /**
     * Queue the execution of a job (never twice for the same job).
     *
     * @param int $jobid Job id.
     * @return void
     */
    public static function queue(int $jobid): void {
        $task = new self();
        $task->set_component('local_ltiaccountmerge');
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * One merge at a time.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 1;
    }

    /**
     * Run the job.
     *
     * @return void
     */
    public function execute() {
        $jobid = (int) ($this->get_custom_data()->jobid ?? 0);
        if ($jobid <= 0) {
            return;
        }
        $status = (new executor())->execute($jobid);
        mtrace("local_ltiaccountmerge merge job {$jobid}: {$status}");
        if ($status === executor::BUSY) {
            throw new \moodle_exception('err_busy', 'local_ltiaccountmerge');
        }
    }
}
