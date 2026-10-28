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
 * Integration helper steps.
 *
 * Usage:
 *   step.php snapshot <out.json>                 ownership of every watched row, jobs, tool logs, pseudonym resolution
 *   step.php direct <toid> <fromid> <out.json>   merge directly with tool_mergeusers (as its own UI/CLI would), no consent
 *   step.php declare-undeclared <preview> <out>  the administrator declares every table that blocks a preview as
 *                                                "undeclared" in tool_mergeusers' custom settings (user columns)
 *   step.php job <source> <target>               print the newest job of a pair as JSON
 *   step.php render <source> <target> <out.html> render the merge page parts with the real data (no missing strings)
 *   step.php jobstate <source> <target>          "<status> inmerge=<0|1> log=<tool log id>" of the newest job of a pair
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The bootstrap (config.php of the private tree on a scratch database) is in lib.php.
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
require(__DIR__ . '/lib.php');

$cmd = $argv[1] ?? '';
\core\session\manager::set_user(get_admin());

if ($cmd === 'snapshot') {
    $pseudonyms = [];
    if ($DB->get_manager()->table_exists('ltisource_ltipseudonym_map')) {
        foreach ($DB->get_records('ltisource_ltipseudonym_map', null, 'id', 'id, userid, token, status') as $row) {
            $pseudonyms[$row->token] = ['userid' => (int) $row->userid, 'status' => $row->status];
        }
    }
    $grades = [];
    foreach ($DB->get_records('grade_grades', null, 'id', 'id, itemid, userid, finalgrade') as $row) {
        $grades[(int) $row->id] = [(int) $row->itemid, (int) $row->userid, (float) $row->finalgrade];
    }
    $outbox = [];
    if ($DB->get_manager()->table_exists('local_lticompletionsync_out')) {
        foreach ($DB->get_records('local_lticompletionsync_out', null, 'id') as $row) {
            $outbox[(int) $row->id] = [(int) $row->userid, (int) $row->resourcelinkid, (int) $row->desiredstate];
        }
    }
    $bindings = [];
    foreach ($DB->get_records('auth_lti_linked_login', null, 'id', 'id, userid, issuer, sub') as $row) {
        $bindings[$row->issuer . '|' . $row->sub] = (int) $row->userid;
    }
    $users = [];
    foreach ($DB->get_records_select('user', "username LIKE 'ltiam\\_%'", null, 'id', 'id, username, suspended, deleted') as $u) {
        $users[$u->username] = ['id' => (int) $u->id, 'suspended' => (int) $u->suspended, 'deleted' => (int) $u->deleted];
    }
    ltiam_write($argv[2], [
        'ownership' => ltiam_ownership(),
        'pseudonyms' => $pseudonyms,
        'grades' => $grades,
        'outbox' => $outbox,
        'bindings' => $bindings,
        'users' => $users,
        'jobs' => array_values(array_map(fn($r) => (array) $r, $DB->get_records('local_ltiaccountmerge_job', null, 'id'))),
        'toollogs' => array_values(array_map(fn($r) => (array) $r, $DB->get_records(
            'tool_mergeusers',
            null,
            'id',
            'id, touserid, fromuserid, status'
        ))),
        'journal' => array_values(array_map(fn($r) => (array) $r, $DB->get_records('local_ltiaccountmerge_merge', null, 'id'))),
        'audit' => array_values(array_map(
            fn($r) => [$r->action, $r->outcome, (int) $r->targetid, (int) $r->relateduserid],
            $DB->get_records('local_ltiaccountmerge_audit', null, 'id')
        )),
        'adhoc' => array_values(array_map(
            fn($r) => [$r->classname, $r->customdata],
            $DB->get_records('task_adhoc', null, 'id', 'id, classname, customdata')
        )),
    ]);
    exit(0);
}

if ($cmd === 'direct') {
    [, , $to, $from, $out] = $argv;
    $merger = new \tool_mergeusers\local\user_merger();
    [$ok, $log, $logid] = $merger->merge((int) $to, (int) $from);
    $log = array_map(fn($l) => substr(strip_tags((string) $l), 0, 400), (array) $log);
    ltiam_write($out, ['success' => (bool) $ok, 'logid' => (int) $logid, 'log' => array_values(array_filter(
        $log,
        fn($l) => stripos($l, 'ltiaccountmerge') !== false || stripos($l, 'error') !== false
        || stripos($l, 'exception') !== false
    ))]);
    ltiam_info("direct merge $from -> $to: " . ($ok ? 'success' : 'failed'));
    exit(0);
}

if ($cmd === 'declare-undeclared') {
    // The administrator's way out of a blocked preview: tool_mergeusers' custom settings declare how each undeclared plugin
    // table is merged (here: its user columns get the tool's row-by-row update). Usage: declare-undeclared <preview> <out>.
    $preview = ltiam_read($argv[2]);
    $custom = json_decode((string) get_config('tool_mergeusers', 'customdbsettings'), true) ?: [];
    $added = [];
    foreach ($preview['blockers'] ?? [] as $blocker) {
        if ($blocker['reason'] !== 'undeclared') {
            continue;
        }
        $table = $blocker['table'];
        $columns = array_keys($DB->get_columns($table));
        $custom['userfieldnames'][$table] = array_values(array_intersect($columns, ['userid', 'usermodified', 'relateduserid',
            'actorid', 'realuserid']));
        $added[$table] = $custom['userfieldnames'][$table];
    }
    if ($added) {
        set_config('customdbsettings', json_encode($custom), 'tool_mergeusers');
    }
    ltiam_write($argv[3], ['admindeclared' => $added]);
    ltiam_info('declared by the administrator (tool_mergeusers customdbsettings): ' . json_encode($added));
    exit(0);
}

if ($cmd === 'render') {
    // Render the merge page's preview, job and job list (templates and strings with the real tool's data).
    // Usage: render <source> <target> <out.html>.
    [, , $source, $target, $out] = $argv;
    $PAGE->set_url(new moodle_url('/local/ltiaccountmerge/merge.php'));
    $PAGE->set_context(context_system::instance());
    $renderer = $PAGE->get_renderer('local_ltiaccountmerge');
    $report = (new \local_ltiaccountmerge\local\merge\executor())->preview((int) $source, (int) $target);
    $html = $renderer->merge_preview($report);
    foreach ($DB->get_records('local_ltiaccountmerge_job', null, 'id DESC', '*', 0, 3) as $job) {
        $html .= $renderer->merge_job($job, new moodle_url('/local/ltiaccountmerge/merge.php', ['job' => $job->id]));
    }
    $html .= $renderer->merge_jobs(
        $DB->get_records('local_ltiaccountmerge_job', null, 'id DESC', '*', 0, 20),
        new moodle_url('/local/ltiaccountmerge/merge.php')
    );
    file_put_contents($out, $html);
    ltiam_info('rendered ' . strlen($html) . ' bytes, verdict ' . $report->verdict);
    exit(strpos($html, '[[') === false ? 0 : 1);
}

if ($cmd === 'jobstate') {
    $jobs = $DB->get_records(
        'local_ltiaccountmerge_job',
        ['sourceuserid' => (int) $argv[2], 'targetuserid' => (int) $argv[3]],
        'id DESC',
        '*',
        0,
        1
    );
    $job = $jobs ? reset($jobs) : null;
    echo $job ? "{$job->status} inmerge={$job->inmerge} log={$job->mergelogid}" : 'none', "\n";
    exit(0);
}

if ($cmd === 'job') {
    $jobs = $DB->get_records(
        'local_ltiaccountmerge_job',
        ['sourceuserid' => (int) $argv[2], 'targetuserid' => (int) $argv[3]],
        'id DESC',
        '*',
        0,
        1
    );
    echo json_encode($jobs ? (array) reset($jobs) : null), "\n";
    exit(0);
}

fwrite(STDERR, "unknown command\n");
exit(2);
