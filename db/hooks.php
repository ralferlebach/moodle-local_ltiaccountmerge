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
 * Hook callbacks of local_ltiaccountmerge.
 *
 * The after_config callback is a no-op unless the optional "pre-launch apply" setting is on and the
 * request is for exactly one of the two enrol_lti launch scripts. The output callback is a no-op unless the optional
 * "first-launch hint" setting is on and the request is for the core binding dialog or a launch script.
 *
 * The two tool_mergeusers hooks exist only when that plugin is installed. Core accepts callbacks for hook classes that do
 * not exist and simply never dispatches them (see tests/mergeusers_callbacks_test.php), so they are registered unconditionally.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\after_config::class,
        'callback' => [\local_ltiaccountmerge\hook_callbacks::class, 'after_config'],
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => [\local_ltiaccountmerge\hook_callbacks::class, 'before_standard_top_of_body_html_generation'],
        'priority' => 0,
    ],
    [
        'hook' => \tool_mergeusers\hook\add_settings_before_merging::class,
        'callback' => [\local_ltiaccountmerge\mergeusers_callbacks::class, 'add_settings_before_merging'],
        'priority' => 0,
    ],
    [
        'hook' => \tool_mergeusers\hook\after_merged_all_tables::class,
        'callback' => [\local_ltiaccountmerge\mergeusers_callbacks::class, 'after_merged_all_tables'],
        'priority' => 0,
    ],
];
