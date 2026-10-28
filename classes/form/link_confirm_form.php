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
 * Step 2 of the admin link flow: explicit confirmation. Carries the identity in hidden fields, protected by sesskey.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_confirm_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';
        foreach (
            ['issuer' => PARAM_RAW_TRIMMED, 'sub' => PARAM_RAW_TRIMMED, 'userid' => PARAM_INT, 'route' => PARAM_ALPHA,
                'step' => PARAM_ALPHA] as $name => $type
        ) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, $type);
        }
        $mform->setDefault('step', 'confirm');
        if ($this->_customdata['route'] === 'direct') {
            $mform->addElement('advcheckbox', 'attested', get_string('attestation', $s), get_string('attestation_label', $s));
            $mform->addElement('textarea', 'reason', get_string('reason', $s), ['rows' => 3, 'cols' => 60, 'maxlength' => 255]);
            $mform->setType('reason', PARAM_TEXT);
        }
        $this->add_action_buttons(true, get_string($this->_customdata['route'] === 'direct' ? 'linknow' : 'sendcode', $s));
    }
}
