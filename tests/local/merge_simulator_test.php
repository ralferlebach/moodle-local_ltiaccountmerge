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

namespace local_ltiaccountmerge\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');

/**
 * Tests of the merge simulation: inventory, conflict detection, blocking, read-only behaviour, export.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\merge_simulator
 */
final class merge_simulator_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /** @var string Name of the fake third-party table. */
    protected const FAKE = 'zzfake_visits';

    /**
     * Create the fake plugin table (no install.xml describes it, no component owns it).
     */
    protected function create_fake_table(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FAKE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('payload', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'x');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $dbman->create_table($table);
    }

    /**
     * Drop the fake table again.
     */
    protected function drop_fake_table(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FAKE);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
    }

    /**
     * Find a matrix row.
     *
     * @param \stdClass $report Report.
     * @param string $table Table name.
     * @return array|null
     */
    protected function row(\stdClass $report, string $table): ?array {
        foreach ($report->tables as $row) {
            if ($row['table'] === $table) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Two accounts that are both enrolled in the same course and graded: the collisions are named.
     */
    public function test_names_conflicts_for_shared_course_and_grades(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $a = $generator->create_user(['username' => 'dup.a']);
        $b = $generator->create_user(['username' => 'dup.b']);
        $generator->enrol_user($a->id, $course->id, 'student');
        $generator->enrol_user($b->id, $course->id, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemmodule' => 'assign', 'iteminstance' => $assign->id]);
        $item->update_final_grade($a->id, 50);
        $item->update_final_grade($b->id, 60);

        $report = (new merge_simulator())->simulate($a->id, $b->id);

        $enrolments = $this->row($report, 'user_enrolments');
        $this->assertNotNull($enrolments);
        $this->assertSame('conflict', $enrolments['status']);
        $this->assertSame(1, $enrolments['collisions']);
        $this->assertStringContainsString('(enrolid, userid)', $enrolments['reason']);
        $grades = $this->row($report, 'grade_grades');
        $this->assertSame('conflict', $grades['status']);
        $this->assertSame('grades', $grades['category']);
        $roles = $this->row($report, 'role_assignments');
        $this->assertNotNull($roles);
        $names = array_column($report->conflicts, 'table');
        $this->assertContains('user_enrolments', $names);
        $this->assertContains('grade_grades', $names);
        // The MVP never offers to execute a merge.
        $this->assertFalse($report->fullmergeavailable);
        $this->assertSame('dup.a', $report->source->username);
        $this->assertSame('dup.b', $report->target->username);
    }

    /**
     * Rows that exist only for one account are "review", not conflicts; immutable log tables are "preserve".
     */
    public function test_review_and_preserve_statuses(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $a = $generator->create_user();
        $b = $generator->create_user();
        $course = $generator->create_course();
        $generator->enrol_user($a->id, $course->id, 'student');
        $DB->insert_record('logstore_standard_log', (object) ['eventname' => '\\core\\event\\x', 'component' => 'core',
            'action' => 'a', 'target' => 't', 'crud' => 'r', 'edulevel' => 0, 'contextid' => 1, 'contextlevel' => 10,
            'contextinstanceid' => 0, 'userid' => $a->id, 'anonymous' => 0, 'timecreated' => time(), 'origin' => 'cli']);
        $report = (new merge_simulator())->simulate($a->id, $b->id);
        $this->assertSame('review', $this->row($report, 'user_enrolments')['status']);
        $this->assertSame('preserve', $this->row($report, 'logstore_standard_log')['status']);
        $this->assertSame('logs', $this->row($report, 'logstore_standard_log')['category']);
        // An immutable log table never blocks and never conflicts.
        $this->assertNotContains('logstore_standard_log', $report->blockedtables);
    }

    /**
     * An unknown plugin table holding data of either account blocks a full merge; the fake table is named.
     */
    public function test_unknown_plugin_table_blocks(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $before = (new merge_simulator())->simulate($a->id, $b->id);
        $this->assertNotContains(self::FAKE, $before->blockedtables);
        try {
            $this->create_fake_table();
            $DB->insert_record(self::FAKE, (object) ['userid' => $a->id, 'payload' => 'secret-data']);
            $report = (new merge_simulator())->simulate($a->id, $b->id);
            $row = $this->row($report, self::FAKE);
            $this->assertNotNull($row, 'the table without install.xml was not found by the DB metadata scan');
            $this->assertSame('blocked', $row['status']);
            $this->assertSame('unknown', $row['owner']);
            $this->assertSame(1, $row['rowssource']);
            $this->assertSame(0, $row['rowstarget']);
            $this->assertTrue($report->blocked);
            $this->assertSame('blocked', $report->verdict);
            $this->assertContains(self::FAKE, $report->blockedtables);
            $this->assertContains(self::FAKE, array_column($report->conflicts, 'table'));
            $this->assertFalse($report->fullmergeavailable);
            // The data of the unknown table is not copied into the report.
            $this->assertStringNotContainsString('secret-data', merge_simulator::to_json($report));

            // A table of an unknown plugin without data of either account does not block.
            $DB->delete_records(self::FAKE);
            $empty = (new merge_simulator())->simulate($a->id, $b->id);
            $this->assertSame('ok', $this->row($empty, self::FAKE)['status']);
            $this->assertNotContains(self::FAKE, $empty->blockedtables);
        } finally {
            $this->drop_fake_table();
        }
    }

    /**
     * A declared adapter lifts the block of a third-party component (downgrading it to "review"), an undeclared
     * third-party component with data blocks. An unowned table stays blocked whatever is declared.
     */
    public function test_declared_adapter_unblocks_third_party_component(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        // A row of this plugin's policy table references account A (usermodified).
        $this->create_policy(['usermodified' => $a->id]);
        audit::log('x', audit::OK, 'none', 0, $a->id);

        $declared = (new merge_simulator())->simulate($a->id, $b->id);
        $policy = $this->row($declared, 'local_ltiaccountmerge_policy');
        $this->assertSame('adapter', $policy['owner']);
        $this->assertSame('review', $policy['status']);
        $this->assertSame('preserve', $this->row($declared, 'local_ltiaccountmerge_audit')['status']);

        $undeclared = new class extends merge_simulator {
            /**
             * Constructor without the built-in declaration of this plugin.
             */
            public function __construct() {
                $this->adaptedcomponents = [];
            }
        };
        $report = $undeclared->simulate($a->id, $b->id);
        $policy = $this->row($report, 'local_ltiaccountmerge_policy');
        $this->assertSame('thirdparty', $policy['owner']);
        $this->assertSame('blocked', $policy['status']);
        $this->assertTrue($report->blocked);
        $this->assertContains('local_ltiaccountmerge_policy', $report->blockedtables);
        // Immutable audit data is preserved, never blocked, even for an undeclared component.
        $this->assertSame('preserve', $this->row($report, 'local_ltiaccountmerge_audit')['status']);

        try {
            $this->create_fake_table();
            global $DB;
            $DB->insert_record(self::FAKE, (object) ['userid' => $b->id]);
            $fake = (new merge_simulator(['local_zzfake']))->simulate($a->id, $b->id);
            $this->assertSame('blocked', $this->row($fake, self::FAKE)['status']);
        } finally {
            $this->drop_fake_table();
        }
    }

    /**
     * The simulation performs no database write at all and does not change any data.
     */
    public function test_simulation_is_read_only(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $a = $generator->create_user();
        $b = $generator->create_user();
        $generator->enrol_user($a->id, $course->id);
        $generator->enrol_user($b->id, $course->id);
        $tables = ['user', 'user_enrolments', 'role_assignments', 'grade_grades', 'auth_lti_linked_login',
            'local_ltiaccountmerge_audit'];
        $snapshot = function () use ($DB, $tables) {
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = md5(json_encode(array_values($DB->get_records($table, null, 'id'))));
            }
            return $out;
        };
        $before = $snapshot();
        $writes = $DB->perf_get_writes();
        (new merge_simulator())->simulate($a->id, $b->id);
        $this->assertSame($writes, $DB->perf_get_writes(), 'the simulation issued a write query');
        $this->assertSame($before, $snapshot());
    }

    /**
     * Invalid input: the same account twice, missing or deleted accounts.
     */
    public function test_invalid_accounts_rejected(): void {
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_user();
        $gone = $this->getDataGenerator()->create_user(['deleted' => 1]);
        $cases = [[$a->id, $a->id, 'err_samemerge'], [$a->id, 999999, 'err_mergeuser'], [$gone->id, $a->id, 'err_mergeuser']];
        foreach ($cases as $case) {
            try {
                (new merge_simulator())->simulate($case[0], $case[1]);
                $this->fail('accepted ' . $case[2]);
            } catch (\moodle_exception $e) {
                $this->assertSame($case[2], $e->errorcode);
            }
        }
    }

    /**
     * Exports: CSV has one line per table plus a header, JSON round-trips the verdict.
     */
    public function test_exports(): void {
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $report = (new merge_simulator())->simulate($a->id, $b->id);
        $csv = merge_simulator::to_csv($report);
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertStringStartsWith('table,component,owner', $lines[0]);
        $this->assertGreaterThan(50, count($lines));
        $json = json_decode(merge_simulator::to_json($report), true);
        $this->assertSame($report->verdict, $json['verdict']);
        $this->assertFalse($json['fullmergeavailable']);
        $this->assertCount(count($report->tables), $json['tables']);
    }

    /**
     * The inventory covers the whole schema: tables that carry user ids in core and in this plugin are found.
     */
    public function test_inventory_is_broad(): void {
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $report = (new merge_simulator())->simulate($a->id, $b->id);
        $found = array_column($report->tables, 'table');
        foreach (
            ['user_enrolments', 'role_assignments', 'groups_members', 'grade_grades', 'question_attempt_steps', 'quiz_attempts',
                'assign_submission', 'badge_issued', 'competency_usercomp', 'course_modules_completion', 'post',
                'auth_lti_linked_login',
                'logstore_standard_log', 'local_ltiaccountmerge_audit', 'files'] as $expected
        ) {
            $this->assertContains($expected, $found, $expected);
        }
        $this->assertGreaterThan(100, count($found));
        $this->assertSame('ltibinding', $this->row($report, 'auth_lti_linked_login')['category']);
    }

    /**
     * The renderer shows verdict, conflicts and the matrix with accessible table markup.
     */
    public function test_report_rendering(): void {
        global $DB, $PAGE;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $PAGE->set_url('/local/ltiaccountmerge/simulate.php');
        $PAGE->set_context(\context_system::instance());
        $a = $this->getDataGenerator()->create_user(['username' => 'ra']);
        $b = $this->getDataGenerator()->create_user(['username' => 'rb']);
        try {
            $this->create_fake_table();
            $DB->insert_record(self::FAKE, (object) ['userid' => $a->id]);
            $report = (new merge_simulator())->simulate($a->id, $b->id);
            $html = $PAGE->get_renderer('local_ltiaccountmerge')->merge_report($report, true, ['csv' => '#c', 'json' => '#j']);
        } finally {
            $this->drop_fake_table();
        }
        $this->assertStringContainsString('data-verdict="blocked"', $html);
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringContainsString(self::FAKE, $html);
        $this->assertStringContainsString('ra', $html);
    }
}
