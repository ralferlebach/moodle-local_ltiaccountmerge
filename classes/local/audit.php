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
 * Append-only audit trail and support-ID generation.
 *
 * Nothing secret and no raw LTI identifier is ever written: callers pass a pseudonymous reference built by
 * {@see audit::ref()} (a truncated SHA-256) when an identity has to be correlated.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {
    /** @var string Outcome: action completed. */
    public const OK = 'success';
    /** @var string Outcome: action refused (rule, permission, proof). */
    public const REFUSED = 'refused';
    /** @var string Outcome: action failed unexpectedly. */
    public const FAILED = 'failed';
    /** @var string Outcome: nothing to do (idempotent repeat). */
    public const NOOP = 'noop';
    /** @var string Outcome: simulation reported a block. */
    public const BLOCKED = 'blocked';

    /**
     * Generate a short, human-quotable support identifier.
     *
     * @return string Format LTIAM-XXXXXXXX (uppercase hex).
     */
    public static function new_support_id(): string {
        return 'LTIAM-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Whether a string has the support-ID format.
     *
     * @param string $value The string.
     * @return bool
     */
    public static function is_support_id(string $value): bool {
        return (bool) preg_match('/\\ALTIAM-[0-9A-F]{8}\\z/', $value);
    }

    /**
     * Pseudonymous reference for an LTI identity, safe to store and log.
     *
     * @param string $issuer Issuer.
     * @param string $sub Subject.
     * @return string 16 hex characters.
     */
    public static function ref(string $issuer, string $sub): string {
        return substr(hash('sha256', $issuer . "\n" . $sub), 0, 16);
    }

    /**
     * Write one audit row.
     *
     * @param string $action Action name (max 40 chars).
     * @param string $outcome One of the outcome constants.
     * @param string $targettype What was acted on (policy, tool, request, identity, user, text, merge).
     * @param int $targetid Id of the target.
     * @param int $relateduserid Affected user, if any.
     * @param array $detail Non-secret details.
     * @param string|null $supportid Support ID shown to the user, if any.
     * @param int|null $actorid Acting user; defaults to the current user (0 in CLI/cron).
     * @return int Audit row id.
     */
    public static function log(
        string $action,
        string $outcome,
        string $targettype = 'none',
        int $targetid = 0,
        int $relateduserid = 0,
        array $detail = [],
        ?string $supportid = null,
        ?int $actorid = null
    ): int {
        global $DB, $USER;
        if ($actorid === null) {
            $actorid = !empty($USER->id) ? (int) $USER->id : 0;
        }
        return (int) $DB->insert_record('local_ltiaccountmerge_audit', (object) [
            'timecreated' => time(),
            'actorid' => $actorid,
            'action' => substr($action, 0, 40),
            'targettype' => substr($targettype, 0, 30),
            'targetid' => $targetid,
            'relateduserid' => $relateduserid,
            'outcome' => substr($outcome, 0, 20),
            'supportid' => $supportid,
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}
