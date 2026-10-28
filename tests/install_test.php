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
 * Fresh installation of release 0.3.0 (component renamed in 0.2.0; no upgrade path from the 0.1.0 alpha of the old component):
 * every table of install.xml (including both journals) and the setting defaults are present after install, the release
 * information is consistent and no template placeholder is left. The upgrade from 0.2.0 is tested in upgrade_test.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_ltiaccountmerge_upgrade
 */
final class install_test extends \advanced_testcase {
    /**
     * From the current version on the upgrade function has nothing to do: data and settings untouched.
     */
    public function test_upgrade_function_is_a_noop(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/ltiaccountmerge/db/upgrade.php');
        set_config('mergemovebindings', 0, 'local_ltiaccountmerge');
        $id = $DB->insert_record('local_ltiaccountmerge_merge', (object) ['sourceuserid' => 3, 'targetuserid' => 4,
            'status' => 'done', 'attempts' => 1, 'summary' => '{}', 'timecreated' => 1, 'timemodified' => 1, 'timecompleted' => 1]);
        $this->assertTrue(xmldb_local_ltiaccountmerge_upgrade(2026102700));
        $this->assertSame('0', get_config('local_ltiaccountmerge', 'mergemovebindings'));
        $this->assertSame('done', $DB->get_field('local_ltiaccountmerge_merge', 'status', ['id' => $id]));
    }

    /**
     * Every table of install.xml exists with exactly its columns; the settings have their defaults.
     */
    public function test_fresh_install_schema_and_defaults(): void {
        global $CFG, $DB;
        $xml = simplexml_load_file($CFG->dirroot . '/local/ltiaccountmerge/db/install.xml');
        $this->assertSame('local/ltiaccountmerge/db', (string) $xml['PATH']);
        $names = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string) $table['NAME'];
            $names[] = $name;
            $this->assertStringStartsWith('local_ltiaccountmerge_', $name);
            $fields = [];
            foreach ($table->FIELDS->FIELD as $field) {
                $fields[] = (string) $field['NAME'];
            }
            $actual = array_keys($DB->get_columns($name));
            sort($fields);
            sort($actual);
            $this->assertSame($fields, $actual, 'table ' . $name);
        }
        sort($names);
        $this->assertSame([
            'local_ltiaccountmerge_applied', 'local_ltiaccountmerge_audit', 'local_ltiaccountmerge_job',
            'local_ltiaccountmerge_merge', 'local_ltiaccountmerge_policy', 'local_ltiaccountmerge_request',
            'local_ltiaccountmerge_text',
        ], $names);
        $this->assertEquals(1, get_config('local_ltiaccountmerge', 'mergemovebindings'));
        $this->assertEquals(1, get_config('local_ltiaccountmerge', 'requireconsent'));
        $this->assertEquals(0, get_config('local_ltiaccountmerge', 'firsthint'));
    }

    /**
     * Version and release metadata say 0.3.0 / 2026102700; the change log records the release and the rename.
     */
    public function test_version_metadata(): void {
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/ltiaccountmerge/version.php');
        $this->assertSame('local_ltiaccountmerge', $plugin->component);
        $this->assertSame('0.3.0', $plugin->release);
        $this->assertSame(2026102700, $plugin->version);
        $this->assertSame([405, 503], $plugin->supported);
        $info = \core_plugin_manager::instance()->get_plugin_info('local_ltiaccountmerge');
        $this->assertSame('0.3.0', $info->release);
        $changelog = file_get_contents($CFG->dirroot . '/local/ltiaccountmerge/docs/CHANGELOG.md');
        $this->assertStringContainsString('## 0.3.0', $changelog);
        $this->assertStringContainsString('no upgrade path from 0.1.0 alpha', $changelog);
        $xml = simplexml_load_file($CFG->dirroot . '/local/ltiaccountmerge/db/install.xml');
        $this->assertSame('2026102700', (string) $xml['VERSION']);
        // The template files listed in db/removed_files.txt are gone (the stale-files CI job checks the same).
        $root = $CFG->dirroot . '/local/ltiaccountmerge/';
        foreach (file($root . 'db/removed_files.txt', FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line) !== '' && $line[0] !== '#') {
                $this->assertFileDoesNotExist($root . trim($line));
            }
        }
        // No template placeholder survived anywhere in the shipped tree (audit AR-01).
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $CFG->dirroot . '/local/ltiaccountmerge',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (preg_match('~/(node_modules|\.git|test-results)/~', $path)) {
                continue;
            }
            $this->assertStringNotContainsString('{{', basename($path), $path);
            if (preg_match('~\.(php|xml|json)$~', $path)) {
                $content = file_get_contents($path);
                $this->assertDoesNotMatchRegularExpression('~\{\{(COMPONENT|PLUGINNAME|VERSION|RELEASE)\}\}~', $content, $path);
            }
        }
    }
}
