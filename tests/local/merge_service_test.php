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

use local_ltiaccountmerge\observer;
use local_ltiaccountmerge\task\reconcile_merge;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');

/**
 * Tests of the merge executor (reconciliation after an external account merge), modelled on the FlexAccess merge service.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\merge_service
 * @covers     \local_ltiaccountmerge\observer
 * @covers     \local_ltiaccountmerge\task\reconcile_merge
 */
final class merge_service_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Bind an LTI identity to a user through the public core API.
     *
     * @param string $sub Subject.
     * @param int $userid User.
     * @param string|null $issuer Issuer.
     */
    protected function bind(string $sub, int $userid, ?string $issuer = null): void {
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        (new \auth_plugin_lti())->create_user_binding($issuer ?? self::$issuer, $sub, $userid);
    }

    /**
     * Owner of a binding (null if none).
     *
     * @param string $sub Subject.
     * @param string|null $issuer Issuer.
     * @return int|null
     */
    protected function owner(string $sub, ?string $issuer = null): ?int {
        global $DB;
        $id = $DB->get_field('auth_lti_linked_login', 'userid', [
            'issuer256' => hash('sha256', $issuer ?? self::$issuer),
            'sub256' => hash('sha256', $sub),
        ]);
        return $id === false ? null : (int) $id;
    }

    /**
     * Two users.
     *
     * @return int[] [source, target]
     */
    protected function pair(): array {
        $generator = $this->getDataGenerator();
        return [(int) $generator->create_user()->id, (int) $generator->create_user()->id];
    }

    /**
     * Insert a link request row.
     *
     * @param array $fields Overrides.
     * @return int Id.
     */
    protected function request(array $fields): int {
        global $DB;
        return (int) $DB->insert_record('local_ltiaccountmerge_request', (object) array_merge([
            'issuerhash' => hash('sha256', 'i'),
            'subhash' => hash('sha256', 's'),
            'issuer' => self::$issuer,
            'sub' => 'someone',
            'targetuserid' => 0,
            'requestedby' => 0,
            'codehash' => hash('sha256', 'c'),
            'status' => 'pending',
            'attempts' => 0,
            'timecreated' => time(),
            'timeexpires' => time() + 600,
            'timecompleted' => 0,
        ], $fields));
    }

    /**
     * The LTI identity of the merged-away account follows the survivor and the pair is journalled.
     */
    public function test_binding_follows_the_survivor(): void {
        global $DB;
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        $result = merge_service::reconcile($source, $target);
        $this->assertSame(merge_service::RECONCILED, $result->status);
        $this->assertSame(1, $result->bindingsmoved);
        $this->assertSame($target, $this->owner('platform-user-1'));
        $journal = $DB->get_record(
            'local_ltiaccountmerge_merge',
            ['sourceuserid' => $source, 'targetuserid' => $target],
            '*',
            MUST_EXIST
        );
        $this->assertSame('done', $journal->status);
        $this->assertSame(1, json_decode($journal->summary)->bindingsmoved);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile',
            'outcome' => audit::OK, 'relateduserid' => $target]));
    }

    /**
     * A second run changes nothing: no second audit row, the counters of the real run stay in the journal.
     */
    public function test_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        merge_service::reconcile($source, $target);
        $second = merge_service::reconcile($source, $target);
        $this->assertSame(merge_service::NOTHINGTODO, $second->status);
        $this->assertSame($target, $this->owner('platform-user-1'));
        $this->assertSame(1, $DB->count_records('auth_lti_linked_login', ['userid' => $target]));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_merge'));
        $journal = $DB->get_record('local_ltiaccountmerge_merge', []);
        $this->assertSame(2, (int) $journal->attempts);
        $this->assertSame(1, json_decode($journal->summary)->bindingsmoved);
    }

    /**
     * A merge tool that already moved the binding itself leaves nothing to do.
     */
    public function test_binding_already_moved_by_the_merge_tool(): void {
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $target);
        $result = merge_service::reconcile($source, $target);
        $this->assertSame(merge_service::NOTHINGTODO, $result->status);
        $this->assertSame($target, $this->owner('platform-user-1'));
    }

    /**
     * Another identity of the same platform is already bound to the survivor: never overwritten, left for an administrator.
     */
    public function test_conflict_is_reported_and_nothing_is_overwritten(): void {
        global $DB;
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        $this->bind('platform-user-2', $target);
        $this->bind('other-platform-user', $source, 'https://other.example.org');
        $result = merge_service::reconcile($source, $target);
        $this->assertSame(merge_service::PARTIAL, $result->status);
        $this->assertSame(1, $result->bindingconflicts);
        $this->assertSame($source, $this->owner('platform-user-1'));
        $this->assertSame($target, $this->owner('platform-user-2'));
        // The binding of the other platform has no conflict and moved.
        $this->assertSame($target, $this->owner('other-platform-user', 'https://other.example.org'));
        $this->assertSame('partial', $DB->get_field('local_ltiaccountmerge_merge', 'status', ['sourceuserid' => $source]));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile',
            'outcome' => audit::BLOCKED]));
    }

    /**
     * Setting off: bindings stay where they are and the skip is counted.
     */
    public function test_setting_off_leaves_bindings(): void {
        $this->resetAfterTest();
        set_config('mergemovebindings', 0, 'local_ltiaccountmerge');
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        $result = merge_service::reconcile($source, $target);
        $this->assertSame(1, $result->bindingsskipped);
        $this->assertSame(0, $result->bindingsmoved);
        $this->assertSame($source, $this->owner('platform-user-1'));
    }

    /**
     * Pending link requests: those aimed at the merged-away account are cancelled and lose the identity; its own go to
     * the survivor; finished requests are history and stay untouched.
     */
    public function test_requests(): void {
        global $DB;
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $aimed = $this->request(['targetuserid' => $source, 'requestedby' => $other]);
        $made = $this->request(['targetuserid' => $other, 'requestedby' => $source]);
        $done = $this->request(['targetuserid' => $source, 'requestedby' => $source, 'status' => 'completed']);
        $result = merge_service::reconcile($source, $target);
        $this->assertSame(1, $result->requestscancelled);
        $this->assertSame(1, $result->requestshanded);
        $aimedrow = $DB->get_record('local_ltiaccountmerge_request', ['id' => $aimed]);
        $this->assertSame('cancelled', $aimedrow->status);
        $this->assertNull($aimedrow->issuer);
        $this->assertNull($aimedrow->sub);
        $this->assertEquals($target, $DB->get_field('local_ltiaccountmerge_request', 'requestedby', ['id' => $made]));
        $this->assertSame('pending', $DB->get_field('local_ltiaccountmerge_request', 'status', ['id' => $made]));
        $donerow = $DB->get_record('local_ltiaccountmerge_request', ['id' => $done]);
        $this->assertEquals($source, $donerow->targetuserid);
        $this->assertSame('completed', $donerow->status);
    }

    /**
     * The audit trail is never rewritten; policies and texts follow the survivor.
     */
    public function test_audit_is_kept_and_modified_by_follows(): void {
        global $DB;
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        audit::log('linkdirect', audit::OK, 'user', $source, $source, [], null, $source);
        $this->create_policy(['usermodified' => $source]);
        $DB->insert_record('local_ltiaccountmerge_text', (object) [
            'code' => 'busy', 'lang' => '*', 'title' => 't', 'body' => '<p>b</p>', 'bodyformat' => FORMAT_HTML,
            'usermodified' => $source, 'timemodified' => time(),
        ]);
        $result = merge_service::reconcile($source, $target);
        $this->assertSame(2, $result->modifiedrepointed);
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_policy', ['usermodified' => $source]));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_policy', ['usermodified' => $target]));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_text', ['usermodified' => $target]));
        $row = $DB->get_record('local_ltiaccountmerge_audit', ['action' => 'linkdirect'], '*', MUST_EXIST);
        $this->assertEquals($source, $row->actorid);
        $this->assertEquals($source, $row->relateduserid);
    }

    /**
     * Invalid pairs are refused without a side effect (not even a journal row).
     */
    public function test_invalid_pairs(): void {
        global $DB;
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $pairs = [[$source, $source], [0, $target], [$source, 0], [-1, $target], [$source, 99999999],
            [$source, (int) $deleted->id]];
        foreach ($pairs as $pair) {
            $this->assertSame(merge_service::INVALID, merge_service::reconcile(...$pair)->status);
        }
        $this->assertSame($source, $this->owner('platform-user-1'));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_merge'));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));
    }

    /**
     * A busy pair lock: nothing is changed and the caller is told to retry.
     */
    public function test_busy_lock(): void {
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        $result = merge_service::reconcile($source, $target, null, $this->busy_lock_factory());
        $this->assertSame(merge_service::LOCKED, $result->status);
        $this->assertSame($source, $this->owner('platform-user-1'));
    }

    /**
     * A failure half way rolls EVERYTHING back (the binding that was already moved goes back) and is journalled as failed.
     */
    public function test_failure_rolls_back_everything(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        $this->request(['targetuserid' => $source]);
        $manager = $DB->get_manager();
        $manager->rename_table(new \xmldb_table('local_ltiaccountmerge_request'), 'local_ltiaccountmerge_request_x');
        try {
            merge_service::reconcile($source, $target);
            $thrown = false;
        } catch (\Throwable $e) {
            $thrown = true;
        } finally {
            $manager->rename_table(new \xmldb_table('local_ltiaccountmerge_request_x'), 'local_ltiaccountmerge_request');
        }
        $this->assertTrue($thrown);
        $this->assertSame($source, $this->owner('platform-user-1'), 'the binding move must be rolled back with the rest');
        $journal = $DB->get_record('local_ltiaccountmerge_merge', ['sourceuserid' => $source], '*', MUST_EXIST);
        $this->assertSame('failed', $journal->status);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile',
            'outcome' => audit::FAILED]));
        // The retry succeeds.
        $this->assertSame(merge_service::RECONCILED, merge_service::reconcile($source, $target)->status);
        $this->assertSame($target, $this->owner('platform-user-1'));
        $this->assertSame('done', $DB->get_field('local_ltiaccountmerge_merge', 'status', ['sourceuserid' => $source]));
    }

    /**
     * Consistency with the simulation: every user-id column of the plugin's own tables has an explicit decision in the
     * executor. A new column without a decision fails here.
     */
    public function test_every_user_column_is_accounted_for(): void {
        global $CFG;
        $xml = simplexml_load_file($CFG->dirroot . '/local/ltiaccountmerge/db/install.xml');
        $found = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $keys = [];
            foreach ($table->KEYS->KEY ?? [] as $key) {
                if ((string) $key['TYPE'] === 'foreign' && (string) $key['REFTABLE'] === 'user') {
                    $keys[] = (string) $key['FIELDS'];
                }
            }
            foreach ($table->FIELDS->FIELD as $field) {
                $name = (string) $field['NAME'];
                if (
                    in_array($name, $keys, true)
                    || preg_match('/(^|[a-z])userid$|^usermodified$|^requestedby$|^actorid$/', $name)
                ) {
                    $found[] = (string) $table['NAME'] . '.' . $name;
                }
            }
        }
        sort($found);
        $handled = array_keys(merge_service::HANDLED);
        sort($handled);
        $this->assertSame($handled, $found);
    }

    /**
     * The observer is registered for the tool_mergeusers event, queues one deduplicated task and the task reconciles.
     */
    public function test_observer_queues_once_and_the_task_reconciles(): void {
        $this->resetAfterTest();
        $observers = \core\event\manager::get_all_observers();
        $found = false;
        foreach ($observers['\tool_mergeusers\event\user_merged_success'] ?? [] as $observer) {
            if (((array) $observer)['callable'] === '\local_ltiaccountmerge\observer::user_merged') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'observer for the tool_mergeusers event is not registered');

        [$source, $target] = $this->pair();
        $this->bind('platform-user-1', $source);
        // The tool_mergeusers plugin is not installed in the test trees: a core event stands in, with the same payload.
        $event = \core\event\user_updated::create([
            'objectid' => $target,
            'relateduserid' => $target,
            'context' => \context_user::instance($target),
            'other' => ['usersinvolved' => ['toid' => $target, 'fromid' => $source]],
        ]);
        observer::user_merged($event);
        $this->assertSame($source, $this->owner('platform-user-1'), 'the observer must only queue');
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(reconcile_merge::class));
        observer::user_merged($event);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(reconcile_merge::class));
        $this->expectOutputRegex('/merge \d+ -> \d+: reconciled/');
        $this->runAdhocTasks(reconcile_merge::class);
        $this->assertSame($target, $this->owner('platform-user-1'));
    }

    /**
     * Payloads without a usable pair queue nothing.
     */
    public function test_observer_ignores_incomplete_payloads(): void {
        $this->resetAfterTest();
        [$source, $target] = $this->pair();
        $payloads = [[], ['usersinvolved' => ['toid' => $target]], ['usersinvolved' => ['toid' => $target, 'fromid' => $target]]];
        foreach ($payloads as $other) {
            observer::user_merged(\core\event\user_updated::create([
                'objectid' => $target,
                'relateduserid' => $target,
                'context' => \context_user::instance($target),
                'other' => $other,
            ]));
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(reconcile_merge::class));
        $this->assertNotSame($source, $target);
    }

    /**
     * Retry: a pair that is locked makes the task fail (so core reschedules it); after the attempt limit it stops asking.
     */
    public function test_task_fails_for_retry_while_locked(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        [$source, $target] = $this->pair();
        $ids = [$source, $target];
        sort($ids);
        $holder = new \core\lock\file_lock_factory('local_ltiaccountmerge_merge');
        $lock = $holder->get_lock('pair_' . implode('_', $ids), 1);
        $this->assertNotFalse($lock);
        $task = new reconcile_merge();
        $task->set_custom_data(['from' => $source, 'to' => $target]);
        $this->expectOutputRegex('/locked/');
        merge_service::$locktimeout = 1;
        try {
            $task->execute();
            $this->fail('A locked pair must make the task fail so that it is retried.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_busy', $e->errorcode);
        } finally {
            merge_service::$locktimeout = merge_service::LOCK_TIMEOUT;
            $lock->release();
        }
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));
        $this->assertSame(1, merge_service::attempts($source, $target));
        $this->assertSame('queued', $DB->get_field('local_ltiaccountmerge_merge', 'status', ['sourceuserid' => $source]));
    }
}
