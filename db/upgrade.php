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
 * Upgrade steps for local_ltiaccountmerge.
 *
 * Release 0.2.0 (2026102502) was the first release under this component name (renamed from local_ltiaccountreconcile, see
 * docs/CHANGELOG.md). There is no upgrade path from the 0.1.0 alpha of the old component: uninstall the old plugin and
 * install this one. Steps below start at the last delivered build 2026102502.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_ltiaccountmerge plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_ltiaccountmerge_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102700) {
        // Release 0.3.0: crash-recovery journal of merges run through the reviewed merge workflow (audit item AR-03).
        $table = new xmldb_table('local_ltiaccountmerge_job');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sourceuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'consented');
        $table->add_field('previewhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('preview', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('consentby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeconsented', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('inmerge', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('mergelogid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('recovered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('outcome', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('supportid', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('consentby', XMLDB_KEY_FOREIGN, ['consentby'], 'user', ['id']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('sourceuserid', XMLDB_INDEX_NOTUNIQUE, ['sourceuserid']);
        $table->add_index('targetuserid', XMLDB_INDEX_NOTUNIQUE, ['targetuserid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // New setting (default on): merges of accounts with LTI identities need the reviewed workflow. Written explicitly so
        // that the behaviour change is visible in the configuration log of upgraded sites; an existing value is kept.
        if (get_config('local_ltiaccountmerge', 'requireconsent') === false) {
            set_config('requireconsent', 1, 'local_ltiaccountmerge');
        }
        upgrade_plugin_savepoint(true, 2026102700, 'local', 'ltiaccountmerge');
    }

    return true;
}
