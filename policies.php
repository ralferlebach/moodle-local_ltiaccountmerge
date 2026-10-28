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
 * List, delete and navigate provisioning policies.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ltiaccountmerge_policies');
require_capability('local/ltiaccountmerge:managepolicy', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$url = new moodle_url('/local/ltiaccountmerge/policies.php');
$PAGE->set_url($url);

if ($action === 'delete' && $id) {
    $policy = $DB->get_record('local_ltiaccountmerge_policy', ['id' => $id], '*', MUST_EXIST);
    if ($confirm && confirm_sesskey()) {
        $DB->delete_records('local_ltiaccountmerge_policy', ['id' => $id]);
        \local_ltiaccountmerge\local\audit::log(
            'policydelete',
            \local_ltiaccountmerge\local\audit::OK,
            'policy',
            $id,
            0,
            ['name' => $policy->name]
        );
        \local_ltiaccountmerge\event\policy_changed::create(['context' => context_system::instance(), 'objectid' => $id,
            'other' => ['action' => 'deleted']])->trigger();
        // Bring the resources in line right away instead of waiting for the next task run.
        (new \local_ltiaccountmerge\local\mode_applier())->apply_all(time(), 'manual');
        redirect($url, get_string('policydeleted', 'local_ltiaccountmerge'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletepolicy', 'local_ltiaccountmerge', s($policy->name)),
        new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}

$policies = $DB->get_records('local_ltiaccountmerge_policy', null, 'kind DESC, priority DESC, id DESC');
$table = new html_table();
$table->attributes['class'] = 'generaltable table-sm';
$table->caption = get_string('page_policies', 'local_ltiaccountmerge');
$table->head = [
    get_string('policy_name', 'local_ltiaccountmerge'),
    get_string('policy_kind', 'local_ltiaccountmerge'),
    get_string('policy_scope', 'local_ltiaccountmerge'),
    get_string('policy_priority', 'local_ltiaccountmerge'),
    get_string('policy_window', 'local_ltiaccountmerge'),
    get_string('policy_modelearner', 'local_ltiaccountmerge'),
    get_string('policy_modeinstructor', 'local_ltiaccountmerge'),
    get_string('policy_enabled', 'local_ltiaccountmerge'),
    get_string('actions'),
];
foreach ($policies as $record) {
    $p = \local_ltiaccountmerge\local\policy::from_record($record);
    $window = ($p->localstart ?? '…') . ' → ' . ($p->localend ?? '…') . ' (' . $p->timezone . ', ' .
        get_string('rec_' . $p->recurrence, 'local_ltiaccountmerge') . ')';
    $scope = get_string('scope_' . $p->scope, 'local_ltiaccountmerge') . ($p->scope === 'site' ? '' : ' #' . $p->scopeid);
    $edit = html_writer::link(new moodle_url('/local/ltiaccountmerge/policy_edit.php', ['id' => $p->id]), get_string('edit'));
    $delete = html_writer::link(new moodle_url($url, ['action' => 'delete', 'id' => $p->id]), get_string('delete'));
    $table->data[] = [
        s($p->name),
        get_string('kind_' . $p->kind, 'local_ltiaccountmerge'),
        s($scope),
        $p->priority,
        s($window),
        \local_ltiaccountmerge\local\modes::name($p->modelearner),
        \local_ltiaccountmerge\local\modes::name($p->modeinstructor),
        $p->enabled ? get_string('yes') : get_string('no'),
        $edit . ' | ' . $delete,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_policies', 'local_ltiaccountmerge'));
echo html_writer::tag('p', get_string('policies_intro', 'local_ltiaccountmerge'));
echo $OUTPUT->single_button(
    new moodle_url('/local/ltiaccountmerge/policy_edit.php'),
    get_string('addpolicy', 'local_ltiaccountmerge'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/local/ltiaccountmerge/policy_edit.php', ['kind' => 'override']),
    get_string('addoverride', 'local_ltiaccountmerge'),
    'get'
);
if ($policies) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nopolicies', 'local_ltiaccountmerge'), 'info');
}
echo $OUTPUT->footer();
