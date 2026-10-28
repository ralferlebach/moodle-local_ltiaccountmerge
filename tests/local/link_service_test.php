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
 * Tests of the own linking flow: permissions, proof, refusal rules, atomicity, idempotency, audit.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\link_service
 */
final class link_service_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Prepare: platform registered, a linking administrator role, a target user.
     *
     * @return \stdClass[] [linker, target]
     */
    protected function prepare(): array {
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        $this->register_platform();
        $linker = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ltiaccountmerge:link', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $linker->id, \context_system::instance());
        $target = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        return [$linker, $target];
    }

    /**
     * Number of binding rows for an identity.
     *
     * @param string $sub Subject.
     * @return int
     */
    protected function bindings(string $sub): int {
        global $DB;
        return $DB->count_records('auth_lti_linked_login', ['issuer256' => hash('sha256', self::$issuer),
            'sub256' => hash('sha256', $sub)]);
    }

    /**
     * Happy path: the identity is linked through the core API, audited, evented, and a REAL launch then logs in to the
     * existing account instead of creating a new one.
     */
    public function test_direct_link_then_real_launch_logs_in_to_existing_account(): void {
        global $DB, $USER;
        [$linker, $target] = $this->prepare();
        $sink = $this->redirectEvents();
        $result = (new link_service())->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'checked passport', true);
        $events = array_map('get_class', $sink->get_events());
        $sink->close();
        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame('success', $result->outcome);
        $this->assertSame(1, $this->bindings('ext-1'));
        $this->assertContains(\local_ltiaccountmerge\event\identity_linked::class, $events);
        $audit = $DB->get_record('local_ltiaccountmerge_audit', ['action' => 'link', 'outcome' => 'success'], '*', MUST_EXIST);
        $this->assertEquals($linker->id, $audit->actorid);
        $this->assertEquals($target->id, $audit->relateduserid);
        // Audit must not leak the raw identity.
        $this->assertStringNotContainsString('ext-1', (string) $audit->detail);
        $this->assertStringNotContainsString(self::$issuer, (string) $audit->detail);

        $users = $DB->count_records('user');
        $this->setUser(0);
        @(new \auth_plugin_lti())->complete_login($this->launchdata('ext-1'), new \moodle_url('/enrol/lti/launch.php'), 1);
        $this->assertEquals($target->id, $USER->id);
        $this->assertSame($users, $DB->count_records('user'));
    }

    /**
     * Without the capability nothing happens, and it is audited with a support ID.
     */
    public function test_requires_capability(): void {
        global $DB;
        [, $target] = $this->prepare();
        $nobody = $this->getDataGenerator()->create_user();
        $result = (new link_service())->link_direct($nobody->id, self::$issuer, 'ext-1', $target->id, 'why', true);
        $this->assertFalse($result->ok);
        $this->assertSame('nopermission', $result->error);
        $this->assertTrue(audit::is_support_id($result->supportid));
        $this->assertSame(0, $this->bindings('ext-1'));
        $this->assertTrue($DB->record_exists(
            'local_ltiaccountmerge_audit',
            ['supportid' => $result->supportid, 'outcome' => 'refused']
        ));
    }

    /**
     * Explicit confirmation and a reason are mandatory.
     */
    public function test_requires_attestation_and_reason(): void {
        [$linker, $target] = $this->prepare();
        $service = new link_service();
        $unattested = $service->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', false);
        $noreason = $service->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, '  ', true);
        $this->assertSame('noattestation', $unattested->error);
        $this->assertSame('noattestation', $noreason->error);
        $this->assertSame(0, $this->bindings('ext-1'));
    }

    /**
     * Provider of malformed identities.
     *
     * @return array
     */
    public static function bad_identity_provider(): array {
        return [
            'empty issuer' => ['', 'sub'],
            'empty sub' => ['https://lms.example.org', ''],
            'not a url' => ['lms.example.org', 'sub'],
            'javascript scheme' => ['javascript:alert(1)', 'sub'],
            'issuer with space' => ['https://lms.example.org /x', 'sub'],
            'issuer with newline' => ["https://lms.example.org\n", 'sub'],
            'sub with control char' => ['https://lms.example.org', "su\x00b"],
            'sub too long' => ['https://lms.example.org', str_repeat('a', 256)],
        ];
    }

    /**
     * Malformed identities are refused before anything is written.
     *
     * @dataProvider bad_identity_provider
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     */
    public function test_bad_identity_refused(string $issuer, string $sub): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        $result = (new link_service())->link_direct($linker->id, $issuer, $sub, $target->id, 'why', true);
        $this->assertSame('badidentity', $result->error);
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * Only platforms registered with enrol_lti are accepted.
     */
    public function test_unknown_issuer_refused(): void {
        [$linker, $target] = $this->prepare();
        $result = (new link_service())->link_direct($linker->id, 'https://evil.example.net', 'ext-1', $target->id, 'why', true);
        $this->assertSame('unknownissuer', $result->error);
    }

    /**
     * Provider of ineligible target accounts.
     *
     * @return array
     */
    public static function bad_target_provider(): array {
        return [
            'suspended' => ['suspended', 'targetinvalid'],
            'deleted' => ['deleted', 'targetinvalid'],
            'nologin' => ['nologin', 'targetinvalid'],
            'guest' => ['guest', 'targetinvalid'],
            'admin' => ['admin', 'targetadmin'],
            'missing' => ['missing', 'targetinvalid'],
        ];
    }

    /**
     * Accounts that must never receive an external identity are refused.
     *
     * @dataProvider bad_target_provider
     * @param string $kind Kind of bad target.
     * @param string $expected Expected error code.
     */
    public function test_bad_targets_refused(string $kind, string $expected): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        $id = $target->id;
        switch ($kind) {
            case 'suspended':
                $DB->set_field('user', 'suspended', 1, ['id' => $id]);
                break;
            case 'deleted':
                $DB->set_field('user', 'deleted', 1, ['id' => $id]);
                break;
            case 'nologin':
                $DB->set_field('user', 'auth', 'nologin', ['id' => $id]);
                break;
            case 'guest':
                $id = guest_user()->id;
                break;
            case 'admin':
                $id = get_admin()->id;
                break;
            case 'missing':
                $id = 987654;
                break;
        }
        $result = (new link_service())->link_direct($linker->id, self::$issuer, 'ext-1', $id, 'why', true);
        $this->assertSame($expected, $result->error);
        $this->assertSame(0, $this->bindings('ext-1'));
    }

    /**
     * An identity already bound to another account is never moved; the existing binding is untouched.
     */
    public function test_existing_binding_to_other_account_refused(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        $owner = $this->getDataGenerator()->create_user();
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'ext-1', $owner->id);
        $before = $DB->get_record('auth_lti_linked_login', ['userid' => $owner->id]);
        $result = (new link_service())->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', true);
        $this->assertSame('identitybound', $result->error);
        $this->assertEquals($before, $DB->get_record('auth_lti_linked_login', ['userid' => $owner->id]));
        $this->assertSame(1, $this->bindings('ext-1'));
    }

    /**
     * Idempotency: repeating a completed link is a no-op, not an error and not a second row.
     */
    public function test_repeat_is_idempotent(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        $service = new link_service();
        $this->assertTrue($service->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', true)->ok);
        $again = $service->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', true);
        $this->assertTrue($again->ok);
        $this->assertSame('noop', $again->outcome);
        $this->assertSame(1, $this->bindings('ext-1'));
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_audit', ['action' => 'link', 'outcome' => 'noop']));
    }

    /**
     * "Target taken": an account holding another identity of the same platform cannot receive a second one.
     */
    public function test_target_taken_by_other_identity_of_same_issuer(): void {
        [$linker, $target] = $this->prepare();
        $service = new link_service();
        $this->assertTrue($service->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', true)->ok);
        $second = $service->link_direct($linker->id, self::$issuer, 'ext-2', $target->id, 'why', true);
        $this->assertSame('targettaken', $second->error);
        $this->assertSame(0, $this->bindings('ext-2'));
        // A different platform is fine.
        $this->register_platform('https://other.example.org');
        $this->assertTrue($service->link_direct($linker->id, 'https://other.example.org', 'ext-2', $target->id, 'why', true)->ok);
    }

    /**
     * Race: a competing writer binds the same identity to another account between our check and our commit. The
     * post-condition detects two rows, everything is rolled back, and nothing is left behind.
     */
    public function test_race_competing_binding_rolls_back(): void {
        global $DB;
        $this->preventResetByRollback();
        [$linker, $target] = $this->prepare();
        $rival = $this->getDataGenerator()->create_user();
        $compete = function () use ($DB, $rival) {
            $DB->insert_record('auth_lti_linked_login', (object) ['userid' => $rival->id, 'issuer' => self::$issuer,
                'issuer256' => hash('sha256', self::$issuer), 'sub' => 'ext-1', 'sub256' => hash('sha256', 'ext-1'),
                'timecreated' => time(), 'timemodified' => time()]);
        };
        $result = (new link_service($compete))->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', true);
        $this->assertFalse($result->ok);
        $this->assertSame('racelost', $result->error);
        $this->assertSame(0, $this->bindings('ext-1'));
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login'));
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'link', 'outcome' => 'failed',
            'supportid' => $result->supportid]));
    }

    /**
     * A lock held by another process makes the attempt fail closed with "busy"; nothing is written.
     */
    public function test_busy_lock_fails_closed(): void {
        [$linker, $target] = $this->prepare();
        $result = (new link_service(null, $this->busy_lock_factory()))
            ->link_direct($linker->id, self::$issuer, 'ext-1', $target->id, 'why', true);
        $this->assertSame('busy', $result->error);
        $this->assertSame(0, $this->bindings('ext-1'));
    }

    /**
     * Matching names or e-mail addresses never link anything: there is simply no such code path.
     */
    public function test_same_name_and_email_do_not_link(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        $this->register_platform();
        $existing = $this->getDataGenerator()->create_user([
            'firstname' => 'Given', 'lastname' => 'Familyx', 'email' => 'userx@lms.example.org',
        ]);
        $this->setUser(0);
        @(new \auth_plugin_lti())->complete_login($this->launchdata('x'), new \moodle_url('/enrol/lti/launch.php'), 1);
        // A new account was created for the launch although a user with the same name and e-mail exists.
        $this->assertNotEquals($existing->id, $GLOBALS['USER']->id);
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login', ['userid' => $existing->id]));
    }

    /**
     * Preview reports the state without writing.
     */
    public function test_preview_does_not_write(): void {
        global $DB;
        [, $target] = $this->prepare();
        $service = new link_service();
        $before = $DB->perf_get_writes();
        $this->assertSame('ready', $service->preview(self::$issuer, 'ext-1', $target->id));
        $this->assertSame('badidentity', $service->preview('nope', 'ext-1', $target->id));
        $this->assertSame($before, $DB->perf_get_writes());
    }
}
