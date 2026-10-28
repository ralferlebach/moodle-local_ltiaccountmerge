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

/**
 * Own account-linking flow, outside the core auth_lti dialog (gate AR-G1 is negative).
 *
 * Links an UNBOUND LTI identity (issuer + sub) to an existing Moodle account using only the public core API
 * auth_plugin_lti::create_user_binding() / get_user_binding(). It never deletes or rewrites a core binding
 * (core offers no API for that - gate AR-G3 stays open for re-linking) and refuses when the identity is already
 * bound or the target account already carries another identity of the same issuer.
 *
 * Ownership proof, two routes:
 *  - admin attestation: a user with local/ltiaccountmerge:link links directly after an explicit, audited confirmation;
 *  - challenge: an administrator creates a request, a one-time code is e-mailed to the target account, and the
 *    owner proves control by being logged in as the target account AND entering the code within the time limit.
 *
 * All outcomes are audited; every refusal carries a support ID.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_service {
    /** @var int Lifetime of a challenge in seconds. */
    public const CODE_TTL = 900;
    /** @var int Wrong code attempts before a request locks. */
    public const MAX_ATTEMPTS = 5;
    /** @var string Code alphabet without look-alike characters. */
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var callable|null Test seam: called inside the transaction right after the binding was created. */
    protected $afterbinding;

    /** @var \core\lock\lock_factory|null */
    protected $lockfactory;

    /**
     * Constructor.
     *
     * @param callable|null $afterbinding Test seam only (used to simulate a competing writer).
     * @param \core\lock\lock_factory|null $lockfactory Lock factory; the configured core factory when null (injectable for tests).
     */
    public function __construct(?callable $afterbinding = null, ?\core\lock\lock_factory $lockfactory = null) {
        $this->afterbinding = $afterbinding;
        $this->lockfactory = $lockfactory;
    }

    /**
     * The lock factory in use.
     *
     * @return \core\lock\lock_factory
     */
    protected function factory(): \core\lock\lock_factory {
        return $this->lockfactory ?? \core\lock\lock_config::get_lock_factory('local_ltiaccountmerge');
    }

    /**
     * Whether the user-initiated confirmation route is switched on.
     *
     * @return bool
     */
    public static function userlinking_enabled(): bool {
        return (bool) get_config('local_ltiaccountmerge', 'userlinking');
    }

    /**
     * Validate the shape of an LTI identity without altering it.
     *
     * @param string $issuer Issuer (an https/http URL).
     * @param string $sub Subject.
     * @return bool
     */
    public static function is_valid_identity(string $issuer, string $sub): bool {
        if ($issuer === '' || strlen($issuer) > 1000 || $sub === '' || strlen($sub) > 255) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $issuer) || preg_match('/[\x00-\x1f\x7f]/', $sub)) {
            return false;
        }
        $parts = parse_url($issuer);
        return $parts && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && !empty($parts['host']);
    }

    /**
     * Whether the issuer is a platform registered with enrol_lti on this site.
     *
     * @param string $issuer Issuer.
     * @return bool
     */
    public static function issuer_is_known(string $issuer): bool {
        global $DB;
        return $DB->record_exists_select(
            'enrol_lti_app_registration',
            $DB->sql_compare_text('platformid') . ' = :iss',
            ['iss' => $issuer]
        );
    }

    /**
     * Check whether an account may receive a new LTI binding. Fail closed.
     *
     * @param int $userid Target user id.
     * @return string|null Error code, or null when the account is acceptable.
     */
    public static function check_target(int $userid): ?string {
        global $CFG, $DB;
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || $user->deleted || isguestuser($user)) {
            return 'targetinvalid';
        }
        if ($user->suspended || $user->auth === 'nologin' || (int) $user->mnethostid !== (int) $CFG->mnet_localhost_id) {
            return 'targetinvalid';
        }
        if (is_siteadmin($user)) {
            // A platform-controlled identity must never become a way into a site administrator account.
            return 'targetadmin';
        }
        return null;
    }

    /**
     * Pseudonymous reference of an identity for audit rows.
     *
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @return string
     */
    protected static function ref(string $issuer, string $sub): string {
        return audit::ref($issuer, $sub);
    }

    /**
     * The core auth plugin instance (public binding API).
     *
     * @return \auth_plugin_lti
     */
    protected static function auth(): \auth_plugin_lti {
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        return new \auth_plugin_lti();
    }

    /**
     * Build a result object and, for refusals, a support ID plus audit row.
     *
     * @param string $action Audit action.
     * @param string $outcome Audit outcome constant.
     * @param string|null $error Error code (null on success).
     * @param int $targetuserid Target user.
     * @param int $actorid Acting user.
     * @param array $detail Audit detail.
     * @param int $targetid Target object id for the audit row.
     * @param string $targettype Target type for the audit row.
     * @return \stdClass With ok, outcome, error, supportid.
     */
    protected static function result(
        string $action,
        string $outcome,
        ?string $error,
        int $targetuserid,
        int $actorid,
        array $detail,
        int $targetid = 0,
        string $targettype = 'identity'
    ): \stdClass {
        $supportid = $error !== null ? audit::new_support_id() : null;
        if ($error !== null) {
            $detail['error'] = $error;
        }
        audit::log($action, $outcome, $targettype, $targetid, $targetuserid, $detail, $supportid, $actorid);
        return (object) [
            'ok' => $error === null,
            'outcome' => $outcome,
            'error' => $error,
            'supportid' => $supportid,
        ];
    }

    /**
     * Dry-run: what would happen if this identity was linked to this account?
     *
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @param int $targetuserid Target account.
     * @return string 'ready', 'noop' (already linked to this account) or an error code.
     */
    public function preview(string $issuer, string $sub, int $targetuserid): string {
        return $this->evaluate($issuer, $sub, $targetuserid);
    }

    /**
     * Shared pre-flight evaluation.
     *
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @param int $targetuserid Target account.
     * @return string 'ready', 'noop' or an error code.
     */
    protected function evaluate(string $issuer, string $sub, int $targetuserid): string {
        global $DB;
        if (!self::is_valid_identity($issuer, $sub)) {
            return 'badidentity';
        }
        if (!self::issuer_is_known($issuer)) {
            return 'unknownissuer';
        }
        $targeterror = self::check_target($targetuserid);
        if ($targeterror !== null) {
            return $targeterror;
        }
        $existing = self::auth()->get_user_binding($issuer, $sub);
        if ($existing !== null) {
            return $existing === $targetuserid ? 'noop' : 'identitybound';
        }
        // One person per platform per account: another identity of the same issuer on the target means "taken".
        if ($DB->record_exists('auth_lti_linked_login', ['userid' => $targetuserid, 'issuer256' => hash('sha256', $issuer)])) {
            return 'targettaken';
        }
        return 'ready';
    }

    /**
     * Admin-initiated link with explicit attestation that the person's identity was verified.
     *
     * @param int $actorid Acting administrator.
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @param int $targetuserid Target account.
     * @param string $reason Free-text justification (stored, max 255 chars).
     * @param bool $attested Whether the administrator ticked the verification confirmation.
     * @return \stdClass Result.
     */
    public function link_direct(
        int $actorid,
        string $issuer,
        string $sub,
        int $targetuserid,
        string $reason,
        bool $attested
    ): \stdClass {
        $detail = ['proof' => 'admin_attestation', 'reason' => \core_text::substr(trim($reason), 0, 255)];
        if (self::is_valid_identity($issuer, $sub)) {
            $detail['ref'] = self::ref($issuer, $sub);
        }
        $context = \context_system::instance();
        if (!has_capability('local/ltiaccountmerge:link', $context, $actorid)) {
            return self::result('link', audit::REFUSED, 'nopermission', $targetuserid, $actorid, $detail);
        }
        if (!$attested || trim($reason) === '') {
            return self::result('link', audit::REFUSED, 'noattestation', $targetuserid, $actorid, $detail);
        }
        return $this->bind($actorid, $issuer, $sub, $targetuserid, $detail);
    }

    /**
     * Create a challenge request and e-mail the code to the account owner.
     *
     * @param int $actorid Acting administrator.
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @param int $targetuserid Target account.
     * @return \stdClass Result; on success also requestid and (for tests/CLI only) never the code.
     */
    public function create_request(int $actorid, string $issuer, string $sub, int $targetuserid): \stdClass {
        global $DB;
        $detail = [];
        if (self::is_valid_identity($issuer, $sub)) {
            $detail['ref'] = self::ref($issuer, $sub);
        }
        $context = \context_system::instance();
        if (!has_capability('local/ltiaccountmerge:link', $context, $actorid)) {
            return self::result('request', audit::REFUSED, 'nopermission', $targetuserid, $actorid, $detail);
        }
        if (!self::userlinking_enabled()) {
            return self::result('request', audit::REFUSED, 'userlinkingoff', $targetuserid, $actorid, $detail);
        }
        $state = $this->evaluate($issuer, $sub, $targetuserid);
        if ($state !== 'ready') {
            return self::result(
                'request',
                $state === 'noop' ? audit::NOOP : audit::REFUSED,
                $state === 'noop' ? null : $state,
                $targetuserid,
                $actorid,
                $detail
            );
        }
        $now = time();
        $code = self::new_code();
        $transaction = $DB->start_delegated_transaction();
        $DB->set_field_select(
            'local_ltiaccountmerge_request',
            'status',
            'cancelled',
            "issuerhash = :i AND subhash = :s AND status = 'pending'",
            ['i' => hash('sha256', $issuer), 's' => hash('sha256', $sub)]
        );
        $requestid = $DB->insert_record('local_ltiaccountmerge_request', (object) [
            'issuerhash' => hash('sha256', $issuer),
            'subhash' => hash('sha256', $sub),
            'issuer' => $issuer,
            'sub' => $sub,
            'targetuserid' => $targetuserid,
            'requestedby' => $actorid,
            'codehash' => self::hash_code($code),
            'status' => 'pending',
            'attempts' => 0,
            'timecreated' => $now,
            'timeexpires' => $now + self::CODE_TTL,
            'timecompleted' => 0,
        ]);
        $transaction->allow_commit();
        $this->send_code($targetuserid, (int) $requestid, $code, $issuer);
        audit::log('request', audit::OK, 'request', (int) $requestid, $targetuserid, $detail, null, $actorid);
        $result = (object) ['ok' => true, 'outcome' => audit::OK, 'error' => null, 'supportid' => null,
            'requestid' => (int) $requestid];
        return $result;
    }

    /**
     * The account owner confirms a request with the code they received.
     *
     * The caller must have established that $userid is the currently logged-in (and not "logged in as") user.
     *
     * @param int $userid The logged-in user.
     * @param int $requestid Request id.
     * @param string $code The code as typed.
     * @return \stdClass Result.
     */
    public function confirm_request(int $userid, int $requestid, string $code): \stdClass {
        global $DB;
        $now = time();
        $request = $DB->get_record('local_ltiaccountmerge_request', ['id' => $requestid]);
        // The same answer for "no such request" and "somebody else's request": no oracle.
        if (!$request || (int) $request->targetuserid !== $userid) {
            return self::result('confirm', audit::REFUSED, 'requestinvalid', $userid, $userid, [], $requestid, 'request');
        }
        $detail = ['proof' => 'challenge', 'ref' => self::ref((string) $request->issuer, (string) $request->sub)];
        $detail['requestedby'] = (int) $request->requestedby;
        if (
            !self::userlinking_enabled() || !has_capability(
                'local/ltiaccountmerge:linkown',
                \context_system::instance(),
                $userid
            )
        ) {
            return self::result('confirm', audit::REFUSED, 'nopermission', $userid, $userid, $detail, $requestid, 'request');
        }
        $lock = $this->factory()->get_lock('request_' . $requestid, 5);
        if (!$lock) {
            return self::result('confirm', audit::REFUSED, 'busy', $userid, $userid, $detail, $requestid, 'request');
        }
        try {
            $request = $DB->get_record('local_ltiaccountmerge_request', ['id' => $requestid]);
            if ($request->status === 'completed') {
                return self::result('confirm', audit::NOOP, null, $userid, $userid, $detail, $requestid, 'request');
            }
            if ($request->status !== 'pending') {
                return self::result('confirm', audit::REFUSED, 'requestinvalid', $userid, $userid, $detail, $requestid, 'request');
            }
            if ((int) $request->timeexpires < $now) {
                $DB->update_record('local_ltiaccountmerge_request', (object) ['id' => $request->id, 'status' => 'expired',
                    'issuer' => null, 'sub' => null]);
                return self::result('confirm', audit::REFUSED, 'requestexpired', $userid, $userid, $detail, $requestid, 'request');
            }
            $attempts = (int) $request->attempts + 1;
            $update = (object) ['id' => $request->id, 'attempts' => $attempts];
            if (!hash_equals((string) $request->codehash, self::hash_code($code))) {
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $update->status = 'locked';
                    $update->issuer = null;
                    $update->sub = null;
                }
                $DB->update_record('local_ltiaccountmerge_request', $update);
                return self::result('confirm', audit::REFUSED, 'challengefailed', $userid, $userid, $detail, $requestid, 'request');
            }
            $DB->update_record('local_ltiaccountmerge_request', $update);
            $result = $this->bind($userid, (string) $request->issuer, (string) $request->sub, $userid, $detail);
            if ($result->ok) {
                $DB->update_record('local_ltiaccountmerge_request', (object) ['id' => $request->id, 'status' => 'completed',
                    'issuer' => null, 'sub' => null, 'timecompleted' => time()]);
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Create the core binding atomically.
     *
     * Steps: lock per identity, pre-flight evaluation, transaction, core create_user_binding(), post-condition
     * "exactly one row for this identity and it is ours", commit; on any deviation roll back.
     *
     * @param int $actorid Acting user.
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @param int $targetuserid Target account.
     * @param array $detail Audit detail (proof etc.).
     * @return \stdClass Result.
     */
    protected function bind(int $actorid, string $issuer, string $sub, int $targetuserid, array $detail): \stdClass {
        global $DB;
        if (self::is_valid_identity($issuer, $sub)) {
            $detail['ref'] = self::ref($issuer, $sub);
        }
        $factory = $this->factory();
        $lock = self::is_valid_identity($issuer, $sub) ? $factory->get_lock('bind_' . sha1($issuer . "\n" . $sub), 5) : null;
        if (!$lock) {
            $err = self::is_valid_identity($issuer, $sub) ? 'busy' : 'badidentity';
            return self::result('link', audit::REFUSED, $err, $targetuserid, $actorid, $detail);
        }
        try {
            $state = $this->evaluate($issuer, $sub, $targetuserid);
            if ($state === 'noop') {
                return self::result('link', audit::NOOP, null, $targetuserid, $actorid, $detail);
            }
            if ($state !== 'ready') {
                return self::result('link', audit::REFUSED, $state, $targetuserid, $actorid, $detail);
            }
            $auth = self::auth();
            $transaction = $DB->start_delegated_transaction();
            try {
                $auth->create_user_binding($issuer, $sub, $targetuserid);
                if ($this->afterbinding) {
                    ($this->afterbinding)();
                }
                $rows = $DB->count_records(
                    'auth_lti_linked_login',
                    ['issuer256' => hash('sha256', $issuer), 'sub256' => hash('sha256', $sub)]
                );
                if ($rows !== 1 || $auth->get_user_binding($issuer, $sub) !== $targetuserid) {
                    throw new \moodle_exception('err_racelost', 'local_ltiaccountmerge');
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // Expected: rollback() rethrows.
                    unset($rethrown);
                }
                return self::result('link', audit::FAILED, 'racelost', $targetuserid, $actorid, $detail);
            }
        } finally {
            $lock->release();
        }
        $bindingid = (int) $DB->get_field('auth_lti_linked_login', 'id', ['userid' => $targetuserid,
            'issuer256' => hash('sha256', $issuer), 'sub256' => hash('sha256', $sub)]);
        audit::log('link', audit::OK, 'identity', $bindingid, $targetuserid, $detail, null, $actorid);
        \local_ltiaccountmerge\event\identity_linked::create([
            'context' => \context_system::instance(),
            'objectid' => $bindingid,
            'relateduserid' => $targetuserid,
            'userid' => $actorid,
            'other' => ['proof' => $detail['proof'] ?? '', 'ref' => $detail['ref'] ?? ''],
        ])->trigger();
        return (object) ['ok' => true, 'outcome' => audit::OK, 'error' => null, 'supportid' => null];
    }

    /**
     * A fresh challenge code, formatted XXXX-XXXX.
     *
     * @return string
     */
    protected static function new_code(): string {
        $chars = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, $max)];
        }
        return substr($chars, 0, 4) . '-' . substr($chars, 4);
    }

    /**
     * Keyed hash of a code, tolerant to case, spaces and the dash typed by the user.
     *
     * @param string $code The code.
     * @return string
     */
    public static function hash_code(string $code): string {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        return hash_hmac('sha256', $normalised, get_site_identifier());
    }

    /**
     * E-mail the code to the account owner.
     *
     * @param int $userid Target user.
     * @param int $requestid Request id.
     * @param string $code The code.
     * @param string $issuer Issuer (only its host name is mentioned).
     */
    protected function send_code(int $userid, int $requestid, string $code, string $issuer): void {
        global $CFG;
        $user = \core_user::get_user($userid);
        $a = (object) [
            'code' => $code,
            'minutes' => (int) (self::CODE_TTL / 60),
            'platform' => (string) parse_url($issuer, PHP_URL_HOST),
            'url' => (new \moodle_url('/local/ltiaccountmerge/confirm.php', ['id' => $requestid]))->out(false),
            'site' => format_string(get_site()->fullname),
        ];
        email_to_user(
            $user,
            \core_user::get_noreply_user(),
            get_string('email_subject', 'local_ltiaccountmerge', $a),
            get_string('email_body', 'local_ltiaccountmerge', $a)
        );
    }

    /**
     * Housekeeping: expire stale pending requests and remove old finished ones; optional audit retention.
     *
     * @param int $now Current time.
     */
    public static function cleanup(int $now): void {
        global $DB;
        $DB->execute("UPDATE {local_ltiaccountmerge_request} SET status = 'expired', issuer = NULL, sub = NULL
                       WHERE status = 'pending' AND timeexpires < :now", ['now' => $now]);
        $DB->delete_records_select(
            'local_ltiaccountmerge_request',
            "status <> 'pending' AND timecreated < :old",
            ['old' => $now - 30 * DAYSECS]
        );
        $days = (int) get_config('local_ltiaccountmerge', 'auditretention');
        if ($days > 0) {
            $DB->delete_records_select('local_ltiaccountmerge_audit', 'timecreated < :old', ['old' => $now - $days * DAYSECS]);
        }
    }
}
