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
 * Multi-process check: many PHP processes run the pre-launch apply at the instant a policy window opens.
 *
 * Expected: all processes finish, exactly one of them writes the new mode (one audit row, one enrol_instance_updated), every
 * process afterwards reads the new mode, the uuid is unchanged, and after the window the baseline is back (once).
 *
 * Disposable development sites only: it creates a course, a published resource and a policy and removes them again.
 * Usage: php apply_race.php [processes]
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true); // phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState

// The plugin lives in <root>/local/ltiaccountmerge (4.5) or <root>/public/local/ltiaccountmerge (5.x).
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
$root = is_readable(__DIR__ . '/../../../../config.php') ? __DIR__ . '/../../../..' : __DIR__ . '/../../../../..';
require($root . '/config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_ltiaccountmerge\local\mode_applier;

$procs = 8;
$worker = false;
$toolid = 0;
$start = 0.0;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--worker=') === 0) {
        $worker = true;
        [$toolid, $start] = array_map('floatval', explode(',', substr($arg, 9)));
        $toolid = (int) $toolid;
    } else {
        $procs = max(2, (int) $arg);
    }
}

if ($worker) {
    // Spin until the common instant, then do what the after_config callback does on a launch request.
    while (microtime(true) < $start) {
        usleep(200);
    }
    (new mode_applier(null, null, 10))->apply_all(null, 'prelaunch');
    echo (int) $DB->get_field('enrol_lti_tools', 'provisioningmodelearner', ['id' => $toolid]), "\n";
    exit(0);
}

// Setup: a course, a published LTI 1.3 resource (learner mode 2) and a policy that opens in about 4 seconds.
$unique = random_int(1, 999999);
$course = create_course((object) [
    'fullname' => 'ltiam race ' . $unique,
    'shortname' => 'ltiamrace' . $unique,
    'category' => $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}'),
]);
$plugin = enrol_get_plugin('lti');
$instanceid = $plugin->add_instance($course, [
    'name' => 'ltiam race', 'contextid' => context_course::instance($course->id)->id, 'ltiversion' => 'LTI-1p3',
    'status' => ENROL_INSTANCE_ENABLED, 'provisioningmodelearner' => 2, 'provisioningmodeinstructor' => 2,
    'roleinstructor' => $DB->get_field('role', 'id', ['shortname' => 'teacher']),
    'rolelearner' => $DB->get_field('role', 'id', ['shortname' => 'student']),
]);
$tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $instanceid], '*', MUST_EXIST);
$opens = time() + 4;
$policyid = $DB->insert_record('local_ltiaccountmerge_policy', (object) [
    'name' => 'race', 'scope' => 'tool', 'scopeid' => $tool->id, 'kind' => 'scheduled', 'priority' => 0, 'enabled' => 1,
    'timezone' => 'UTC', 'recurrence' => 'once', 'localstart' => gmdate('Y-m-d H:i:s', $opens),
    'localend' => gmdate('Y-m-d H:i:s', $opens + 3600), 'modelearner' => 3, 'modeinstructor' => null,
    'usermodified' => 0, 'timecreated' => time(), 'timemodified' => time(),
]);
$auditbefore = $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'applymode', 'targetid' => $tool->id]);

$begin = $opens + 0.0;
$children = [];
for ($i = 0; $i < $procs; $i++) {
    $cmd = [PHP_BINARY, __FILE__, '--worker=' . $tool->id . ',' . $begin];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $children[] = [$proc, $pipes];
}
$seen = [];
$failed = 0;
foreach ($children as [$proc, $pipes]) {
    $seen[] = trim(stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    if (proc_close($proc) !== 0) {
        $failed++;
    }
}
$mode = (int) $DB->get_field('enrol_lti_tools', 'provisioningmodelearner', ['id' => $tool->id]);
$uuid = $DB->get_field('enrol_lti_tools', 'uuid', ['id' => $tool->id]);
$auditwhere = ['action' => 'applymode', 'outcome' => 'success', 'targetid' => $tool->id];
$writes = $DB->count_records('local_ltiaccountmerge_audit', $auditwhere) - $auditbefore;

// The window closes: one more run restores the baseline, once.
$DB->set_field('local_ltiaccountmerge_policy', 'localend', gmdate('Y-m-d H:i:s', time() - 1), ['id' => $policyid]);
(new mode_applier())->apply_all(null, 'task');
(new mode_applier())->apply_all(null, 'task');
$restored = (int) $DB->get_field('enrol_lti_tools', 'provisioningmodelearner', ['id' => $tool->id]);
$totalwrites = $DB->count_records('local_ltiaccountmerge_audit', $auditwhere) - $auditbefore;
$uuidafter = $DB->get_field('enrol_lti_tools', 'uuid', ['id' => $tool->id]);

// Cleanup.
$DB->delete_records('local_ltiaccountmerge_policy', ['id' => $policyid]);
$DB->delete_records('local_ltiaccountmerge_applied', ['toolid' => $tool->id]);
$DB->delete_records('local_ltiaccountmerge_audit', ['action' => 'applymode', 'targetid' => $tool->id]);
delete_course($course->id, false);

echo "processes: $procs, failed: $failed, modes read after the run: " . implode(',', array_unique($seen)) . "\n";
echo "mode after opening: $mode, writes while opening: $writes, mode after closing: $restored, total writes: $totalwrites\n";
$ok = $failed === 0 && $mode === 3 && $writes === 1 && array_unique($seen) === ['3'] && $restored === 2 && $totalwrites === 2
    && $uuid === $uuidafter;
echo $ok ? "PASS\n" : "FAIL\n";
exit($ok ? 0 : 1);
