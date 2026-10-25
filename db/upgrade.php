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
 * Upgrade steps for {{COMPONENT}}.
 *
 * Ship this file from the start: Moodle calls the function on every version bump, and the
 * validation of the Moodle plugins directory rejects a plugin without it.
 *
 * @package    {{COMPONENT}}
 * @copyright  {{YEAR}} {{AUTHOR}}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the {{COMPONENT}} plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_{{PLUGINNAME}}_upgrade($oldversion) {
    // Add steps as "if ($oldversion < <version>) { ... upgrade_plugin_savepoint(...); }".
    //
    // Never declare DEFAULT="" on a CHAR NOT NULL column: XMLDB emits a debugging message for it,
    // and moodle-plugin-ci treats any debugging message during PHPUnit initialisation as a failure.
    return true;
}
