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
 * Merge simulation (read-only) with report export.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\merge_simulator;

admin_externalpage_setup('local_ltiaccountmerge_simulate');
$context = context_system::instance();
require_capability('local/ltiaccountmerge:simulatemerge', $context);

$sourceid = optional_param('source', 0, PARAM_INT);
$targetid = optional_param('target', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/simulate.php'));

$form = new \local_ltiaccountmerge\form\simulate_form($PAGE->url);
if ($data = $form->get_data()) {
    $sourceid = (int) \core_user::get_user_by_username($data->source)->id;
    $targetid = (int) \core_user::get_user_by_username($data->target)->id;
    $download = '';
} else if ($download !== '') {
    require_sesskey();
}

$report = null;
if ($sourceid && $targetid) {
    try {
        $report = (new merge_simulator())->simulate($sourceid, $targetid);
    } catch (moodle_exception $e) {
        $sid = audit::new_support_id();
        audit::log('simulatemerge', audit::FAILED, 'merge', $sourceid, $targetid, ['error' => $e->errorcode], $sid);
        redirect(new moodle_url('/local/ltiaccountmerge/error.php', ['code' => 'mergeconflict', 'sid' => $sid]));
    }
    audit::log(
        'simulatemerge',
        $report->blocked ? audit::BLOCKED : audit::OK,
        'merge',
        $sourceid,
        $targetid,
        ['verdict' => $report->verdict, 'download' => $download]
    );
    \local_ltiaccountmerge\event\merge_simulated::create(['context' => $context, 'objectid' => $sourceid,
        'relateduserid' => $targetid, 'other' => ['verdict' => $report->verdict]])->trigger();
    if ($download === 'csv' || $download === 'json') {
        $body = $download === 'csv' ? merge_simulator::to_csv($report) : merge_simulator::to_json($report);
        send_file(
            $body,
            'merge-simulation-' . $sourceid . '-' . $targetid . '.' . $download,
            0,
            0,
            true,
            true,
            $download === 'csv' ? 'text/csv' : 'application/json'
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_simulate', 'local_ltiaccountmerge'));
echo html_writer::tag('p', get_string('simulate_intro', 'local_ltiaccountmerge'));
$form->display();
if ($report) {
    $base = ['source' => $sourceid, 'target' => $targetid, 'sesskey' => sesskey()];
    echo $PAGE->get_renderer('local_ltiaccountmerge')->merge_report($report, true, [
        'csv' => (new moodle_url($PAGE->url, $base + ['download' => 'csv']))->out(false),
        'json' => (new moodle_url($PAGE->url, $base + ['download' => 'json']))->out(false),
    ]);
}
echo $OUTPUT->footer();
