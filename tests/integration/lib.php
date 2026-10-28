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

/**
 * Shared helpers of the integration harness (tests/integration/run.sh): boots a PRIVATE copy of a Moodle tree on a
 * scratch database whose name must end in "_ltiam" (never the shared main or PHPUnit databases).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState

define('CLI_SCRIPT', true);
$ltiamroot = getenv('LTIAM_ROOT');
if (!$ltiamroot || !is_file($ltiamroot . '/config.php')) {
    fwrite(STDERR, "LTIAM_ROOT not set or no config.php\n");
    exit(2);
}
if (!preg_match('/_ltiam$/', (string) getenv('LTI_DBNAME'))) {
    fwrite(STDERR, "Refusing to run: LTI_DBNAME must end in _ltiam (scratch database only)\n");
    exit(2);
}
require($ltiamroot . '/config.php');
require_once($CFG->libdir . '/clilib.php');

// Number of failed checks.
$GLOBALS['ltiamfail'] = 0;

/**
 * Print one check.
 *
 * @param string $name Check.
 * @param bool $ok Result.
 * @param mixed $detail Detail (JSON encoded).
 * @return bool
 */
function ltiam_check(string $name, bool $ok, $detail = null): bool {
    if (!$ok) {
        $GLOBALS['ltiamfail']++;
    }
    echo ($ok ? '  OK   ' : '  FAIL ') . $name . ($detail !== null ? ' :: ' . json_encode($detail, JSON_UNESCAPED_SLASHES) : '')
        . "\n";
    return $ok;
}

/**
 * Print an info line.
 *
 * @param string $line Line.
 * @return void
 */
function ltiam_info(string $line): void {
    echo '  INFO ' . $line . "\n";
}

/**
 * Read a JSON file.
 *
 * @param string $file File.
 * @return array
 */
function ltiam_read(string $file): array {
    return json_decode((string) file_get_contents($file), true) ?: [];
}

/**
 * Write a JSON file.
 *
 * @param string $file File.
 * @param array $data Data.
 * @return void
 */
function ltiam_write(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Ownership snapshot: for every row of the watched tables, id => user columns. Used to prove that rows only ever move
 * from a merged-away account to its kept account (no identity mix-up, no foreign grading).
 *
 * @return array table => [rowid => [column => userid]]
 */
function ltiam_ownership(): array {
    global $DB;
    $tables = [
        'user_enrolments' => ['userid'], 'role_assignments' => ['userid'], 'groups_members' => ['userid'],
        'grade_grades' => ['userid'], 'quiz_attempts' => ['userid'], 'question_attempt_steps' => ['userid'],
        'course_modules_completion' => ['userid'], 'course_completions' => ['userid'],
        'auth_lti_linked_login' => ['userid'], 'ltisource_ltipseudonym_map' => ['userid'],
        'local_lticompletionsync_out' => ['userid'], 'local_lticompletionsync_rcv' => ['userid'],
        'local_ltiaccountmerge_request' => ['targetuserid', 'requestedby'],
    ];
    $out = [];
    $dbman = $DB->get_manager();
    foreach ($tables as $table => $columns) {
        if (!$dbman->table_exists($table)) {
            continue;
        }
        $rows = [];
        foreach ($DB->get_records($table, null, 'id', 'id, ' . implode(', ', $columns)) as $row) {
            foreach ($columns as $column) {
                $rows[(int) $row->id][$column] = (int) $row->$column;
            }
        }
        $out[$table] = $rows;
    }
    return $out;
}

/**
 * Compare two ownership snapshots under a set of allowed moves.
 *
 * Allowed: unchanged; moved from a merged-away account to ITS kept account; deleted only where $deletable allows it
 * (duplicates resolved by a declared compound index). Everything else is a violation (identity mix-up).
 *
 * @param array $before Snapshot.
 * @param array $after Snapshot.
 * @param array $moves fromid => toid of the merges that ran in between.
 * @param string[] $deletable Tables where rows of merged accounts may disappear.
 * @return array List of violations.
 */
function ltiam_ownership_violations(array $before, array $after, array $moves, array $deletable = []): array {
    $violations = [];
    $merged = array_merge(array_keys($moves), array_values($moves));
    foreach ($before as $table => $rows) {
        foreach ($rows as $id => $columns) {
            foreach ($columns as $column => $owner) {
                if (!isset($after[$table][$id])) {
                    if (!in_array($table, $deletable, true) || !in_array($owner, $merged, true)) {
                        $violations[] = "$table#$id.$column of user $owner deleted";
                    }
                    continue;
                }
                $now = $after[$table][$id][$column];
                if ($now === $owner) {
                    continue;
                }
                if (!isset($moves[$owner]) || $moves[$owner] !== $now) {
                    $violations[] = "$table#$id.$column moved from user $owner to user $now";
                }
            }
        }
    }
    return $violations;
}
