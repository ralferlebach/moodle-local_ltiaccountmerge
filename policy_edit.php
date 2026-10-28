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
 * Create or edit a provisioning policy.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ltiaccountmerge_policies');
require_capability('local/ltiaccountmerge:managepolicy', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$kind = optional_param('kind', 'scheduled', PARAM_ALPHA);
$returnurl = new moodle_url('/local/ltiaccountmerge/policies.php');
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/policy_edit.php', ['id' => $id]));

$form = new \local_ltiaccountmerge\form\policy_form($PAGE->url);
if ($id) {
    $record = $DB->get_record('local_ltiaccountmerge_policy', ['id' => $id], '*', MUST_EXIST);
    $form->set_data($record);
} else {
    $form->set_data((object) ['kind' => $kind === 'override' ? 'override' : 'scheduled',
        'localstart' => $kind === 'override' ? gmdate('Y-m-d H:i:s') : '',
        'timezone' => $kind === 'override' ? 'UTC' : \core_date::get_server_timezone(),
        'priority' => $kind === 'override' ? 100 : 0]);
}

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $now = time();
    $row = (object) [
        'name' => $data->name,
        'scope' => $data->scope,
        'scopeid' => $data->scope === 'site' ? 0 : (int) $data->scopeid,
        'kind' => $data->kind,
        'priority' => (int) $data->priority,
        'enabled' => (int) $data->enabled,
        'timezone' => $data->timezone,
        'recurrence' => $data->recurrence,
        'localstart' => $data->localstart === '' ? null : $data->localstart,
        'localend' => $data->localend === '' ? null : $data->localend,
        'modelearner' => $data->modelearner === '' ? null : (int) $data->modelearner,
        'modeinstructor' => $data->modeinstructor === '' ? null : (int) $data->modeinstructor,
        'usermodified' => $USER->id,
        'timemodified' => $now,
    ];
    if ($id) {
        $row->id = $id;
        $DB->update_record('local_ltiaccountmerge_policy', $row);
        $action = 'updated';
    } else {
        $row->timecreated = $now;
        $id = $DB->insert_record('local_ltiaccountmerge_policy', $row);
        $action = 'created';
    }
    \local_ltiaccountmerge\local\audit::log(
        'policy' . $action,
        \local_ltiaccountmerge\local\audit::OK,
        'policy',
        $id,
        0,
        ['name' => $row->name, 'scope' => $row->scope, 'scopeid' => $row->scopeid, 'kind' => $row->kind]
    );
    \local_ltiaccountmerge\event\policy_changed::create(['context' => context_system::instance(), 'objectid' => $id,
        'other' => ['action' => $action]])->trigger();
    (new \local_ltiaccountmerge\local\mode_applier())->apply_all(time(), 'manual');
    redirect($returnurl, get_string('policysaved', 'local_ltiaccountmerge'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($id ? 'editpolicy' : 'addpolicy', 'local_ltiaccountmerge'));
$form->display();
echo $OUTPUT->footer();
