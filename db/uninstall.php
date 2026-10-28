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
 * Uninstall hook of local_ltiaccountmerge.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Give every published resource its own provisioning modes back before the bookkeeping disappears.
 *
 * Without this, a resource that is governed by a policy at the moment the plugin is removed would keep the policy's mode
 * for good, because the baseline lives in this plugin's table.
 *
 * @return bool
 */
function xmldb_local_ltiaccountmerge_uninstall() {
    \local_ltiaccountmerge\local\mode_applier::restore_all_baselines();
    return true;
}
