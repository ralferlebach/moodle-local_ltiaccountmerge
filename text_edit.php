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
 * Edit one error catalogue text.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ltiaccountmerge\local\error_catalogue;

admin_externalpage_setup('local_ltiaccountmerge_texts');
require_capability('local/ltiaccountmerge:managetexts', context_system::instance());

$code = required_param('code', PARAM_ALPHANUMEXT);
if (!error_catalogue::is_code($code)) {
    throw new moodle_exception('err_code', 'local_ltiaccountmerge');
}
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/text_edit.php', ['code' => $code]));
$returnurl = new moodle_url('/local/ltiaccountmerge/texts.php');

$form = new \local_ltiaccountmerge\form\text_form($PAGE->url);
$form->set_data(['code' => $code, 'lang' => '*', 'title' => get_string('ec_' . $code . '_title', 'local_ltiaccountmerge'),
    'body' => get_string('ec_' . $code . '_body', 'local_ltiaccountmerge')]);
if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    error_catalogue::save($data->code, $data->lang, $data->title, $data->body, (int) $USER->id);
    redirect($returnurl, get_string('textsaved', 'local_ltiaccountmerge'), null, \core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_texts', 'local_ltiaccountmerge') . ': ' . s($code));
$form->display();
echo $OUTPUT->footer();
