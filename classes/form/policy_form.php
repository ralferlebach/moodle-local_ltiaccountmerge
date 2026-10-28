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

use local_ltiaccountmerge\local\modes;
use local_ltiaccountmerge\local\policy;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to create or edit a provisioning policy.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('policy_name', $s), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('select', 'kind', get_string('policy_kind', $s), [
            policy::KIND_SCHEDULED => get_string('kind_scheduled', $s),
            policy::KIND_OVERRIDE => get_string('kind_override', $s),
        ]);
        $mform->addHelpButton('kind', 'policy_kind', $s);

        $mform->addElement('select', 'scope', get_string('policy_scope', $s), [
            policy::SCOPE_SITE => get_string('scope_site', $s),
            policy::SCOPE_TOOL => get_string('scope_tool', $s),
            policy::SCOPE_COURSE => get_string('scope_course', $s),
        ]);
        $mform->addElement('text', 'scopeid', get_string('policy_scopeid', $s), ['size' => 10]);
        $mform->setType('scopeid', PARAM_INT);
        $mform->addHelpButton('scopeid', 'policy_scopeid', $s);
        $mform->hideIf('scopeid', 'scope', 'eq', policy::SCOPE_SITE);

        $mform->addElement('text', 'priority', get_string('policy_priority', $s), ['size' => 6]);
        $mform->setType('priority', PARAM_INT);
        $mform->setDefault('priority', 0);
        $mform->addHelpButton('priority', 'policy_priority', $s);

        $mform->addElement('advcheckbox', 'enabled', get_string('policy_enabled', $s));
        $mform->setDefault('enabled', 1);

        $mform->addElement('select', 'timezone', get_string('policy_timezone', $s), \core_date::get_list_of_timezones(null, true));
        $mform->setDefault('timezone', \core_date::get_server_timezone());

        $mform->addElement('select', 'recurrence', get_string('policy_recurrence', $s), [
            policy::REC_ONCE => get_string('rec_once', $s),
            policy::REC_DAILY => get_string('rec_daily', $s),
        ]);
        $mform->addElement('text', 'localstart', get_string('policy_localstart', $s), ['size' => 22,
            'placeholder' => 'YYYY-MM-DD HH:MM:SS / HH:MM:SS']);
        $mform->setType('localstart', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('localstart', 'policy_localstart', $s);
        $mform->addElement('text', 'localend', get_string('policy_localend', $s), ['size' => 22,
            'placeholder' => 'YYYY-MM-DD HH:MM:SS / HH:MM:SS']);
        $mform->setType('localend', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('localend', 'policy_localend', $s);

        $mform->addElement('select', 'modelearner', get_string('policy_modelearner', $s), modes::options(true));
        $mform->addElement('select', 'modeinstructor', get_string('policy_modeinstructor', $s), modes::options(true));

        $this->add_action_buttons();
    }

    /**
     * Validation: the same rules as the policy class, plus existence of the scope target.
     *
     * @param array $data Submitted data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $policy = policy::from_record((object) $data);
        foreach ($policy->validate() as $field => $stringid) {
            $errors[$field] = get_string($stringid, 'local_ltiaccountmerge');
        }
        if (empty($errors['scopeid'])) {
            if ($policy->scope === policy::SCOPE_TOOL && !$DB->record_exists('enrol_lti_tools', ['id' => $policy->scopeid])) {
                $errors['scopeid'] = get_string('err_scopeid', 'local_ltiaccountmerge');
            } else if ($policy->scope === policy::SCOPE_COURSE && !$DB->record_exists('course', ['id' => $policy->scopeid])) {
                $errors['scopeid'] = get_string('err_scopeid', 'local_ltiaccountmerge');
            }
        }
        return $errors;
    }
}
