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

use local_ltiaccountmerge\tests\fixtures\preview_config;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');
require_once(__DIR__ . '/../fixtures/preview_config.php');

/**
 * Dry run of a real merge (audit item AR-03): categories, strategies, blockers, conflicts, fingerprint, read-only.
 *
 * tool_mergeusers is not installed on the shared test sites, so the tool's effective configuration is given explicitly
 * ({@see preview_config}: its default exceptions, user columns and two compound indexes plus this plugin's hook settings).
 * The same class reads the real configuration in the integration runs with the real tool (tests/integration).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\merge_preview
 */
final class merge_preview_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /** @var string Fake table of an unknown plugin. */
    protected const FAKE = 'zzfake_progress';

    /**
     * Drop the fake table if a test created it.
     */
    public function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::FAKE);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        parent::tearDown();
    }

    /**
     * Create the fake table (no install.xml, no component).
     */
    protected function create_fake_table(): void {
        global $DB;
        $table = new \xmldb_table(self::FAKE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);
    }

    /**
     * Source A and target B with enrolment, group, grade, completion and an LTI identity of A.
     *
     * @return array [a, b, course, group]
     */
    protected function scenario(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $generator = $this->getDataGenerator();
        $a = $generator->create_user(['username' => 'merge_a']);
        $b = $generator->create_user(['username' => 'merge_b']);
        $course = $generator->create_course(['enablecompletion' => 1]);
        $other = $generator->create_course();
        $generator->enrol_user($a->id, $course->id, 'student');
        $generator->enrol_user($b->id, $other->id, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $a->id]);
        $item = $generator->create_grade_item(['courseid' => $course->id]);
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $a->id, 'finalgrade' => 80,
            'rawgrade' => 80]);
        $DB->insert_record('course_completions', (object) ['userid' => $a->id, 'course' => $course->id,
            'timeenrolled' => 1, 'timestarted' => 1, 'timecompleted' => 2, 'reaggregate' => 0]);
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-a', (int) $a->id);
        return [$a, $b, $course, $group];
    }

    /**
     * Row of a table in the report.
     *
     * @param \stdClass $report Report.
     * @param string $table Table.
     * @return array
     */
    protected function row(\stdClass $report, string $table): array {
        foreach ($report->tables as $row) {
            if ($row['table'] === $table) {
                return $row;
            }
        }
        $this->fail('table not in report: ' . $table);
    }

    /**
     * Category entry.
     *
     * @param \stdClass $report Report.
     * @param string $category Category.
     * @return array
     */
    protected function category_of(\stdClass $report, string $category): array {
        foreach ($report->categories as $entry) {
            if ($entry['category'] === $category) {
                return $entry;
            }
        }
        $this->fail('category not in report: ' . $category);
    }

    /**
     * Every required category is listed (first, in order), every table gets the tool's strategy, and a clean pair is ready.
     */
    public function test_categories_and_strategies(): void {
        $this->resetAfterTest();
        [$a, $b] = $this->scenario();
        $report = preview_config::preview()->build((int) $a->id, (int) $b->id);

        $this->assertTrue($report->available);
        $this->assertSame(merge_preview::READY, $report->verdict, json_encode($report->blockers + $report->conflicts));
        $this->assertSame(
            merge_preview::REQUIRED_CATEGORIES,
            array_slice(array_column($report->categories, 'category'), 0, count(merge_preview::REQUIRED_CATEGORIES))
        );
        $binding = $this->row($report, 'auth_lti_linked_login');
        $this->assertSame(merge_preview::S_OWNER, $binding['strategy']);
        $this->assertSame('local_ltiaccountmerge', $binding['handler']);
        $this->assertSame(merge_preview::T_MOVE, $binding['status']);
        $this->assertSame('ltibindings', $binding['category']);
        $this->assertSame(merge_preview::S_SPECIAL, $this->row($report, 'grade_grades')['strategy']);
        $this->assertSame(merge_preview::S_COMPOUND, $this->row($report, 'groups_members')['strategy']);
        $this->assertSame(merge_preview::S_COMPOUND, $this->row($report, 'user_enrolments')['strategy']);
        $this->assertSame(merge_preview::S_GENERIC, $this->row($report, 'course_completions')['strategy']);
        $this->assertSame(merge_preview::S_SKIPPED, $this->row($report, 'user_preferences')['strategy']);
        foreach (['grades' => 1, 'enrolments' => 1, 'groups' => 1, 'completion' => 1, 'ltibindings' => 1] as $cat => $min) {
            $entry = $this->category_of($report, $cat);
            $this->assertGreaterThanOrEqual($min, $entry['rowssource'], $cat);
            $this->assertSame(merge_preview::T_MOVE, $entry['status'], $cat);
        }
        foreach (['attempts', 'pseudonym', 'completionsync'] as $cat) {
            $this->assertSame(0, $this->category_of($report, $cat)['rowssource'], $cat);
        }
        $this->assertSame(64, strlen($report->hash));
        $summary = merge_preview::summary($report);
        $this->assertSame(merge_preview::READY, $summary['verdict']);
        $this->assertStringNotContainsString('merge_a', json_encode($summary));
    }

    /**
     * A second identity of the same platform, a shared group and a pseudonym-style compound key are conflicts that need
     * explicit acceptance; the merge is not blocked.
     */
    public function test_conflicts(): void {
        $this->resetAfterTest();
        [$a, $b, $course, $group] = $this->scenario();
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-b', (int) $b->id);
        $this->getDataGenerator()->enrol_user($b->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $b->id]);
        $report = preview_config::preview()->build((int) $a->id, (int) $b->id);

        $this->assertSame(merge_preview::CONFLICTS, $report->verdict);
        $this->assertSame(merge_preview::T_CONFLICT, $this->row($report, 'auth_lti_linked_login')['status']);
        $this->assertSame('ownerconflict', $this->row($report, 'auth_lti_linked_login')['reason']);
        $this->assertSame(1, $this->row($report, 'groups_members')['collisions']);
        $this->assertSame(merge_preview::T_CONFLICT, $this->row($report, 'groups_members')['status']);
        $conflicts = array_column($report->conflicts, 'table');
        foreach (['auth_lti_linked_login', 'groups_members', 'user_enrolments', 'role_assignments'] as $table) {
            $this->assertContains($table, $conflicts);
        }
        $this->assertSame([], $report->blockers);
        // An identity of another platform is no conflict.
        $other = $this->getDataGenerator()->create_user();
        (new \auth_plugin_lti())->create_user_binding('https://third.example.org', 'sub-x', (int) $other->id);
        $this->assertSame(0, $this->row(
            preview_config::preview()->build((int) $other->id, (int) $b->id),
            'auth_lti_linked_login'
        )['collisions']);
    }

    /**
     * No blind user-id update: an undeclared plugin table with rows of the source blocks the merge; a declaration by its
     * owner (or an administrator) lifts the block. A unique key without a declared compound index blocks too, and so does a
     * site administrator as the account to be merged away.
     */
    public function test_blockers(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $course] = $this->scenario();
        $this->create_fake_table();
        $DB->insert_record(self::FAKE, (object) ['userid' => $a->id]);

        $report = preview_config::preview()->build((int) $a->id, (int) $b->id);
        $this->assertSame(merge_preview::BLOCKED, $report->verdict);
        $fake = $this->row($report, self::FAKE);
        $this->assertSame(merge_preview::T_BLOCKED, $fake['status']);
        $this->assertSame('undeclared', $fake['reason']);
        $this->assertSame('unknown', $fake['owner']);
        $this->assertSame([self::FAKE], array_column($report->blockers, 'table'));

        $declared = preview_config::preview([self::FAKE => ['admin']])->build((int) $a->id, (int) $b->id);
        $this->assertSame(merge_preview::READY, $declared->verdict);
        $this->assertSame(merge_preview::T_MOVE, $this->row($declared, self::FAKE)['status']);
        $this->assertSame(['admin'], $this->row($declared, self::FAKE)['declaredby']);

        // Unique key (userid, course) of course_completions, both accounts completed the same course, no compound index.
        $DB->insert_record('course_completions', (object) ['userid' => $b->id, 'course' => $course->id, 'timeenrolled' => 1,
            'reaggregate' => 0]);
        $unique = preview_config::preview([self::FAKE => ['admin']])->build((int) $a->id, (int) $b->id);
        $this->assertSame(merge_preview::BLOCKED, $unique->verdict);
        $this->assertSame('uniqueviolation', $this->row($unique, 'course_completions')['reason']);
        // Declared as compound index: a conflict the tool resolves, no longer a blocker.
        $compound = preview_config::preview([self::FAKE => ['admin']], ['course_completions' => ['userfield' => ['userid'],
            'otherfields' => ['course']]])->build((int) $a->id, (int) $b->id);
        $this->assertSame(merge_preview::CONFLICTS, $compound->verdict);

        $admin = get_admin();
        $asadmin = preview_config::preview()->build((int) $admin->id, (int) $b->id);
        $this->assertSame(merge_preview::BLOCKED, $asadmin->verdict);
        $this->assertContains('sourceadmin', array_column($asadmin->blockers, 'reason'));
    }

    /**
     * The fingerprint binds decisions, not counters: more rows in a moving table keep it, a newly affected table or a new
     * conflict change it.
     */
    public function test_fingerprint(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $course] = $this->scenario();
        $first = preview_config::preview()->build((int) $a->id, (int) $b->id);
        $again = preview_config::preview()->build((int) $a->id, (int) $b->id);
        $this->assertSame($first->hash, $again->hash);

        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id]);
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $a->id, 'finalgrade' => 1]);
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $b->id, 'finalgrade' => 2]);
        $this->assertSame($first->hash, preview_config::preview()->build((int) $a->id, (int) $b->id)->hash);

        $DB->insert_record('user_lastaccess', (object) ['userid' => $a->id, 'courseid' => $course->id, 'timeaccess' => 1]);
        $newtable = preview_config::preview()->build((int) $a->id, (int) $b->id);
        $this->assertNotSame($first->hash, $newtable->hash);

        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-b', (int) $b->id);
        $this->assertNotSame($newtable->hash, preview_config::preview()->build((int) $a->id, (int) $b->id)->hash);
        $this->assertNotSame($first->hash, preview_config::preview()->build((int) $b->id, (int) $a->id)->hash);
    }

    /**
     * The preview only reads; invalid pairs are refused; without the merge tool the verdict is "unavailable".
     */
    public function test_read_only_invalid_pairs_and_unavailable(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b] = $this->scenario();
        $writes = $DB->perf_get_writes();
        preview_config::preview()->build((int) $a->id, (int) $b->id);
        $this->assertSame($writes, $DB->perf_get_writes());

        foreach (
            [[(int) $a->id, (int) $a->id, 'err_samemerge'], [(int) $a->id, 999999, 'err_mergeuser'],
                [(int) guest_user()->id, (int) $b->id, 'err_mergeuser']] as [$s, $t, $code]
        ) {
            try {
                preview_config::preview()->build($s, $t);
                $this->fail('expected ' . $code);
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $this->expectException(\moodle_exception::class);
        preview_config::preview()->build((int) $deleted->id, (int) $b->id);
    }

    /**
     * Without tool_mergeusers the preview reports the reduced function; with it, the real configuration is read.
     */
    public function test_environment(): void {
        $this->resetAfterTest();
        [$a, $b] = $this->scenario();
        $unavailable = preview_config::preview([], [], 'notinstalled')->build((int) $a->id, (int) $b->id);
        $this->assertSame(merge_preview::UNAVAILABLE, $unavailable->verdict);
        $this->assertFalse($unavailable->available);
        $this->assertSame([], $unavailable->tables);

        $env = merge_preview::from_environment()->build((int) $a->id, (int) $b->id);
        $this->assertSame(merge_preview::tool_available(), $env->available);
        if (!merge_preview::tool_available()) {
            $this->assertSame(merge_preview::UNAVAILABLE, $env->verdict);
        } else {
            $this->assertSame(merge_preview::S_OWNER, $this->row($env, 'auth_lti_linked_login')['strategy']);
        }
    }

    /**
     * Category of a table name.
     *
     * @dataProvider category_provider
     * @param string $table Table.
     * @param string $expected Category.
     */
    public function test_category(string $table, string $expected): void {
        $this->assertSame($expected, merge_preview::category($table));
    }

    /**
     * Tables and their categories.
     *
     * @return array
     */
    public static function category_provider(): array {
        return [
            ['grade_grades', 'grades'], ['grade_grades_history', 'grades'], ['quiz_attempts', 'attempts'],
            ['question_attempt_steps', 'attempts'], ['assign_submission', 'attempts'], ['scorm_attempt', 'attempts'],
            ['course_modules_completion', 'completion'], ['course_completions', 'completion'],
            ['user_enrolments', 'enrolments'], ['role_assignments', 'enrolments'], ['groups_members', 'groups'],
            ['auth_lti_linked_login', 'ltibindings'], ['enrol_lti_users', 'ltibindings'],
            ['local_ltiaccountmerge_job', 'ltibindings'], ['ltisource_ltipseudonym_map', 'pseudonym'],
            ['local_lticompletionsync_out', 'completionsync'], ['local_lticompletionsync_rcv', 'completionsync'],
            ['logstore_standard_log', 'logs'], ['log', 'logs'], ['log_display', 'other'], ['forum_posts', 'other'],
        ];
    }
}
