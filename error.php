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
 * Public error page of the error catalogue: branded, translated, with a support ID. Reveals no identity data.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Public by design: shown to people whose launch failed before any login.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../config.php');

$code = \local_ltiaccountmerge\local\error_catalogue::normalise(optional_param('code', 'launcherror', PARAM_ALPHANUMEXT));
$sid = optional_param('sid', '', PARAM_ALPHANUMEXT);
$supportid = \local_ltiaccountmerge\local\audit::is_support_id($sid) ? $sid : null;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ltiaccountmerge/error.php', ['code' => $code]));
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('errorpagetitle', 'local_ltiaccountmerge'));
$PAGE->set_heading(get_string('errorpagetitle', 'local_ltiaccountmerge'));

echo $OUTPUT->header();
echo $PAGE->get_renderer('local_ltiaccountmerge')->error_page($code, $supportid);
echo $OUTPUT->footer();
