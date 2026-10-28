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

/**
 * Event observers of local_ltiaccountmerge.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Merges performed by tool_mergeusers are reconciled; the event only fires when that plugin is installed.
        'eventname' => '\tool_mergeusers\event\user_merged_success',
        'callback' => '\local_ltiaccountmerge\observer::user_merged',
    ],
    [
        // Failed (rolled back) merges of LTI accounts are recorded in the audit trail.
        'eventname' => '\tool_mergeusers\event\user_merged_failure',
        'callback' => '\local_ltiaccountmerge\observer::user_merge_failed',
    ],
];
