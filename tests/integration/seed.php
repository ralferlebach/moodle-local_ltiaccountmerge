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
 * Integration seed: real data of every category the merge preview must list, for several merge pairs.
 *
 * Usage: seed.php <out.json>
 *
 * Accounts (key => role): per pair "a<n>" is merged into "b<n>"; "c1" is a bystander that shares course, group, grade item,
 * quiz, activity, platform, tool scope and completion connection with a1 and must never be touched.
 *  pair1  a1 -> b1   main scenario: enrolment, group, grade, quiz attempt, activity and course completion, LTI binding,
 *                    pseudonym mapping, completion-sync outbox and receiver state, a pending link request; b1 has its own
 *                    binding of another platform and a pseudonym of another scope.
 *  pair2  a2 -> b2   conflicts: same platform (different subject), same pseudonym scope, same group, same outbox key.
 *  pair3  a3 -> b3   crash during the merge (rolled back).
 *  pair6  a6 -> b6   crash after the commit.
 *  pair4  a4 -> b4 and b4 -> c4: competing consents.
 *  pair7/8           two jobs executed in parallel.
 *  pair9  a9 -> b9   execution through cron (ad hoc task).
 *  pair5  a5 -> b5   LTI accounts merged directly in tool_mergeusers without consent (refused).
 *  pairx  x1 -> y1   ordinary accounts merged directly in tool_mergeusers (allowed).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The bootstrap (config.php of the private tree on a scratch database) is in lib.php.
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
require(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
// The 4.5 question generator loads lib/phpunit classes, which extend PHPUnit\Framework\TestCase.
if (!class_exists('PHPUnit\\Framework\\TestCase') && file_exists($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/auth/lti/auth.php');
require_once($CFG->libdir . '/completionlib.php');

$out = $argv[1] ?? '';
\core\session\manager::set_user(get_admin());
set_config('debug', DEBUG_DEVELOPER);
set_config('debugdisplay', 1);
set_config('noreplyaddress', 'noreply@example.org');
set_config('enablecompletion', 1);
$gen = new testing_data_generator();
$auth = new auth_plugin_lti();
$ids = [];
$iss1 = 'https://platform-one.example.org';
$iss2 = 'https://platform-two.example.org';
$ids['iss1'] = $iss1;
$ids['iss2'] = $iss2;

$users = [];
foreach (
    ['a1' => 'lti', 'b1' => 'manual', 'c1' => 'lti', 'a2' => 'lti', 'b2' => 'lti', 'a3' => 'lti', 'b3' => 'manual',
        'a4' => 'lti', 'b4' => 'manual', 'c4' => 'manual', 'a5' => 'lti', 'b5' => 'manual', 'a6' => 'lti', 'b6' => 'manual',
        'a7' => 'lti', 'b7' => 'manual', 'a8' => 'lti', 'b8' => 'manual', 'a9' => 'lti', 'b9' => 'manual', 'x1' => 'manual',
        'y1' => 'manual'] as $key => $method
) {
    $users[$key] = $gen->create_user(['username' => 'ltiam_' . $key, 'auth' => $method, 'firstname' => 'Int' . strtoupper($key),
        'lastname' => 'User', 'email' => "ltiam_$key@example.org"]);
    $ids['user_' . $key] = (int) $users[$key]->id;
}
$course1 = $gen->create_course(['shortname' => 'LTIAM1', 'fullname' => 'Merge course 1', 'enablecompletion' => 1]);
$course2 = $gen->create_course(['shortname' => 'LTIAM2', 'fullname' => 'Merge course 2', 'enablecompletion' => 1]);
$ids['course1'] = (int) $course1->id;
$ids['course2'] = (int) $course2->id;
foreach (['a1', 'c1', 'a2', 'b2', 'a3', 'a6', 'a7', 'a8', 'a9', 'a5', 'x1'] as $key) {
    $gen->enrol_user($users[$key]->id, $course1->id, 'student');
}
foreach (['b1', 'b3', 'b6'] as $key) {
    $gen->enrol_user($users[$key]->id, $course2->id, 'student');
}
$group = $gen->create_group(['courseid' => $course1->id, 'name' => 'Merge group']);
$ids['group1'] = (int) $group->id;
foreach (['a1', 'c1', 'a2', 'b2'] as $key) {
    $gen->create_group_member(['groupid' => $group->id, 'userid' => $users[$key]->id]);
}

// Grades of a manual grade item.
$item = $gen->create_grade_item(['courseid' => $course1->id, 'itemname' => 'Merge item']);
$ids['gradeitem'] = (int) $item->id;
foreach (['a1' => 80, 'c1' => 40, 'a2' => 70] as $key => $grade) {
    $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $users[$key]->id, 'rawgrade' => $grade,
        'finalgrade' => $grade, 'timecreated' => time(), 'timemodified' => time()]);
}

// Quiz attempts.
$quiz = $gen->create_module('quiz', ['course' => $course1->id, 'grade' => 10]);
$qgen = $gen->get_plugin_generator('core_question');
$category = $qgen->create_question_category();
$question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
quiz_add_quiz_question($question->id, $quiz, 0, 1);
\mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
$quizgen = $gen->get_plugin_generator('mod_quiz');
foreach (['a1', 'c1'] as $key) {
    // The generator starts the attempt for the current user.
    \core\session\manager::set_user($users[$key]);
    $quizgen->create_attempt($quiz->id, $users[$key]->id);
}
\core\session\manager::set_user(get_admin());
$ids['quiz'] = (int) $quiz->id;

// Activity and course completion.
$page = $gen->create_module('page', ['course' => $course1->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
$ids['pagecmid'] = (int) $page->cmid;
foreach (['a1', 'c1'] as $key) {
    $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $page->cmid, 'userid' => $users[$key]->id,
        'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time()]);
}
$DB->insert_record('course_completions', (object) ['userid' => $users['a1']->id, 'course' => $course1->id,
    'timeenrolled' => time(), 'timestarted' => time(), 'timecompleted' => time(), 'reaggregate' => 0]);

// LTI bindings (core API).
$bindings = ['a1' => [$iss1, 'sub-a1'], 'c1' => [$iss1, 'sub-c1'], 'b1' => [$iss2, 'sub-b1'], 'a2' => [$iss1, 'sub-a2'],
    'b2' => [$iss1, 'sub-b2'], 'a3' => [$iss2, 'sub-a3'], 'a4' => [$iss2, 'sub-a4'], 'a5' => [$iss2, 'sub-a5'],
    'a6' => [$iss2, 'sub-a6'], 'a7' => [$iss2, 'sub-a7'], 'a8' => [$iss2, 'sub-a8'], 'a9' => [$iss2, 'sub-a9']];
foreach ($bindings as $key => [$iss, $sub]) {
    $auth->create_user_binding($iss, $sub, (int) $users[$key]->id);
}

// Both platforms are registered (enrol_lti), as the link service requires a known issuer.
foreach ([$iss1 => 'Platform one', $iss2 => 'Platform two'] as $iss => $name) {
    $DB->insert_record('enrol_lti_app_registration', (object) ['name' => $name, 'platformid' => $iss,
        'clientid' => 'client-' . md5($iss), 'uniqueid' => md5($iss . 'u'),
        'platformclienthash' => hash('sha256', $iss . 'client-' . md5($iss)),
        'platformuniqueidhash' => hash('sha256', $iss . md5($iss . 'u')), 'authenticationrequesturl' => $iss . '/auth',
        'jwksurl' => $iss . '/jwks', 'accesstokenurl' => $iss . '/token', 'status' => 1, 'timecreated' => time(),
        'timemodified' => time()]);
}
// A pending link request aimed at a1 (cancelled by the merge) and one created by a1 (handed to b1).
set_config('userlinking', 1, 'local_ltiaccountmerge');
// The account a1 acts as support staff for the second request (capability to link identities), as in the release gate.
$roleid = create_role('Merge link support', 'ltiamlinksupport', 'Integration support role');
assign_capability('local/ltiaccountmerge:link', CAP_ALLOW, $roleid, context_system::instance()->id);
role_assign($roleid, $users['a1']->id, context_system::instance()->id);
$service = new \local_ltiaccountmerge\local\link_service();
$r1 = $service->create_request((int) get_admin()->id, $iss2, 'sub-r-a1', (int) $users['a1']->id);
$r2 = $service->create_request((int) $users['a1']->id, $iss2, 'sub-r-c1', (int) $users['c1']->id);
$ids['request_aimed_a1'] = (int) ($r1->requestid ?? 0);
$ids['request_by_a1'] = (int) ($r2->requestid ?? 0);
if (!$ids['request_aimed_a1'] || !$ids['request_by_a1']) {
    ltiam_info('link requests not created: ' . json_encode([$r1, $r2]));
    exit(1);
}

// Platform side: an external tool with pseudonyms (ltisource_ltipseudonym), when installed.
$typeid = $gen->get_plugin_generator('mod_lti')->create_tool_types(['name' => 'Merge tool', 'baseurl' =>
    'https://tool.example.org/lti/launch', 'state' => LTI_TOOL_STATE_CONFIGURED, 'lti_resourcekey' => 'k', 'lti_password' => 's']);
$ids['ltitype'] = (int) $typeid;
$ids['pseudonyms'] = [];
if (class_exists('\\ltisource_ltipseudonym\\local\\token_service')) {
    $tokens = new \ltisource_ltipseudonym\local\token_service();
    foreach (['a1' => $course1, 'c1' => $course1, 'b1' => $course2, 'a2' => $course1, 'b2' => $course1] as $key => $course) {
        $ids['pseudonyms'][$key] = $tokens->get_or_create(
            (int) $users[$key]->id,
            (int) $typeid,
            'course',
            (int) $course->id,
            'random_persisted'
        );
    }
}

// Tool side: completion-sync outbox and receiver state (local_lticompletionsync), when installed.
$ids['completionsync'] = false;
if ($DB->get_manager()->table_exists('local_lticompletionsync_out')) {
    $now = time();
    $con = $DB->insert_record('local_lticompletionsync_con', (object) ['role' => 'sender', 'enabled' => 1, 'ltitypeid' => 0,
        'registrationid' => 1, 'deploymentid' => 1, 'issuer' => $iss1, 'clientid' => 'c', 'automanage' => 0, 'lockdown' => 1,
        'conflictpolicy' => 'provider', 'agsprogress' => 0, 'partnerstatus' => 'unknown', 'timechecked' => 0,
        'usermodified' => get_admin()->id, 'timecreated' => $now, 'timemodified' => $now]);
    foreach (['a1' => 1, 'c1' => 0, 'a2' => 1, 'b2' => 0] as $key => $state) {
        $DB->insert_record('local_lticompletionsync_out', (object) ['connectionid' => $con, 'resourcelinkid' => 11,
            'toolid' => 1, 'userid' => $users[$key]->id, 'sourcekind' => 'course', 'sourceid' => $course1->id,
            'desiredstate' => $state, 'version' => 1, 'statetime' => $now, 'correlationid' => sprintf('%036d', $users[$key]->id),
            'status' => 'pending', 'sentversion' => 0, 'ackversion' => 0, 'attempts' => 0, 'nextattempt' => $now,
            'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('local_lticompletionsync_rcv', (object) ['connectionid' => $con, 'cmid' => $page->cmid,
            'userid' => $users[$key]->id, 'remoteversion' => 1, 'remotestate' => $state, 'remotetime' => $now,
            'appliedversion' => 1, 'status' => 'applied', 'correlationid' => sprintf('%036d', $users[$key]->id),
            'timecreated' => $now, 'timemodified' => $now]);
    }
    $ids['completionsync'] = true;
    $ids['csconnection'] = (int) $con;
}

$plugins = [];
foreach (
    ['local_ltiaccountmerge', 'ltisource_ltipseudonym', 'local_lticompletionsync', 'local_ltiidentity', 'local_ltixsembed',
        'tool_ltidiagnostics', 'tool_ltigroupautoenrol', 'tool_mergeusers'] as $component
) {
    $info = core_plugin_manager::instance()->get_plugin_info($component);
    $plugins[$component] = $info ? ['version' => $info->versiondb, 'release' => $info->release] : null;
}
ltiam_write($out, ['ids' => $ids, 'plugins' => $plugins, 'moodle' => $CFG->release]);
ltiam_info('seeded ' . count($users) . ' accounts; plugins: ' . json_encode($plugins));
exit(0);
