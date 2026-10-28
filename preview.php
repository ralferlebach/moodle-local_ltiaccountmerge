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
 * Preview the effective provisioning mode of every published resource at any instant.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ltiaccountmerge\local\modes;
use local_ltiaccountmerge\local\resolver;

admin_externalpage_setup('local_ltiaccountmerge_preview');
require_capability('local/ltiaccountmerge:managepolicy', context_system::instance());
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/preview.php'));

$form = new \local_ltiaccountmerge\form\preview_form($PAGE->url);
$ts = time();
$tzname = \core_date::get_server_timezone();
if ($data = $form->get_data()) {
    $tzname = $data->timezone;
    if ($data->localtime !== '') {
        $ts = \local_ltiaccountmerge\form\preview_form::to_timestamp($data->localtime, $tzname);
    }
}
$resolver = resolver::from_db();
$tools = $DB->get_records_sql(
    "SELECT t.id, t.provisioningmodelearner, t.provisioningmodeinstructor, e.courseid, e.name AS enrolname, c.fullname
       FROM {enrol_lti_tools} t
       JOIN {enrol} e ON e.id = t.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE t.ltiversion = :v
   ORDER BY c.fullname, t.id",
    ['v' => 'LTI-1p3']
);
$rows = [];
foreach ($tools as $tool) {
    $coreinstructor = $tool->provisioningmodeinstructor === null ? null : (int) $tool->provisioningmodeinstructor;
    $eff = $resolver->effective((int) $tool->id, (int) $tool->courseid, $ts);
    $line = function (array $e) {
        return [modes::name($e['mode']), $e['policy'] ? s($e['policy']->name) : '-'];
    };
    [$learner, $learnerpolicy] = $line($eff[resolver::LEARNER]);
    [$instructor, $instructorpolicy] = $line($eff[resolver::INSTRUCTOR]);
    $rows[] = [
        'toolname' => s($tool->enrolname !== '' && $tool->enrolname !== null ? $tool->enrolname : '#' . $tool->id),
        'coursename' => format_string($tool->fullname),
        'learner' => $learner, 'learnerpolicy' => $learnerpolicy,
        'instructor' => $instructor, 'instructorpolicy' => $instructorpolicy,
        'corelearner' => modes::name($tool->provisioningmodelearner === null ? null : (int) $tool->provisioningmodelearner),
        'coreinstructor' => modes::name($coreinstructor),
    ];
}
$instant = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($tzname))->format('Y-m-d H:i:s T (e)');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_preview', 'local_ltiaccountmerge'));
$form->display();
echo $PAGE->get_renderer('local_ltiaccountmerge')->mode_preview([
    'instant' => $instant,
    'hasrows' => !empty($rows),
    'rows' => $rows,
]);
echo $OUTPUT->footer();
