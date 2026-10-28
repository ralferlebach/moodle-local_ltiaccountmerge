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

namespace local_ltiaccountmerge\local\merge;

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\merge_preview;

/**
 * Reviewed account-merge workflow (audit item AR-03): consent, execution through tool_mergeusers, crash recovery.
 *
 * This plugin never changes user ids itself. The merge is done by tool_mergeusers in ONE transaction (all or nothing); this
 * class adds what a merge of LTI accounts needs around it:
 *
 *  1. Consent ({@see consent()}): an administrator consents to exactly one preview (fingerprint), names the account to be
 *     merged away and, if the preview lists conflicts, accepts them. Blocked or unavailable previews cannot be consented.
 *     At most one open job (consented or running) per account. Result: a job row (status consented).
 *  2. Execution ({@see execute()}): one merge at a time on the site (lock), the job's own lock is held while it runs.
 *     The preview is built again; a different fingerprint ends the job as "stale" without any change. Then the job is
 *     marked running (committed BEFORE the merge transaction) and tool_mergeusers merges. Inside its transaction, the
 *     after_merged_all_tables callback sets job.inmerge = 1, so this flag exists in the database exactly when the merge
 *     was committed. Outcome: succeeded or failed (rolled back by tool_mergeusers).
 *  3. Recovery ({@see recover()}): a job that is still "running" while nobody holds its lock belongs to a process that
 *     died. inmerge = 1 means the merge was committed: status succeeded (recovered). inmerge = 0 means the database rolled
 *     the transaction back: status aborted, nothing changed, a new preview and consent are needed. The pending log entry
 *     of tool_mergeusers, which the tool itself never finishes after a crash, is corrected as well.
 *
 * Status of a job: consented -> running -> succeeded | failed | aborted; consented -> stale | expired | cancelled.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class executor {
    /** @var string Job status. */
    public const CONSENTED = 'consented';
    /** @var string Job status. */
    public const RUNNING = 'running';
    /** @var string Job status. */
    public const SUCCEEDED = 'succeeded';
    /** @var string Job status. */
    public const FAILED = 'failed';
    /** @var string Job status. */
    public const ABORTED = 'aborted';
    /** @var string Job status. */
    public const STALE = 'stale';
    /** @var string Job status. */
    public const EXPIRED = 'expired';
    /** @var string Job status. */
    public const CANCELLED = 'cancelled';

    /** @var string Result of execute(): another merge is running; try again later. */
    public const BUSY = 'busy';

    /** @var string[] Statuses of an open job. */
    public const OPEN = [self::CONSENTED, self::RUNNING];

    /** @var int Seconds a consent stays valid. */
    public const CONSENT_TTL = DAYSECS;

    /** @var string Lock resource serialising merges on the site. */
    public const EXEC_LOCK = 'exec';

    /** @var string Lock resource serialising consents. */
    public const CONSENT_LOCK = 'consent';

    /** @var int[] Jobs this process is running right now (a lock of the own process is re-entrant for some factories). */
    private static $runninghere = [];

    /** @var runner The merge tool. */
    private $runner;

    /** @var callable Returns the preview builder. */
    private $previewfactory;

    /** @var \core\lock\lock_factory Lock factory. */
    private $locks;

    /**
     * Constructor.
     *
     * @param runner|null $runner Merge tool; tool_mergeusers when null.
     * @param callable|null $previewfactory Returns a {@see merge_preview}; the installed tool's configuration when null.
     * @param \core\lock\lock_factory|null $locks Lock factory; the configured core factory when null.
     */
    public function __construct(?runner $runner = null, ?callable $previewfactory = null, ?\core\lock\lock_factory $locks = null) {
        $this->runner = $runner ?? new mergeusers_runner();
        $this->previewfactory = $previewfactory ?? [merge_preview::class, 'from_environment'];
        $this->locks = $locks ?? \core\lock\lock_config::get_lock_factory('local_ltiaccountmerge_job');
    }

    /**
     * Build the preview of a pair.
     *
     * @param int $sourceid Account to merge away.
     * @param int $targetid Account to keep.
     * @return \stdClass
     */
    public function preview(int $sourceid, int $targetid): \stdClass {
        return call_user_func($this->previewfactory)->build($sourceid, $targetid);
    }

    /**
     * Record the administrator's consent to one preview.
     *
     * @param int $sourceid Account to merge away.
     * @param int $targetid Account to keep.
     * @param string $hash Fingerprint of the preview the administrator saw.
     * @param string $confirmation Username of the account to merge away, typed by the administrator.
     * @param bool $acceptconflicts Whether the listed conflicts were accepted.
     * @param int $actorid Administrator.
     * @param bool $queue Queue the ad hoc task that executes the job (false: the caller runs {@see execute()} itself).
     * @return \stdClass The job row.
     * @throws \moodle_exception With an error-catalogue code when the consent is refused (nothing is written then).
     */
    public function consent(
        int $sourceid,
        int $targetid,
        string $hash,
        string $confirmation,
        bool $acceptconflicts,
        int $actorid,
        bool $queue = true
    ): \stdClass {
        global $DB;
        $lock = $this->locks->get_lock(self::CONSENT_LOCK, 10);
        if (!$lock) {
            throw new \moodle_exception('err_busy', 'local_ltiaccountmerge');
        }
        try {
            $report = $this->preview($sourceid, $targetid);
            $refuse = function (string $code) use ($sourceid, $targetid, $report) {
                audit::log('mergeconsent', audit::REFUSED, 'merge', $sourceid, $targetid, ['reason' => $code,
                    'verdict' => $report->verdict]);
                throw new \moodle_exception('err_merge_' . $code, 'local_ltiaccountmerge');
            };
            if (!$report->available) {
                $refuse('unavailable');
            }
            if ($report->verdict === merge_preview::BLOCKED) {
                $refuse('blocked');
            }
            if (!hash_equals($report->hash, $hash)) {
                $refuse('stale');
            }
            if (\core_text::strtolower(trim($confirmation)) !== \core_text::strtolower($report->source->username)) {
                $refuse('confirmation');
            }
            if ($report->verdict === merge_preview::CONFLICTS && !$acceptconflicts) {
                $refuse('conflicts');
            }
            [$insql, $params] = $DB->get_in_or_equal(self::OPEN, SQL_PARAMS_NAMED, 'st');
            $params += ['s1' => $sourceid, 's2' => $targetid, 't1' => $sourceid, 't2' => $targetid];
            if (
                $DB->record_exists_select('local_ltiaccountmerge_job', "status {$insql}
                    AND (sourceuserid IN (:s1, :s2) OR targetuserid IN (:t1, :t2))", $params)
            ) {
                $refuse('open');
            }
            $now = time();
            $job = (object) [
                'sourceuserid' => $sourceid,
                'targetuserid' => $targetid,
                'status' => self::CONSENTED,
                'previewhash' => $report->hash,
                'preview' => json_encode(merge_preview::summary($report)),
                'consentby' => $actorid,
                'timeconsented' => $now,
                'inmerge' => 0,
                'mergelogid' => 0,
                'attempts' => 0,
                'recovered' => 0,
                'outcome' => null,
                'supportid' => null,
                'timestarted' => 0,
                'timefinished' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $job->id = $DB->insert_record('local_ltiaccountmerge_job', $job);
            audit::log('mergeconsent', audit::OK, 'mergejob', (int) $job->id, $targetid, ['source' => $sourceid,
                'verdict' => $report->verdict, 'conflicts' => count($report->conflicts)], null, $actorid);
        } finally {
            $lock->release();
        }
        if ($queue) {
            \local_ltiaccountmerge\task\execute_merge::queue((int) $job->id);
        }
        return $job;
    }

    /**
     * Cancel a consented job that has not started.
     *
     * @param int $jobid Job.
     * @param int $actorid Administrator.
     * @return bool Whether the job was cancelled.
     */
    public function cancel(int $jobid, int $actorid): bool {
        global $DB;
        $lock = $this->locks->get_lock('job_' . $jobid, 0);
        if (!$lock) {
            return false;
        }
        try {
            $job = $DB->get_record('local_ltiaccountmerge_job', ['id' => $jobid]);
            if (!$job || $job->status !== self::CONSENTED) {
                return false;
            }
            $this->finish($job, self::CANCELLED, 'cancelled');
            audit::log('mergecancel', audit::OK, 'mergejob', $jobid, (int) $job->targetuserid, [], null, $actorid);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Execute a consented job.
     *
     * @param int $jobid Job.
     * @return string Final job status, {@see self::BUSY} when another merge runs, or the unchanged status of a job that was
     *     not in the consented state.
     */
    public function execute(int $jobid): string {
        global $DB;
        $exec = $this->locks->get_lock(self::EXEC_LOCK, 0);
        if (!$exec) {
            return self::BUSY;
        }
        $joblock = null;
        try {
            $joblock = $this->locks->get_lock('job_' . $jobid, 0);
            if (!$joblock) {
                return self::BUSY;
            }
            $job = $DB->get_record('local_ltiaccountmerge_job', ['id' => $jobid]);
            if (!$job) {
                return '';
            }
            if ($job->status !== self::CONSENTED) {
                // Idempotent: a second task for the same job, or a job that was cancelled meanwhile.
                return $job->status;
            }
            $now = time();
            if ((int) $job->timeconsented + self::CONSENT_TTL < $now) {
                $this->finish($job, self::EXPIRED, 'consentexpired');
                audit::log(
                    'mergeexecute',
                    audit::REFUSED,
                    'mergejob',
                    $jobid,
                    (int) $job->targetuserid,
                    ['reason' => 'expired'],
                    null,
                    0
                );
                return self::EXPIRED;
            }
            if (!$this->runner->is_available()) {
                $this->finish($job, self::STALE, 'unavailable');
                audit::log(
                    'mergeexecute',
                    audit::REFUSED,
                    'mergejob',
                    $jobid,
                    (int) $job->targetuserid,
                    ['reason' => 'unavailable'],
                    null,
                    0
                );
                return self::STALE;
            }
            try {
                $report = $this->preview((int) $job->sourceuserid, (int) $job->targetuserid);
                $fresh = $report->hash;
            } catch (\moodle_exception $e) {
                $fresh = 'invalid:' . $e->errorcode;
            }
            if (!hash_equals((string) $job->previewhash, $fresh)) {
                // Something that the administrator consented to has changed: refuse, change nothing.
                $this->finish($job, self::STALE, 'previewchanged');
                audit::log(
                    'mergeexecute',
                    audit::REFUSED,
                    'mergejob',
                    $jobid,
                    (int) $job->targetuserid,
                    ['reason' => 'previewchanged'],
                    null,
                    0
                );
                return self::STALE;
            }
            // Committed before the merge transaction: if the process dies from here on, recovery finds a running job.
            $job->status = self::RUNNING;
            $job->attempts = (int) $job->attempts + 1;
            $job->timestarted = $now;
            $job->timemodified = $now;
            $DB->update_record('local_ltiaccountmerge_job', $job);
            $job->mergelogid = $this->runner->prepare_log(
                (int) $job->targetuserid,
                (int) $job->sourceuserid,
                (int) $job->consentby
            );
            if ($job->mergelogid) {
                $DB->set_field('local_ltiaccountmerge_job', 'mergelogid', $job->mergelogid, ['id' => $jobid]);
            }
            self::$runninghere[$jobid] = $jobid;
            try {
                [$success, , $logid] = $this->runner->merge(
                    (int) $job->targetuserid,
                    (int) $job->sourceuserid,
                    (int) $job->mergelogid
                );
            } catch (\Throwable $e) {
                // An exception escaping the tool happened outside its transaction handling; the transaction is gone.
                if ($DB->is_transaction_started()) {
                    $DB->force_transaction_rollback();
                }
                $success = false;
                $logid = (int) $job->mergelogid;
            } finally {
                unset(self::$runninghere[$jobid]);
            }
            $job = $DB->get_record('local_ltiaccountmerge_job', ['id' => $jobid], '*', MUST_EXIST);
            $job->mergelogid = (int) $logid ?: (int) $job->mergelogid;
            if ($success) {
                $outcome = (int) $job->inmerge === 1 ? 'merged' : 'mergednohook';
                $this->finish($job, self::SUCCEEDED, $outcome);
                audit::log('mergeexecute', audit::OK, 'mergejob', $jobid, (int) $job->targetuserid, ['source' =>
                    (int) $job->sourceuserid, 'outcome' => $outcome, 'mergelogid' => (int) $job->mergelogid], null, 0);
                return self::SUCCEEDED;
            }
            $sid = audit::new_support_id();
            $job->supportid = $sid;
            $this->finish($job, self::FAILED, 'rolledback');
            audit::log('mergeexecute', audit::FAILED, 'mergejob', $jobid, (int) $job->targetuserid, ['source' =>
                (int) $job->sourceuserid, 'mergelogid' => (int) $job->mergelogid], $sid, 0);
            return self::FAILED;
        } finally {
            if ($joblock) {
                $joblock->release();
            }
            $exec->release();
        }
    }

    /**
     * Resolve jobs of processes that died during a merge, and expire old consents.
     *
     * @param int|null $now Current time.
     * @return array Counters: recoveredsucceeded, recoveredaborted, expired, busy.
     */
    public function recover(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['recoveredsucceeded' => 0, 'recoveredaborted' => 0, 'expired' => 0, 'busy' => 0];
        foreach ($DB->get_records('local_ltiaccountmerge_job', ['status' => self::RUNNING], 'id') as $job) {
            if (isset(self::$runninghere[(int) $job->id])) {
                $counts['busy']++;
                continue;
            }
            $lock = $this->locks->get_lock('job_' . $job->id, 0);
            if (!$lock) {
                // The owner is alive (its lock dies with its database session or process).
                $counts['busy']++;
                continue;
            }
            try {
                $job = $DB->get_record('local_ltiaccountmerge_job', ['id' => $job->id]);
                if (!$job || $job->status !== self::RUNNING) {
                    continue;
                }
                $committed = (int) $job->inmerge === 1;
                if ($committed) {
                    $job->recovered = 1;
                    $this->finish($job, self::SUCCEEDED, 'recoveredcommitted');
                    $counts['recoveredsucceeded']++;
                } else {
                    $job->recovered = 1;
                    $job->supportid = audit::new_support_id();
                    $this->finish($job, self::ABORTED, 'recoveredrolledback');
                    $counts['recoveredaborted']++;
                }
                $status = $this->runner->is_available() ? $this->runner->log_status((int) $job->mergelogid) : '';
                if (in_array($status, ['pending', 'inprogress'], true)) {
                    $this->runner->finish_log((int) $job->mergelogid, $committed, $committed
                        ? 'local_ltiaccountmerge: merge committed, process ended before the tool finished its log (recovered)'
                        : 'local_ltiaccountmerge: process ended during the merge; the database rolled it back (recovered)');
                }
                audit::log(
                    'mergerecover',
                    $committed ? audit::OK : audit::FAILED,
                    'mergejob',
                    (int) $job->id,
                    (int) $job->targetuserid,
                    ['source' => (int) $job->sourceuserid, 'committed' => $committed ? 1 : 0,
                    'mergelogid' => (int) $job->mergelogid],
                    $job->supportid,
                    0
                );
                if ($committed) {
                    // The tool's success event may not have fired: make sure the binding reconciliation runs (idempotent).
                    \local_ltiaccountmerge\task\reconcile_merge::queue((int) $job->sourceuserid, (int) $job->targetuserid);
                }
            } finally {
                $lock->release();
            }
        }
        $old = $DB->get_records_select(
            'local_ltiaccountmerge_job',
            'status = :st AND timeconsented < :limit',
            ['st' => self::CONSENTED, 'limit' => $now - self::CONSENT_TTL],
            'id'
        );
        foreach ($old as $job) {
            $lock = $this->locks->get_lock('job_' . $job->id, 0);
            if (!$lock) {
                continue;
            }
            try {
                $current = $DB->get_field('local_ltiaccountmerge_job', 'status', ['id' => $job->id]);
                if ($current === self::CONSENTED) {
                    $this->finish($job, self::EXPIRED, 'consentexpired');
                    $counts['expired']++;
                }
            } finally {
                $lock->release();
            }
        }
        return $counts;
    }

    /**
     * Write a final status.
     *
     * @param \stdClass $job Job row (changed fields are written).
     * @param string $status New status.
     * @param string $outcome Outcome code.
     * @return void
     */
    private function finish(\stdClass $job, string $status, string $outcome): void {
        global $DB;
        $now = time();
        $DB->update_record('local_ltiaccountmerge_job', (object) [
            'id' => $job->id,
            'status' => $status,
            'outcome' => $outcome,
            'mergelogid' => (int) ($job->mergelogid ?? 0),
            'recovered' => (int) ($job->recovered ?? 0),
            'supportid' => $job->supportid ?? null,
            'timefinished' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * The job that is running a merge of exactly this pair right now (used inside the merge transaction).
     *
     * @param int $fromid Account being merged away.
     * @param int $toid Account kept.
     * @return \stdClass|null
     */
    public static function running_job(int $fromid, int $toid): ?\stdClass {
        global $DB;
        $jobs = $DB->get_records('local_ltiaccountmerge_job', ['sourceuserid' => $fromid, 'targetuserid' => $toid,
            'status' => self::RUNNING], 'id DESC', '*', 0, 1);
        return $jobs ? reset($jobs) : null;
    }

    /**
     * Whether a pair needs the reviewed workflow: one of the accounts holds an LTI identity or was created by auth_lti.
     *
     * @param int $fromid Account being merged away.
     * @param int $toid Account kept.
     * @return bool
     */
    public static function involves_lti(int $fromid, int $toid): bool {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal([$fromid, $toid], SQL_PARAMS_NAMED);
        return $DB->record_exists_select('auth_lti_linked_login', "userid {$insql}", $params)
            || $DB->record_exists_select('user', "id {$insql} AND auth = 'lti'", $params);
    }
}
