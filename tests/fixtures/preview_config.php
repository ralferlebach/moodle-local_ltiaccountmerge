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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/settings_hook_standin.php');

use local_ltiaccountmerge\local\merge_preview;

/**
 * Preview configuration resembling tool_mergeusers 2026081302 with this plugin's hook settings (no tool installed).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_config {
    /** @var string[] User columns of tool_mergeusers' default configuration. */
    public const DEFAULT_FIELDS = ['authorid', 'id_user', 'loggeduser', 'reviewerid', 'user', 'user_id', 'usercreated', 'userid',
        'useridfrom', 'useridto', 'usermodified'];

    /**
     * Build a preview: tool_mergeusers' default exceptions, user columns, compound indexes and table mergers (a subset of
     * its default_db_config), layered like the tool does with the settings that the installed plugins' callbacks
     * contribute (called with {@see settings_hook_standin}), plus test-specific declarations.
     *
     * @param array $extradeclared Table => declaring sources (for example ['admin']).
     * @param array $compound Additional compound indexes.
     * @param string|null $unavailable Reason when the tool is "not installed".
     * @return merge_preview
     */
    public static function preview(array $extradeclared = [], array $compound = [], ?string $unavailable = null): merge_preview {
        $defaults = [
            'exceptions' => ['user_preferences', 'user_private_key', 'user_info_data', 'my_pages'],
            'compoundindexes' => [
                'groups_members' => ['userfield' => ['userid'], 'otherfields' => ['groupid']],
                'user_enrolments' => ['userfield' => ['userid'], 'otherfields' => ['enrolid']],
                'role_assignments' => ['userfield' => ['userid'], 'otherfields' => ['contextid', 'roleid']],
            ],
            'userfieldnames' => ['default' => self::DEFAULT_FIELDS],
            'tablemergers' => ['grade_grades' => 'tool_mergeusers\\local\\merger\\grade_grades_table_merger'],
        ];
        $declared = [];
        merge_preview::declare_tables($declared, $defaults, 'tool_mergeusers');
        $effective = $defaults;
        foreach (merge_preview::callback_settings(fn() => new settings_hook_standin()) as $component => $settings) {
            merge_preview::declare_tables($declared, $settings, $component);
            // Same layering as tool_mergeusers: defaults have the lowest priority.
            $effective = array_replace_recursive($effective, $settings);
        }
        foreach ($extradeclared as $table => $sources) {
            foreach ($sources as $source) {
                $declared[$table][$source] = $source;
            }
        }
        foreach ($declared as $table => $sources) {
            $declared[$table] = array_values($sources);
        }
        return new merge_preview(
            [
                'skip' => array_values($effective['exceptions']),
                'compoundindexes' => $compound + $effective['compoundindexes'],
                'userfieldnames' => $effective['userfieldnames'],
            ],
            $declared,
            merge_preview::after_handlers(),
            ['grade_grades' => $defaults['tablemergers']['grade_grades']],
            '2026081302',
            $unavailable
        );
    }
}
