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
 * Explicit consent of an administrator to one merge preview.
 *
 * Custom data: source (id), target (id), hash (fingerprint of the preview shown), sourceusername, conflicts (bool).
 * The administrator must type the username of the account to be merged away, confirm that the merge is irreversible and,
 * if the preview lists conflicts, accept their documented resolution. The executor checks all of it again.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class merge_consent_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';
        $data = $this->_customdata;
        foreach (['source', 'target'] as $field) {
            $mform->addElement('hidden', $field, $data[$field]);
            $mform->setType($field, PARAM_INT);
        }
        $mform->addElement('hidden', 'hash', $data['hash']);
        $mform->setType('hash', PARAM_ALPHANUM);
        $mform->addElement('header', 'consenthdr', get_string('merge_consent', $s));
        $mform->addElement('static', 'consentinfo', '', get_string('merge_consent_intro', $s));
        $mform->addElement('text', 'confirmation', get_string('merge_confirmation', $s), ['size' => 30, 'autocomplete' => 'off']);
        $mform->setType('confirmation', PARAM_RAW_TRIMMED);
        $mform->addRule('confirmation', null, 'required', null, 'client');
        $mform->addHelpButton('confirmation', 'merge_confirmation', $s);
        $mform->addElement('advcheckbox', 'irreversible', get_string('merge_irreversible', $s));
        if (!empty($data['conflicts'])) {
            $mform->addElement('advcheckbox', 'acceptconflicts', get_string('merge_acceptconflicts', $s));
        }
        $this->add_action_buttons(false, get_string('merge_consentbutton', $s));
    }

    /**
     * Validation of the visible checks (the executor repeats them against the database).
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $s = 'local_ltiaccountmerge';
        $typed = \core_text::strtolower(trim((string) $data['confirmation']));
        if ($typed !== \core_text::strtolower($this->_customdata['sourceusername'])) {
            $errors['confirmation'] = get_string('err_merge_confirmation', $s);
        }
        if (empty($data['irreversible'])) {
            $errors['irreversible'] = get_string('err_merge_irreversible', $s);
        }
        if (!empty($this->_customdata['conflicts']) && empty($data['acceptconflicts'])) {
            $errors['acceptconflicts'] = get_string('err_merge_conflicts', $s);
        }
        return $errors;
    }
}
