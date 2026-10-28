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
 * Behat steps of local_ltiaccountmerge.
 *
 * @package    local_ltiaccountmerge
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps for negative UI checks: Moodle's Behat fails every step that ends on an exception page, so a refusal that core
 * renders as an exception (access denied, missing or wrong sesskey) is checked inside one step, which then leaves the page.
 *
 * @package    local_ltiaccountmerge
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_ltiaccountmerge extends behat_base {
    /**
     * Visit a local URL, expect a refusal text on the resulting page, then return to the front page.
     *
     * @When I visit :localurl and it is refused with :text
     * @param string $localurl URL relative to the site root.
     * @param string $text Text the refusal page must contain.
     */
    public function i_visit_and_it_is_refused_with(string $localurl, string $text): void {
        $url = new moodle_url($localurl);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
        $content = html_entity_decode(strip_tags($this->getSession()->getPage()->getContent()), ENT_QUOTES);
        $found = strpos(preg_replace('/\s+/', ' ', $content), $text) !== false;
        // Leave the exception page before the step ends, otherwise the framework fails the step for the expected exception.
        $this->getSession()->visit($this->locate_path('/'));
        if (!$found) {
            throw new ExpectationException("'{$text}' was not shown for {$localurl}", $this->getSession());
        }
    }

    /**
     * Send the confirmed deletion of a policy with a forged session key (a cross-site request).
     *
     * @When I request the deletion of the policy :name with a forged session key
     * @param string $name Policy name.
     */
    public function i_request_the_deletion_of_the_policy_with_a_forged_session_key(string $name): void {
        global $DB;
        $id = $DB->get_field('local_ltiaccountmerge_policy', 'id', ['name' => $name], MUST_EXIST);
        $url = new moodle_url('/local/ltiaccountmerge/policies.php', ['action' => 'delete', 'id' => $id, 'confirm' => 1,
            'sesskey' => 'forged']);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }
}
