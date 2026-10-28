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

namespace local_ltiaccountmerge\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');

/**
 * Evidence: the three core provisioning modes against the policy modes, using the real
 * auth_plugin_lti::complete_login(). Existing bindings stay stable whatever the policy does.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\mode_applier
 */
final class core_modes_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Read the learner mode the way enrol/lti/launch.php does (by resource uuid, through the helper).
     *
     * @param \stdClass $tool Tool record.
     * @return int
     */
    protected function mode_as_launch_php_reads_it(\stdClass $tool): int {
        $resource = array_values(\enrol_lti\helper::get_lti_tools(['uuid' => $tool->uuid]))[0];
        return (int) $resource->provisioningmodelearner;
    }

    /**
     * Run complete_login() and report what happened.
     *
     * @param array $launchdata Launch data.
     * @param int $mode Provisioning mode read like launch.php.
     * @return string 'login' or 'prompt'
     */
    protected function launch(array $launchdata, int $mode): string {
        global $CFG, $SESSION;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->setUser(0);
        unset($SESSION->auth_lti);
        $auth = new \auth_plugin_lti();
        try {
            // The @ hides the session-id warning of complete_user_login() without a real session (as core tests do).
            @$auth->complete_login($launchdata, new \moodle_url('/enrol/lti/launch.php'), $mode);
        } catch (\moodle_exception $e) {
            // In a CLI test redirect() throws instead of redirecting; core has cached the launch for its own dialog.
            if ($e->errorcode === 'redirecterrordetected' && !empty($SESSION->auth_lti)) {
                $this->assertSame($mode, $SESSION->auth_lti->provisioningmode);
                $this->assertSame($launchdata['sub'], $SESSION->auth_lti->launchdata['sub']);
                return 'prompt';
            }
            throw $e;
        }
        return 'login';
    }

    /**
     * Provider: policy modes (the new modes) crossed with the baseline stored in the resource.
     *
     * @return array
     */
    public static function mode_matrix_provider(): array {
        $cases = [];
        foreach ([1, 2, 3] as $baseline) {
            foreach ([1, 2, 3] as $policymode) {
                $cases["baseline $baseline policy $policymode"] = [$baseline, $policymode];
            }
        }
        return $cases;
    }

    /**
     * For every baseline/policy combination: the mode launch.php reads is the policy mode while active and the baseline
     * afterwards; a bound identity logs in to the same account regardless; an unbound one is provisioned (mode 1) or
     * prompted (modes 2 and 3) according to the effective mode.
     *
     * @dataProvider mode_matrix_provider
     * @param int $baseline Mode in the resource.
     * @param int $policymode Mode set by the policy.
     */
    public function test_mode_matrix_against_real_login(int $baseline, int $policymode): void {
        global $DB;
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        $tool = $this->create_tool($baseline, $baseline);
        $this->register_platform();
        $auth = new \auth_plugin_lti();
        $bound = $this->getDataGenerator()->create_user();
        $auth->create_user_binding(self::$issuer, 'bound-sub', $bound->id);
        $bindings = $DB->count_records('auth_lti_linked_login');
        $users = $DB->count_records('user');

        $this->create_policy(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00',
            'modelearner' => $policymode]);
        $applier = new mode_applier();
        $applier->apply_all(self::utc('2026-06-01 08:30:00'));
        $mode = $this->mode_as_launch_php_reads_it($tool);
        $this->assertSame($policymode, $mode);

        // Bound identity: same account, no new user, no new binding, never a prompt.
        $this->assertSame('login', $this->launch($this->launchdata('bound-sub'), $mode));
        $this->assertEquals($bound->id, $GLOBALS['USER']->id);
        $this->assertSame($bindings, $DB->count_records('auth_lti_linked_login'));
        $this->assertSame($users, $DB->count_records('user'));

        // Unbound identity: provisioned only in automatic mode.
        $result = $this->launch($this->launchdata('new-sub-' . $policymode), $mode);
        if ($policymode === modes::AUTO_ONLY) {
            $this->assertSame('login', $result);
            $this->assertSame($bindings + 1, $DB->count_records('auth_lti_linked_login'));
        } else {
            $this->assertSame('prompt', $result);
            $this->assertSame($bindings, $DB->count_records('auth_lti_linked_login'));
        }

        // After the window the baseline is back and the bound identity is still the same account.
        $applier->apply_all(self::utc('2026-06-01 09:00:00'));
        $this->assertSame($baseline, $this->mode_as_launch_php_reads_it($tool));
        $this->assertSame('login', $this->launch($this->launchdata('bound-sub'), $this->mode_as_launch_php_reads_it($tool)));
        $this->assertEquals($bound->id, $GLOBALS['USER']->id);
    }

    /**
     * Changing the policy does not force a re-binding: the binding row of an existing user is untouched.
     */
    public function test_policy_change_never_rebinds(): void {
        global $DB;
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        $tool = $this->create_tool(1, 1);
        $auth = new \auth_plugin_lti();
        $user = $this->getDataGenerator()->create_user();
        $auth->create_user_binding(self::$issuer, 'stable', $user->id);
        $before = $DB->get_record('auth_lti_linked_login', ['userid' => $user->id]);
        $applier = new mode_applier();
        $this->create_policy(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00', 'modelearner' => 3,
            'modeinstructor' => 3]);
        foreach (['07:00:00', '08:00:00', '08:30:00', '09:00:00', '10:00:00'] as $time) {
            $applier->apply_all(self::utc('2026-06-01 ' . $time));
            $this->assertSame('login', $this->launch($this->launchdata('stable'), $this->mode_as_launch_php_reads_it($tool)));
            $this->assertEquals($user->id, $GLOBALS['USER']->id);
        }
        $after = $DB->get_record('auth_lti_linked_login', ['userid' => $user->id]);
        $this->assertEquals($before, $after);
        $this->assertSame(1, $DB->count_records('auth_lti_linked_login'));
    }
}
