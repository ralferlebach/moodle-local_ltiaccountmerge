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
 * Integration helper for the provisioning schedule (audit item AR-04), used by run.sh with REAL scheduled-task runs.
 *
 * Usage:
 *   sched.php seed <out.json> <t0>            resources, bindings and policies (windows relative to t0, UTC)
 *   sched.php state <out.json>                stored modes, bookkeeping, bindings, audit counters, task fail delay
 *   sched.php launch <sub> <toolkey> <seed>   what enrol/lti/launch.php does for a learner: read the stored mode, then
 *                                             auth_plugin_lti::complete_login(); prints "user=<id> mode=<m>" or "prompt mode=<m>"
 *   sched.php manual <toolkey> <mode> <seed>  an administrator changes the learner mode of a resource (enrol API)
 *   sched.php policy <toolkey> <start> <mode> <seed>  an open-ended window (UTC start) for one resource
 *   sched.php break <toolkey> <seed>          remove the course of a resource (a resource that cannot be written)
 *   sched.php repair <seed>                   put the course back
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The bootstrap (config.php of the private tree on a scratch database) is in lib.php.
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
require(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/auth/lti/auth.php');

$cmd = $argv[1] ?? '';
$iss = 'https://platform-sched.example.org';

/**
 * Tool id of a seed key.
 *
 * @param string $seedfile Seed file.
 * @param string $key Key (t1 ...).
 * @return int
 */
function ltiam_tool(string $seedfile, string $key): int {
    return (int) ltiam_read($seedfile)['tools'][$key];
}

if ($cmd === 'seed') {
    \core\session\manager::set_user(get_admin());
    $out = $argv[2];
    $t0 = (int) $argv[3];
    $gen = new testing_data_generator();
    $DB->insert_record('enrol_lti_app_registration', (object) ['name' => 'Schedule platform', 'platformid' => $iss,
        'clientid' => 'client-sched', 'uniqueid' => md5($iss . 'u'), 'platformclienthash' => hash('sha256', $iss . 'client-sched'),
        'platformuniqueidhash' => hash('sha256', $iss . md5($iss . 'u')), 'authenticationrequesturl' => $iss . '/auth',
        'jwksurl' => $iss . '/jwks', 'accesstokenurl' => $iss . '/token', 'status' => 1, 'timecreated' => time(),
        'timemodified' => time()]);
    $tools = [];
    $baselines = ['t1' => 1, 't2' => 2, 't4' => 1, 't5' => 1, 't6' => 1, 't7' => 1];
    foreach ($baselines as $key => $learner) {
        $course = $gen->create_course(['shortname' => 'SCHED_' . $key]);
        $tool = $gen->create_lti_tool((object) ['courseid' => $course->id, 'ltiversion' => 'LTI-1p3',
            'provisioningmodelearner' => $learner, 'provisioningmodeinstructor' => 2]);
        $tools[$key] = (int) $tool->id;
    }
    $l1 = $gen->create_user(['username' => 'sched_l1', 'auth' => 'lti']);
    $l2 = $gen->create_user(['username' => 'sched_l2', 'auth' => 'manual']);
    $auth = new auth_plugin_lti();
    $auth->create_user_binding($iss, 'sub-l1', (int) $l1->id);
    $auth->create_user_binding($iss, 'sub-l2', (int) $l2->id);
    $policy = function (array $fields) use ($DB) {
        return (int) $DB->insert_record('local_ltiaccountmerge_policy', (object) array_merge(['scope' => 'site', 'scopeid' => 0,
            'kind' => 'scheduled', 'priority' => 0, 'enabled' => 1, 'timezone' => 'UTC', 'recurrence' => 'once',
            'localstart' => null, 'localend' => null, 'modelearner' => null, 'modeinstructor' => null, 'usermodified' => 0,
            'timecreated' => time(), 'timemodified' => time()], $fields));
    };
    $utc = fn(int $t) => gmdate('Y-m-d H:i:s', $t);
    $policies = [
        // Site-wide window (all resources): learner "existing account only".
        'p1' => $policy(['name' => 'site window', 'localstart' => $utc($t0 + 20), 'localend' => $utc($t0 + 50),
            'modelearner' => 3]),
        // Overlapping, higher priority, only t1: "new or existing".
        'p2' => $policy(['name' => 't1 overlap', 'scope' => 'tool', 'scopeid' => $tools['t1'], 'priority' => 5,
            'localstart' => $utc($t0 + 28), 'localend' => $utc($t0 + 38), 'modelearner' => 2]),
        // Daily window in Europe/Berlin for t4 (02:30-03:30 local; the start does not exist on the spring DST day).
        'p3' => $policy(['name' => 'daily berlin', 'scope' => 'tool', 'scopeid' => $tools['t4'], 'priority' => 10,
            'timezone' => 'Europe/Berlin', 'recurrence' => 'daily', 'localstart' => '02:30:00', 'localend' => '03:30:00',
            'modelearner' => 3]),
        // Open-ended windows for the crash and failure checks (start later).
        'p5' => $policy(['name' => 'kill window', 'scope' => 'tool', 'scopeid' => $tools['t5'],
            'localstart' => $utc($t0 + 75), 'modelearner' => 3]),
        'p6' => $policy(['name' => 'kill window 2', 'scope' => 'tool', 'scopeid' => $tools['t6'],
            'localstart' => $utc($t0 + 75), 'modelearner' => 3]),
        'p7' => $policy(['name' => 'broken window', 'scope' => 'tool', 'scopeid' => $tools['t7'],
            'localstart' => $utc($t0 + 95), 'modelearner' => 3]),
    ];
    ltiam_write($out, ['t0' => $t0, 'tools' => $tools, 'policies' => $policies, 'users' => ['l1' => (int) $l1->id,
        'l2' => (int) $l2->id], 'iss' => $iss]);
    exit(0);
}

if ($cmd === 'state') {
    $tools = [];
    $rows = $DB->get_records('enrol_lti_tools', null, 'id', 'id, uuid, provisioningmodelearner, provisioningmodeinstructor');
    foreach ($rows as $t) {
        $tools[(int) $t->id] = [(int) $t->provisioningmodelearner, (int) $t->provisioningmodeinstructor, $t->uuid];
    }
    $applied = [];
    foreach ($DB->get_records('local_ltiaccountmerge_applied') as $a) {
        $applied[(int) $a->toolid] = [$a->baselinelearner, $a->appliedlearner];
    }
    $bindings = [];
    foreach ($DB->get_records('auth_lti_linked_login', null, 'id', 'id, userid, sub') as $b) {
        $bindings[$b->sub] = (int) $b->userid;
    }
    $task = $DB->get_record('task_scheduled', ['classname' => '\\local_ltiaccountmerge\\task\\apply_modes']);
    $audit = [];
    foreach ($DB->get_records('local_ltiaccountmerge_audit', ['action' => 'applymode'], 'id') as $r) {
        $audit[] = [(int) $r->targetid, $r->outcome, $r->detail];
    }
    $ltiusers = $DB->count_records('user', ['auth' => 'lti', 'deleted' => 0]);
    ltiam_write($argv[2], ['tools' => $tools, 'applied' => $applied, 'bindings' => $bindings, 'audit' => $audit,
        'faildelay' => (int) ($task->faildelay ?? -1), 'ltiusers' => $ltiusers]);
    exit(0);
}

if ($cmd === 'launch') {
    [, , $sub, $key, $seedfile] = $argv;
    $toolid = ltiam_tool($seedfile, $key);
    // As enrol/lti/launch.php: the mode is read from the published resource at launch time, then core logs the user in.
    $mode = (int) $DB->get_field('enrol_lti_tools', 'provisioningmodelearner', ['id' => $toolid]);
    $launch = ['iss' => $iss, 'sub' => $sub, 'aud' => 'client-sched',
        'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => '1',
        'https://purl.imsglobal.org/spec/lti/claim/roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner']];
    // A CLI process has no session id; core's login stores one session row per id, so give every probe its own.
    session_id(md5(uniqid('ltiam', true)));
    try {
        (new auth_plugin_lti())->complete_login($launch, new moodle_url('/'), $mode);
    } catch (\Throwable $e) {
        // In prompt modes core redirects to the binding dialog, which a CLI process cannot follow.
        if (getenv('LTIAM_DEBUG')) {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        }
    }
    if (!empty($SESSION->auth_lti)) {
        echo "prompt mode=$mode\n";
    } else {
        echo 'user=' . (int) ($USER->id ?? 0) . " mode=$mode\n";
    }
    exit(0);
}

if ($cmd === 'manual') {
    [, , $key, $mode, $seedfile] = $argv;
    \core\session\manager::set_user(get_admin());
    $tool = $DB->get_record('enrol_lti_tools', ['id' => ltiam_tool($seedfile, $key)], '*', MUST_EXIST);
    $instance = $DB->get_record('enrol', ['id' => $tool->enrolid], '*', MUST_EXIST);
    enrol_get_plugin('lti')->update_instance($instance, (object) ['toolid' => $tool->id, 'ltiversion' => $tool->ltiversion,
        'uuid' => $tool->uuid, 'provisioningmodelearner' => (int) $mode,
        'provisioningmodeinstructor' => $tool->provisioningmodeinstructor]);
    exit(0);
}

if ($cmd === 'policy') {
    // Usage: sched.php policy <toolkey> <utc start> <mode> <seed>: an open-ended window for one resource.
    [, , $key, $start, $mode, $seedfile] = $argv;
    $DB->insert_record('local_ltiaccountmerge_policy', (object) ['name' => 'race ' . $key, 'scope' => 'tool',
        'scopeid' => ltiam_tool($seedfile, $key), 'kind' => 'scheduled', 'priority' => 20, 'enabled' => 1, 'timezone' => 'UTC',
        'recurrence' => 'once', 'localstart' => gmdate('Y-m-d H:i:s', (int) $start), 'localend' => null,
        'modelearner' => (int) $mode, 'modeinstructor' => null, 'usermodified' => 0, 'timecreated' => time(),
        'timemodified' => time()]);
    exit(0);
}

if ($cmd === 'break') {
    // The course of the resource disappears from under it (the course row is kept aside for the repair): writing the
    // resource then fails inside core (enrol_instance_updated needs the course context, which needs the course).
    [, , $key, $seedfile] = $argv;
    $tool = $DB->get_record('enrol_lti_tools', ['id' => ltiam_tool($seedfile, $key)], '*', MUST_EXIST);
    $courseid = (int) $DB->get_field('enrol', 'courseid', ['id' => $tool->enrolid]);
    $DB->delete_records('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $courseid]);
    file_put_contents($seedfile . '.course', json_encode($DB->get_record('course', ['id' => $courseid])));
    $DB->delete_records('course', ['id' => $courseid]);
    exit(0);
}

if ($cmd === 'repair') {
    [, , $seedfile] = $argv;
    $DB->import_record('course', (object) json_decode(file_get_contents($seedfile . '.course'), true));
    \core\context_helper::create_instances(CONTEXT_COURSE);
    \core\context_helper::build_all_paths(false);
    exit(0);
}

fwrite(STDERR, "unknown command\n");
exit(2);
