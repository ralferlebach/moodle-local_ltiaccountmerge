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
 * Administrator-initiated linking of an unbound LTI identity to an existing account (outside the core dialog).
 *
 * Step 1 collects identity and account, step 2 shows a pre-flight card and asks for explicit confirmation (sesskey
 * protected). The actual work and every safety rule live in {@see \local_ltiaccountmerge\local\link_service}.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\link_service;

admin_externalpage_setup('local_ltiaccountmerge_link');
$context = context_system::instance();
require_capability('local/ltiaccountmerge:link', $context);
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/link.php'));
$renderer = $PAGE->get_renderer('local_ltiaccountmerge');
$service = new link_service();

$step = optional_param('step', '', PARAM_ALPHA);
$form = new \local_ltiaccountmerge\form\link_form($PAGE->url);
$confirmform = null;
$previewhtml = '';
$errorredirect = function (string $code, ?string $sid) {
    redirect(new moodle_url('/local/ltiaccountmerge/error.php', ['code' => $code, 'sid' => $sid ?? '']));
};

if ($step === 'confirm') {
    // Step 2 submitted. The form re-validates sesskey itself.
    $route = optional_param('route', 'direct', PARAM_ALPHA);
    $confirmform = new \local_ltiaccountmerge\form\link_confirm_form($PAGE->url, ['route' => $route]);
    if ($confirmform->is_cancelled()) {
        redirect($PAGE->url);
    }
    if ($data = $confirmform->get_data()) {
        if ($route === 'request') {
            $result = $service->create_request((int) $USER->id, $data->issuer, $data->sub, (int) $data->userid);
        } else {
            $result = $service->link_direct(
                (int) $USER->id,
                $data->issuer,
                $data->sub,
                (int) $data->userid,
                (string) ($data->reason ?? ''),
                !empty($data->attested)
            );
        }
        if (!$result->ok) {
            $errorredirect($result->error, $result->supportid);
        }
        $message = $result->outcome === audit::NOOP ? 'link_noop' : ($route === 'request' ? 'request_sent' : 'link_done');
        redirect(
            $PAGE->url,
            get_string($message, 'local_ltiaccountmerge'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
} else if ($data = $form->get_data()) {
    $target = \core_user::get_user_by_username($data->username);
    if (!$target) {
        $errorredirect('targetinvalid', null);
    }
    $state = $service->preview($data->issuer, $data->sub, (int) $target->id);
    $ready = ($state === 'ready');
    $messagekey = $ready ? 'preview_ready' : ($state === 'noop' ? 'link_noop' : 'ec_' . $state . '_title');
    $previewhtml = $renderer->link_preview([
        'platform' => s((string) parse_url($data->issuer, PHP_URL_HOST)),
        'reference' => audit::ref($data->issuer, $data->sub),
        'targetname' => fullname($target),
        'targetusername' => s($target->username),
        'ready' => $ready,
        'message' => get_string($messagekey, 'local_ltiaccountmerge'),
    ]);
    if ($ready) {
        $confirmform = new \local_ltiaccountmerge\form\link_confirm_form($PAGE->url, ['route' => $data->route]);
        $confirmform->set_data(['issuer' => $data->issuer, 'sub' => $data->sub, 'userid' => $target->id,
            'route' => $data->route, 'step' => 'confirm']);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_link', 'local_ltiaccountmerge'));
echo html_writer::tag('p', get_string('link_intro', 'local_ltiaccountmerge'));
// The pre-flight card is shown whenever a check ran, also when the identity cannot be linked (the card then says why).
echo $previewhtml;
if ($confirmform === null) {
    $form->display();
} else {
    $confirmform->display();
}
echo $OUTPUT->footer();
