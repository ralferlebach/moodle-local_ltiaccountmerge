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
 * Reviewed account merge (audit item AR-03): preview (dry run), explicit consent, job status.
 *
 * The merge itself is executed by tool_mergeusers in an ad hoc task ({@see \local_ltiaccountmerge\task\execute_merge});
 * this page never changes user data. Without tool_mergeusers the page shows the reduced function (preview "unavailable";
 * the read-only simulation stays available).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\merge\executor;
use local_ltiaccountmerge\local\merge_preview;

admin_externalpage_setup('local_ltiaccountmerge_merge');
$context = context_system::instance();
require_capability('local/ltiaccountmerge:merge', $context);
if (merge_preview::tool_available()) {
    // Whoever may start a merge here must also be allowed to merge in the merge tool itself.
    require_capability('tool/mergeusers:mergeusers', $context);
}

$sourceid = optional_param('source', 0, PARAM_INT);
$targetid = optional_param('target', 0, PARAM_INT);
$jobid = optional_param('job', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$url = new moodle_url('/local/ltiaccountmerge/merge.php');
$PAGE->set_url($url);
$s = 'local_ltiaccountmerge';
$executor = new executor();
$renderer = $PAGE->get_renderer('local_ltiaccountmerge');

if ($action === 'cancel' && $jobid) {
    require_sesskey();
    $done = $executor->cancel($jobid, (int) $USER->id);
    redirect(
        new moodle_url($url, ['job' => $jobid]),
        get_string($done ? 'merge_cancelled' : 'merge_notcancelled', $s),
        null,
        $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

$pick = new \local_ltiaccountmerge\form\merge_pick_form($url);
if ($data = $pick->get_data()) {
    redirect(new moodle_url($url, [
        'source' => (int) \core_user::get_user_by_username($data->source)->id,
        'target' => (int) \core_user::get_user_by_username($data->target)->id,
    ]));
}

$report = null;
$consent = null;
$error = null;
if ($sourceid && $targetid) {
    try {
        $report = $executor->preview($sourceid, $targetid);
    } catch (moodle_exception $e) {
        $error = $e->errorcode;
    }
    if ($report && in_array($report->verdict, [merge_preview::READY, merge_preview::CONFLICTS], true)) {
        $consent = new \local_ltiaccountmerge\form\merge_consent_form(new moodle_url($url, ['source' => $sourceid,
            'target' => $targetid]), [
            'source' => $sourceid,
            'target' => $targetid,
            'hash' => $report->hash,
            'sourceusername' => $report->source->username,
            'conflicts' => $report->verdict === merge_preview::CONFLICTS,
        ]);
        if ($consentdata = $consent->get_data()) {
            try {
                $job = $executor->consent(
                    (int) $consentdata->source,
                    (int) $consentdata->target,
                    (string) $consentdata->hash,
                    (string) $consentdata->confirmation,
                    !empty($consentdata->acceptconflicts),
                    (int) $USER->id
                );
                redirect(
                    new moodle_url($url, ['job' => $job->id]),
                    get_string('merge_queued', $s),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            } catch (moodle_exception $e) {
                $error = $e->errorcode;
            }
        }
    }
    if ($report) {
        audit::log(
            'mergepreview',
            $report->verdict === merge_preview::BLOCKED ? audit::BLOCKED : audit::OK,
            'merge',
            $sourceid,
            $targetid,
            ['verdict' => $report->verdict]
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_merge', $s));
echo html_writer::tag('p', get_string('merge_intro', $s));
if (!merge_preview::tool_available()) {
    echo $OUTPUT->notification(get_string('merge_unavailable', $s), \core\output\notification::NOTIFY_WARNING);
}
if ($error !== null) {
    // Only the code's message from this plugin's language pack; never the identity or a raw exception text.
    $key = get_string_manager()->string_exists($error, $s) ? $error : 'err_merge_failed';
    echo $OUTPUT->notification(get_string($key, $s), \core\output\notification::NOTIFY_ERROR);
}
if ($jobid && ($job = $DB->get_record('local_ltiaccountmerge_job', ['id' => $jobid]))) {
    echo $renderer->merge_job($job, new moodle_url($url, ['job' => $job->id, 'action' => 'cancel', 'sesskey' => sesskey()]));
}
$pick->display();
if ($report) {
    echo $renderer->merge_preview($report);
    if ($consent) {
        $consent->display();
    }
}
echo $renderer->merge_jobs($DB->get_records('local_ltiaccountmerge_job', null, 'id DESC', '*', 0, 20), $url);
echo $OUTPUT->footer();
