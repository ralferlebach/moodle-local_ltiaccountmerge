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

/**
 * Reconciles this plugin's own state after an EXTERNAL account merge (tool_mergeusers).
 *
 * This is deliberately NOT a general user merge: Moodle core has no merge API (gate AR-G4) and moving grades, attempts,
 * enrolments and the rest of core is the job of the merging tool. This class does what only this plugin can know:
 *
 *  - LTI identity bindings (auth_lti_linked_login) of the merged-away account follow the surviving account, so the
 *    platform user lands in the surviving account on the next launch. Core offers no API to move a binding, so this is
 *    a direct update of that one column; it can be switched off (setting mergemovebindings) and is skipped, with a
 *    reported conflict, when the surviving account already carries another identity of the same issuer.
 *  - Pending link requests that target the merged-away account are cancelled (their e-mailed code belonged to that
 *    account) and their LTI identity is cleared; pending requests it created are handed to the survivor.
 *  - "Modified by" references of policies and texts follow the survivor.
 *  - The audit trail is append-only and is NEVER rewritten: history stays attributed to the account that acted at
 *    the time; one new audit row records the reconciliation.
 *
 * Contract: idempotent (a second run changes nothing and reports 'nothingtodo'), serialised per pair with a lock,
 * all changes in one delegated transaction (all or nothing), never touches core tables other than the one column
 * above, and records every pair in the journal table so retries and reports are possible.
 *
 * Two entry points (finding F1 of the 0.2.0 release gates): with the real tool_mergeusers the decision runs INSIDE the
 * merge transaction ({@see reconcile_within_merge()}, called from the after_merged_all_tables hook), because the binding
 * table is taken out of tool_mergeusers' generic processing (add_settings_before_merging hook) and would otherwise not be
 * moved at all. The adhoc task queued by the success event runs {@see reconcile()} afterwards as the idempotent fallback.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_service {
    /** @var string Result: the pair was invalid, nothing happened. */
    public const INVALID = 'invalid';
    /** @var string Result: another process holds the pair lock; retry later. */
    public const LOCKED = 'locked';
    /** @var string Result: something was changed. */
    public const RECONCILED = 'reconciled';
    /** @var string Result: everything was already in order. */
    public const NOTHINGTODO = 'nothingtodo';
    /** @var string Result: done, but at least one conflict is left for an administrator. */
    public const PARTIAL = 'partial';

    /** @var int Seconds to wait for the pair lock. */
    public const LOCK_TIMEOUT = 10;

    /** @var int Seconds to wait for the pair lock; a test seam, leave at the default. */
    public static $locktimeout = self::LOCK_TIMEOUT;

    /** @var int Seconds to wait for the pair lock inside a running tool_mergeusers transaction (the task is the fallback). */
    public const INLINE_LOCK_TIMEOUT = 0;

    /** @var int Attempts after which the adhoc task stops asking for a retry. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Every user-id column of this plugin's own tables and what the executor does with it. The test
     * merge_service_test::test_every_user_column_is_accounted_for compares this map with db/install.xml, so a new column
     * without a decision fails the build.
     *
     * @var string[]
     */
    public const HANDLED = [
        'local_ltiaccountmerge_policy.usermodified' => 'repoint',
        'local_ltiaccountmerge_text.usermodified' => 'repoint',
        'local_ltiaccountmerge_request.targetuserid' => 'cancel-pending',
        'local_ltiaccountmerge_request.requestedby' => 'repoint-pending',
        'local_ltiaccountmerge_audit.actorid' => 'history-kept',
        'local_ltiaccountmerge_audit.relateduserid' => 'history-kept',
        'local_ltiaccountmerge_merge.sourceuserid' => 'journal',
        'local_ltiaccountmerge_merge.targetuserid' => 'journal',
        'local_ltiaccountmerge_job.sourceuserid' => 'journal',
        'local_ltiaccountmerge_job.targetuserid' => 'journal',
        'local_ltiaccountmerge_job.consentby' => 'history-kept',
    ];

    /**
     * Reconcile one merged pair.
     *
     * @param int $sourceuserid Account that was merged away.
     * @param int $targetuserid Surviving account.
     * @param int|null $now Current time.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory; the configured core factory when null (injectable for tests).
     * @return \stdClass ->status (invalid|locked|reconciled|nothingtodo|partial), ->bindingsmoved, ->bindingconflicts,
     *     ->bindingsskipped, ->requestscancelled, ->requestshanded, ->modifiedrepointed
     */
    public static function reconcile(
        int $sourceuserid,
        int $targetuserid,
        ?int $now = null,
        ?\core\lock\lock_factory $lockfactory = null
    ): \stdClass {
        return self::run($sourceuserid, $targetuserid, $now, $lockfactory, false);
    }

    /**
     * Reconcile one pair inside the transaction of a running tool_mergeusers merge.
     *
     * Same decisions as {@see reconcile()}. Differences: the pair lock is not waited for (a busy pair is left to the adhoc
     * task, the journal counts the attempt); a failure is NOT journalled here but thrown, because the caller (the
     * after_merged_all_tables callback) turns it into an error of the merge and tool_mergeusers then rolls back the whole
     * merge, including anything written here. The journal and audit rows of a successful run are part of the merge
     * transaction: they exist exactly when the merge was committed.
     *
     * @param int $sourceuserid Account being merged away.
     * @param int $targetuserid Surviving account.
     * @param int|null $now Current time.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory; the configured core factory when null (injectable for tests).
     * @return \stdClass Same shape as {@see reconcile()}.
     */
    public static function reconcile_within_merge(
        int $sourceuserid,
        int $targetuserid,
        ?int $now = null,
        ?\core\lock\lock_factory $lockfactory = null
    ): \stdClass {
        return self::run($sourceuserid, $targetuserid, $now, $lockfactory, true);
    }

    /**
     * Shared implementation of both entry points.
     *
     * @param int $sourceuserid Account that was (or is being) merged away.
     * @param int $targetuserid Surviving account.
     * @param int|null $now Current time.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory or null.
     * @param bool $withinmerge True inside a tool_mergeusers transaction.
     * @return \stdClass
     */
    private static function run(
        int $sourceuserid,
        int $targetuserid,
        ?int $now,
        ?\core\lock\lock_factory $lockfactory,
        bool $withinmerge
    ): \stdClass {
        global $DB;
        $now = $now ?? time();
        $result = (object) [
            'status' => self::INVALID,
            'bindingsmoved' => 0,
            'bindingconflicts' => 0,
            'bindingsskipped' => 0,
            'targetduplicates' => 0,
            'requestscancelled' => 0,
            'requestshanded' => 0,
            'modifiedrepointed' => 0,
        ];
        if ($sourceuserid <= 0 || $targetuserid <= 0 || $sourceuserid === $targetuserid) {
            return $result;
        }
        if (!$DB->record_exists('user', ['id' => $targetuserid, 'deleted' => 0])) {
            return $result;
        }

        // Serialise per pair (ordered ids) so the event task and any manual run cannot race.
        $ids = [$sourceuserid, $targetuserid];
        sort($ids);
        $factory = $lockfactory ?? \core\lock\lock_config::get_lock_factory('local_ltiaccountmerge_merge');
        $lock = $factory->get_lock('pair_' . implode('_', $ids), $withinmerge ? self::INLINE_LOCK_TIMEOUT : self::$locktimeout);
        if (!$lock) {
            $result->status = self::LOCKED;
            try {
                // Count the attempt so that the task stops asking for retries after MAX_ATTEMPTS.
                self::journal($sourceuserid, $targetuserid, 'queued', $result, $now, true);
            } catch (\dml_exception $e) {
                // Two waiters inserted the first journal row at the same moment: the attempt is counted by the other one.
                debugging('local_ltiaccountmerge: journal row exists already', DEBUG_DEVELOPER);
            }
            return $result;
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                self::move_bindings($sourceuserid, $targetuserid, $now, $result);
                self::settle_requests($sourceuserid, $targetuserid, $now, $result);
                $result->modifiedrepointed = self::repoint_modified($sourceuserid, $targetuserid);
                $result->targetduplicates = self::count_target_duplicates($targetuserid);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } catch (\Throwable $e) {
            if ($withinmerge) {
                // The surrounding merge transaction is doomed and rolls back; anything written now would vanish with it.
                // tool_mergeusers records the error in its own log and fires its failure event.
                throw $e;
            }
            // The transaction is rolled back; record the failure outside of it so an administrator can see it.
            self::journal($sourceuserid, $targetuserid, 'failed', $result, $now, true);
            audit::log('mergereconcile', audit::FAILED, 'merge', 0, $targetuserid, ['source' => $sourceuserid], null, 0);
            throw $e;
        } finally {
            $lock->release();
        }

        $changed = $result->bindingsmoved + $result->requestscancelled + $result->requestshanded + $result->modifiedrepointed > 0;
        if ($result->bindingconflicts > 0 || $result->targetduplicates > 0) {
            $result->status = self::PARTIAL;
        } else {
            $result->status = $changed ? self::RECONCILED : self::NOTHINGTODO;
        }
        $journalstatus = $result->status === self::PARTIAL ? 'partial' : 'done';
        $kept = self::journal($sourceuserid, $targetuserid, $journalstatus, $result, $now, false);
        // A repeated run that finds the same open conflict again records the attempt, not a second audit entry.
        if ($changed || ($result->status === self::PARTIAL && !$kept)) {
            $outcome = $result->status === self::PARTIAL ? audit::BLOCKED : audit::OK;
            audit::log('mergereconcile', $outcome, 'merge', 0, $targetuserid, [
                'source' => $sourceuserid,
                'bindingsmoved' => $result->bindingsmoved,
                'bindingconflicts' => $result->bindingconflicts,
                'targetduplicates' => $result->targetduplicates,
                'requestscancelled' => $result->requestscancelled,
                'withinmerge' => $withinmerge ? 1 : 0,
            ], null, 0);
        }
        return $result;
    }

    /**
     * Move the LTI identity bindings of the source to the target.
     *
     * @param int $source Source user id.
     * @param int $target Target user id.
     * @param int $now Time.
     * @param \stdClass $result Result counters, updated.
     */
    protected static function move_bindings(int $source, int $target, int $now, \stdClass $result): void {
        global $DB;
        $bindings = $DB->get_records('auth_lti_linked_login', ['userid' => $source], 'id');
        if (!$bindings) {
            return;
        }
        $setting = get_config('local_ltiaccountmerge', 'mergemovebindings');
        if ($setting !== false && !(int) $setting) {
            $result->bindingsskipped = count($bindings);
            return;
        }
        foreach ($bindings as $binding) {
            if (
                $DB->record_exists('auth_lti_linked_login', [
                    'userid' => $target, 'issuer256' => $binding->issuer256, 'sub256' => $binding->sub256,
                ])
            ) {
                // The survivor already carries exactly this identity: the source row is a duplicate.
                $DB->delete_records('auth_lti_linked_login', ['id' => $binding->id]);
                $result->bindingsmoved++;
                continue;
            }
            if ($DB->record_exists('auth_lti_linked_login', ['userid' => $target, 'issuer256' => $binding->issuer256])) {
                // Another identity of the same platform is bound to the survivor: never guess, leave it for an admin.
                $result->bindingconflicts++;
                continue;
            }
            $DB->update_record('auth_lti_linked_login', (object) [
                'id' => $binding->id,
                'userid' => $target,
                'timemodified' => $now,
            ]);
            $result->bindingsmoved++;
        }
    }

    /**
     * Number of platforms (issuers) of which the survivor holds more than one identity after the run.
     *
     * Normally 0, because the conflict rule above never creates such a state. It is not 0 when something else put the
     * bindings there, for example tool_mergeusers' generic merger when an administrator re-enabled it for
     * auth_lti_linked_login (its setting "excluded_exceptions"), or an older merge. The state is reported, never repaired
     * by guessing which identity is the right one.
     *
     * @param int $target Target user id.
     * @return int
     */
    protected static function count_target_duplicates(int $target): int {
        global $DB;
        $sql = 'SELECT issuer256, COUNT(1) AS n
                  FROM {auth_lti_linked_login}
                 WHERE userid = :userid
              GROUP BY issuer256
                HAVING COUNT(1) > 1';
        return count($DB->get_records_sql($sql, ['userid' => $target]));
    }

    /**
     * Cancel pending link requests aimed at the source; hand its own pending requests to the target.
     *
     * @param int $source Source user id.
     * @param int $target Target user id.
     * @param int $now Time.
     * @param \stdClass $result Result counters, updated.
     */
    protected static function settle_requests(int $source, int $target, int $now, \stdClass $result): void {
        global $DB;
        $aimed = ['targetuserid' => $source, 'status' => 'pending'];
        $cancel = $DB->get_records('local_ltiaccountmerge_request', $aimed, '', 'id');
        foreach ($cancel as $request) {
            $DB->update_record('local_ltiaccountmerge_request', (object) [
                'id' => $request->id,
                'status' => 'cancelled',
                'issuer' => null,
                'sub' => null,
                'timecompleted' => $now,
            ]);
            $result->requestscancelled++;
        }
        $hand = $DB->get_records('local_ltiaccountmerge_request', ['requestedby' => $source, 'status' => 'pending'], '', 'id');
        foreach ($hand as $request) {
            $DB->set_field('local_ltiaccountmerge_request', 'requestedby', $target, ['id' => $request->id]);
            $result->requestshanded++;
        }
    }

    /**
     * Point "modified by" references at the survivor.
     *
     * @param int $source Source user id.
     * @param int $target Target user id.
     * @return int Rows changed.
     */
    protected static function repoint_modified(int $source, int $target): int {
        global $DB;
        $count = 0;
        foreach (['local_ltiaccountmerge_policy', 'local_ltiaccountmerge_text'] as $table) {
            $n = $DB->count_records($table, ['usermodified' => $source]);
            if ($n) {
                $DB->set_field($table, 'usermodified', $target, ['usermodified' => $source]);
                $count += $n;
            }
        }
        return $count;
    }

    /**
     * Write or update the journal row of a pair.
     *
     * @param int $source Source user id.
     * @param int $target Target user id.
     * @param string $status Journal status.
     * @param \stdClass $result Counters.
     * @param int $now Time.
     * @param bool $failed Whether this records a failed attempt (attempt counter is raised).
     * @return bool True when an already finished row was found with nothing new: status and counters were kept.
     */
    protected static function journal(int $source, int $target, string $status, \stdClass $result, int $now, bool $failed): bool {
        global $DB;
        $summary = json_encode([
            'bindingsmoved' => $result->bindingsmoved,
            'bindingconflicts' => $result->bindingconflicts,
            'bindingsskipped' => $result->bindingsskipped,
            'targetduplicates' => $result->targetduplicates,
            'requestscancelled' => $result->requestscancelled,
            'requestshanded' => $result->requestshanded,
            'modifiedrepointed' => $result->modifiedrepointed,
        ]);
        $row = $DB->get_record('local_ltiaccountmerge_merge', ['sourceuserid' => $source, 'targetuserid' => $target]);
        if (!$row) {
            $DB->insert_record('local_ltiaccountmerge_merge', (object) [
                'sourceuserid' => $source,
                'targetuserid' => $target,
                'status' => $status,
                'attempts' => 1,
                'summary' => $failed ? null : $summary,
                'timecreated' => $now,
                'timemodified' => $now,
                'timecompleted' => $failed ? 0 : $now,
            ]);
            return false;
        }
        // A finished pair that is found again with nothing to do must not overwrite the counters of the real run.
        $changed = $result->bindingsmoved + $result->requestscancelled + $result->requestshanded + $result->modifiedrepointed;
        $finished = in_array($row->status, ['done', 'partial'], true);
        // Nothing new: no change and the same open conflicts as recorded (none, or the ones an administrator still has to
        // resolve). The counters of the run that did the work stay in the summary.
        $recorded = json_decode((string) $row->summary, true) ?: [];
        $sameconflicts = $result->bindingconflicts === (int) ($recorded['bindingconflicts'] ?? 0)
            && $result->targetduplicates === (int) ($recorded['targetduplicates'] ?? 0);
        $keep = !$failed && $changed === 0 && $sameconflicts && $finished;
        // A busy-lock attempt on an already finished pair only counts the attempt.
        $countonly = $failed && $status === 'queued' && $finished;
        $DB->update_record('local_ltiaccountmerge_merge', (object) [
            'id' => $row->id,
            'status' => $keep || $countonly ? $row->status : $status,
            'attempts' => (int) $row->attempts + 1,
            'summary' => $keep || $failed ? $row->summary : $summary,
            'timemodified' => $now,
            'timecompleted' => $keep || $countonly ? $row->timecompleted : ($failed ? 0 : $now),
        ]);
        return $keep;
    }

    /**
     * Number of attempts recorded for a pair (0 when unknown).
     *
     * @param int $source Source user id.
     * @param int $target Target user id.
     * @return int
     */
    public static function attempts(int $source, int $target): int {
        global $DB;
        return (int) $DB->get_field('local_ltiaccountmerge_merge', 'attempts', [
            'sourceuserid' => $source, 'targetuserid' => $target,
        ]);
    }
}
