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
 * Reverse LTI registration on the two-site stack for the first-launch stories with Moodle 4.5 as the TOOL:
 * site B (Moodle 5.3) becomes the platform, site A (Moodle 4.5) publishes a resource. Everything created here is removed
 * by the cleanup stages; site settings that are changed are restored.
 *
 * Run through the stack's CLI wrapper (cwd = tree root), always under the stack lock:
 *   moodle_cli b <this> --stage=platform1 --state=<file> --a=<url of A> --b=<url of B>
 *   moodle_cli a <this> --stage=tool --state=<file>                     (needs platform1)
 *   moodle_cli b <this> --stage=platform2 --state=<file>                (needs tool)
 *   moodle_cli a <this> --stage=cleanup-tool --state=<file>
 *   moodle_cli b <this> --stage=cleanup-platform --state=<file>
 *   moodle_cli a|b <this> --stage=existing [--username=<name>]          (re)create a manual account, print it as JSON
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true); // phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
require(getcwd() . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

use enrol_lti\local\ltiadvantage\entity\application_registration;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\context_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use enrol_lti\local\ltiadvantage\repository\resource_link_repository;
use enrol_lti\local\ltiadvantage\repository\user_repository;
use enrol_lti\local\ltiadvantage\service\application_registration_service;

[$o] = cli_get_params(['stage' => '', 'state' => '', 'a' => 'https://a.platform-a.test', 'b' => 'https://b.tool-b.test',
    'username' => 'ltiamexisting'], []);
$statefile = $o['state'];
$state = is_readable($statefile) ? (json_decode(file_get_contents($statefile), true) ?: []) : [];
$save = function () use (&$state, $statefile) {
    file_put_contents($statefile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
\core\session\manager::set_user(get_admin());
/** Course of the platform side. */
const LTIAM_REV_COURSE_PLATFORM = 'LTIAMREVP';
/** Course of the tool side. */
const LTIAM_REV_COURSE_TOOL = 'LTIAMREVT';
/** Name of the tool type on the platform. */
const LTIAM_REV_TYPE = 'LTIAM reverse tool (4.5)';

/**
 * Create a fresh manual account (removing an earlier one with the same name).
 *
 * @param string $username User name.
 * @param string $password Password.
 * @param string $first First name.
 * @return stdClass
 */
function ltiam_rev_user(string $username, string $password, string $first): stdClass {
    global $DB, $CFG;
    if ($old = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
        delete_user($old);
    }
    $id = user_create_user((object) ['username' => $username, 'password' => $password, 'firstname' => $first,
        'lastname' => 'Reverse', 'email' => $username . '@example.com', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'lang' => 'en'], true, false);
    return $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
}

/**
 * Delete a course by short name if it exists.
 *
 * @param string $shortname Short name.
 * @return void
 */
function ltiam_rev_drop_course(string $shortname): void {
    global $DB;
    if ($course = $DB->get_record('course', ['shortname' => $shortname])) {
        delete_course($course, false);
    }
}

switch ($o['stage']) {
    case 'platform1':
        // Site B as platform: course, learners, a tool type for site A.
        $tool = rtrim($o['a'], '/');
        ltiam_rev_drop_course(LTIAM_REV_COURSE_PLATFORM);
        foreach ($DB->get_records('lti_types', ['name' => LTIAM_REV_TYPE]) as $type) {
            lti_delete_type($type->id);
        }
        $course = create_course((object) ['fullname' => 'LTIAM reverse platform', 'shortname' => LTIAM_REV_COURSE_PLATFORM,
            'category' => 1, 'format' => 'topics', 'numsections' => 1]);
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $learners = [];
        foreach (['revlearner1', 'revlearner2', 'revlearner3', 'revlearner4'] as $i => $username) {
            $password = 'E2e!Pass-' . $username . '-2026';
            $user = ltiam_rev_user($username, $password, 'Rev' . ($i + 1));
            $manual->enrol_user($instance, $user->id, $DB->get_field('role', 'id', ['shortname' => 'student']));
            $learners[] = ['username' => $username, 'password' => $password, 'id' => (int) $user->id,
                'email' => $user->email, 'firstname' => $user->firstname];
        }
        $typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED], (object) [
            'lti_typename' => LTIAM_REV_TYPE, 'lti_toolurl' => "$tool/enrol/lti/launch.php", 'lti_description' => 'reverse',
            'lti_ltiversion' => LTI_VERSION_1P3, 'lti_keytype' => LTI_JWK_KEYSET, 'lti_publickeyset' => "$tool/enrol/lti/jwks.php",
            'lti_initiatelogin' => "$tool/enrol/lti/login.php",
            'lti_redirectionuris' => "$tool/enrol/lti/launch.php\n$tool/enrol/lti/launch_deeplink.php",
            'lti_contentitem' => 0, 'lti_coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED, 'lti_sendname' => LTI_SETTING_ALWAYS,
            'lti_sendemailaddr' => LTI_SETTING_ALWAYS, 'lti_acceptgrades' => LTI_SETTING_NEVER,
            'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW, 'lti_customparameters' => '',
        ]);
        $type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
        $state['platform'] = ['url' => $CFG->wwwroot, 'courseid' => (int) $course->id, 'typeid' => (int) $typeid,
            'clientid' => $type->clientid, 'learners' => $learners];
        $save();
        echo "platform1 ok: type $typeid\n";
        break;

    case 'tool':
        // Site A as tool: registration of platform B, a published course resource, the settings a tool site needs.
        $p = $state['platform'];
        $state['toolconfig'] = ['allowframembedding' => get_config('moodle', 'allowframembedding'),
            'enrollti' => (int) in_array('lti', explode(',', (string) $CFG->enrol_plugins_enabled), true),
            'authlti' => (int) is_enabled_auth('lti')];
        set_config('allowframembedding', 1);
        \core\plugininfo\enrol::enable_plugin('lti', 1);
        \core\plugininfo\auth::enable_plugin('lti', 1);
        ltiam_rev_drop_course(LTIAM_REV_COURSE_TOOL);
        $repo = new application_registration_repository();
        foreach ($repo->find_all() as $reg) {
            if ($reg->get_platformid()->out(false) === $p['url']) {
                (new application_registration_service(
                    $repo,
                    new deployment_repository(),
                    new resource_link_repository(),
                    new context_repository(),
                    new user_repository()
                ))->delete_application_registration($reg->get_id());
            }
        }
        $reg = application_registration::create(
            'LTIAM reverse platform (5.3)',
            bin2hex(random_bytes(20)),
            new moodle_url($p['url']),
            $p['clientid'],
            new moodle_url($p['url'] . '/mod/lti/auth.php'),
            new moodle_url($p['url'] . '/mod/lti/certs.php'),
            new moodle_url($p['url'] . '/mod/lti/token.php')
        );
        $reg = $repo->save($reg);
        (new deployment_repository())->save($reg->add_tool_deployment('LTIAM reverse deployment', (string) $p['typeid']));
        $course = create_course((object) ['fullname' => 'LTIAM reverse tool', 'shortname' => LTIAM_REV_COURSE_TOOL,
            'category' => 1, 'format' => 'topics', 'numsections' => 1]);
        $plugin = enrol_get_plugin('lti');
        $instanceid = $plugin->add_instance($course, ['name' => 'LTIAM reverse resource', 'courseid' => $course->id,
            'contextid' => context_course::instance($course->id)->id, 'status' => ENROL_INSTANCE_ENABLED,
            'ltiversion' => 'LTI-1p3', 'roleinstructor' => $DB->get_field('role', 'id', ['shortname' => 'teacher']),
            'rolelearner' => $DB->get_field('role', 'id', ['shortname' => 'student']), 'cmid' => 0,
            'provisioningmodelearner' => 1, 'provisioningmodeinstructor' => 2, 'gradesync' => 0, 'gradesynccompletion' => 0,
            'membersync' => 0, 'membersyncmode' => 1, 'maxenrolled' => 0]);
        $tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $instanceid], '*', MUST_EXIST);
        $existing = ltiam_rev_user('revexisting', 'E2e!Pass-revexisting-2026', 'Existing');
        $state['tool'] = ['url' => $CFG->wwwroot, 'courseid' => (int) $course->id, 'toolid' => (int) $tool->id,
            'uuid' => $tool->uuid, 'registrationid' => (int) $reg->get_id(), 'uniqueid' => $reg->get_uniqueid(),
            'loginurl' => $CFG->wwwroot . '/enrol/lti/login.php?id=' . $reg->get_uniqueid(),
            'existing' => ['username' => 'revexisting', 'password' => 'E2e!Pass-revexisting-2026', 'id' => (int) $existing->id]];
        $save();
        echo "tool ok: uuid {$tool->uuid}\n";
        break;

    case 'platform2':
        // Site B: the login URL of the type points at A's registration; the activity carries A's resource uuid.
        $p = $state['platform'];
        $t = $state['tool'];
        $DB->set_field('lti_types_config', 'value', $t['loginurl'], ['typeid' => $p['typeid'], 'name' => 'initiatelogin']);
        $course = $DB->get_record('course', ['id' => $p['courseid']], '*', MUST_EXIST);
        $cm = add_moduleinfo((object) ['modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']),
            'course' => $course->id, 'section' => 1, 'visible' => 1, 'name' => 'Launch reverse tool',
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0], 'showdescription' => 0,
            'typeid' => $p['typeid'], 'toolurl' => $t['url'] . '/enrol/lti/launch.php', 'securetoolurl' => '',
            'instructorcustomparameters' => 'id=' . $t['uuid'], 'launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
            'instructorchoicesendname' => 1, 'instructorchoicesendemailaddr' => 1, 'instructorchoiceacceptgrades' => 0,
            'instructorchoiceallowroster' => 0, 'instructorchoiceallowsetting' => 0, 'grade' => 0, 'resourcekey' => '',
            'password' => '', 'cmidnumber' => 'ltiam-rev', 'visibleoncoursepage' => 1, 'completion' => 0], $course);
        $state['platform']['cmid'] = (int) $cm->coursemodule;
        $state['platform']['viewurl'] = $CFG->wwwroot . '/mod/lti/view.php?id=' . $cm->coursemodule;
        $save();
        echo "platform2 ok: cmid {$cm->coursemodule}\n";
        break;

    case 'existing':
        // A manual account on this site with a known password (target of "use existing account"); printed as JSON.
        $username = clean_param($o['username'], PARAM_USERNAME);
        $user = ltiam_rev_user($username, 'E2e!Pass-' . $username . '-2026', 'Existing');
        echo json_encode(['username' => $username, 'password' => 'E2e!Pass-' . $username . '-2026', 'id' => (int) $user->id]),
            "\n";
        break;

    case 'cleanup-tool':
        // Site A: resource, registration, the accounts provisioned by the reverse launches, settings.
        $p = $state['platform'] ?? [];
        ltiam_rev_drop_course(LTIAM_REV_COURSE_TOOL);
        $repo = new application_registration_repository();
        foreach ($repo->find_all() as $reg) {
            if (!empty($p['url']) && $reg->get_platformid()->out(false) === $p['url']) {
                (new application_registration_service(
                    $repo,
                    new deployment_repository(),
                    new resource_link_repository(),
                    new context_repository(),
                    new user_repository()
                ))->delete_application_registration($reg->get_id());
            }
        }
        if (!empty($p['url'])) {
            $iss = hash('sha256', $p['url']);
            foreach ($DB->get_records('auth_lti_linked_login', ['issuer256' => $iss]) as $binding) {
                $user = $DB->get_record('user', ['id' => $binding->userid]);
                if ($user && $user->auth === 'lti') {
                    delete_user($user);
                }
            }
            $DB->delete_records('auth_lti_linked_login', ['issuer256' => $iss]);
        }
        if ($old = $DB->get_record('user', ['username' => 'revexisting'])) {
            delete_user($old);
        }
        if (isset($state['toolconfig'])) {
            set_config('allowframembedding', $state['toolconfig']['allowframembedding']);
            \core\plugininfo\enrol::enable_plugin('lti', (int) $state['toolconfig']['enrollti']);
            \core\plugininfo\auth::enable_plugin('lti', (int) $state['toolconfig']['authlti']);
        }
        echo "cleanup-tool ok\n";
        break;

    case 'cleanup-platform':
        ltiam_rev_drop_course(LTIAM_REV_COURSE_PLATFORM);
        foreach ($DB->get_records('lti_types', ['name' => LTIAM_REV_TYPE]) as $type) {
            lti_delete_type($type->id);
        }
        foreach (['revlearner1', 'revlearner2', 'revlearner3', 'revlearner4'] as $username) {
            if ($old = $DB->get_record('user', ['username' => $username])) {
                delete_user($old);
            }
        }
        echo "cleanup-platform ok\n";
        break;

    default:
        cli_error('unknown stage');
}
