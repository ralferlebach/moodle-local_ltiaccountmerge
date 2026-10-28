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
 * Audit trail viewer.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ltiaccountmerge_audit');
require_capability('local/ltiaccountmerge:viewaudit', context_system::instance());

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$sid = optional_param('sid', '', PARAM_ALPHANUMEXT);
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/audit.php', ['sid' => $sid]));

$where = '1 = 1';
$params = [];
if ($sid !== '') {
    $where = 'a.supportid = :sid';
    $params['sid'] = $sid;
}
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_ltiaccountmerge_audit} a WHERE $where", $params);
$records = $DB->get_records_sql(
    "SELECT a.*, u.username AS actorname
       FROM {local_ltiaccountmerge_audit} a
  LEFT JOIN {user} u ON u.id = a.actorid
      WHERE $where
   ORDER BY a.id DESC",
    $params,
    $page * $perpage,
    $perpage
);

$table = new html_table();
$table->attributes['class'] = 'generaltable table-sm';
$table->caption = get_string('page_audit', 'local_ltiaccountmerge');
$table->head = [get_string('time'), get_string('actor', 'local_ltiaccountmerge'),
    get_string('action'), get_string('target', 'local_ltiaccountmerge'), get_string('relateduser', 'local_ltiaccountmerge'),
    get_string('outcome', 'local_ltiaccountmerge'), get_string('supportid', 'local_ltiaccountmerge'),
    get_string('details', 'local_ltiaccountmerge')];
foreach ($records as $r) {
    $table->data[] = [
        userdate($r->timecreated),
        $r->actorid ? s($r->actorname ?? '#' . $r->actorid) : get_string('system', 'local_ltiaccountmerge'),
        s($r->action),
        s($r->targettype . ($r->targetid ? ' #' . $r->targetid : '')),
        $r->relateduserid ?: '',
        s($r->outcome),
        s((string) $r->supportid),
        html_writer::tag('code', s((string) $r->detail)),
    ];
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('page_audit', 'local_ltiaccountmerge'));
if ($records) {
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
} else {
    echo $OUTPUT->notification(get_string('noaudit', 'local_ltiaccountmerge'), 'info');
}
echo $OUTPUT->footer();
