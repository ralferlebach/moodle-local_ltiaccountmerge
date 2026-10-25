<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI seed for the {{COMPONENT}} Playwright tests.
 *
 * Creates a course with an anonymous-temporary {{PLUGINTITLE}} instance and prints the base URL, course
 * id and course name as shell "export" lines so the CI runner can source them.
 *
 * @package    {{COMPONENT}}
 * @copyright  2026 {{AUTHOR}}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

// A presentable name: the screenshots of a green run are used as illustrations.
$coursename = 'My favourite course';
$category = \core_course_category::get_default();
$course = create_course((object) [
    'fullname' => $coursename,
    // Unique by construction: two seeds started within the same second would otherwise
    // collide on the shortname and the course could not be created.
    'shortname' => uniqid('FAPW', true),
    'category' => $category->id,
    'visible' => 1,
]);

set_config('allowwidening', 1, '{{COMPONENT}}');
$plugin = enrol_get_plugin('{{PLUGINNAME}}');
$enrolid = $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
\{{COMPONENT}}\local\instance_config::save($enrolid, [
    'allowtemporary' => 1,
    'allowquick' => 1,
    'maxparticipants' => 0,
]);

// The browser journey cannot read verification email, so persistence is immediate on this test
// site, and the {{PLUGINTITLE}} auth method must be enabled so converted accounts can log in again.
set_config('requireemailverification', 0, '{{COMPONENT}}');
$enabledauths = get_enabled_auth_plugins();
if (!in_array('{{PLUGINNAME}}', $enabledauths, true)) {
    $enabledauths[] = '{{PLUGINNAME}}';
    set_config('auth', implode(',', $enabledauths));
}

echo "export FLEXACCESS_BASE_URL='" . $CFG->wwwroot . "'\n";
echo "export FLEXACCESS_COURSE_ID='" . $course->id . "'\n";
echo "export FLEXACCESS_COURSE_NAME='" . $coursename . "'\n";
// A manager account for the authenticated accessibility checks of the plugin's own administration
// pages. Created on a throwaway CI site only; the specs skip when the variables are absent.
$adminuser = $DB->get_record('user', ['username' => 'flexa11y'], '*', IGNORE_MISSING);
if (!$adminuser) {
    $adminuser = \core_user::get_user(
        user_create_user((object) [
            'username' => 'flexa11y',
            'password' => 'Flex-A11y-Pass!1',
            'firstname' => 'Access',
            'lastname' => 'Checker',
            'email' => 'flexa11y@example.invalid',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
        ], true, false)
    );
}
role_assign(
    $DB->get_field('role', 'id', ['shortname' => 'manager']),
    $adminuser->id,
    \context_system::instance()->id
);

// Deliberately NOT called FLEXACCESS_ADMIN_*: this is a manager, not the site administrator.
// Overriding the admin credentials with it denied the specs access to /admin pages that require
// moodle/site:config.
echo "export FLEXACCESS_MANAGER_USER='flexa11y'\n";
echo "export FLEXACCESS_MANAGER_PASS='Flex-A11y-Pass!1'\n";
