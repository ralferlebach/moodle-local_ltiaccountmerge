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
 * Error catalogue management: list codes and overrides, delete overrides.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ltiaccountmerge\local\audit;
use local_ltiaccountmerge\local\error_catalogue;

admin_externalpage_setup('local_ltiaccountmerge_texts');
require_capability('local/ltiaccountmerge:managetexts', context_system::instance());
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/texts.php'));

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete) {
    require_sesskey();
    $DB->delete_records('local_ltiaccountmerge_text', ['id' => $delete]);
    audit::log('deletetext', audit::OK, 'text', $delete);
    redirect(
        $PAGE->url,
        get_string('textdeleted', 'local_ltiaccountmerge'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$overrides = [];
foreach ($DB->get_records('local_ltiaccountmerge_text', null, 'code, lang') as $row) {
    $overrides[$row->code][] = $row;
}
$table = new html_table();
$table->attributes['class'] = 'generaltable table-sm';
$table->caption = get_string('page_texts', 'local_ltiaccountmerge');
$table->head = [get_string('code', 'local_ltiaccountmerge'), get_string('texttitle', 'local_ltiaccountmerge'),
    get_string('overrides', 'local_ltiaccountmerge'), get_string('actions')];
foreach (error_catalogue::codes() as $code) {
    $list = [];
    foreach ($overrides[$code] ?? [] as $row) {
        $list[] = s($row->lang) . ' ' . html_writer::link(new moodle_url($PAGE->url, ['delete' => $row->id,
            'sesskey' => sesskey()]), get_string('delete'));
    }
    $table->data[] = [
        html_writer::tag('code', $code),
        s(get_string('ec_' . $code . '_title', 'local_ltiaccountmerge')),
        $list ? implode('<br>', $list) : get_string('defaulttext', 'local_ltiaccountmerge'),
        html_writer::link(new moodle_url('/local/ltiaccountmerge/text_edit.php', ['code' => $code]), get_string('edit')),
    ];
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_texts', 'local_ltiaccountmerge'));
echo html_writer::tag('p', get_string('texts_intro', 'local_ltiaccountmerge'));
echo html_writer::table($table);
echo $OUTPUT->footer();
