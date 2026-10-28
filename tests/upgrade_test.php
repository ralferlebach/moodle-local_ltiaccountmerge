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

namespace local_ltiaccountmerge;

/**
 * Upgrade from the last delivered build 2026102502 (release 0.2.0) to 2026102700 (release 0.3.0).
 *
 * The 0.2.0 state is reproduced on the test site: the merge-job table is dropped, the new setting removed, and data of
 * the 0.2.0 tables is present. The upgrade step must create the table exactly as install.xml defines it, set the new
 * setting, keep all other data, and be repeatable.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_ltiaccountmerge_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Make sure the table exists again even if a test failed half way (the schema is not reset by PHPUnit).
     */
    protected function tearDown(): void {
        global $CFG, $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_ltiaccountmerge_job')) {
            $dbman->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/local/ltiaccountmerge/db/install.xml',
                'local_ltiaccountmerge_job'
            );
        }
        parent::tearDown();
    }

    /**
     * Bring the site into the state of a 0.2.0 installation.
     *
     * @return int Id of a reconciliation-journal row that must survive.
     */
    protected function make_020_state(): int {
        global $DB;
        $dbman = $DB->get_manager();
        $dbman->drop_table(new \xmldb_table('local_ltiaccountmerge_job'));
        unset_config('requireconsent', 'local_ltiaccountmerge');
        set_config('mergemovebindings', 0, 'local_ltiaccountmerge');
        set_config('version', 2026102502, 'local_ltiaccountmerge');
        return (int) $DB->insert_record('local_ltiaccountmerge_merge', (object) ['sourceuserid' => 3, 'targetuserid' => 4,
            'status' => 'done', 'attempts' => 1, 'summary' => '{}', 'timecreated' => 1, 'timemodified' => 1,
            'timecompleted' => 1]);
    }

    /**
     * 2026102502 -> 2026102700: job table created like install.xml, setting on, other data kept, version saved.
     */
    public function test_upgrade_from_020(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/ltiaccountmerge/db/upgrade.php');
        $keep = $this->make_020_state();
        $this->assertFalse($DB->get_manager()->table_exists('local_ltiaccountmerge_job'));

        $this->assertTrue(xmldb_local_ltiaccountmerge_upgrade(2026102502));

        $dbman = $DB->get_manager();
        $this->assertTrue($dbman->table_exists('local_ltiaccountmerge_job'));
        $xml = simplexml_load_file($CFG->dirroot . '/local/ltiaccountmerge/db/install.xml');
        foreach ($xml->TABLES->TABLE as $table) {
            if ((string) $table['NAME'] !== 'local_ltiaccountmerge_job') {
                continue;
            }
            $expected = [];
            foreach ($table->FIELDS->FIELD as $field) {
                $expected[(string) $field['NAME']] = (string) $field['TYPE'];
            }
            $actual = $DB->get_columns('local_ltiaccountmerge_job');
            $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual));
            foreach (['status', 'previewhash', 'outcome', 'supportid'] as $char) {
                $this->assertSame('C', $actual[$char]->meta_type, $char);
            }
            $this->assertSame('X', $actual['preview']->meta_type);
        }
        foreach (['status', 'sourceuserid', 'targetuserid'] as $index) {
            $this->assertTrue($dbman->index_exists(
                new \xmldb_table('local_ltiaccountmerge_job'),
                new \xmldb_index($index, XMLDB_INDEX_NOTUNIQUE, [$index])
            ), $index);
        }
        $this->assertSame('1', get_config('local_ltiaccountmerge', 'requireconsent'));
        $this->assertSame('0', get_config('local_ltiaccountmerge', 'mergemovebindings'));
        $this->assertSame('done', $DB->get_field('local_ltiaccountmerge_merge', 'status', ['id' => $keep]));
        $this->assertEquals(2026102700, get_config('local_ltiaccountmerge', 'version'));
        // The new table is usable.
        $DB->insert_record('local_ltiaccountmerge_job', (object) ['sourceuserid' => 3, 'targetuserid' => 4,
            'previewhash' => str_repeat('a', 64), 'consentby' => 2, 'timeconsented' => 1]);
    }

    /**
     * A site where an administrator already decided (setting off) keeps the decision; a repeated run is harmless.
     */
    public function test_upgrade_keeps_existing_setting_and_is_repeatable(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/ltiaccountmerge/db/upgrade.php');
        $this->make_020_state();
        set_config('requireconsent', 0, 'local_ltiaccountmerge');
        $this->assertTrue(xmldb_local_ltiaccountmerge_upgrade(2026102502));
        $this->assertSame('0', get_config('local_ltiaccountmerge', 'requireconsent'));
        // Same old version again (an interrupted upgrade that is restarted): the table exists, nothing fails.
        set_config('version', 2026102502, 'local_ltiaccountmerge');
        $this->assertTrue(xmldb_local_ltiaccountmerge_upgrade(2026102502));
        $this->assertTrue($DB->get_manager()->table_exists('local_ltiaccountmerge_job'));
    }
}
