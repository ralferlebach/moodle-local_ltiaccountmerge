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

use local_ltiaccountmerge\local\merge\executor;
use local_ltiaccountmerge\tests\fixtures\fake_runner;
use local_ltiaccountmerge\tests\fixtures\preview_config;
use local_ltiaccountmerge\tests\fixtures\selective_lock_factory;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');
require_once(__DIR__ . '/../fixtures/after_hook_standin.php');
require_once(__DIR__ . '/../fixtures/settings_hook_standin.php');
require_once(__DIR__ . '/../fixtures/preview_config.php');
require_once(__DIR__ . '/../fixtures/fake_runner.php');
require_once(__DIR__ . '/../fixtures/selective_lock_factory.php');

/**
 * Reviewed merge workflow (audit item AR-03): consent, execution, rollback, stale preview, concurrency, crash recovery.
 *
 * tool_mergeusers is not installed on the shared test sites: {@see fake_runner} stands for it (one transaction, generic
 * user-id update of three tables, this plugin's after_merged_all_tables callback inside the transaction, commit only
 * without errors). The real tool runs in tests/integration on scratch databases.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\merge\executor
 * @covers     \local_ltiaccountmerge\mergeusers_callbacks
 * @covers     \local_ltiaccountmerge\task\execute_merge
 * @covers     \local_ltiaccountmerge\task\recover_merges
 */
final class merge_executor_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /** @var string Fake table of an unknown plugin. */
    protected const FAKE = 'zzfake_progress';

    /** @var fake_runner Merge tool stand-in. */
    protected $runner;

    /** @var selective_lock_factory Locks. */
    protected $locks;

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
     * Executor with the stand-ins.
     *
     * @param string $mode Runner mode.
     * @return executor
     */
    protected function executor(string $mode = 'ok'): executor {
        $this->runner = $this->runner ?? new fake_runner();
        $this->runner->mode = $mode;
        $this->locks = $this->locks ?? new selective_lock_factory();
        return new executor($this->runner, fn() => preview_config::preview(), $this->locks);
    }

    /**
     * A (source) with enrolment, group membership, grade and an LTI identity; B (target) elsewhere enrolled; C bystander.
     *
     * @return array [a, b, c, course]
     */
    protected function scenario(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        // The merge transaction must really roll back: no outer test transaction around it.
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator();
        $a = $generator->create_user(['username' => 'merge_a']);
        $b = $generator->create_user(['username' => 'merge_b']);
        $c = $generator->create_user(['username' => 'merge_c']);
        $course = $generator->create_course();
        $generator->enrol_user($a->id, $course->id, 'student');
        $generator->enrol_user($c->id, $course->id, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $a->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $c->id]);
        $item = $generator->create_grade_item(['courseid' => $course->id]);
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $a->id, 'finalgrade' => 80]);
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $c->id, 'finalgrade' => 40]);
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-a', (int) $a->id);
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-c', (int) $c->id);
        return [$a, $b, $c, $course];
    }

    /**
     * Owner of an identity.
     *
     * @param string $sub Subject.
     * @return int
     */
    protected function owner(string $sub): int {
        global $DB;
        return (int) $DB->get_field('auth_lti_linked_login', 'userid', ['sub256' => hash('sha256', $sub)]);
    }

    /**
     * Consent with the current preview.
     *
     * @param executor $executor Executor.
     * @param \stdClass $a Source.
     * @param \stdClass $b Target.
     * @param bool $accept Accept conflicts.
     * @return \stdClass Job.
     */
    protected function consent(executor $executor, \stdClass $a, \stdClass $b, bool $accept = false): \stdClass {
        $hash = $executor->preview((int) $a->id, (int) $b->id)->hash;
        return $executor->consent((int) $a->id, (int) $b->id, $hash, $a->username, $accept, (int) get_admin()->id);
    }

    /**
     * Snapshot of everything the merge may touch.
     *
     * @return array
     */
    protected function snapshot(): array {
        global $DB;
        $out = [];
        foreach (['user_enrolments', 'groups_members', 'grade_grades', 'auth_lti_linked_login'] as $table) {
            $out[$table] = array_values(array_map(fn($r) => (array) $r, $DB->get_records($table, null, 'id', 'id, userid')));
        }
        return $out;
    }

    /**
     * Consent and execution: the data of A moves to B, A's identity follows B, C is untouched; the job is journalled.
     */
    public function test_consent_and_execute(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $executor = $this->executor();
        $job = $this->consent($executor, $a, $b);
        $this->assertSame(executor::CONSENTED, $job->status);
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\local_ltiaccountmerge\\task\\execute_merge']));
        $this->assertStringNotContainsString('merge_a', $job->preview);
        $cbefore = [$DB->count_records('user_enrolments', ['userid' => $c->id]), $this->owner('sub-c')];

        $this->assertSame(executor::SUCCEEDED, $executor->execute((int) $job->id));

        $row = $DB->get_record('local_ltiaccountmerge_job', ['id' => $job->id]);
        $this->assertSame(executor::SUCCEEDED, $row->status);
        $this->assertSame('merged', $row->outcome);
        $this->assertEquals(1, $row->inmerge);
        $this->assertEquals(100, $row->mergelogid);
        $this->assertEquals(1, $row->attempts);
        $this->assertSame(0, $DB->count_records('user_enrolments', ['userid' => $a->id]));
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $b->id]));
        $this->assertSame(0, $DB->count_records('grade_grades', ['userid' => $a->id]));
        $this->assertSame((int) $b->id, $this->owner('sub-a'));
        // No identity mix-up: the bystander keeps exactly what it had.
        $this->assertSame($cbefore, [$DB->count_records('user_enrolments', ['userid' => $c->id]), $this->owner('sub-c')]);
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'mergeexecute', 'outcome' => audit::OK]));
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'mergeconsent', 'outcome' => audit::OK]));

        // Idempotent: a second task for the same job does nothing.
        $this->assertSame(executor::SUCCEEDED, $executor->execute((int) $job->id));
        $this->assertCount(1, $this->runner->calls);
    }

    /**
     * An error of the merge tool rolls everything back, including the in-transaction mark; the job says so with a support
     * ID; an exception escaping the tool leaves no open transaction.
     */
    public function test_failed_merge_rolls_back(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $executor = $this->executor('error');
        $before = $this->snapshot();
        $job = $this->consent($executor, $a, $b);
        $this->assertSame(executor::FAILED, $executor->execute((int) $job->id));
        $row = $DB->get_record('local_ltiaccountmerge_job', ['id' => $job->id]);
        $this->assertSame('rolledback', $row->outcome);
        $this->assertEquals(0, $row->inmerge);
        $this->assertTrue(audit::is_support_id((string) $row->supportid));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('error', $this->runner->log_status(100));

        $executor = $this->executor('throw');
        $job2 = $this->consent($executor, $c, $b);
        $this->assertSame(executor::FAILED, $executor->execute((int) $job2->id));
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Consent is refused (and nothing but an audit row is written) for a blocked preview, a stale fingerprint, a wrong
     * confirmation, unaccepted conflicts, an account already in an open job, a missing tool and a busy consent lock.
     */
    public function test_consent_refusals(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $executor = $this->executor();
        $hash = $executor->preview((int) $a->id, (int) $b->id)->hash;
        $attempt = function (callable $fn, string $code) use ($DB) {
            $jobs = $DB->count_records('local_ltiaccountmerge_job');
            try {
                $fn();
                $this->fail('expected ' . $code);
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
            $this->assertSame($jobs, $DB->count_records('local_ltiaccountmerge_job'), $code);
        };
        $admin = (int) get_admin()->id;
        $attempt(
            fn() => $executor->consent((int) $a->id, (int) $b->id, str_repeat('0', 64), 'merge_a', false, $admin),
            'err_merge_stale'
        );
        $attempt(fn() => $executor->consent((int) $a->id, (int) $b->id, $hash, 'merge_b', false, $admin), 'err_merge_confirmation');

        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-b', (int) $b->id);
        $conflicthash = $executor->preview((int) $a->id, (int) $b->id)->hash;
        $attempt(
            fn() => $executor->consent((int) $a->id, (int) $b->id, $conflicthash, 'merge_a', false, $admin),
            'err_merge_conflicts'
        );

        $table = new \xmldb_table(self::FAKE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);
        $DB->insert_record(self::FAKE, (object) ['userid' => $c->id]);
        $blockedhash = $executor->preview((int) $c->id, (int) $b->id)->hash;
        $attempt(
            fn() => $executor->consent((int) $c->id, (int) $b->id, $blockedhash, 'merge_c', true, $admin),
            'err_merge_blocked'
        );
        $DB->delete_records(self::FAKE);

        $job = $executor->consent((int) $a->id, (int) $b->id, $conflicthash, 'MERGE_A ', true, $admin);
        $this->assertSame(executor::CONSENTED, $job->status);
        // B is part of an open job: no second job may involve it, in either role.
        $attempt(fn() => $this->consent($executor, $c, $b, true), 'err_merge_open');
        $attempt(fn() => $this->consent($executor, $b, $c, true), 'err_merge_open');

        $unavailable = new executor($this->runner, fn() => preview_config::preview([], [], 'notinstalled'), $this->locks);
        $d = $this->getDataGenerator()->create_user();
        $attempt(
            fn() => $unavailable->consent((int) $d->id, (int) $c->id, 'x', $d->username, true, $admin),
            'err_merge_unavailable'
        );

        $this->locks->busy = [executor::CONSENT_LOCK];
        $attempt(fn() => $this->consent($executor, $d, $c, true), 'err_busy');
        $this->assertGreaterThanOrEqual(5, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergeconsent',
            'outcome' => audit::REFUSED]));
    }

    /**
     * Something the administrator consented to changes before the job runs: the job ends "stale" and the tool is never
     * called. An expired consent ends "expired".
     */
    public function test_stale_and_expired(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $executor = $this->executor();
        $job = $this->consent($executor, $a, $b);
        $before = $this->snapshot();
        // After the consent, A gets a second identity of the platform B already uses: new conflict, new fingerprint.
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'sub-b', (int) $b->id);
        $this->assertSame(executor::STALE, $executor->execute((int) $job->id));
        $this->assertSame('previewchanged', $DB->get_field('local_ltiaccountmerge_job', 'outcome', ['id' => $job->id]));
        $this->assertSame([], $this->runner->calls);
        $before['auth_lti_linked_login'] = $this->snapshot()['auth_lti_linked_login'];
        $this->assertSame($before, $this->snapshot());

        $job2 = $this->consent($executor, $c, $b, true);
        $DB->set_field('local_ltiaccountmerge_job', 'timeconsented', time() - executor::CONSENT_TTL - 1, ['id' => $job2->id]);
        $this->assertSame(executor::EXPIRED, $executor->execute((int) $job2->id));
        $this->assertSame([], $this->runner->calls);

        // The recovery expires waiting consents as well.
        $d = $this->getDataGenerator()->create_user();
        $job3 = $this->consent($executor, $d, $c, true);
        $DB->set_field('local_ltiaccountmerge_job', 'timeconsented', time() - executor::CONSENT_TTL - 1, ['id' => $job3->id]);
        $this->assertSame(1, $executor->recover()['expired']);
        $this->assertSame(executor::EXPIRED, $DB->get_field('local_ltiaccountmerge_job', 'status', ['id' => $job3->id]));
    }

    /**
     * One merge at a time: while another process holds the execution lock (or the job's lock) nothing happens and the job
     * keeps waiting; the ad hoc task asks for a retry. Cancelling works only before the start.
     */
    public function test_concurrency_and_cancel(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $executor = $this->executor();
        $job = $this->consent($executor, $a, $b);
        $this->locks->busy = [executor::EXEC_LOCK];
        $this->assertSame(executor::BUSY, $executor->execute((int) $job->id));
        $this->locks->busy = ['job_' . $job->id];
        $this->assertSame(executor::BUSY, $executor->execute((int) $job->id));
        $this->assertFalse($executor->cancel((int) $job->id, (int) get_admin()->id));
        $this->assertSame(executor::CONSENTED, $DB->get_field('local_ltiaccountmerge_job', 'status', ['id' => $job->id]));
        $this->assertSame([], $this->runner->calls);
        $this->locks->busy = [];
        $this->assertTrue($executor->cancel((int) $job->id, (int) get_admin()->id));
        $this->assertSame(executor::CANCELLED, $executor->execute((int) $job->id));
        $this->assertSame([], $this->runner->calls);
        // A cancelled job is closed: the accounts are free for a new consent.
        $this->assertSame(executor::CONSENTED, $this->consent($executor, $a, $b)->status);
        $this->assertFalse($executor->cancel(999999, (int) get_admin()->id));
    }

    /**
     * Crash recovery: a running job without a living owner is resolved from the in-transaction mark. Committed (mark set):
     * succeeded, the tool's unfinished log corrected, the binding reconciliation queued. Rolled back (no mark): aborted
     * with a support ID. A job whose owner still holds its lock is left alone.
     */
    public function test_recovery(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $executor = $this->executor();
        $make = function (int $source, int $target, int $inmerge, int $logid) use ($DB) {
            return (int) $DB->insert_record('local_ltiaccountmerge_job', (object) ['sourceuserid' => $source,
                'targetuserid' => $target, 'status' => executor::RUNNING, 'previewhash' => str_repeat('a', 64),
                'consentby' => get_admin()->id, 'timeconsented' => time() - 60, 'inmerge' => $inmerge, 'mergelogid' => $logid,
                'attempts' => 1, 'timestarted' => time() - 50, 'timecreated' => time() - 60, 'timemodified' => time() - 50]);
        };
        $this->runner->logs = [7 => 'inprogress', 8 => 'inprogress', 9 => 'inprogress'];
        $committed = $make((int) $a->id, (int) $b->id, 1, 7);
        $rolledback = $make((int) $c->id, (int) $b->id, 0, 8);
        $d = $this->getDataGenerator()->create_user();
        $alive = $make((int) $d->id, (int) $b->id, 0, 9);
        $this->locks->busy = ['job_' . $alive];

        $counts = $executor->recover();
        $this->assertSame(['recoveredsucceeded' => 1, 'recoveredaborted' => 1, 'expired' => 0, 'busy' => 1], $counts);
        $row = $DB->get_record('local_ltiaccountmerge_job', ['id' => $committed]);
        $this->assertSame(
            [executor::SUCCEEDED, 'recoveredcommitted', '1'],
            [$row->status, $row->outcome, (string) $row->recovered]
        );
        $row = $DB->get_record('local_ltiaccountmerge_job', ['id' => $rolledback]);
        $this->assertSame([executor::ABORTED, 'recoveredrolledback'], [$row->status, $row->outcome]);
        $this->assertTrue(audit::is_support_id((string) $row->supportid));
        $this->assertSame(executor::RUNNING, $DB->get_field('local_ltiaccountmerge_job', 'status', ['id' => $alive]));
        $this->assertSame([[7, true], [8, false]], $this->runner->finished);
        $this->assertSame('inprogress', $this->runner->log_status(9));
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\local_ltiaccountmerge\\task\\reconcile_merge']));
        // Repeated recovery changes nothing more.
        $this->assertSame(['recoveredsucceeded' => 0, 'recoveredaborted' => 0, 'expired' => 0, 'busy' => 1], $executor->recover());
    }

    /**
     * The in-transaction callback enforces consent: a merge of LTI accounts started outside the workflow is rolled back;
     * other accounts merge as before; with the setting off the old behaviour returns.
     */
    public function test_merge_without_consent_is_refused_for_lti_accounts(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $c] = $this->scenario();
        $runner = new fake_runner();
        $before = $this->snapshot();
        [$ok, $messages] = $runner->merge((int) $b->id, (int) $a->id, 1);
        $this->assertFalse($ok);
        $this->assertStringContainsString('local_ltiaccountmerge', implode(' ', $messages));
        $this->assertSame($before, $this->snapshot());

        $generator = $this->getDataGenerator();
        $x = $generator->create_user();
        $y = $generator->create_user();
        $generator->enrol_user($x->id, $generator->create_course()->id);
        [$ok] = $runner->merge((int) $y->id, (int) $x->id, 2);
        $this->assertTrue($ok);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $y->id]));

        set_config('requireconsent', 0, 'local_ltiaccountmerge');
        [$ok] = $runner->merge((int) $b->id, (int) $a->id, 3);
        $this->assertTrue($ok);
        $this->assertSame((int) $b->id, $this->owner('sub-a'));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_job'));
    }

    /**
     * Ad hoc and scheduled task wrappers: the execution task is queued once per job and retried while busy; the recovery
     * task runs the recovery.
     */
    public function test_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        \local_ltiaccountmerge\task\execute_merge::queue(41);
        \local_ltiaccountmerge\task\execute_merge::queue(41);
        $this->assertSame(1, $DB->count_records('task_adhoc', ['classname' => '\\local_ltiaccountmerge\\task\\execute_merge']));
        $task = new \local_ltiaccountmerge\task\execute_merge();
        $task->set_custom_data(['jobid' => 999999]);
        ob_start();
        $task->execute();
        (new \local_ltiaccountmerge\task\recover_merges())->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('merge job 999999', $output);
        $this->assertStringContainsString('merge recovery', $output);
    }
}
