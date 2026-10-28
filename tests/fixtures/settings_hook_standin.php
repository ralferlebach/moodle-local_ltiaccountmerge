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
 * Stand-in with the public contract of tool_mergeusers\hook\add_settings_before_merging (tool_mergeusers 2026081302).
 *
 * Used only when tool_mergeusers is not installed. add_raw_settings() combines like the real db_config::add_raw():
 * array_replace_recursive($new, $existing), so settings that are already there win. get_settings() returns the plain
 * array instead of a db_config object.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_hook_standin {
    /** @var array Collected settings. */
    private array $settings = [];

    /**
     * Add one setting.
     *
     * @param string $name Root setting name.
     * @param mixed $value Value.
     */
    public function add_setting(string $name, mixed $value): void {
        $this->add_raw_settings([$name => $value]);
    }

    /**
     * Add several settings; existing ones win.
     *
     * @param array $settings Settings.
     */
    public function add_raw_settings(array $settings): void {
        $this->settings = array_replace_recursive($settings, $this->settings);
    }

    /**
     * Collected settings.
     *
     * @return array
     */
    public function get_settings(): array {
        return $this->settings;
    }
}
