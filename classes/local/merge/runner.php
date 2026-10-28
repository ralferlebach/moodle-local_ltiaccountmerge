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
 * The tool that performs a real account merge. Production: {@see mergeusers_runner} (tool_mergeusers); tests use a
 * stand-in with the same transaction semantics.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface runner {
    /**
     * Whether the merge tool is installed and usable.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Create the tool's own log entry for a merge that is about to run, before the merge transaction starts.
     *
     * @param int $toid Account to keep.
     * @param int $fromid Account to merge away.
     * @param int $byuserid Administrator who consented.
     * @return int Log id, 0 when the tool cannot pre-create a log entry.
     */
    public function prepare_log(int $toid, int $fromid, int $byuserid): int;

    /**
     * Run the merge: one transaction, all or nothing; the tool dispatches after_merged_all_tables inside it.
     *
     * @param int $toid Account to keep.
     * @param int $fromid Account to merge away.
     * @param int $logid Log id from {@see prepare_log()} or 0.
     * @return array [bool success, string[] log lines, int log id]
     */
    public function merge(int $toid, int $fromid, int $logid): array;

    /**
     * Status of a log entry of the tool ('' when unknown).
     *
     * @param int $logid Log id.
     * @return string
     */
    public function log_status(int $logid): string;

    /**
     * Correct the status of a log entry the tool left unfinished (crash recovery).
     *
     * @param int $logid Log id.
     * @param bool $success Final outcome.
     * @param string $line Explanation appended to the log.
     * @return void
     */
    public function finish_log(int $logid, bool $success, string $line): void;
}
