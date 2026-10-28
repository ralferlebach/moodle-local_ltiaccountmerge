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
 * Integration verification phases (see run.sh). Every phase prints OK/FAIL lines and exits 1 on any FAIL.
 *
 * Usage: verify.php <phase> <seed.json> [files...]
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The bootstrap (config.php of the private tree on a scratch database) is in lib.php.
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
require(__DIR__ . '/lib.php');

[$self, $phase, $seedfile] = array_pad($argv, 3, '');
$args = array_slice($argv, 3);
$seed = ltiam_read($seedfile);
$ids = $seed['ids'];
$u = fn(string $key) => (int) $ids['user_' . $key];
$cs = !empty($ids['completionsync']);
$ps = !empty($ids['pseudonyms']);

/**
 * Latest job of a pair from a snapshot.
 *
 * @param array $snap Snapshot.
 * @param int $s Source.
 * @param int $t Target.
 * @return array|null
 */
function ltiam_job(array $snap, int $s, int $t): ?array {
    $found = null;
    foreach ($snap['jobs'] as $job) {
        if ((int) $job['sourceuserid'] === $s && (int) $job['targetuserid'] === $t) {
            $found = $job;
        }
    }
    return $found;
}

/**
 * Tool log entries of a pair.
 *
 * @param array $snap Snapshot.
 * @param int $s Source.
 * @param int $t Target.
 * @return array
 */
function ltiam_logs(array $snap, int $s, int $t): array {
    return array_values(array_filter($snap['toollogs'], fn($l) => (int) $l['fromuserid'] === $s && (int) $l['touserid'] === $t));
}

switch ($phase) {
    case 'direct-refused':
        // Args: before.json after.json direct.json.
        [$before, $after, $direct] = array_map('ltiam_read', $args);
        ltiam_check('tool_mergeusers merge of LTI accounts without consent failed', $direct['success'] === false);
        ltiam_check(
            'the failure names this plugin (consent required)',
            (bool) preg_grep('/ltiaccountmerge/', $direct['log']),
            $direct['log'][0] ?? null
        );
        ltiam_check('no row changed owner (whole merge rolled back)', ltiam_ownership_violations(
            $before['ownership'],
            $after['ownership'],
            []
        ) === [], ltiam_ownership_violations($before['ownership'], $after['ownership'], []));
        ltiam_check('bindings unchanged', $before['bindings'] === $after['bindings']);
        ltiam_check(
            'the refusal is in the audit trail (mergefailed, written after the rollback)',
            in_array(['mergefailed', 'failed', 0, $u('b5')], $after['audit'], true)
        );
        $logs = ltiam_logs($after, $u('a5'), $u('b5'));
        ltiam_check('tool_mergeusers logged the failed merge', count($logs) === 1 && $logs[0]['status'] === 'error', $logs);
        break;

    case 'direct-allowed':
        [$before, $after, $direct] = array_map('ltiam_read', $args);
        ltiam_check('ordinary accounts still merge directly in tool_mergeusers', $direct['success'] === true, $direct['log']);
        $v = ltiam_ownership_violations($before['ownership'], $after['ownership'], [$u('x1') => $u('y1')]);
        ltiam_check('only rows of x1 moved, and only to y1', $v === [], $v);
        break;

    case 'preview':
        // Args: preview.json, expected verdict.
        $p = ltiam_read($args[0]);
        $expected = $args[1];
        if ($expected !== 'any') {
            ltiam_check("preview verdict is $expected", $p['verdict'] === $expected, [$p['verdict'], $p['blockers'] ?? []]);
        } else {
            ltiam_info('preview verdict ' . $p['verdict'] . ', blockers ' . json_encode($p['blockers'] ?? []));
        }
        foreach ($p['blockers'] ?? [] as $blocker) {
            ltiam_check(
                'blocker is an undeclared plugin table (the only blocker an administrator may lift by declaring it)',
                $blocker['reason'] === 'undeclared',
                $blocker
            );
        }
        ltiam_check('fingerprint present', strlen((string) $p['hash']) === 64);
        $cats = array_column($p['categories'], null, 'category');
        foreach (\local_ltiaccountmerge\local\merge_preview::REQUIRED_CATEGORIES as $cat) {
            ltiam_check("category $cat listed", isset($cats[$cat]), $cats[$cat] ?? null);
        }
        $tables = array_column($p['tables'], null, 'table');
        $need = ['grades' => 'grade_grades', 'attempts' => 'quiz_attempts', 'completion' => 'course_modules_completion',
            'enrolments' => 'user_enrolments', 'groups' => 'groups_members', 'ltibindings' => 'auth_lti_linked_login'];
        // Pair 2 (args[2] = "pair2") has no attempts and no completion of its own.
        if (($args[2] ?? '') === 'pair2') {
            unset($need['attempts'], $need['completion']);
        }
        if ($ps) {
            $need['pseudonym'] = 'ltisource_ltipseudonym_map';
        }
        if ($cs) {
            $need['completionsync'] = 'local_lticompletionsync_out';
        }
        foreach ($need as $cat => $table) {
            ltiam_check(
                "$cat: $table affected (rows of the source listed)",
                ($tables[$table]['rowssource'] ?? 0) > 0,
                $tables[$table] ?? null
            );
            ltiam_check("$cat counted in its category", ($cats[$cat]['rowssource'] ?? 0) > 0);
        }
        $b = $tables['auth_lti_linked_login'] ?? [];
        ltiam_check(
            'LTI bindings: handled inside the merge by local_ltiaccountmerge (no generic update)',
            ($b['strategy'] ?? '') === 'owner' && ($b['handler'] ?? '') === 'local_ltiaccountmerge',
            $b
        );
        if ($ps) {
            $m = $tables['ltisource_ltipseudonym_map'] ?? [];
            ltiam_check(
                'pseudonyms: handled inside the merge by ltisource_ltipseudonym (no generic update)',
                ($m['strategy'] ?? '') === 'owner' && ($m['handler'] ?? '') === 'ltisource_ltipseudonym',
                $m
            );
        }
        if ($cs) {
            foreach (['local_lticompletionsync_out', 'local_lticompletionsync_rcv'] as $t) {
                $row = $tables[$t] ?? [];
                $declared = !empty($row['declaredby']);
                ltiam_check(
                    "completion sync $t: " . ($declared ? 'declared by ' . implode(',', $row['declaredby'])
                    : 'UNDECLARED, so the merge is blocked (no blind update)'),
                    $declared ? $row['status'] !== 'blocked' : $row['status'] === 'blocked',
                    $row
                );
            }
        }
        foreach ($p['tables'] as $row) {
            if ($row['strategy'] === 'generic' && $row['rowssource'] > 0 && !$row['declaredby']) {
                // Core and standard plugin tables: tool_mergeusers' own domain. Listed for the record.
                ltiam_info('generic update (core/standard, not declared): ' . $row['table'] . ' (' . $row['status'] . ')');
            }
        }
        break;

    case 'refused':
        // Args: cli output json, expected error code.
        $r = ltiam_read($args[0]);
        ltiam_check('consent refused with ' . $args[1], ($r['error'] ?? '') === $args[1], $r);
        break;

    case 'pair1':
        // Args: before.json after.json result.json.
        [$before, $after, $result] = array_map('ltiam_read', $args);
        [$a, $b, $c] = [$u('a1'), $u('b1'), $u('c1')];
        ltiam_check('job succeeded', ($result['status'] ?? '') === 'succeeded', $result);
        $job = ltiam_job($after, $a, $b);
        ltiam_check('job: inmerge mark committed with the merge, outcome merged', $job && (int) $job['inmerge'] === 1
            && $job['outcome'] === 'merged', $job);
        $logs = ltiam_logs($after, $a, $b);
        ltiam_check('exactly one tool_mergeusers log entry, status success, linked to the job', count($logs) === 1
            && $logs[0]['status'] === 'success' && (int) $logs[0]['id'] === (int) $job['mergelogid'], $logs);
        $v = ltiam_ownership_violations($before['ownership'], $after['ownership'], [$a => $b], ['groups_members']);
        ltiam_check('no identity mix-up: every row stayed or moved from a1 to b1 only', $v === [], $v);
        foreach (
            ['user_enrolments', 'groups_members', 'grade_grades', 'quiz_attempts', 'course_modules_completion',
                'course_completions'] as $t
        ) {
            $left = array_filter($after['ownership'][$t], fn($r) => in_array($a, $r, true));
            ltiam_check("$t: nothing left on a1", $left === [], $left);
        }
        $grade = function (array $snap, int $user) use ($ids): ?float {
            foreach ($snap['grades'] as [$item, $owner, $value]) {
                if ($item === (int) $ids['gradeitem'] && $owner === $user) {
                    return (float) $value;
                }
            }
            return null;
        };
        ltiam_check(
            'grade of a1 (80) is now b1\'s, c1 keeps 40, a1 has none',
            $grade($after, $b) === 80.0 && $grade($after, $c) === 40.0 && $grade($after, $a) === null,
            [$grade($after, $a), $grade($after, $b), $grade($after, $c)]
        );
        $aimed = $after['ownership']['local_ltiaccountmerge_request'][$ids['request_aimed_a1']] ?? null;
        ltiam_check(
            'link request aimed at a1 still points to a1 (cancelled by the merge, never given to b1)',
            ($aimed['targetuserid'] ?? 0) === $a,
            $aimed
        );
        ltiam_check('binding sub-a1 follows b1; c1 and b1 keep theirs', $after['bindings'][$ids['iss1'] . '|sub-a1'] === $b
            && $after['bindings'][$ids['iss1'] . '|sub-c1'] === $c && $after['bindings'][$ids['iss2'] . '|sub-b1'] === $b);
        if ($ps) {
            $t = $ids['pseudonyms'];
            ltiam_check(
                'pseudonym of a1 resolves to b1 (same token, active): scores for it land on the same person',
                $after['pseudonyms'][$t['a1']] === ['userid' => $b, 'status' => 'active'],
                $after['pseudonyms'][$t['a1']]
            );
            ltiam_check(
                'pseudonyms of c1 and b1 unchanged (no foreign grading)',
                $after['pseudonyms'][$t['c1']] === $before['pseudonyms'][$t['c1']]
                && $after['pseudonyms'][$t['b1']] === $before['pseudonyms'][$t['b1']]
            );
        }
        if ($cs) {
            foreach ($before['outbox'] as $id => [$owner, $rl, $state]) {
                $now = $after['outbox'][$id] ?? null;
                if ($owner === $c) {
                    ltiam_check("completion outbox row $id of c1 unchanged", $now === [$owner, $rl, $state], $now);
                } else if ($owner === $a) {
                    ltiam_check("completion outbox row $id of a1 now b1's, desired state kept", $now === [$b, $rl, $state], $now);
                }
            }
        }
        ltiam_check('a1 suspended by tool_mergeusers, b1 active', $after['users']['ltiam_a1']['suspended'] === 1
            && $after['users']['ltiam_b1']['suspended'] === 0);
        $req = $after['ownership']['local_ltiaccountmerge_request'];
        ltiam_check('link request created by a1 handed to b1', ($req[$ids['request_by_a1']]['requestedby'] ?? 0) === $b);
        ltiam_check('audit: mergeconsent and mergeexecute success', in_array(
            ['mergeexecute', 'success', (int) $job['id'], $b],
            $after['audit'],
            true
        ));
        break;

    case 'pair2':
        [$before, $after, $result] = array_map('ltiam_read', $args);
        [$a, $b] = [$u('a2'), $u('b2')];
        ltiam_check('job succeeded after the conflicts were accepted', ($result['status'] ?? '') === 'succeeded', $result);
        $v = ltiam_ownership_violations($before['ownership'], $after['ownership'], [$a => $b], ['groups_members',
            'user_enrolments', 'role_assignments', 'local_lticompletionsync_out', 'local_lticompletionsync_rcv']);
        ltiam_check('no identity mix-up', $v === [], $v);
        ltiam_check(
            'conflict rule: identity sub-a2 stays on a2, b2 keeps sub-b2',
            $after['bindings'][$ids['iss1'] . '|sub-a2'] === $a
            && $after['bindings'][$ids['iss1'] . '|sub-b2'] === $b
        );
        $journal = array_values(array_filter($after['journal'], fn($j) => (int) $j['sourceuserid'] === $a));
        ltiam_check(
            'reconciliation journal: partial (conflict left for an administrator)',
            ($journal[0]['status'] ?? '') === 'partial',
            $journal
        );
        if ($ps) {
            $t = $ids['pseudonyms'];
            ltiam_check(
                'pseudonym of a2 stays on a2 and is retired (refused from now on)',
                $after['pseudonyms'][$t['a2']] === ['userid' => $a, 'status' => 'retired'],
                $after['pseudonyms'][$t['a2']]
            );
            ltiam_check('pseudonym of b2 unchanged and active', $after['pseudonyms'][$t['b2']] === ['userid' => $b,
                'status' => 'active']);
        }
        $members = array_filter($after['ownership']['groups_members'], fn($r) => $r['userid'] === $b);
        ltiam_check('b2 is in the shared group exactly once', count($members) === 1, count($members));
        if ($cs) {
            $rows = array_filter($after['outbox'], fn($r) => $r[1] === 11 && in_array($r[0], [$a, $b], true));
            ltiam_check('completion outbox: one row per key for b2, none left on a2', count($rows) === 1
                && reset($rows)[0] === $b, $rows);
        }
        break;

    case 'crash-aborted':
        // Args: before.json after.json recover.json.
        [$before, $after, $recover] = array_map('ltiam_read', $args);
        [$a, $b] = [$u('a3'), $u('b3')];
        $resolved = ($recover['recover']['recoveredaborted'] ?? 0) === 1;
        ltiam_check('recovery resolved the dead job as rolled back', $resolved, $recover);
        $job = ltiam_job($after, $a, $b);
        ltiam_check('job aborted, recovered, no in-transaction mark', $job && $job['status'] === 'aborted'
            && (int) $job['recovered'] === 1 && (int) $job['inmerge'] === 0, $job);
        $v = ltiam_ownership_violations($before['ownership'], $after['ownership'], []);
        ltiam_check('nothing changed: the database rolled the merge back', $v === [], $v);
        ltiam_check('bindings unchanged', $before['bindings'] === $after['bindings']);
        $logs = ltiam_logs($after, $a, $b);
        ltiam_check('tool_mergeusers log no longer "in progress" (corrected to error)', count($logs) === 1
            && $logs[0]['status'] === 'error', $logs);
        break;

    case 'crash-committed':
        [$before, $after, $recover] = array_map('ltiam_read', $args);
        [$a, $b] = [$u('a6'), $u('b6')];
        $resolved = ($recover['recover']['recoveredsucceeded'] ?? 0) === 1;
        ltiam_check('recovery resolved the dead job as committed', $resolved, $recover);
        $job = ltiam_job($after, $a, $b);
        ltiam_check('job succeeded, recovered, in-transaction mark present', $job && $job['status'] === 'succeeded'
            && (int) $job['recovered'] === 1 && (int) $job['inmerge'] === 1, $job);
        $v = ltiam_ownership_violations($before['ownership'], $after['ownership'], [$a => $b], ['groups_members']);
        ltiam_check('rows of a6 moved to b6 only', $v === [], $v);
        $left = array_filter($after['ownership']['user_enrolments'], fn($r) => $r['userid'] === $a);
        ltiam_check('enrolment of a6 moved', $left === []);
        ltiam_check(
            'binding sub-a6 follows b6 (reconciled inside the committed merge)',
            $after['bindings'][$ids['iss2'] . '|sub-a6'] === $b
        );
        $logs = ltiam_logs($after, $a, $b);
        ltiam_check('tool_mergeusers log is success', count($logs) === 1 && $logs[0]['status'] === 'success', $logs);
        break;

    case 'consent-race':
        // Args: result1.json result2.json.
        [$r1, $r2] = array_map('ltiam_read', $args);
        $ok = array_filter([$r1, $r2], fn($r) => ($r['status'] ?? '') === 'consented');
        $refused = array_filter([$r1, $r2], fn($r) => in_array($r['error'] ?? '', ['err_merge_open', 'err_busy'], true));
        ltiam_check('competing consents (a4->b4, b4->c4): exactly one job, the other refused', count($ok) === 1
            && count($refused) === 1, [$r1, $r2]);
        break;

    case 'exec-race':
        // Args: result1.json result2.json [expected succeeded count].
        [$r1, $r2] = array_map('ltiam_read', array_slice($args, 0, 2));
        $statuses = [$r1['status'] ?? '', $r2['status'] ?? ''];
        sort($statuses);
        ltiam_check(
            'two executions at the same time: one merged, the other reported busy',
            $statuses === ['busy', 'succeeded'],
            $statuses
        );
        break;

    case 'sched':
        // Args: output directory of the schedule part.
        $dir = $args[0];
        $st = fn(string $n) => ltiam_read("$dir/st_$n.json");
        $tools = $seed['tools'];
        $m = fn(array $state, string $key) => $state['tools'][$tools[$key]][0] ?? null;
        $l1 = (int) $seed['users']['l1'];
        $l2 = (int) $seed['users']['l2'];
        $expect = [
            'w0' => ['t1' => 1, 't2' => 2], 'w1' => ['t1' => 3, 't2' => 3], 'w2' => ['t1' => 2, 't2' => 3],
            'manual' => ['t1' => 2, 't2' => 1], 'w3' => ['t1' => 2, 't2' => 3], 'w4' => ['t1' => 3, 't2' => 3],
            'w5' => ['t1' => 1, 't2' => 1],
        ];
        $labels = ['w0' => 'before the windows (baselines)', 'w1' => 'site window: existing account only',
            'w2' => 'overlap: higher-priority window of t1 wins', 'manual' => 'administrator changed t2 by hand',
            'w3' => 'active window still governs t2; the manual value becomes its baseline',
            'w4' => 'overlap ended: site window again', 'w5' => 'all windows ended: t1 back to 1, t2 to the MANUAL baseline 1'];
        $first = $st('w0');
        foreach ($expect as $n => $want) {
            $state = $st($n);
            $got = ['t1' => $m($state, 't1'), 't2' => $m($state, 't2')];
            ltiam_check("real task run $n: {$labels[$n]}", $got === $want, $got);
            ltiam_check(
                "$n: bindings of the two linked accounts unchanged",
                ($state['bindings']['sub-l1'] ?? 0) === $l1 && ($state['bindings']['sub-l2'] ?? 0) === $l2
            );
            $uuids = array_map(fn($t) => $t[2], $state['tools']);
            ltiam_check("$n: no resource got a new uuid", $uuids === array_map(fn($t) => $t[2], $first['tools']));
            if (is_file("$dir/task_$n.log")) {
                ltiam_check("$n: task exit code 0", strpos((string) file_get_contents("$dir/task_$n.log"), 'rc=0') !== false);
            }
        }
        ltiam_check(
            'w3: bookkeeping keeps the manual value as baseline of t2',
            (int) ($st('w3')['applied'][$tools['t2']][0] ?? -1) === 1,
            $st('w3')['applied'][$tools['t2']] ?? null
        );
        $probes = ['w0' => ["user=$l1 mode=1", "user=$l2 mode=1", 'prompt mode=2'],
            'w1' => ["user=$l1 mode=3", "user=$l2 mode=3", 'prompt mode=3']];
        foreach ($probes as $n => $want) {
            $got = array_values(array_filter(array_map('trim', file("$dir/probe_$n.txt"))));
            ltiam_check(
                "launch probes $n: linked accounts keep landing in the same account; an unbound identity follows the mode",
                $got === $want,
                $got
            );
        }
        $w5 = array_values(array_filter(array_map('trim', file("$dir/probe_w5.txt"))));
        ltiam_check(
            'launch probes w5 (automatic again): linked accounts unchanged, a new identity is provisioned',
            ($w5[0] ?? '') === "user=$l1 mode=1" && ($w5[1] ?? '') === "user=$l2 mode=1"
            && preg_match('/^user=(\d+) mode=1$/', $w5[2] ?? '', $mm) && !in_array((int) $mm[1], [0, $l1, $l2], true),
            $w5
        );

        // Parallel task processes and launches at a boundary.
        [$r0, $r1] = [$st('r0'), $st('r1')];
        ltiam_check('race: t1 before 1 (or 1 from the windows), after 2', $m($r1, 't1') === 2, [$m($r0, 't1'), $m($r1, 't1')]);
        $changes = array_filter(array_slice($r1['audit'], count($r0['audit'])), fn($a) => $a[0] === (int) $tools['t1']
            && $a[1] === 'success');
        ltiam_check('race: exactly one of four parallel task processes changed t1', count($changes) === 1, array_values($changes));
        foreach ([1, 2, 3, 4] as $i) {
            ltiam_check(
                "race: task process $i finished with exit code 0",
                strpos((string) file_get_contents("$dir/task_r$i.log"), 'rc=0') !== false
            );
        }
        foreach ([1, 2, 3] as $i) {
            $line = trim((string) file_get_contents("$dir/probe_r$i.txt"));
            ltiam_check(
                "race: parallel launch $i lands in the linked account (mode read: old or new)",
                (bool) preg_match("/^user=$l1 mode=[12]$/", $line),
                $line
            );
        }

        // Task process killed while writing (t6 row locked).
        [$k0, $k1, $k2] = [$st('k0'), $st('k1'), $st('k2')];
        ltiam_check('kill: t5 (before the locked resource) was committed by the killed process', $m($k0, 't5') === 3
            && $m($k1, 't5') === 3, [$m($k0, 't5'), $m($k1, 't5')]);
        ltiam_check('kill: t6 untouched after the kill (its transaction rolled back), no bookkeeping row', $m($k1, 't6') === 1
            && !isset($k1['applied'][$tools['t6']]), [$m($k1, 't6'), $k1['applied'][$tools['t6']] ?? null]);
        ltiam_check('kill: the next real run (no stale lock) applies t6', $m($k2, 't6') === 3
            && strpos((string) file_get_contents("$dir/task_k2.log"), 'rc=0') !== false);
        ltiam_check('kill: bindings unchanged', $k2['bindings']['sub-l1'] === $l1 && $k2['bindings']['sub-l2'] === $l2);

        // A resource that cannot be written.
        [$b1, $b2] = [$st('b1'), $st('b2')];
        $failed = array_filter(array_slice($b1['audit'], count($k2['audit'])), fn($a) => $a[0] === (int) $tools['t7']
            && $a[1] === 'failed');
        ltiam_check('broken resource: the failure is audited', count($failed) >= 1, array_values($failed));
        ltiam_check('broken resource: nothing half-written, other resources unaffected, task exit code 0', $m($b1, 't7') === 1
            && $m($b1, 't5') === 3 && $m($b1, 't6') === 3
            && strpos((string) file_get_contents("$dir/task_b1.log"), 'rc=0') !== false, [$m($b1, 't7'), $m($b1, 't5')]);
        ltiam_check('broken resource: applied after the repair', $m($b2, 't7') === 3);

        // Daylight saving time, real task runs under faketime.
        $berlin = new \DateTimeZone('Europe/Berlin');
        foreach (file("$dir/dst.txt") as $line) {
            [$when, $want, $got] = preg_split('/\s+/', trim($line));
            $local = (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->setTimezone($berlin)->format('Y-m-d H:i:s T');
            ltiam_check("DST: task run started at $when UTC ($local) gives mode $want", (int) $got === (int) $want, $got);
        }
        break;

    case 'final':
        // Args: s0.json sfinal.json moves.json.
        [$first, $last, $moves] = array_map('ltiam_read', $args);
        $map = [];
        foreach ($moves as $from => $to) {
            $map[(int) $from] = (int) $to;
        }
        $v = ltiam_ownership_violations($first['ownership'], $last['ownership'], $map, ['groups_members', 'user_enrolments',
            'role_assignments', 'local_lticompletionsync_out', 'local_lticompletionsync_rcv']);
        ltiam_check('whole run: every row either stayed or moved from a merged account to its kept account', $v === [], $v);
        foreach ($last['jobs'] as $job) {
            $logs = ltiam_logs($last, (int) $job['sourceuserid'], (int) $job['targetuserid']);
            if ($job['status'] === 'running') {
                ltiam_check("job {$job['id']} not left running", false, $job);
            } else if ($job['status'] === 'succeeded') {
                ltiam_check(
                    "job {$job['id']} succeeded with in-transaction mark and a successful tool log",
                    (int) $job['inmerge'] === 1 && in_array('success', array_column($logs, 'status'), true),
                    [$job, $logs]
                );
            } else if (in_array($job['status'], ['failed', 'aborted'], true)) {
                ltiam_check("job {$job['id']} {$job['status']} without in-transaction mark", (int) $job['inmerge'] === 0);
            }
        }
        $c = $u('c1');
        $cg = array_filter($last['grades'], fn($g) => $g[1] === $c);
        ltiam_check('bystander c1 keeps exactly its grades', $cg === array_filter($first['grades'], fn($g) => $g[1] === $c));
        ltiam_check('bystander c1 keeps its binding', $last['bindings'][$ids['iss1'] . '|sub-c1'] === $c);
        if ($ps) {
            foreach ($first['pseudonyms'] as $token => $owner) {
                $now = $last['pseudonyms'][$token] ?? null;
                $allowed = $now === $owner || ($now && isset($map[$owner['userid']]) && $now['userid'] === $map[$owner['userid']])
                    || ($now && $now['userid'] === $owner['userid'] && $now['status'] === 'retired');
                ltiam_check(
                    "pseudonym " . substr($token, 0, 6) . "... resolves to the same person or is retired",
                    $allowed,
                    [$owner, $now]
                );
            }
        }
        break;

    default:
        fwrite(STDERR, "unknown phase $phase\n");
        exit(2);
}
exit($GLOBALS['ltiamfail'] ? 1 : 0);
