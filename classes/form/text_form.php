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

use local_ltiaccountmerge\local\html_policy;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Edit one error catalogue text for one language.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';
        $mform->addElement('hidden', 'code');
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $langs = ['*' => get_string('alllanguages', $s)] + get_string_manager()->get_list_of_translations();
        $mform->addElement('select', 'lang', get_string('language'), $langs);
        $mform->addElement('text', 'title', get_string('texttitle', $s), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('textarea', 'body', get_string('textbody', $s), ['rows' => 8, 'cols' => 60]);
        $mform->setType('body', PARAM_RAW);
        $mform->addRule('body', null, 'required', null, 'client');
        $mform->addHelpButton('body', 'textbody', $s);
        $this->add_action_buttons();
    }

    /**
     * Validation: the HTML allow-list rejects scripts, event handlers, unsafe URLs and unknown elements.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $problems = html_policy::problems((string) $data['body']);
        if ($problems) {
            $errors['body'] = get_string('err_html', 'local_ltiaccountmerge', implode(', ', $problems));
        }
        return $errors;
    }
}
