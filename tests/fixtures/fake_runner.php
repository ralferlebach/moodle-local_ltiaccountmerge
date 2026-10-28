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

namespace local_ltiaccountmerge\tests\fixtures;

use local_ltiaccountmerge\mergeusers_callbacks;

/**
 * Stand-in for tool_mergeusers in the merge-workflow tests (the tool is not installed on the shared test sites).
 *
 * It reproduces what the executor relies on: a pending log entry, ONE delegated transaction that moves the user id of a
 * configurable list of tables (generic update), the after_merged_all_tables hook with this plugin's callback inside the
 * transaction, commit only without error messages, otherwise rollback. Modes: ok, error (an error message after the tables:
 * rollback), throw (an exception escapes the tool).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_runner implements \local_ltiaccountmerge\local\merge\runner {
    /** @var string[] Tables whose userid column the fake merge updates. */
    public $tables;
    /** @var string Mode: ok, error, throw. */
    public $mode;
    /** @var bool Whether the tool is "installed". */
    public $available = true;
    /** @var array Calls of merge(). */
    public $calls = [];
    /** @var array Log id => status. */
    public $logs = [];
    /** @var array finish_log() calls. */
    public $finished = [];
    /** @var int Next log id. */
    private $nextlog = 100;

    /**
     * Constructor.
     *
     * @param string[] $tables Tables to update.
     * @param string $mode Mode.
     */
    public function __construct(array $tables = ['user_enrolments', 'groups_members', 'grade_grades'], string $mode = 'ok') {
        $this->tables = $tables;
        $this->mode = $mode;
    }

    /**
     * Availability.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->available;
    }

    /**
     * Pending log entry.
     *
     * @param int $toid Kept.
     * @param int $fromid Merged away.
     * @param int $byuserid Consenting admin.
     * @return int
     */
    public function prepare_log(int $toid, int $fromid, int $byuserid): int {
        $id = $this->nextlog++;
        $this->logs[$id] = 'inprogress';
        return $id;
    }

    /**
     * The fake merge.
     *
     * @param int $toid Kept.
     * @param int $fromid Merged away.
     * @param int $logid Log id.
     * @return array
     */
    public function merge(int $toid, int $fromid, int $logid): array {
        global $DB;
        $this->calls[] = [$toid, $fromid, $logid];
        if ($this->mode === 'throw') {
            throw new \coding_exception('tool failure outside its transaction');
        }
        $logs = [];
        $errors = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($this->tables as $table) {
                $DB->set_field($table, 'userid', $toid, ['userid' => $fromid]);
            }
            $hook = mergeusers_callbacks::AFTER_HOOK;
            if (class_exists($hook)) {
                \core\di::get(\core\hook\manager::class)->dispatch(new $hook($toid, $fromid, $logs, $errors));
            } else {
                mergeusers_callbacks::after_merged_all_tables(new after_hook_standin($toid, $fromid, $logs, $errors));
            }
            if ($this->mode === 'error') {
                $errors[] = 'injected error after all tables';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Exception thrown when merging: ' . $e->getMessage();
        }
        if (!$errors) {
            $transaction->allow_commit();
            $this->logs[$logid] = 'success';
            return [true, $logs, $logid];
        }
        try {
            $transaction->rollback(new \Exception('Rolling back transaction.'));
        } catch (\Exception $e) {
            unset($e);
        }
        $this->logs[$logid] = 'error';
        return [false, $errors, $logid];
    }

    /**
     * Log status.
     *
     * @param int $logid Log id.
     * @return string
     */
    public function log_status(int $logid): string {
        return $this->logs[$logid] ?? '';
    }

    /**
     * Finish a log entry.
     *
     * @param int $logid Log id.
     * @param bool $success Outcome.
     * @param string $line Line.
     * @return void
     */
    public function finish_log(int $logid, bool $success, string $line): void {
        $this->logs[$logid] = $success ? 'success' : 'error';
        $this->finished[] = [$logid, $success];
    }
}
