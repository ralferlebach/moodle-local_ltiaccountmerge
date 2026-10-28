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

namespace local_ltiaccountmerge\local\merge;

/**
 * Runner for tool_mergeusers (version 2026081302 and later: hooks, logger with pending/in-progress status).
 *
 * Only public API of tool_mergeusers is used: user_merger::merge($toid, $fromid, $logid) and logger::create_pending_log() /
 * update_log_status(), the same calls the tool's own queued merge task (merge_users_task) makes. Older versions without a
 * pending log still merge; the job then has no log id until the merge returns.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mergeusers_runner implements runner {
    /**
     * Whether tool_mergeusers with both hooks is installed.
     *
     * @return bool
     */
    public function is_available(): bool {
        return \local_ltiaccountmerge\local\merge_preview::tool_available();
    }

    /**
     * Pre-create a pending log entry of tool_mergeusers.
     *
     * @param int $toid Account to keep.
     * @param int $fromid Account to merge away.
     * @param int $byuserid Administrator who consented.
     * @return int
     */
    public function prepare_log(int $toid, int $fromid, int $byuserid): int {
        $logger = new \tool_mergeusers\local\logger();
        if (!method_exists($logger, 'create_pending_log')) {
            return 0;
        }
        $logid = $logger->create_pending_log($toid, $fromid, $byuserid);
        if ($logid && method_exists($logger, 'update_log_status')) {
            $logger->update_log_status((int) $logid, 'inprogress', []);
        }
        return (int) $logid;
    }

    /**
     * Run the merge.
     *
     * @param int $toid Account to keep.
     * @param int $fromid Account to merge away.
     * @param int $logid Log id or 0.
     * @return array
     */
    public function merge(int $toid, int $fromid, int $logid): array {
        $merger = new \tool_mergeusers\local\user_merger();
        [$success, $log, $id] = $merger->merge($toid, $fromid, $logid > 0 ? $logid : null);
        return [(bool) $success, (array) $log, (int) $id];
    }

    /**
     * Status of a log entry.
     *
     * @param int $logid Log id.
     * @return string
     */
    public function log_status(int $logid): string {
        global $DB;
        if ($logid <= 0) {
            return '';
        }
        return (string) $DB->get_field('tool_mergeusers', 'status', ['id' => $logid]);
    }

    /**
     * Correct an unfinished log entry.
     *
     * @param int $logid Log id.
     * @param bool $success Final outcome.
     * @param string $line Explanation.
     * @return void
     */
    public function finish_log(int $logid, bool $success, string $line): void {
        if ($logid <= 0) {
            return;
        }
        $logger = new \tool_mergeusers\local\logger();
        if (method_exists($logger, 'update_log_status')) {
            $logger->update_log_status($logid, $success ? 'success' : 'error', [$line]);
        }
    }
}
