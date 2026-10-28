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

namespace local_ltiaccountmerge;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/helper.php');

/**
 * Tests for the optional pre-launch apply (after_config hook).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Dispatch the real core after_config hook as setup.php does, for a given script name.
     *
     * @param string $script Value of SCRIPT_NAME.
     */
    protected function dispatch_after_config(string $script): void {
        $_SERVER['SCRIPT_NAME'] = $script;
        \core\di::get(\core\hook\manager::class)->dispatch(new \core\hook\after_config());
    }

    /**
     * Provider for script matching.
     *
     * @return array
     */
    public static function script_provider(): array {
        return [
            'launch' => ['/enrol/lti/launch.php', true],
            'deeplink' => ['/enrol/lti/launch_deeplink.php', true],
            'subdirectory install' => ['/moodle/enrol/lti/launch.php', true],
            'nested subdirectory' => ['/a/b/enrol/lti/launch_deeplink.php', true],
            'suffix trick' => ['/enrol/lti/launch.php.evil', false],
            'prefix trick' => ['/xenrol/lti/launch.php', false],
            'login script' => ['/enrol/lti/login.php', false],
            'other launch' => ['/mod/lti/launch.php', false],
            'index' => ['/index.php', false],
            'empty' => ['', false],
            'relative trick' => ['/../enrol/lti/launch.php', false],
            'double slash' => ['//enrol/lti/launch.php', false],
        ];
    }

    /**
     * Only exactly the two launch scripts qualify.
     *
     * @dataProvider script_provider
     * @param string $script Script name.
     * @param bool $expected Whether it qualifies.
     */
    public function test_is_launch_script(string $script, bool $expected): void {
        $this->assertSame($expected, hook_callbacks::is_launch_script($script));
    }

    /**
     * The callback is registered with the real hook manager (db/hooks.php is read by core).
     */
    public function test_callback_is_registered(): void {
        $callbacks = \core\di::get(\core\hook\manager::class)->get_callbacks_for_hook(\core\hook\after_config::class);
        $found = false;
        foreach ($callbacks as $callback) {
            if (($callback['component'] ?? '') === 'local_ltiaccountmerge') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'after_config callback of local_ltiaccountmerge is not registered');
    }

    /**
     * Default: the setting is off and nothing happens even on the launch script.
     */
    public function test_off_by_default(): void {
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);
        $this->dispatch_after_config('/enrol/lti/launch.php');
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
    }

    /**
     * On: the mode is applied before core reads it, but only for the two launch scripts.
     */
    public function test_applies_only_on_launch_scripts(): void {
        $this->resetAfterTest();
        set_config('prelaunchapply', 1, 'local_ltiaccountmerge');
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);

        $this->dispatch_after_config('/index.php');
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
        $this->dispatch_after_config('/enrol/lti/launch.php.evil');
        $this->assertSame([2, 2], $this->stored_modes($tool->id));

        $this->dispatch_after_config('/enrol/lti/launch.php');
        $this->assertSame([1, 2], $this->stored_modes($tool->id));
    }

    /**
     * The deep-linking script is covered by the callback as well.
     */
    public function test_deeplink_script_triggers(): void {
        $this->resetAfterTest();
        set_config('prelaunchapply', 1, 'local_ltiaccountmerge');
        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 3]);
        $this->dispatch_after_config('/enrol/lti/launch_deeplink.php');
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
    }

    /**
     * Launch exactly when a window opens: without the hook the launch still sees the old mode (prompt), with the hook it
     * sees the new mode (automatic provisioning). Real auth_plugin_lti::complete_login() decides.
     */
    public function test_launch_at_window_start_sees_new_mode_only_with_hook(): void {
        global $CFG, $DB, $SESSION;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        $tool = $this->create_tool(2, 2);
        $this->register_platform();
        // The window opened a second ago; the minute-task has not run yet.
        $this->create_policy(['localstart' => gmdate('Y-m-d H:i:s', time() - 1), 'localend' => gmdate('Y-m-d H:i:s', time() + 3600),
            'modelearner' => 1]);

        // Without the pre-launch step the launch reads the stale baseline mode 2: the dialog is shown.
        $read = function () use ($tool) {
            $resource = array_values(\enrol_lti\helper::get_lti_tools(['uuid' => $tool->uuid]))[0];
            return (int) $resource->provisioningmodelearner;
        };
        $this->assertSame(2, $read());

        set_config('prelaunchapply', 1, 'local_ltiaccountmerge');
        $this->dispatch_after_config('/enrol/lti/launch.php');
        $this->assertSame(1, $read());

        $this->setUser(0);
        unset($SESSION->auth_lti);
        $users = $DB->count_records('user');
        // The @ hides the session-id warning of complete_user_login() without a real session (as core tests do).
        $auth = new \auth_plugin_lti();
        @$auth->complete_login($this->launchdata('boundary-sub'), new \moodle_url('/enrol/lti/launch.php'), $read());
        $this->assertSame($users + 1, $DB->count_records('user'));
        $this->assertNotEmpty($GLOBALS['USER']->id);
    }

    /**
     * Launch exactly when a window closes: the baseline is back before core reads it.
     */
    public function test_launch_at_window_end_sees_baseline_with_hook(): void {
        $this->resetAfterTest();
        set_config('prelaunchapply', 1, 'local_ltiaccountmerge');
        $tool = $this->create_tool(2, 2);
        $policy = $this->create_policy(['localstart' => gmdate('Y-m-d H:i:s', time() - 3600),
            'localend' => gmdate('Y-m-d H:i:s', time() + 3600), 'modelearner' => 1]);
        $this->dispatch_after_config('/enrol/lti/launch.php');
        $this->assertSame([1, 2], $this->stored_modes($tool->id));
        // The window ends now.
        global $DB;
        $DB->set_field('local_ltiaccountmerge_policy', 'localend', gmdate('Y-m-d H:i:s', time() - 1), ['id' => $policy->id]);
        $this->dispatch_after_config('/enrol/lti/launch.php');
        $this->assertSame([2, 2], $this->stored_modes($tool->id));
    }

    /**
     * A failing apply must never break the request: the callback swallows the error (developer debugging only).
     */
    public function test_failure_is_swallowed(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        set_config('prelaunchapply', 1, 'local_ltiaccountmerge');
        $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 1]);
        // Corrupt the bookkeeping query target so the applier throws.
        $applied = 'local_ltiaccountmerge_applied';
        $DB->get_manager()->rename_table(new \xmldb_table($applied), $applied . '_x');
        try {
            $this->dispatch_after_config('/enrol/lti/launch.php');
            $swallowed = true;
        } catch (\Throwable $e) {
            $swallowed = false;
        } finally {
            $DB->get_manager()->rename_table(new \xmldb_table($applied . '_x'), $applied);
        }
        $this->assertTrue($swallowed);
        $messages = $this->getDebuggingMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('local_ltiaccountmerge pre-launch apply failed', $messages[0]->message);
        $this->resetDebugging();
    }

    /**
     * Dispatch the real output hook and return the HTML the plugin added.
     *
     * @param string $script Value of SCRIPT_NAME.
     * @return string
     */
    protected function hint_output(string $script): string {
        global $PAGE;
        $PAGE->set_url('/');
        $PAGE->set_context(\context_system::instance());
        $_SERVER['SCRIPT_NAME'] = $script;
        $hook = new \core\hook\output\before_standard_top_of_body_html_generation($PAGE->get_renderer('core'));
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook->get_output();
    }

    /**
     * Script matching of the hint: the core binding dialog and the two launch scripts only.
     */
    public function test_hint_scripts(): void {
        $accepted = ['/auth/lti/login.php', '/enrol/lti/launch.php', '/enrol/lti/launch_deeplink.php', '/m/auth/lti/login.php'];
        foreach ($accepted as $ok) {
            $this->assertTrue(hook_callbacks::is_hint_script($ok), $ok);
        }
        $refused = ['/auth/lti/login.php.x', '/login/index.php', '/auth/lti/index.php', '', '/xauth/lti/login.php',
            '//auth/lti/login.php'];
        foreach ($refused as $no) {
            $this->assertFalse(hook_callbacks::is_hint_script($no), $no);
        }
    }

    /**
     * The hint callback is registered with the real hook manager.
     */
    public function test_hint_callback_is_registered(): void {
        $callbacks = \core\di::get(\core\hook\manager::class)
            ->get_callbacks_for_hook(\core\hook\output\before_standard_top_of_body_html_generation::class);
        $found = false;
        foreach ($callbacks as $callback) {
            if (($callback['component'] ?? '') === 'local_ltiaccountmerge') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * Off by default; on, it adds the catalogue text on the three scripts only, and never elsewhere.
     */
    public function test_hint_is_added_only_when_enabled_and_only_on_its_scripts(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->hint_output('/auth/lti/login.php'));
        set_config('firsthint', 1, 'local_ltiaccountmerge');
        $html = $this->hint_output('/auth/lti/login.php');
        $this->assertStringContainsString('data-ltiam-hint="1"', $html);
        $this->assertStringContainsString('Signing in from your learning platform', $html);
        $this->assertStringContainsString('role="note"', $html);
        $this->assertStringContainsString('data-ltiam-hint', $this->hint_output('/enrol/lti/launch.php'));
        $this->assertStringContainsString('data-ltiam-hint', $this->hint_output('/enrol/lti/launch_deeplink.php'));
        $this->assertSame('', $this->hint_output('/index.php'));
        $this->assertSame('', $this->hint_output('/my/index.php'));
        $this->assertSame('', $this->hint_output('/auth/lti/login.php.evil'));
    }

    /**
     * The text comes from the catalogue (English default, German string present) and is admin-editable limited HTML.
     * A stored script text is never rendered; the support contact is escaped.
     */
    public function test_hint_text_is_localised_editable_and_safe(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('firsthint', 1, 'local_ltiaccountmerge');
        set_config('supportcontact', 'help<b>@example.org & co', 'local_ltiaccountmerge');
        // The German text exists for this code too: error_catalogue_test::test_every_code_is_localised_en_and_de covers every code.
        // The German language pack need not be installed on the test site, so the language switch is not exercised here.

        $catalogue = \local_ltiaccountmerge\local\error_catalogue::class;
        $catalogue::save('firstloginhint', '*', 'Custom title', '<p>Call <strong>us</strong></p>', 2);
        $html = $this->hint_output('/auth/lti/login.php');
        $this->assertStringContainsString('Custom title', $html);
        $this->assertStringContainsString('<strong>us</strong>', $html);
        $this->assertStringNotContainsString('help<b>', $html);
        $this->assertStringContainsString('help&lt;b&gt;@example.org &amp; co', $html);

        // A tampered row (script injected straight into the table) falls back to the language string.
        $DB->set_field('local_ltiaccountmerge_text', 'body', '<p>x</p><script>alert(1)</script>', ['code' => 'firstloginhint']);
        $html = $this->hint_output('/auth/lti/login.php');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Signing in from your learning platform', $html);
        // The editor refuses script input for this code like for any other.
        $this->expectException(\moodle_exception::class);
        \local_ltiaccountmerge\local\error_catalogue::save('firstloginhint', '*', 't', '<p onclick="x()">a</p>', 2);
    }

    /**
     * A failing hint must never break the page: the callback swallows the error (developer debugging only).
     */
    public function test_hint_failure_is_swallowed(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        global $DB;
        set_config('firsthint', 1, 'local_ltiaccountmerge');
        $table = 'local_ltiaccountmerge_text';
        $DB->get_manager()->rename_table(new \xmldb_table($table), $table . '_x');
        try {
            $html = $this->hint_output('/auth/lti/login.php');
        } finally {
            $DB->get_manager()->rename_table(new \xmldb_table($table . '_x'), $table);
        }
        $this->assertSame('', $html);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }
}
