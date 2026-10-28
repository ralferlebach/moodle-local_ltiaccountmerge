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
 * Tests of capabilities, events, tasks and plugin wiring.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\event\mode_applied
 * @covers     \local_ltiaccountmerge\event\policy_changed
 * @covers     \local_ltiaccountmerge\event\identity_linked
 * @covers     \local_ltiaccountmerge\event\merge_simulated
 * @covers     \local_ltiaccountmerge\task\apply_modes
 * @covers     \local_ltiaccountmerge\task\cleanup
 */
final class plugin_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * The required capabilities exist with sane risk flags and strings; no role gets power by default except :linkown.
     */
    public function test_capabilities(): void {
        global $DB;
        $caps = ['managepolicy', 'managetexts', 'link', 'linkown', 'viewaudit', 'simulatemerge', 'merge'];
        foreach ($caps as $cap) {
            $record = $DB->get_record('capabilities', ['name' => 'local/ltiaccountmerge:' . $cap], '*', MUST_EXIST);
            $this->assertEquals(CONTEXT_SYSTEM, $record->contextlevel);
            $this->assertNotEmpty(get_capability_string('local/ltiaccountmerge:' . $cap));
        }
        $risk = function (string $cap) use ($DB) {
            return (int) $DB->get_field('capabilities', 'riskbitmask', ['name' => 'local/ltiaccountmerge:' . $cap]);
        };
        $this->assertNotSame(0, $risk('link') & RISK_PERSONAL);
        $this->assertNotSame(0, $risk('managepolicy') & RISK_CONFIG);
        $this->assertNotSame(0, $risk('managetexts') & RISK_XSS);
        $this->assertNotSame(0, $risk('viewaudit') & RISK_PERSONAL);
        $this->assertNotSame(0, $risk('simulatemerge') & RISK_PERSONAL);
        $this->assertNotSame(0, $risk('merge') & RISK_DATALOSS);
        $this->assertNotSame(0, $risk('merge') & RISK_PERSONAL);
    }

    /**
     * Default roles: managers and teachers get nothing; authenticated users only get :linkown (which is further gated
     * by the off-by-default site setting).
     */
    public function test_default_role_permissions(): void {
        global $DB;
        $this->resetAfterTest();
        $context = \context_system::instance();
        $generator = $this->getDataGenerator();
        $manager = $generator->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, $context);
        $user = $generator->create_user();
        foreach (['managepolicy', 'managetexts', 'link', 'viewaudit', 'simulatemerge', 'merge'] as $cap) {
            $this->assertFalse(has_capability('local/ltiaccountmerge:' . $cap, $context, $manager->id), $cap);
            $this->assertFalse(has_capability('local/ltiaccountmerge:' . $cap, $context, $user->id), $cap);
            $this->assertTrue(has_capability('local/ltiaccountmerge:' . $cap, $context, get_admin()->id), $cap);
        }
        $this->assertTrue(has_capability('local/ltiaccountmerge:linkown', $context, $user->id));
        $this->assertFalse(\local_ltiaccountmerge\local\link_service::userlinking_enabled());
    }

    /**
     * Events carry a name, a description without identity data and the expected metadata.
     */
    public function test_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();
        $events = [
            \local_ltiaccountmerge\event\mode_applied::create(['context' => $context, 'objectid' => 1, 'other' => ['a' => 1]]),
            \local_ltiaccountmerge\event\policy_changed::create(['context' => $context, 'objectid' => 1,
                'other' => ['action' => 'created']]),
            \local_ltiaccountmerge\event\identity_linked::create(['context' => $context, 'objectid' => 1,
                'relateduserid' => 2, 'other' => ['proof' => 'challenge', 'ref' => 'abc']]),
            \local_ltiaccountmerge\event\merge_simulated::create(['context' => $context, 'objectid' => 1,
                'relateduserid' => 2, 'other' => ['verdict' => 'blocked']]),
        ];
        $sink = $this->redirectEvents();
        foreach ($events as $event) {
            $event->trigger();
            $this->assertNotEmpty($event::get_name());
            $this->assertNotEmpty($event->get_description());
        }
        $this->assertCount(4, $sink->get_events());
        $sink->close();
    }

    /**
     * The scheduled tasks are declared (every minute / daily) and runnable.
     */
    public function test_scheduled_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        $apply = \core\task\manager::get_scheduled_task(\local_ltiaccountmerge\task\apply_modes::class);
        $this->assertNotNull($apply);
        $this->assertSame('*', $apply->get_minute());
        $this->assertSame('*', $apply->get_hour());
        $this->assertNotEmpty($apply->get_name());
        $cleanup = \core\task\manager::get_scheduled_task(\local_ltiaccountmerge\task\cleanup::class);
        $this->assertNotNull($cleanup);
        $this->assertNotEmpty($cleanup->get_name());
        $recover = \core\task\manager::get_scheduled_task(\local_ltiaccountmerge\task\recover_merges::class);
        $this->assertNotNull($recover);
        $this->assertSame('*/5', $recover->get_minute());
        $this->assertNotEmpty($recover->get_name());

        $tool = $this->create_tool(2, 2);
        $this->create_policy(['modelearner' => 3]);
        ob_start();
        $apply->execute();
        ob_end_clean();
        $this->assertSame([3, 2], $this->stored_modes($tool->id));
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'applymode']));
        ob_start();
        $cleanup->execute();
        $recover->execute();
        ob_end_clean();
    }

    /**
     * The admin tree exposes the pages, each guarded by its own capability.
     */
    public function test_admin_pages_registered(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = admin_get_root(true, false);
        foreach (['policies', 'preview', 'link', 'texts', 'audit', 'simulate', 'merge'] as $page) {
            $this->assertNotNull($admin->locate('local_ltiaccountmerge_' . $page), $page);
        }
        $this->assertNotNull($admin->locate('local_ltiaccountmerge_settings'));
    }

    /**
     * A delegated role without moodle/site:config reaches exactly the page its capability belongs to (found by Behat:
     * the "localplugins" category does not exist for such users, so the plugin attaches its category to the root).
     */
    public function test_delegated_role_reaches_only_its_admin_page(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $system = \context_system::instance();
        assign_capability('local/ltiaccountmerge:viewaudit', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);
        $this->assertFalse(has_capability('moodle/site:config', $system));
        $root = admin_get_root(true, false);
        $audit = $root->locate('local_ltiaccountmerge_audit');
        $this->assertInstanceOf(\admin_externalpage::class, $audit);
        $this->assertTrue($audit->check_access());
        $this->assertFalse($root->locate('local_ltiaccountmerge_policies')->check_access());
        $this->assertFalse($root->locate('local_ltiaccountmerge_simulate')->check_access());
        $this->assertFalse($root->locate('local_ltiaccountmerge_merge')->check_access());
    }

    /**
     * Policies can only be saved through validation: the form rejects an invalid scope target and bad times.
     */
    public function test_policy_form_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_tool();
        $form = new \local_ltiaccountmerge\form\policy_form(new \moodle_url('/local/ltiaccountmerge/policy_edit.php'));
        $valid = ['name' => 'x', 'kind' => 'scheduled', 'scope' => 'site', 'scopeid' => 0, 'priority' => 0, 'enabled' => 1,
            'timezone' => 'UTC', 'recurrence' => 'once', 'localstart' => '', 'localend' => '', 'modelearner' => '1',
            'modeinstructor' => ''];
        $this->assertSame([], $form->validation($valid, []));
        $tool = $form->validation(array_merge($valid, ['scope' => 'tool', 'scopeid' => 99999]), []);
        $this->assertArrayHasKey('scopeid', $tool);
        $course = $form->validation(array_merge($valid, ['scope' => 'course', 'scopeid' => 99999]), []);
        $this->assertArrayHasKey('scopeid', $course);
        $bad = $form->validation(array_merge($valid, ['localstart' => '2026-13-01 00:00:00']), []);
        $this->assertArrayHasKey('localstart', $bad);
    }

    /**
     * The text form refuses scripts; the simulate form refuses unknown and identical users.
     */
    public function test_other_forms_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $textform = new \local_ltiaccountmerge\form\text_form(new \moodle_url('/local/ltiaccountmerge/text_edit.php'));
        $errors = $textform->validation(['body' => '<script>alert(1)</script>'], []);
        $this->assertArrayHasKey('body', $errors);
        $this->assertSame([], $textform->validation(['body' => '<p>fine</p>'], []));

        $user = $this->getDataGenerator()->create_user(['username' => 'known.user']);
        $simulate = new \local_ltiaccountmerge\form\simulate_form(new \moodle_url('/local/ltiaccountmerge/simulate.php'));
        $this->assertArrayHasKey('source', $simulate->validation(['source' => 'nobody', 'target' => $user->username], []));
        $this->assertArrayHasKey('target', $simulate->validation(['source' => $user->username, 'target' => $user->username], []));
    }

    /**
     * The preview form converts local wall-clock time with the same DST rules as the policies.
     */
    public function test_preview_form_timestamp(): void {
        $ts = \local_ltiaccountmerge\form\preview_form::to_timestamp('2026-03-29 03:00:00', 'Europe/Berlin');
        $this->assertSame(self::utc('2026-03-29 01:00:00'), $ts);
        $this->assertNull(\local_ltiaccountmerge\form\preview_form::to_timestamp('garbage', 'UTC'));
        $this->assertNull(\local_ltiaccountmerge\form\preview_form::to_timestamp('2026-06-01 00:00:00', 'Mars/Olympus'));
    }
}
