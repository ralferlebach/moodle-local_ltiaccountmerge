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
 * Writes the effective provisioning mode into the published resources (enrol_lti_tools) through the official
 * enrol plugin API, remembering the baseline so that the administrator's own configuration is restored when
 * no policy is active any more.
 *
 * Core reads provisioningmodelearner / provisioningmodeinstructor in enrol/lti/launch.php without any hook
 * (gate AR-G2), so the only no-fork way to influence a launch is to keep those two columns current. The mode is
 * always computed live by {@see resolver}; this class is just the writer. It is called by the scheduled task and,
 * if enabled, by the after_config callback right before a launch script runs.
 *
 * Which core API: \enrol_lti\helper has no setter. enrol_lti_plugin::update_instance() is the method the core
 * edit form uses, so it is used here too (it also fires the standard enrol_instance_updated event).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mode_applier {
    /** @var int Seconds to wait for the lock. */
    public const LOCK_TIMEOUT = 5;

    /** @var resolver|null */
    protected $resolver;

    /** @var int Seconds to wait for the lock (the pre-launch path uses a shorter wait: a launch is waiting). */
    protected $locktimeout;

    /** @var \core\lock\lock_factory|null */
    protected $lockfactory;

    /**
     * Constructor.
     *
     * @param resolver|null $resolver Resolver to use; loaded from the database when null.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory; the configured core factory when null (injectable for tests).
     * @param int $locktimeout Seconds to wait for the lock.
     */
    public function __construct(
        ?resolver $resolver = null,
        ?\core\lock\lock_factory $lockfactory = null,
        int $locktimeout = self::LOCK_TIMEOUT
    ) {
        $this->resolver = $resolver;
        $this->lockfactory = $lockfactory;
        $this->locktimeout = $locktimeout;
    }

    /**
     * Write every baseline back and forget the bookkeeping (used on uninstall, also callable by an administrator tool).
     *
     * Resources whose stored mode was changed by hand since the plugin wrote it keep that choice. The uuid is preserved.
     *
     * @return int Number of resources restored.
     */
    public static function restore_all_baselines(): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_ltiaccountmerge_applied')) {
            return 0;
        }
        $plugin = enrol_get_plugin('lti');
        $count = 0;
        foreach ($DB->get_records('local_ltiaccountmerge_applied') as $rec) {
            $tool = $DB->get_record('enrol_lti_tools', ['id' => $rec->toolid]);
            if ($tool && $plugin) {
                $learner = $tool->provisioningmodelearner;
                if ($rec->appliedlearner !== null && (int) $learner === (int) $rec->appliedlearner) {
                    $learner = $rec->baselinelearner === null ? null : (int) $rec->baselinelearner;
                }
                $instructor = $tool->provisioningmodeinstructor;
                if ($rec->appliedinstructor !== null && (int) $instructor === (int) $rec->appliedinstructor) {
                    $instructor = $rec->baselineinstructor === null ? null : (int) $rec->baselineinstructor;
                }
                $instance = $DB->get_record('enrol', ['id' => $tool->enrolid]);
                if ($instance) {
                    $plugin->update_instance($instance, (object) [
                        'toolid' => $tool->id,
                        'ltiversion' => $tool->ltiversion,
                        'uuid' => $tool->uuid,
                        'provisioningmodelearner' => $learner,
                        'provisioningmodeinstructor' => $instructor,
                    ]);
                    $count++;
                }
            }
            $DB->delete_records('local_ltiaccountmerge_applied', ['id' => $rec->id]);
        }
        return $count;
    }

    /**
     * Apply the effective modes to every LTI 1.3 resource.
     *
     * @param int|null $now Instant to evaluate. When null the clock is read AFTER the lock was obtained: a process that
     *     waited for another one must not evaluate a stale instant (a launch that waited across a window boundary has to
     *     see the new mode).
     * @param string $source Origin of the call, recorded in the audit trail (task, prelaunch, manual).
     * @return array{changed: int, restored: int, locked: bool, failed: int} Summary.
     */
    public function apply_all(?int $now = null, string $source = 'task'): array {
        global $DB;
        $summary = ['changed' => 0, 'restored' => 0, 'locked' => false, 'failed' => 0];
        $resolver = $this->resolver ?? resolver::from_db();
        if ($resolver->count() === 0 && !$DB->record_exists('local_ltiaccountmerge_applied', [])) {
            return $summary;
        }
        $plugin = enrol_get_plugin('lti');
        if (!$plugin) {
            return $summary;
        }
        $factory = $this->lockfactory ?? \core\lock\lock_config::get_lock_factory('local_ltiaccountmerge');
        $lock = $factory->get_lock('applymodes', $this->locktimeout);
        if (!$lock) {
            $summary['locked'] = true;
            if ($source !== 'task') {
                // A launch continues with whatever mode is stored; say so in the audit trail instead of staying silent.
                audit::log('applymode', audit::FAILED, 'tool', 0, 0, ['source' => $source, 'reason' => 'lock'], null, 0);
            }
            return $summary;
        }
        try {
            $now = $now ?? time();
            // Reload the policies under the lock: an administrator may have saved one while this process was waiting.
            if ($this->resolver === null) {
                $resolver = resolver::from_db();
            }
            $tools = $DB->get_records_sql(
                "SELECT t.id, t.enrolid, t.ltiversion, t.uuid, t.provisioningmodelearner, t.provisioningmodeinstructor, e.courseid
                   FROM {enrol_lti_tools} t
                   JOIN {enrol} e ON e.id = t.enrolid
                  WHERE t.ltiversion = :version
               ORDER BY t.id",
                ['version' => 'LTI-1p3']
            );
            foreach ($tools as $tool) {
                try {
                    $result = $this->apply_tool($plugin, $tool, $resolver, $now, $source);
                } catch (\Throwable $e) {
                    // One broken resource must not stop the others, and must not leave a transaction open.
                    $summary['failed']++;
                    audit::log('applymode', audit::FAILED, 'tool', (int) $tool->id, 0, [
                        'source' => $source,
                        'error' => get_class($e),
                    ], null, 0);
                    continue;
                }
                if ($result === 'changed') {
                    $summary['changed']++;
                } else if ($result === 'restored') {
                    $summary['restored']++;
                }
            }
            // Forget bookkeeping of resources that no longer exist.
            $DB->delete_records_select('local_ltiaccountmerge_applied', 'toolid NOT IN (SELECT id FROM {enrol_lti_tools})');
        } finally {
            $lock->release();
        }
        return $summary;
    }

    /**
     * Apply the effective modes to one resource. The caller must hold the lock.
     *
     * @param \enrol_plugin $plugin The enrol_lti plugin instance.
     * @param \stdClass $tool Row with id, enrolid, ltiversion, uuid, provisioningmode*, courseid.
     * @param resolver $resolver The resolver.
     * @param int $now Instant.
     * @param string $source Origin.
     * @return string 'none', 'changed' (a policy mode was written) or 'restored' (baseline written back).
     */
    protected function apply_tool(\enrol_plugin $plugin, \stdClass $tool, resolver $resolver, int $now, string $source): string {
        global $DB;
        $roles = [
            resolver::LEARNER => 'provisioningmodelearner',
            resolver::INSTRUCTOR => 'provisioningmodeinstructor',
        ];
        $rec = $DB->get_record('local_ltiaccountmerge_applied', ['toolid' => $tool->id]);
        $effective = $resolver->effective((int) $tool->id, (int) $tool->courseid, $now);

        $current = [];
        $base = [];
        $owned = [];
        foreach ($roles as $role => $column) {
            $current[$role] = $tool->$column === null ? null : (int) $tool->$column;
            $base[$role] = $current[$role];
            $owned[$role] = null;
            if ($rec) {
                $b = $rec->{'baseline' . $role};
                $a = $rec->{'applied' . $role};
                $base[$role] = $b === null ? null : (int) $b;
                $owned[$role] = $a === null ? null : (int) $a;
                if ($owned[$role] !== null && $current[$role] !== $owned[$role]) {
                    // Somebody changed the setting by hand since we wrote it: that choice is the new baseline.
                    $base[$role] = $current[$role];
                    $owned[$role] = null;
                } else if ($owned[$role] === null) {
                    $base[$role] = $current[$role];
                }
            }
        }

        $target = [];
        $newowned = [];
        foreach ($roles as $role => $column) {
            $mode = $effective[$role]['mode'];
            $target[$role] = $mode ?? $base[$role];
            $newowned[$role] = $mode;
        }

        $changed = false;
        $restored = false;
        foreach ($roles as $role => $column) {
            if ($target[$role] !== $current[$role]) {
                $changed = true;
                if ($newowned[$role] === null) {
                    $restored = true;
                }
            }
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($changed) {
                // Optimistic check inside the transaction: if the administrator saved the resource since the snapshot
                // was read, this round is skipped (and re-evaluated by the next run) instead of overwriting their value.
                $fresh = $DB->get_record(
                    'enrol_lti_tools',
                    ['id' => $tool->id],
                    'id, provisioningmodelearner, provisioningmodeinstructor'
                );
                $freshlearner = $fresh && $fresh->provisioningmodelearner !== null ? (int) $fresh->provisioningmodelearner : null;
                $freshinstructor = $fresh && $fresh->provisioningmodeinstructor !== null
                    ? (int) $fresh->provisioningmodeinstructor : null;
                if (
                    !$fresh
                    || $freshlearner !== $current[resolver::LEARNER]
                    || $freshinstructor !== $current[resolver::INSTRUCTOR]
                ) {
                    $transaction->allow_commit();
                    return 'none';
                }
            }
            $this->write($plugin, $tool, $rec, $changed, $target, $newowned, $base);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return $this->after_write($tool, $effective, $current, $target, $newowned, $source, $changed, $restored);
    }

    /**
     * Write the modes through the enrol API and update the bookkeeping. Runs inside the caller's transaction.
     *
     * @param \enrol_plugin $plugin The enrol_lti plugin instance.
     * @param \stdClass $tool Resource row.
     * @param \stdClass|false $rec Bookkeeping row or false.
     * @param bool $changed Whether a mode must be written.
     * @param array $target Modes to store per role.
     * @param array $newowned Modes this plugin owns per role (null = not governed).
     * @param array $base Baselines per role.
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
        global $DB;
        if ($changed) {
            $instance = $DB->get_record('enrol', ['id' => $tool->enrolid], '*', MUST_EXIST);
            $plugin->update_instance($instance, (object) [
                'toolid' => $tool->id,
                'ltiversion' => $tool->ltiversion,
                // Must be passed: enrol_lti_plugin::update_instance() generates a NEW uuid for an LTI 1.3 tool when none is
                // supplied, which would silently break every published launch link.
                'uuid' => $tool->uuid,
                'provisioningmodelearner' => $target[resolver::LEARNER],
                'provisioningmodeinstructor' => $target[resolver::INSTRUCTOR],
            ]);
        }
        $anyowned = $newowned[resolver::LEARNER] !== null || $newowned[resolver::INSTRUCTOR] !== null;
        if ($anyowned) {
            $row = (object) [
                'toolid' => $tool->id,
                'baselinelearner' => $base[resolver::LEARNER],
                'baselineinstructor' => $base[resolver::INSTRUCTOR],
                'appliedlearner' => $newowned[resolver::LEARNER],
                'appliedinstructor' => $newowned[resolver::INSTRUCTOR],
                'timemodified' => time(),
            ];
            if ($rec) {
                $row->id = $rec->id;
                $DB->update_record('local_ltiaccountmerge_applied', $row);
            } else {
                $DB->insert_record('local_ltiaccountmerge_applied', $row);
            }
        } else if ($rec) {
            $DB->delete_records('local_ltiaccountmerge_applied', ['id' => $rec->id]);
        }
    }

    /**
     * Audit and event after a successful write.
     *
     * @param \stdClass $tool Resource row.
     * @param array $effective Effective modes with their policies.
     * @param array $current Modes stored before.
     * @param array $target Modes stored now.
     * @param array $newowned Modes this plugin owns per role.
     * @param string $source Origin.
     * @param bool $changed Whether a mode was written.
     * @param bool $restored Whether a baseline was written back.
     * @return string 'none', 'changed' or 'restored'.
     */
    protected function after_write(
        \stdClass $tool,
        array $effective,
        array $current,
        array $target,
        array $newowned,
        string $source,
        bool $changed,
        bool $restored
    ): string {
        $anyowned = $newowned[resolver::LEARNER] !== null || $newowned[resolver::INSTRUCTOR] !== null;
        if ($changed) {
            $detail = [
                'source' => $source,
                'learner' => [$current[resolver::LEARNER], $target[resolver::LEARNER]],
                'instructor' => [$current[resolver::INSTRUCTOR], $target[resolver::INSTRUCTOR]],
                'policy' => [
                    resolver::LEARNER => $effective[resolver::LEARNER]['policy']->id ?? null,
                    resolver::INSTRUCTOR => $effective[resolver::INSTRUCTOR]['policy']->id ?? null,
                ],
            ];
            audit::log('applymode', audit::OK, 'tool', (int) $tool->id, 0, $detail, null, 0);
            \local_ltiaccountmerge\event\mode_applied::create([
                'context' => \context_system::instance(),
                'objectid' => (int) $tool->id,
                'other' => $detail,
            ])->trigger();
        }
        return $changed ? ($restored && !$anyowned ? 'restored' : 'changed') : 'none';
    }
}
