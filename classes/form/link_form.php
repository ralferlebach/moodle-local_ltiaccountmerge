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
 * Step 1 of the admin link flow: which LTI identity, which account, which proof route.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $s = 'local_ltiaccountmerge';
        $mform->addElement('text', 'issuer', get_string('issuer', $s), ['size' => 60]);
        $mform->setType('issuer', PARAM_RAW_TRIMMED);
        $mform->addRule('issuer', null, 'required', null, 'client');
        $mform->addHelpButton('issuer', 'issuer', $s);
        $mform->addElement('text', 'sub', get_string('sub', $s), ['size' => 60, 'autocomplete' => 'off']);
        $mform->setType('sub', PARAM_RAW_TRIMMED);
        $mform->addRule('sub', null, 'required', null, 'client');
        $mform->addElement('text', 'username', get_string('targetusername', $s), ['size' => 30]);
        $mform->setType('username', PARAM_USERNAME);
        $mform->addRule('username', null, 'required', null, 'client');
        $routes = ['direct' => get_string('route_direct', $s)];
        if (\local_ltiaccountmerge\local\link_service::userlinking_enabled()) {
            $routes['request'] = get_string('route_request', $s);
        }
        $mform->addElement('select', 'route', get_string('route', $s), $routes);
        $mform->addHelpButton('route', 'route', $s);
        $this->add_action_buttons(false, get_string('continue'));
    }
}
