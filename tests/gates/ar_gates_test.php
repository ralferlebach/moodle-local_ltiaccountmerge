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

namespace local_ltiaccountmerge\gates;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');

/**
 * Feasibility-gate evidence (AR-G1 .. AR-G4) against the Moodle version this test runs on.
 *
 * Each test states in its name which gate it documents and whether the result is positive or negative. These tests
 * are the machine-checkable part of docs/DOCUMENTATION.md "Gates and evidence".
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ar_gates_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Read a core source file.
     *
     * @param string $relative Path relative to dirroot.
     * @return string
     */
    protected function core_source(string $relative): string {
        global $CFG;
        $path = $CFG->dirroot . '/' . $relative;
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    /**
     * AR-G1 NEGATIVE: the core account-binding dialog is rendered by a plain renderer, with no hook or callback in the
     * request path of /auth/lti/login.php. The only override mechanism is a theme renderer, which a plugin cannot provide.
     */
    public function test_ar_g1_negative_no_extension_point_in_core_binding_dialog(): void {
        global $PAGE, $CFG;
        $login = $this->core_source('auth/lti/login.php');
        $this->assertStringContainsString("get_renderer('auth_lti')", $login);
        // Moodle 5.x login.php uses \core\di::get() only to fetch the auth plugin instance: that is service location, not
        // an extension point, so it is deliberately not in this list.
        foreach (['component_callback', 'get_plugins_with_function', 'hook\\manager', 'dispatch('] as $needle) {
            $this->assertStringNotContainsString($needle, $login, "login.php unexpectedly contains $needle");
        }
        $renderer = $this->core_source('auth/lti/classes/output/renderer.php');
        $this->assertStringContainsString('render_account_binding_options_page', $renderer);
        foreach (['component_callback', 'get_plugins_with_function', 'dispatch('] as $needle) {
            $this->assertStringNotContainsString($needle, $renderer);
        }
        // The renderer resolved for auth_lti is core's own class: no plugin can interpose.
        $this->resetAfterTest();
        $PAGE->set_url('/auth/lti/login.php');
        $PAGE->set_context(\context_system::instance());
        $this->assertInstanceOf(\auth_lti\output\renderer::class, $PAGE->get_renderer('auth_lti'));
        $this->assertNotEmpty($CFG->dirroot);
    }

    /**
     * AR-G1 NEGATIVE (continued): no registered core hook is related to the LTI binding dialog or LTI errors.
     */
    public function test_ar_g1_negative_no_core_hook_for_lti_binding(): void {
        $hooks = array_keys(\core\hook\manager::discover_known_hooks());
        $this->assertNotEmpty($hooks);
        foreach ($hooks as $hookclass) {
            $this->assertDoesNotMatchRegularExpression('/auth_lti|account_binding|binding_options/i', $hookclass);
        }
    }

    /**
     * AR-G2 NEGATIVE (native): enrol/lti/launch.php reads the provisioning mode straight from the resource record, no hook
     * or callback sits between the read and auth_plugin_lti::complete_login(); deep linking does not read it at all.
     */
    public function test_ar_g2_negative_launch_reads_mode_without_extension_point(): void {
        $launch = $this->core_source('enrol/lti/launch.php');
        $this->assertStringContainsString('provisioningmodelearner', $launch);
        $this->assertStringContainsString('provisioningmodeinstructor', $launch);
        foreach (['component_callback', 'get_plugins_with_function', 'hook\\manager', 'dispatch('] as $needle) {
            $this->assertStringNotContainsString($needle, $launch);
        }
        $deeplink = $this->core_source('enrol/lti/launch_deeplink.php');
        $this->assertStringContainsString('PROVISIONING_MODE_PROMPT_EXISTING_ONLY', $deeplink);
        $this->assertStringNotContainsString('provisioningmodelearner', $deeplink);
    }

    /**
     * AR-G2 POSITIVE (fallback): the core API to write the mode exists, so a timed writer is possible without a fork.
     */
    public function test_ar_g2_positive_official_writer_exists(): void {
        $plugin = enrol_get_plugin('lti');
        $this->assertTrue(method_exists($plugin, 'update_instance'));
        $this->assertFalse(method_exists(\enrol_lti\helper::class, 'update_lti_tool'));
        $this->assertFalse(method_exists(\enrol_lti\helper::class, 'set_provisioning_mode'));
    }

    /**
     * AR-G2 POSITIVE (optional step): core dispatches after_config on every request before any script runs, with the DB ready.
     */
    public function test_ar_g2_positive_after_config_hook_exists(): void {
        $this->assertTrue(class_exists(\core\hook\after_config::class));
        $setup = $this->core_source('lib/setup.php');
        $this->assertStringContainsString('new \\core\\hook\\after_config()', $setup);
        $this->assertGreaterThan(strpos($setup, 'setup_DB()'), strpos($setup, 'new \\core\\hook\\after_config()'));
    }

    /**
     * AR-G3 PARTIAL: core offers create_user_binding()/get_user_binding() but NO API to remove or move a binding.
     */
    public function test_ar_g3_partial_binding_api_has_no_remove_or_move(): void {
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $methods = [];
        foreach ((new \ReflectionClass(\auth_plugin_lti::class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === \auth_plugin_lti::class) {
                $methods[] = $method->getName();
            }
        }
        $this->assertContains('create_user_binding', $methods);
        $this->assertContains('get_user_binding', $methods);
        foreach ($methods as $method) {
            $this->assertDoesNotMatchRegularExpression('/delete|remove|unbind|relink|move_binding|update_binding/i', $method);
        }
    }

    /**
     * AR-G3 evidence: core's create_user_binding() silently ignores an existing binding of the same identity (it never
     * rebinds), and the schema lets two accounts hold the same identity if code writes it directly - so the plugin's own
     * explicit pre-check and post-condition are necessary.
     */
    public function test_ar_g3_create_user_binding_semantics(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        $auth = new \auth_plugin_lti();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $auth->create_user_binding(self::$issuer, 'sub-1', $first->id);
        $auth->create_user_binding(self::$issuer, 'sub-1', $second->id);
        $this->assertSame($first->id, (string) $auth->get_user_binding(self::$issuer, 'sub-1'));
        $this->assertSame(1, $DB->count_records('auth_lti_linked_login'));
        // The unique key is (userid, issuer256, sub256): a second account can be bound by a direct write.
        $DB->insert_record('auth_lti_linked_login', (object) ['userid' => $second->id, 'issuer' => self::$issuer,
            'issuer256' => hash('sha256', self::$issuer), 'sub' => 'sub-1', 'sub256' => hash('sha256', 'sub-1'),
            'timecreated' => time(), 'timemodified' => time()]);
        $this->assertSame(2, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * AR-G4 BLOCKED by design: there is no core merge API for user records.
     */
    public function test_ar_g4_no_core_user_merge_api(): void {
        $this->assertFalse(function_exists('user_merge'));
        $this->assertFalse(class_exists(\core_user\merge::class));
        $this->assertFalse(class_exists('\\tool_mergeusers\\local\\merger'), 'a third-party merge tool is installed: re-evaluate');
    }

    /**
     * AR-G1 FALLBACK POSITIVE: the dialog cannot be replaced, but a supported output hook can add a notice above it. The
     * dialog page uses the popup layout and a standard header, and the standard top-of-body output dispatches the hook.
     * (The real rendered page is checked by the Playwright story "first-launch hint".)
     */
    public function test_ar_g1_fallback_output_hook_reaches_the_binding_dialog_page(): void {
        $login = $this->core_source('auth/lti/login.php');
        $this->assertStringContainsString("set_pagelayout('popup')", $login);
        $this->assertStringContainsString('$OUTPUT->header()', $login);
        $renderer = $this->core_source('lib/classes/output/core_renderer.php');
        $this->assertStringContainsString('new before_standard_top_of_body_html_generation(', $renderer);
        $this->assertTrue(class_exists(\core\hook\output\before_standard_top_of_body_html_generation::class));
        $theme = $this->core_source('theme/boost/templates/columns1.mustache');
        $this->assertStringContainsString('standard_top_of_body_html', $theme);
    }

    /**
     * AR-G1: the three core provisioning modes against real first launches (see core_modes_test) are the only supported
     * integration; this test pins that the plugin has no override of the account-binding renderer.
     */
    public function test_ar_g1_plugin_ships_no_renderer_override_for_auth_lti(): void {
        global $CFG;
        $this->assertFileDoesNotExist($CFG->dirroot . '/local/ltiaccountmerge/classes/output/auth_lti_renderer.php');
        $this->assertFileDoesNotExist($CFG->dirroot . '/local/ltiaccountmerge/classes/output/auth_lti');
        $this->assertFileDoesNotExist($CFG->dirroot . '/local/ltiaccountmerge/renderer.php');
    }

    /**
     * AR-G4 FALLBACK POSITIVE: the executor needs only public extension points of the third-party merge tool (its success
     * event and its two hooks) and the one core column; it contains no table walker. Pins the contract so a change is noticed.
     */
    public function test_ar_g4_executor_is_event_driven_and_touches_one_core_table(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/ltiaccountmerge/classes/local/merge_service.php');
        preg_match_all('/\$DB->\w+\(\s*\'([a-z0-9_]+)\'/', $source, $m);
        $tables = array_unique($m[1]);
        $foreign = array_values(array_filter($tables, function ($t) {
            return strpos($t, 'local_ltiaccountmerge_') !== 0 && $t !== 'user';
        }));
        $this->assertSame(['auth_lti_linked_login'], $foreign);
        $events = file_get_contents($CFG->dirroot . '/local/ltiaccountmerge/db/events.php');
        $this->assertStringContainsString('\\tool_mergeusers\\event\\user_merged_success', $events);
        // Since finding F1 the decision runs inside the merge through the two public hooks of the merge tool.
        $hooks = file_get_contents($CFG->dirroot . '/local/ltiaccountmerge/db/hooks.php');
        $this->assertStringContainsString('\\tool_mergeusers\\hook\\add_settings_before_merging::class', $hooks);
        $this->assertStringContainsString('\\tool_mergeusers\\hook\\after_merged_all_tables::class', $hooks);
    }
}
