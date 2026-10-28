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

namespace local_ltiaccountmerge\tests\fixtures;

/**
 * Stand-in with the public contract of tool_mergeusers\hook\after_merged_all_tables (tool_mergeusers 2026081302).
 *
 * Used only when tool_mergeusers is not installed: read-only toid and fromid, add_log() and add_error_message() writing
 * into the caller's arrays by reference. In tool_mergeusers an error message makes the whole merge roll back.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class after_hook_standin {
    /** @var array Log lines of the merge. */
    private array $logs;
    /** @var array Error messages of the merge. */
    private array $errormessages;

    /**
     * Constructor with the signature of the real hook.
     *
     * @param int $toid Surviving user.
     * @param int $fromid User being merged away.
     * @param array $logs Log lines, by reference.
     * @param array $errormessages Error messages, by reference.
     */
    public function __construct(
        /** @var int $toid Surviving user. */
        public readonly int $toid,
        /** @var int $fromid User being merged away. */
        public readonly int $fromid,
        array &$logs,
        array &$errormessages,
    ) {
        $this->logs = &$logs;
        $this->errormessages = &$errormessages;
    }

    /**
     * Add a log line.
     *
     * @param string $logline Line.
     */
    public function add_log(string $logline): void {
        $this->logs[] = $logline;
    }

    /**
     * Add an error message (fails the merge).
     *
     * @param string $errorline Line.
     */
    public function add_error_message(string $errorline): void {
        $this->errormessages[] = $errorline;
    }
}
