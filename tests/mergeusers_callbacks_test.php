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

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\merge_service;
use local_ltiaccountmerge\tests\fixtures\after_hook_standin;
use local_ltiaccountmerge\tests\fixtures\settings_hook_standin;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/helper.php');
require_once(__DIR__ . '/fixtures/settings_hook_standin.php');
require_once(__DIR__ . '/fixtures/after_hook_standin.php');

/**
 * The tool_mergeusers integration (finding F1 of the 0.2.0 release gates).
 *
 * tool_mergeusers is not installed on the development and test sites. When it is, the tests construct its real hook
 * classes and dispatch them through the core hook manager; when it is not, they construct stand-ins with the same public
 * contract (tests/fixtures) and call the callbacks directly. The merge transaction is emulated exactly as
 * tool_mergeusers' user_merger::merge_users() runs it: one delegated transaction, the table work, the hook, then commit
 * when no error message was added, otherwise rollback.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\mergeusers_callbacks
 * @covers     \local_ltiaccountmerge\local\merge_service
 */
final class mergeusers_callbacks_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /** @var string Second platform. */
    protected const OTHER_ISSUER = 'https://other.example.org';

    /**
     * Bind an LTI identity through the public core API.
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
     * @return int[] [source A, target B]
     */
    protected function pair(): array {
        $generator = $this->getDataGenerator();
        return [(int) $generator->create_user()->id, (int) $generator->create_user()->id];
    }

    /**
     * Run a merge the way tool_mergeusers does it, with our after_merged_all_tables callback.
     *
     * @param int $to Surviving user.
     * @param int $from User being merged away.
     * @param callable|null $tables Stands for the table mergers (runs first, inside the transaction).
     * @return array [bool committed, string[] logs, string[] errors]
     */
    protected function merge(int $to, int $from, ?callable $tables = null): array {
        global $DB;
        // These tests cover the reconciliation rules of a merge as such. The consent rule for LTI accounts
        // (setting requireconsent) is covered by merge_executor_test, so it is switched off here.
        set_config('requireconsent', 0, 'local_ltiaccountmerge');
        $logs = [];
        $errors = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($tables) {
                $tables();
            }
            if (class_exists(mergeusers_callbacks::AFTER_HOOK)) {
                $class = mergeusers_callbacks::AFTER_HOOK;
                \core\di::get(\core\hook\manager::class)->dispatch(new $class($to, $from, $logs, $errors));
            } else {
                mergeusers_callbacks::after_merged_all_tables(new after_hook_standin($to, $from, $logs, $errors));
            }
        } catch (\Throwable $e) {
            $errors[] = 'Exception thrown when merging: ' . $e->getMessage();
        }
        if (!$errors) {
            try {
                $transaction->allow_commit();
                return [true, $logs, $errors];
            } catch (\Throwable $e) {
                // A swallowed failure inside a nested transaction makes the commit impossible; never leave it open.
                $DB->force_transaction_rollback();
                return [false, $logs, ['Commit failed: ' . $e->getMessage()]];
            }
        }
        try {
            $transaction->rollback(new \Exception('Rolling back transaction.'));
        } catch (\Exception $e) {
            // Same as tool_mergeusers: the controlled exception is expected.
            unset($e);
        }
        return [false, $logs, $errors];
    }

    /**
     * Core registers both callbacks under the tool_mergeusers hook names and stays quiet while that plugin is absent:
     * no debugging when the registry is built and read, or on the admin page "Hooks overview".
     */
    public function test_registration_is_quiet_without_tool_mergeusers(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = \core\di::get(\core\hook\manager::class);
        $expected = [
            mergeusers_callbacks::SETTINGS_HOOK => mergeusers_callbacks::class . '::add_settings_before_merging',
            mergeusers_callbacks::AFTER_HOOK => mergeusers_callbacks::class . '::after_merged_all_tables',
        ];
        foreach ($expected as $hook => $callback) {
            $mine = [];
            foreach ($manager->get_callbacks_for_hook($hook) as $definition) {
                if ($definition['component'] === 'local_ltiaccountmerge') {
                    $mine[] = $definition;
                }
            }
            $this->assertCount(1, $mine, $hook);
            $this->assertSame($callback, $mine[0]['callback']);
            $this->assertFalse($mine[0]['disabled']);
        }
        // The administration page "Hooks overview" lists every registered hook name, existing or not.
        require_once($CFG->libdir . '/tablelib.php');
        $PAGE->set_url('/admin/hooks.php');
        $PAGE->set_context(\context_system::instance());
        ob_start();
        (new \core_admin\table\hook_list_table())->out();
        $html = ob_get_clean();
        $this->assertStringContainsString('add_settings_before_merging', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Pure-array contract: the exclusions are keyed by table name, so tool_mergeusers' layering
     * (array_replace_recursive, earlier layers win) ADDS them to its default list instead of overwriting entries.
     */
    public function test_settings_extend_the_default_exceptions(): void {
        $settings = mergeusers_callbacks::merge_settings();
        $expected = ['auth_lti_linked_login', 'local_ltiaccountmerge_audit', 'local_ltiaccountmerge_merge',
            'local_ltiaccountmerge_job'];
        $this->assertSame($expected, array_values($settings['exceptions']));
        foreach (array_keys($settings['exceptions']) as $key) {
            $this->assertIsString($key);
        }
        // The default list of tool_mergeusers 2026081302 and its merge order: admin settings, hooks, defaults.
        $defaults = ['exceptions' => ['user_preferences', 'user_private_key', 'user_info_data', 'my_pages']];
        $combined = [];
        foreach ([[], $settings, $defaults] as $layer) {
            $combined = array_replace_recursive($layer, $combined);
        }
        $skipped = array_flip($combined['exceptions']);
        foreach (['user_preferences', 'user_private_key', 'user_info_data', 'my_pages', 'auth_lti_linked_login'] as $table) {
            $this->assertArrayHasKey($table, $skipped, $table);
        }
        // Negative control of the reason: a plain list would have replaced "user_preferences".
        $plain = array_replace_recursive($defaults, array_replace_recursive(['exceptions' => ['auth_lti_linked_login']], []));
        $this->assertNotContains('user_preferences', $plain['exceptions']);
    }

    /**
     * The settings callback through the hook object (the real one when installed, else the stand-in).
     */
    public function test_settings_callback_adds_the_exclusions(): void {
        if (class_exists(mergeusers_callbacks::SETTINGS_HOOK)) {
            $class = mergeusers_callbacks::SETTINGS_HOOK;
            $hook = \core\di::get(\core\hook\manager::class)->dispatch(new $class());
            $exceptions = (array) $hook->get_settings()->exceptions;
        } else {
            $hook = new settings_hook_standin();
            $hook->add_raw_settings(['exceptions' => ['someothertable' => 'someothertable']]);
            mergeusers_callbacks::add_settings_before_merging($hook);
            $exceptions = $hook->get_settings()['exceptions'];
            $this->assertContains('someothertable', $exceptions, 'another plugin\'s exclusion is kept');
        }
        foreach (mergeusers_callbacks::EXCLUDED_TABLES as $table) {
            $this->assertContains($table, $exceptions);
        }
    }

    /**
     * Main scenario inside the merge: the binding follows the survivor in the same transaction, the journal and the audit
     * row commit with the merge, the log line says what happened; the fallback task then has nothing to do.
     */
    public function test_binding_moves_inside_the_merge(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$a, $b] = $this->pair();
        $this->bind('sub-a1', $a);
        [$committed, $logs, $errors] = $this->merge($b, $a);
        $this->assertTrue($committed);
        $this->assertSame([], $errors);
        $this->assertSame($b, $this->owner('sub-a1'));
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('1 moved', $logs[0]);
        $journal = $DB->get_record('local_ltiaccountmerge_merge', ['sourceuserid' => $a], '*', MUST_EXIST);
        $this->assertSame('done', $journal->status);
        $this->assertSame(1, json_decode($journal->summary, true)['bindingsmoved']);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));

        // The success event queues the task; it is the idempotent fallback and finds nothing left.
        $this->assertSame(merge_service::NOTHINGTODO, merge_service::reconcile($a, $b)->status);
        $journal = $DB->get_record('local_ltiaccountmerge_merge', ['sourceuserid' => $a], '*', MUST_EXIST);
        $this->assertSame('done', $journal->status);
        $this->assertSame(1, json_decode($journal->summary, true)['bindingsmoved'], 'counters of the real run are kept');
        $this->assertSame(2, (int) $journal->attempts);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));
    }

    /**
     * Gate 2, pair 2: A and B hold two DIFFERENT identities of the same platform. The rule governs the real merge: B keeps
     * only its own identity, A's stays on A for an administrator, the journal says partial with one conflict, the audit
     * records it, and the merge itself is NOT blocked (FlexAccess semantics: report, do not block). A binding of another
     * platform without conflict still moves. A repeated run (the task) adds no second audit entry.
     */
    public function test_same_platform_conflict_is_kept_and_reported(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$a, $b] = $this->pair();
        $this->bind('sub-a2', $a);
        $this->bind('sub-b2', $b);
        $this->bind('sub-a2-other', $a, self::OTHER_ISSUER);
        [$committed, $logs, $errors] = $this->merge($b, $a);
        $this->assertTrue($committed);
        $this->assertSame([], $errors);
        $this->assertSame($a, $this->owner('sub-a2'));
        $this->assertSame($b, $this->owner('sub-b2'));
        $this->assertSame($b, $this->owner('sub-a2-other', self::OTHER_ISSUER));
        $this->assertSame(1, $DB->count_records('auth_lti_linked_login', ['userid' => $b,
            'issuer256' => hash('sha256', self::$issuer)]), 'B holds at most one identity of the platform');
        $this->assertStringContainsString('1 conflict', $logs[0]);
        $journal = $DB->get_record('local_ltiaccountmerge_merge', ['sourceuserid' => $a], '*', MUST_EXIST);
        $this->assertSame('partial', $journal->status);
        $this->assertSame(1, json_decode($journal->summary, true)['bindingconflicts']);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile',
            'outcome' => audit::BLOCKED]));

        $again = merge_service::reconcile($a, $b);
        $this->assertSame(merge_service::PARTIAL, $again->status);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));
        $summary = json_decode($DB->get_field('local_ltiaccountmerge_merge', 'summary', ['sourceuserid' => $a]), true);
        $this->assertSame(1, $summary['bindingsmoved'], 'the moved binding of the real run stays counted');
    }

    /**
     * mergemovebindings = 0 now really governs a merge: the table is excluded from the generic merger and the callback
     * moves nothing.
     */
    public function test_setting_off_moves_nothing(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        set_config('mergemovebindings', 0, 'local_ltiaccountmerge');
        [$a, $b] = $this->pair();
        $this->bind('sub-a1', $a);
        [$committed, $logs] = $this->merge($b, $a);
        $this->assertTrue($committed);
        $this->assertSame($a, $this->owner('sub-a1'));
        $this->assertStringContainsString('1 not moved', $logs[0]);
        $this->assertContains('auth_lti_linked_login', mergeusers_callbacks::merge_settings()['exceptions']);
    }

    /**
     * Safety net: if the generic merger moved the bindings anyway (an administrator re-enabled the table through
     * tool_mergeusers' "excluded_exceptions"), the survivor's two identities of one platform are reported, not repaired.
     */
    public function test_identities_moved_by_the_generic_merger_are_reported(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$a, $b] = $this->pair();
        $this->bind('sub-a2', $a);
        $this->bind('sub-b2', $b);
        [$committed, $logs] = $this->merge($b, $a, function () use ($DB, $a, $b): void {
            $DB->set_field('auth_lti_linked_login', 'userid', $b, ['userid' => $a]);
        });
        $this->assertTrue($committed);
        $this->assertSame($b, $this->owner('sub-a2'));
        $this->assertStringContainsString('1 conflict', $logs[0]);
        $journal = $DB->get_record('local_ltiaccountmerge_merge', ['sourceuserid' => $a], '*', MUST_EXIST);
        $this->assertSame('partial', $journal->status);
        $this->assertSame(1, json_decode($journal->summary, true)['targetduplicates']);
    }

    /**
     * An unexpected failure of the step adds an error message: tool_mergeusers rolls back the WHOLE merge (the table work
     * before the hook too) and nothing of this plugin is left behind. The next merge attempt works.
     */
    public function test_failure_rolls_back_the_whole_merge(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$a, $b] = $this->pair();
        $this->bind('sub-a1', $a);
        $tables = function () use ($DB, $b): void {
            $DB->set_field('user', 'firstname', 'Merged', ['id' => $b]);
        };
        $dbman = $DB->get_manager();
        $dbman->rename_table(new \xmldb_table('local_ltiaccountmerge_request'), 'local_ltiaccountmerge_request_x');
        try {
            [$committed, , $errors] = $this->merge($b, $a, $tables);
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            $dbman->rename_table(new \xmldb_table('local_ltiaccountmerge_request_x'), 'local_ltiaccountmerge_request');
        }
        $this->assertFalse($committed);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('local_ltiaccountmerge', $errors[0]);
        $this->assertSame($a, $this->owner('sub-a1'));
        $this->assertNotSame('Merged', $DB->get_field('user', 'firstname', ['id' => $b]));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_merge', ['sourceuserid' => $a]));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'mergereconcile']));

        [$committed] = $this->merge($b, $a, $tables);
        $this->assertTrue($committed);
        $this->assertSame($b, $this->owner('sub-a1'));
    }

    /**
     * A pair that is busy (another process holds its lock) does not hold up the merge: nothing is moved, the attempt is
     * counted, and the adhoc task queued by the success event does the work.
     */
    public function test_busy_pair_is_left_to_the_task(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$a, $b] = $this->pair();
        $this->bind('sub-a1', $a);
        $factory = $this->createMock(\core\lock\lock_factory::class);
        $factory->method('get_lock')->willReturn(false);
        $transaction = $DB->start_delegated_transaction();
        $result = merge_service::reconcile_within_merge($a, $b, null, $factory);
        $transaction->allow_commit();
        $this->assertSame(merge_service::LOCKED, $result->status);
        $this->assertSame($a, $this->owner('sub-a1'));
        $this->assertSame(1, merge_service::attempts($a, $b));
        $this->assertSame(merge_service::RECONCILED, merge_service::reconcile($a, $b)->status);
        $this->assertSame($b, $this->owner('sub-a1'));
    }
}
