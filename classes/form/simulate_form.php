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
 * Select the two accounts for a merge simulation.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class simulate_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';
        $mform->addElement('text', 'source', get_string('mergesource', $s), ['size' => 30]);
        $mform->setType('source', PARAM_USERNAME);
        $mform->addRule('source', null, 'required', null, 'client');
        $mform->addHelpButton('source', 'mergesource', $s);
        $mform->addElement('text', 'target', get_string('mergetarget', $s), ['size' => 30]);
        $mform->setType('target', PARAM_USERNAME);
        $mform->addRule('target', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('simulate', $s));
    }

    /**
     * Validation: both usernames must exist and differ.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['source', 'target'] as $field) {
            if (!\core_user::get_user_by_username($data[$field])) {
                $errors[$field] = get_string('err_nouser', 'local_ltiaccountmerge');
            }
        }
        if (empty($errors) && $data['source'] === $data['target']) {
            $errors['target'] = get_string('err_samemerge', 'local_ltiaccountmerge');
        }
        return $errors;
    }
}
