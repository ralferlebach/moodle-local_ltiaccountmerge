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
 * Tests of the challenge route: one-time code mailed to the account, confirmed by the logged-in owner.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\link_service
 */
final class link_request_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Prepare site state.
     *
     * @return \stdClass[] [admin-like linker, target]
     */
    protected function prepare(): array {
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        unset_config('noemailever');
        set_config('userlinking', 1, 'local_ltiaccountmerge');
        $this->register_platform();
        $linker = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ltiaccountmerge:link', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $linker->id, \context_system::instance());
        $target = $this->getDataGenerator()->create_user(['auth' => 'manual', 'email' => 'owner@example.org']);
        return [$linker, $target];
    }

    /**
     * Create a request and return its id and the code that was e-mailed.
     *
     * @param \stdClass $linker Requesting user.
     * @param \stdClass $target Target user.
     * @param string $sub Subject.
     * @return array [request id, code]
     */
    protected function request(\stdClass $linker, \stdClass $target, string $sub = 'ext-1'): array {
        $sink = $this->redirectEmails();
        $result = (new link_service())->create_request($linker->id, self::$issuer, $sub, $target->id);
        $this->assertTrue($result->ok, (string) $result->error);
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame('owner@example.org', $messages[0]->to);
        $this->assertSame(1, preg_match('/\b([A-Z2-9]{4}-[A-Z2-9]{4})\b/', $messages[0]->body, $m), $messages[0]->body);
        return [$result->requestid, $m[1]];
    }

    /**
     * Full story: request, mail, owner enters the code, identity linked, request cleared.
     */
    public function test_full_challenge_flow(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id, $code] = $this->request($linker, $target);
        $row = $DB->get_record('local_ltiaccountmerge_request', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('pending', $row->status);
        // The code itself is never stored, only a keyed hash.
        $this->assertStringNotContainsString(str_replace('-', '', $code), (string) json_encode($row));
        $this->assertSame(64, strlen($row->codehash));

        $result = (new link_service())->confirm_request($target->id, $id, strtolower($code));
        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertEquals($target->id, (new \auth_plugin_lti())->get_user_binding(self::$issuer, 'ext-1'));
        $row = $DB->get_record('local_ltiaccountmerge_request', ['id' => $id]);
        $this->assertSame('completed', $row->status);
        $this->assertNull($row->issuer);
        $this->assertNull($row->sub);
        // Repeating is idempotent.
        $again = (new link_service())->confirm_request($target->id, $id, $code);
        $this->assertTrue($again->ok);
        $this->assertSame('noop', $again->outcome);
        $this->assertSame(1, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * The route is off by default (fail closed).
     */
    public function test_off_by_default(): void {
        [$linker, $target] = $this->prepare();
        unset_config('userlinking', 'local_ltiaccountmerge');
        $result = (new link_service())->create_request($linker->id, self::$issuer, 'ext-1', $target->id);
        $this->assertSame('userlinkingoff', $result->error);
    }

    /**
     * Only users with the link capability may create requests.
     */
    public function test_create_requires_capability(): void {
        [, $target] = $this->prepare();
        $nobody = $this->getDataGenerator()->create_user();
        $result = (new link_service())->create_request($nobody->id, self::$issuer, 'ext-1', $target->id);
        $this->assertSame('nopermission', $result->error);
    }

    /**
     * Somebody else cannot confirm (the active login of the TARGET account is the proof), and gets no oracle.
     */
    public function test_other_user_cannot_confirm(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id, $code] = $this->request($linker, $target);
        $other = $this->getDataGenerator()->create_user();
        $wrong = (new link_service())->confirm_request($other->id, $id, $code);
        $missing = (new link_service())->confirm_request($other->id, $id + 100, $code);
        $this->assertSame('requestinvalid', $wrong->error);
        $this->assertSame($wrong->error, $missing->error);
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login'));
        // The linker cannot confirm on behalf of the owner either.
        $this->assertSame('requestinvalid', (new link_service())->confirm_request($linker->id, $id, $code)->error);
    }

    /**
     * Without the own-link capability the owner cannot confirm.
     */
    public function test_confirm_requires_linkown_capability(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id, $code] = $this->request($linker, $target);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('local/ltiaccountmerge:linkown', CAP_PROHIBIT, $roleid, \context_system::instance(), true);
        $this->assertSame('nopermission', (new link_service())->confirm_request($target->id, $id, $code)->error);
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * A wrong code is refused; after the maximum number of attempts even the right code no longer works.
     */
    public function test_wrong_codes_lock_the_request(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id, $code] = $this->request($linker, $target);
        $service = new link_service();
        for ($i = 1; $i <= link_service::MAX_ATTEMPTS; $i++) {
            $this->assertSame('challengefailed', $service->confirm_request($target->id, $id, 'AAAA-AAAA')->error);
        }
        $row = $DB->get_record('local_ltiaccountmerge_request', ['id' => $id]);
        $this->assertSame('locked', $row->status);
        $this->assertNull($row->sub);
        $this->assertSame('requestinvalid', $service->confirm_request($target->id, $id, $code)->error);
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * An expired challenge is refused and the identity data is wiped from the request.
     */
    public function test_expired_request(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id, $code] = $this->request($linker, $target);
        $DB->set_field('local_ltiaccountmerge_request', 'timeexpires', time() - 1, ['id' => $id]);
        $result = (new link_service())->confirm_request($target->id, $id, $code);
        $this->assertSame('requestexpired', $result->error);
        $row = $DB->get_record('local_ltiaccountmerge_request', ['id' => $id]);
        $this->assertSame('expired', $row->status);
        $this->assertNull($row->issuer);
        $this->assertSame(0, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * Multiple requests for the same identity: only the newest stays valid.
     */
    public function test_new_request_cancels_older_pending_one(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$first, $oldcode] = $this->request($linker, $target);
        [$second, $newcode] = $this->request($linker, $target);
        $this->assertSame('cancelled', $DB->get_field('local_ltiaccountmerge_request', 'status', ['id' => $first]));
        $this->assertSame('requestinvalid', (new link_service())->confirm_request($target->id, $first, $oldcode)->error);
        $this->assertTrue((new link_service())->confirm_request($target->id, $second, $newcode)->ok);
    }

    /**
     * If the identity got bound elsewhere while the request was pending, confirming is refused and changes nothing.
     */
    public function test_confirm_after_identity_was_bound_elsewhere(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id, $code] = $this->request($linker, $target);
        $owner = $this->getDataGenerator()->create_user();
        (new \auth_plugin_lti())->create_user_binding(self::$issuer, 'ext-1', $owner->id);
        $result = (new link_service())->confirm_request($target->id, $id, $code);
        $this->assertSame('identitybound', $result->error);
        $this->assertEquals($owner->id, (new \auth_plugin_lti())->get_user_binding(self::$issuer, 'ext-1'));
        $this->assertSame(1, $DB->count_records('auth_lti_linked_login'));
    }

    /**
     * The e-mail never contains the LTI subject or the full issuer URL, only the platform host name.
     */
    public function test_mail_leaks_no_identity(): void {
        [$linker, $target] = $this->prepare();
        $sink = $this->redirectEmails();
        (new link_service())->create_request($linker->id, self::$issuer, 'very-secret-sub', $target->id);
        $body = $sink->get_messages()[0]->body;
        $sink->close();
        $this->assertStringNotContainsString('very-secret-sub', $body);
        $this->assertStringNotContainsString(self::$issuer, $body);
        $this->assertStringContainsString('lms.example.org', $body);
    }

    /**
     * Cleanup expires stale pending requests, wipes their identity data, drops old finished ones and applies retention.
     */
    public function test_cleanup(): void {
        global $DB;
        [$linker, $target] = $this->prepare();
        [$id] = $this->request($linker, $target);
        $DB->set_field('local_ltiaccountmerge_request', 'timeexpires', time() - 10, ['id' => $id]);
        link_service::cleanup(time());
        $row = $DB->get_record('local_ltiaccountmerge_request', ['id' => $id]);
        $this->assertSame('expired', $row->status);
        $this->assertNull($row->sub);
        $DB->set_field('local_ltiaccountmerge_request', 'timecreated', time() - 31 * DAYSECS, ['id' => $id]);
        link_service::cleanup(time());
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_request', ['id' => $id]));

        // Audit retention: 0 keeps everything, N days deletes older rows only.
        audit::log('old', audit::OK);
        $DB->set_field('local_ltiaccountmerge_audit', 'timecreated', time() - 100 * DAYSECS, ['action' => 'old']);
        link_service::cleanup(time());
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'old']));
        set_config('auditretention', 30, 'local_ltiaccountmerge');
        link_service::cleanup(time());
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'old']));
    }
}
