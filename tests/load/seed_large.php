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
 * CLI seed for the {{COMPONENT}} JMeter load test.
 *
 * Creates a visible course with a {{PLUGINTITLE}} enrolment instance (open, high capacity) and prints
 * the base URL and course id as shell "export" lines for the JMeter plan. Idempotent enough for
 * repeated CI runs (it always creates a fresh course).
 *
 * @package    {{COMPONENT}}
 * @copyright  2026 {{AUTHOR}}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$now = time();
$course = create_course((object) [
    'fullname' => '{{PLUGINTITLE}} load ' . $now,
    'shortname' => 'flexload' . $now,
    'category' => 1,
    'visible' => 1,
]);

$plugin = enrol_get_plugin('{{PLUGINNAME}}');
$instanceid = $plugin->add_instance($course, [
    'status' => ENROL_INSTANCE_ENABLED,
    'customint1' => 0,
]);

echo "export BASE_URL='" . $CFG->wwwroot . "'\n";
echo "export COURSEID='" . $course->id . "'\n";
echo "export INSTANCEID='" . $instanceid . "'\n";
