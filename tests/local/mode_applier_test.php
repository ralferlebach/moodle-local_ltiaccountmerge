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
 * Integration tests: the applier writes through the real enrol_lti API and restores the baseline.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\mode_applier
 */
final class mode_applier_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Boundary second: before the window nothing, at the start second the policy mode, at the end second the baseline.
     */
    public function test_window_boundaries_apply_and_restore(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00', 'modelearner' => 1,
            'modeinstructor' => 3]);
        $applier = new mode_applier();

        $summary = $applier->apply_all(self::utc('2026-06-01 07:59:59'));
        $this->assertSame(0, $summary['changed']);
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_applied', []));

        $summary = $applier->apply_all(self::utc('2026-06-01 08:00:00'));
        $this->assertSame(1, $summary['changed']);
        $this->assertSame([1, 3], $this->stored_modes($tool->id));
        $record = $DB->get_record('local_ltiaccountmerge_applied', ['toolid' => $tool->id], '*', MUST_EXIST);
        $this->assertEquals(2, $record->baselinelearner);
        $this->assertEquals(2, $record->baselineinstructor);

        $summary = $applier->apply_all(self::utc('2026-06-01 08:59:59'));
        $this->assertSame(0, $summary['changed']);
        $this->assertSame([1, 3], $this->stored_modes($tool->id));

        $summary = $applier->apply_all(self::utc('2026-06-01 09:00:00'));
        $this->assertSame(1, $summary['restored']);
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_applied', []));
    }

    /**
     * Applying must never change anything of the resource except the two modes: in particular the uuid that the published
     * launch links depend on stays the same (enrol_lti would otherwise generate a new one).
     */
    public function test_only_the_two_modes_change_and_uuid_is_stable(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $enrolbefore = $DB->get_record('enrol', ['id' => $tool->enrolid]);
        $this->create_policy(['modelearner' => 1, 'modeinstructor' => 3]);
        (new mode_applier())->apply_all(time());
        $after = $DB->get_record('enrol_lti_tools', ['id' => $tool->id]);
        $this->assertSame($tool->uuid, $after->uuid);
        $before = (array) $tool;
        $afterarray = (array) $after;
        foreach (['provisioningmodelearner', 'provisioningmodeinstructor', 'timemodified'] as $changed) {
            unset($before[$changed], $afterarray[$changed]);
        }
        $this->assertEquals($before, $afterarray);
        $enrolafter = $DB->get_record('enrol', ['id' => $tool->enrolid]);
        $this->assertEquals($enrolbefore->status, $enrolafter->status);
        $this->assertEquals($enrolbefore->name, $enrolafter->name);
        $this->assertEquals($enrolbefore->roleid, $enrolafter->roleid);
        // The resource is still found by its original uuid, as enrol/lti/launch.php does.
        $this->assertCount(1, \enrol_lti\helper::get_lti_tools(['uuid' => $tool->uuid]));
    }

    /**
     * Repeated or "concurrent" applications at the same instant change nothing the second time.
     */
    public function test_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 3]);
        $now = self::utc('2026-06-01 08:00:00');
        $first = (new mode_applier())->apply_all($now);
        $second = (new mode_applier())->apply_all($now);
        $this->assertSame(1, $first['changed']);
        $this->assertSame(0, $second['changed']);
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'applymode']));
    }

    /**
     * An administrator who changes the mode by hand while a policy is applied wins: it becomes the new baseline.
     */
    public function test_manual_change_becomes_baseline(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $policy = $this->create_policy(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00',
            'modelearner' => 1]);
        $applier = new mode_applier();
        $applier->apply_all(self::utc('2026-06-01 08:10:00'));
        $this->assertSame([1, 2], $this->stored_modes($tool->id));
        // The administrator edits the resource: learners now "prompt existing only".
        $DB->set_field('enrol_lti_tools', 'provisioningmodelearner', 3, ['id' => $tool->id]);
        $applier->apply_all(self::utc('2026-06-01 08:20:00'));
        // The policy still governs, so it is applied again over the manual value ...
        $this->assertSame([1, 2], $this->stored_modes($tool->id));
        // ... but after the window the administrator's choice (3) comes back, not the old baseline (2).
        $applier->apply_all(self::utc('2026-06-01 09:00:00'));
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
        $this->assertNotEmpty($policy->id);
    }

    /**
     * Deleting the policy restores the baseline on the next run (nothing stays "stuck").
     */
    public function test_deleted_policy_restores_baseline(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 3);
        $policy = $this->create_policy(['modelearner' => 1, 'modeinstructor' => 1]);
        (new mode_applier())->apply_all(time());
        $this->assertSame([1, 1], $this->stored_modes($tool->id));
        $DB->delete_records('local_ltiaccountmerge_policy', ['id' => $policy->id]);
        $summary = (new mode_applier())->apply_all(time());
        $this->assertSame(1, $summary['restored']);
        $this->assertSame([2, 3], $this->stored_modes($tool->id));
    }

    /**
     * Scope: tool and course scoped policies touch only their targets; legacy tools are never touched.
     */
    public function test_scopes_and_legacy(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->create_tool(2, 2);
        $b = $this->create_tool(2, 2);
        $legacy = $this->getDataGenerator()->create_lti_tool((object) ['ltiversion' => 'LTI-1p0/LTI-2p0']);
        $legacymodes = [$legacy->provisioningmodelearner, $legacy->provisioningmodeinstructor];
        $this->create_policy(['scope' => 'tool', 'scopeid' => $a->id, 'modelearner' => 1]);
        (new mode_applier())->apply_all(time());
        $this->assertSame([1, 2], $this->stored_modes($a->id));
        $this->assertSame([2, 2], $this->stored_modes($b->id));

        $bcourse = (int) $DB->get_field('enrol', 'courseid', ['id' => $b->enrolid]);
        $this->create_policy(['scope' => 'course', 'scopeid' => $bcourse, 'modelearner' => 3, 'priority' => 5]);
        $summary = (new mode_applier())->apply_all(time());
        $this->assertSame(1, $summary['changed']);
        $this->assertSame([1, 2], $this->stored_modes($a->id));
        $this->assertSame([3, 2], $this->stored_modes($b->id));
        $legacyafter = $DB->get_record('enrol_lti_tools', ['id' => $legacy->id]);
        $this->assertEquals($legacymodes, [$legacyafter->provisioningmodelearner, $legacyafter->provisioningmodeinstructor]);
    }

    /**
     * A busy lock (another process applying) means: do nothing, report it, change nothing.
     */
    public function test_busy_lock_changes_nothing(): void {
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);
        $summary = (new mode_applier(null, $this->busy_lock_factory()))->apply_all(time());
        $this->assertTrue($summary['locked']);
        $this->assertSame(0, $summary['changed']);
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
    }

    /**
     * With no policies and no bookkeeping the applier does not even look at the resources.
     */
    public function test_no_policies_is_a_noop(): void {
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 3);
        $summary = (new mode_applier())->apply_all(time());
        $this->assertSame(['changed' => 0, 'restored' => 0, 'locked' => false, 'failed' => 0], $summary);
        $this->assertSame([2, 3], $this->stored_modes($tool->id));
    }

    /**
     * Applying fires the standard enrol event and writes an audit row without secrets.
     */
    public function test_event_and_audit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);
        $sink = $this->redirectEvents();
        (new mode_applier())->apply_all(time());
        $names = array_map(function ($e) {
            return get_class($e);
        }, $sink->get_events());
        $sink->close();
        $this->assertContains(\core\event\enrol_instance_updated::class, $names);
        $this->assertContains(\local_ltiaccountmerge\event\mode_applied::class, $names);
        $audit = $DB->get_record('local_ltiaccountmerge_audit', ['action' => 'applymode'], '*', MUST_EXIST);
        $this->assertEquals(0, $audit->actorid);
        $this->assertSame('success', $audit->outcome);
    }

    /**
     * DST: a daily window applied across the spring-forward night flips exactly at the local wall-clock boundaries.
     */
    public function test_daily_window_across_dst(): void {
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['timezone' => 'Europe/Berlin', 'recurrence' => 'daily', 'localstart' => '22:00:00',
            'localend' => '06:00:00', 'modelearner' => 3]);
        $applier = new mode_applier();
        $applier->apply_all(self::utc('2026-03-28 20:59:59'));
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
        $applier->apply_all(self::utc('2026-03-28 21:00:00'));
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
        $applier->apply_all(self::utc('2026-03-29 03:59:59'));
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
        $applier->apply_all(self::utc('2026-03-29 04:00:00'));
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
    }

    /**
     * Every other setting of the published resource and of its enrolment instance survives an application and the restore
     * (no lost manual configuration, no uuid rotation).
     */
    public function test_no_other_configuration_is_lost(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 3);
        $columns = $DB->get_columns('enrol_lti_tools');
        $values = ['maxenrolled' => 5, 'enrolstartdate' => 1000, 'enrolenddate' => 2000, 'institution' => 'Institution',
            'lang' => 'de', 'timezone' => 'Europe/Berlin', 'city' => 'Wuppertal', 'country' => 'DE', 'gradesync' => 1,
            'gradesynccompletion' => 1, 'membersync' => 1, 'membersyncmode' => 2, 'maildisplay' => 1,
            'name' => 'Published resource'];
        $set = ['id' => $tool->id];
        foreach ($values as $name => $value) {
            if (isset($columns[$name])) {
                $set[$name] = $value;
            }
        }
        $DB->update_record('enrol_lti_tools', (object) $set);
        $DB->set_field('enrol', 'name', 'My instance', ['id' => $tool->enrolid]);
        $DB->set_field('enrol', 'customint1', 11, ['id' => $tool->enrolid]);
        $toolbefore = (array) $DB->get_record('enrol_lti_tools', ['id' => $tool->id]);
        $enrolbefore = (array) $DB->get_record('enrol', ['id' => $tool->enrolid]);
        $ignore = ['provisioningmodelearner', 'provisioningmodeinstructor', 'timemodified'];

        $this->create_policy(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00', 'modelearner' => 1,
            'modeinstructor' => 1]);
        $applier = new mode_applier();
        foreach (['2026-06-01 08:30:00', '2026-06-01 09:30:00'] as $when) {
            $applier->apply_all(self::utc($when));
            $toolnow = (array) $DB->get_record('enrol_lti_tools', ['id' => $tool->id]);
            $enrolnow = (array) $DB->get_record('enrol', ['id' => $tool->enrolid]);
            $this->assertEquals(array_diff_key($toolbefore, array_flip($ignore)), array_diff_key($toolnow, array_flip($ignore)));
            $this->assertEquals(array_diff_key($enrolbefore, array_flip($ignore)), array_diff_key($enrolnow, array_flip($ignore)));
        }
        $this->assertSame([2, 3], $this->stored_modes($tool->id));
    }

    /**
     * A resource that fails does not stop the others, leaves no open transaction, no half-written bookkeeping and an
     * audit row; the next run retries it.
     */
    public function test_failure_of_one_resource_is_isolated(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $bad = $this->create_tool(2, 2);
        $good = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);
        $applier = new class extends mode_applier {
            /** @var int Resource that must fail. */
            public $failtool = 0;

            /**
             * Fail for one resource.
             *
             * @param \enrol_plugin $plugin Plugin.
             * @param \stdClass $tool Resource.
             * @param \stdClass|false $rec Bookkeeping.
             * @param bool $changed Changed.
             * @param array $target Target.
             * @param array $newowned Owned.
             * @param array $base Baseline.
             */
            protected function write(
                \enrol_plugin $plugin,
                \stdClass $tool,
                $rec,
                bool $changed,
                array $target,
                array $newowned,
                array $base
            ): void {
                if ((int) $tool->id === $this->failtool) {
                    $DB = $GLOBALS['DB'];
                    $DB->insert_record('local_ltiaccountmerge_applied', (object) ['toolid' => $tool->id, 'baselinelearner' => 2,
                        'baselineinstructor' => 2, 'appliedlearner' => 1, 'appliedinstructor' => null, 'timemodified' => time()]);
                    throw new \coding_exception('simulated failure');
                }
                parent::write($plugin, $tool, $rec, $changed, $target, $newowned, $base);
            }
        };
        $applier->failtool = (int) $bad->id;
        $summary = $applier->apply_all(time());
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['changed']);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame([2, 2], $this->stored_modes($bad->id));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_applied', ['toolid' => $bad->id]), 'rolled back');
        $this->assertSame([1, 2], $this->stored_modes($good->id));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'applymode', 'outcome' => 'failed',
            'targetid' => $bad->id]));
        // The next run (no fault) finishes the job.
        $applier->failtool = 0;
        $this->assertSame(1, $applier->apply_all(time())['changed']);
        $this->assertSame([1, 2], $this->stored_modes($bad->id));
    }

    /**
     * An administrator who saves the resource between the snapshot and the write wins: this round is skipped, nothing is
     * overwritten, and the next run applies the policy over the administrator's value as the new baseline.
     */
    public function test_concurrent_manual_save_is_not_overwritten(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $policy = $this->create_policy(['modelearner' => 1]);
        $racing = new class ([$policy]) extends resolver {
            /** @var int Resource whose mode the "administrator" changes. */
            public $toolid = 0;

            /**
             * The administrator saves the edit form right after the applier read its snapshot.
             *
             * @param int $toolid Resource.
             * @param int $courseid Course.
             * @param int $ts Instant.
             * @return array
             */
            public function effective(int $toolid, int $courseid, int $ts): array {
                $GLOBALS['DB']->set_field('enrol_lti_tools', 'provisioningmodelearner', 3, ['id' => $this->toolid]);
                return parent::effective($toolid, $courseid, $ts);
            }
        };
        $racing->toolid = (int) $tool->id;
        $summary = (new mode_applier($racing))->apply_all(time());
        $this->assertSame(0, $summary['changed']);
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_applied', []));
        // Next run: the policy governs, the administrator's 3 is the baseline to return to.
        (new mode_applier())->apply_all(time());
        $this->assertSame([1, 2], $this->stored_modes($tool->id));
        $this->assertEquals(3, $DB->get_field('local_ltiaccountmerge_applied', 'baselinelearner', ['toolid' => $tool->id]));
    }

    /**
     * A process that waited for the lock evaluates the instant AFTER it got the lock. A launch that waited across the start of
     * a window therefore sees the new mode; evaluating the instant before the wait (the old behaviour) would not.
     */
    public function test_waiting_process_uses_a_fresh_instant(): void {
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $start = time() + 1;
        $this->create_policy(['localstart' => gmdate('Y-m-d H:i:s', $start), 'localend' => gmdate('Y-m-d H:i:s', $start + 600),
            'modelearner' => 3]);
        $factory = new class ($this->busy_lock_factory()) implements \core\lock\lock_factory {
            /** @var \core\lock\lock_factory Never granting factory (only used for the interface contract). */
            private $inner;

            /**
             * Constructor.
             *
             * @param \core\lock\lock_factory $inner Unused double.
             */
            public function __construct($inner = null) {
                $this->inner = $inner;
            }

            /**
             * Available.
             *
             * @return bool
             */
            public function is_available() {
                return true;
            }

            /**
             * Timeouts supported.
             *
             * @return bool
             */
            public function supports_timeout() {
                return true;
            }

            /**
             * Auto release supported.
             *
             * @return bool
             */
            public function supports_auto_release() {
                return true;
            }

            /**
             * Another process holds the lock for two seconds, then it is granted.
             *
             * @param string $resource Resource.
             * @param int $timeout Timeout.
             * @param int $maxlifetime Lifetime.
             * @return \core\lock\lock
             */
            public function get_lock($resource, $timeout, $maxlifetime = 86400) {
                sleep(2);
                return new \core\lock\lock($resource, $this);
            }

            /**
             * Release.
             *
             * @param \core\lock\lock $lock Lock.
             * @return bool
             */
            public function release_lock(\core\lock\lock $lock) {
                return true;
            }
        };
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
        $summary = (new mode_applier(null, $factory))->apply_all(null, 'prelaunch');
        $this->assertSame(1, $summary['changed']);
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
    }

    /**
     * A real lock held by ANOTHER PHP process (flock on the lock file core uses) makes the applier give up without a change;
     * as soon as that process lets go, the same call succeeds. A launch path records the failure in the audit trail.
     */
    public function test_lock_held_by_another_process(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);

        $hash = md5('local_ltiaccountmerge_applymodes');
        $dir = $CFG->dataroot . '/lock/' . substr($hash, 0, 2);
        check_dir_exists($dir, true, true);
        $code = '$h = fopen($argv[1], "wb"); flock($h, LOCK_EX); echo "ready\n"; fflush(STDOUT); sleep(3);';
        $process = proc_open([PHP_BINARY, '-r', $code, $dir . '/' . $hash], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
            $summary = (new mode_applier(null, null, 1))->apply_all(null, 'prelaunch');
            $this->assertTrue($summary['locked']);
            $this->assertSame(0, $summary['changed']);
            $this->assertSame([2, 2], $this->stored_modes($tool->id));
            $failedaudit = ['action' => 'applymode', 'outcome' => 'failed'];
            $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', $failedaudit));
            // The scheduled task does not flood the audit trail while the lock is busy.
            (new mode_applier(null, null, 1))->apply_all(null, 'task');
            $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', $failedaudit));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
        $summary = (new mode_applier(null, null, 1))->apply_all(null, 'prelaunch');
        $this->assertFalse($summary['locked']);
        $this->assertSame([1, 2], $this->stored_modes($tool->id));
    }

    /**
     * Two adjacent windows (one ends exactly when the next starts) hand over without a gap and without touching the
     * baseline; overlapping windows of equal rank are decided by the newer policy; after the last window the original
     * configuration is back.
     */
    public function test_adjacent_and_overlapping_windows(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 3);
        $this->create_policy(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00', 'modelearner' => 1]);
        $this->create_policy(['localstart' => '2026-06-01 09:00:00', 'localend' => '2026-06-01 10:00:00', 'modelearner' => 3]);
        // Overlap with the second one, same rank: the newer policy wins for the overlapping half hour.
        $this->create_policy(['localstart' => '2026-06-01 09:30:00', 'localend' => '2026-06-01 11:00:00', 'modelearner' => 1]);
        $applier = new mode_applier();
        $expect = [
            '2026-06-01 07:59:59' => 2, '2026-06-01 08:00:00' => 1, '2026-06-01 08:59:59' => 1, '2026-06-01 09:00:00' => 3,
            '2026-06-01 09:29:59' => 3, '2026-06-01 09:30:00' => 1, '2026-06-01 10:00:00' => 1, '2026-06-01 10:59:59' => 1,
            '2026-06-01 11:00:00' => 2,
        ];
        foreach ($expect as $when => $learner) {
            $applier->apply_all(self::utc($when));
            $this->assertSame($learner, $this->stored_modes($tool->id)[0], $when);
            $this->assertSame(3, $this->stored_modes($tool->id)[1], 'the instructor mode is not governed and never changes');
        }
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_applied', []));
    }

    /**
     * Daylight saving: a one-off window of the 23-hour spring-forward day and one of the 25-hour fall-back day start and end
     * on the local wall clock.
     */
    public function test_windows_over_whole_dst_days(): void {
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['timezone' => 'Europe/Berlin', 'localstart' => '2026-03-29 00:00:00',
            'localend' => '2026-03-30 00:00:00', 'modelearner' => 3]);
        $this->create_policy(['timezone' => 'Europe/Berlin', 'localstart' => '2026-10-25 00:00:00',
            'localend' => '2026-10-26 00:00:00', 'modelearner' => 1]);
        $applier = new mode_applier();
        $steps = [
            ['2026-03-28 22:59:59', 2], ['2026-03-28 23:00:00', 3], ['2026-03-29 21:59:59', 3], ['2026-03-29 22:00:00', 2],
            ['2026-10-24 21:59:59', 2], ['2026-10-24 22:00:00', 1], ['2026-10-25 22:59:59', 1], ['2026-10-25 23:00:00', 2],
        ];
        foreach ($steps as [$when, $learner]) {
            $applier->apply_all(self::utc($when));
            $this->assertSame($learner, $this->stored_modes($tool->id)[0], $when);
        }
    }

    /**
     * Uninstall hands the configuration back: the baseline returns, a value an administrator set by hand stays, the uuid is
     * unchanged and the bookkeeping is gone.
     */
    public function test_uninstall_restores_baselines(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/ltiaccountmerge/db/uninstall.php');
        $a = $this->create_tool(2, 3);
        $b = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1, 'modeinstructor' => 1]);
        (new mode_applier())->apply_all(time());
        $this->assertSame([1, 1], $this->stored_modes($a->id));
        $DB->set_field('enrol_lti_tools', 'provisioningmodeinstructor', 2, ['id' => $b->id]);

        $this->assertTrue(xmldb_local_ltiaccountmerge_uninstall());
        $this->assertSame([2, 3], $this->stored_modes($a->id));
        $this->assertSame([2, 2], $this->stored_modes($b->id), 'a manual value is kept, the owned one is restored');
        $this->assertSame($a->uuid, $DB->get_field('enrol_lti_tools', 'uuid', ['id' => $a->id]));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_applied'));
    }
}
