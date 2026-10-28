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
 * The account owner confirms a link request with the one-time code (proof: active login + code).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\link_service;

$id = required_param('id', PARAM_INT);
require_login(null, false);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/confirm.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('page_confirm', 'local_ltiaccountmerge'));
$PAGE->set_heading(get_string('page_confirm', 'local_ltiaccountmerge'));

$fail = function (string $code, ?string $sid) {
    redirect(new moodle_url('/local/ltiaccountmerge/error.php', ['code' => $code, 'sid' => $sid ?? '']));
};
if (isguestuser() || \core\session\manager::is_loggedinas()) {
    // The active login of the real account owner is the proof: no guest, no "log in as".
    $fail('nopermission', null);
}
require_capability('local/ltiaccountmerge:linkown', $context);
if (!link_service::userlinking_enabled()) {
    $fail('userlinkingoff', null);
}

$form = new \local_ltiaccountmerge\form\code_form($PAGE->url);
$form->set_data(['id' => $id]);
if ($data = $form->get_data()) {
    $result = (new link_service())->confirm_request((int) $USER->id, (int) $data->id, $data->code);
    if ($result->ok) {
        redirect(new moodle_url('/my/'), get_string(
            $result->outcome === audit::NOOP ? 'link_noop' : 'link_done',
            'local_ltiaccountmerge'
        ), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($result->error === 'challengefailed') {
        $message = get_string('err_wrongcode', 'local_ltiaccountmerge', $result->supportid);
        redirect($PAGE->url, $message, null, \core\output\notification::NOTIFY_ERROR);
    }
    $fail($result->error, $result->supportid);
} else if ($form->is_cancelled()) {
    redirect(new moodle_url('/my/'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_confirm', 'local_ltiaccountmerge'));
echo html_writer::tag('p', get_string('confirm_intro', 'local_ltiaccountmerge'));
$form->display();
echo $OUTPUT->footer();
