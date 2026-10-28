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

namespace local_ltiaccountmerge\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to preview the effective provisioning mode at a chosen instant.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';
        $mform->addElement('text', 'localtime', get_string('preview_localtime', $s), ['size' => 22,
            'placeholder' => 'YYYY-MM-DD HH:MM:SS']);
        $mform->setType('localtime', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('localtime', 'preview_localtime', $s);
        $mform->addElement('select', 'timezone', get_string('policy_timezone', $s), \core_date::get_list_of_timezones(null, true));
        $mform->setDefault('timezone', \core_date::get_server_timezone());
        $this->add_action_buttons(false, get_string('preview', $s));
    }

    /**
     * Validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['localtime'] !== '' && self::to_timestamp($data['localtime'], $data['timezone']) === null) {
            $errors['localtime'] = get_string('err_datetime', 'local_ltiaccountmerge');
        }
        return $errors;
    }

    /**
     * Convert local wall-clock text to an instant.
     *
     * @param string $text Y-m-d H:i:s.
     * @param string $timezone Timezone identifier.
     * @return int|null
     */
    public static function to_timestamp(string $text, string $timezone): ?int {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new \DateTimeZone($timezone));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        return \local_ltiaccountmerge\local\policy::wall_to_ts($text, new \DateTimeZone($timezone));
    }
}
