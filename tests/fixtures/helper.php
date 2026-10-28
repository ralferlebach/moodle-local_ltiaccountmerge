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

namespace local_ltiaccountmerge\tests;

use local_ltiaccountmerge\local\policy;

/**
 * Shared test helpers (trait): published resources, platform registrations, launch data, policies.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait helper {
    /** @var string Issuer used by the tests. */
    protected static $issuer = 'https://lms.example.org';

    /**
     * Drop debugging emitted by foreign observers.
     *
     * The shared development trees may contain sibling plugins whose event observers complain while auth_lti creates
     * users. That noise is not this plugin's behaviour, so it is removed before Moodle's "unexpected debugging" check;
     * any other debugging message is kept and still fails the test.
     */
    protected function drop_foreign_debugging(): void {
        $messages = $this->getDebuggingMessages();
        if (!$messages) {
            return;
        }
        $this->resetDebugging();
        foreach ($messages as $message) {
            if (strpos($message->message, 'tool_ltidiagnostics observer failed') === false) {
                debugging($message->message, $message->level);
            }
        }
    }

    /**
     * Drop foreign debugging before the base class checks for unexpected debugging.
     */
    public function tearDown(): void {
        $this->drop_foreign_debugging();
        parent::tearDown();
    }

    /**
     * Create a published LTI 1.3 resource with the given core provisioning modes.
     *
     * @param int|null $learner Learner mode.
     * @param int|null $instructor Instructor mode.
     * @param int $courseid Existing course or 0 to create one.
     * @return \stdClass enrol_lti_tools record.
     */
    protected function create_tool(?int $learner = 2, ?int $instructor = 2, int $courseid = 0): \stdClass {
        $data = (object) [
            'ltiversion' => 'LTI-1p3',
            'provisioningmodelearner' => $learner,
            'provisioningmodeinstructor' => $instructor,
        ];
        if ($courseid) {
            $data->courseid = $courseid;
        }
        return $this->getDataGenerator()->create_lti_tool($data);
    }

    /**
     * Register a platform with enrol_lti so that its issuer is "known".
     *
     * @param string|null $issuer Issuer URL.
     */
    protected function register_platform(?string $issuer = null): void {
        global $DB;
        $issuer = $issuer ?? self::$issuer;
        if (
            $DB->record_exists_select(
                'enrol_lti_app_registration',
                $DB->sql_compare_text('platformid') . ' = ' . $DB->sql_compare_text(':p'),
                ['p' => $issuer]
            )
        ) {
            return;
        }
        $this->getDataGenerator()->get_plugin_generator('enrol_lti')->create_application_registration([
            'name' => 'Test platform',
            'platformid' => $issuer,
            'clientid' => 'client-123',
            'authrequesturl' => $issuer . '/auth',
            'jwksurl' => $issuer . '/jwks',
            'accesstokenurl' => $issuer . '/token',
            'deploymentname' => 'Deployment',
            'deploymentid' => '1',
        ]);
    }

    /**
     * Mock launch data for a platform user.
     *
     * @param string $sub Platform user id.
     * @param bool $instructor Whether the user is an instructor.
     * @return array
     */
    protected function launchdata(string $sub, bool $instructor = false): array {
        $role = $instructor ? 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'
            : 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner';
        return [
            'iss' => self::$issuer,
            'aud' => 'client-123',
            'sub' => $sub,
            'exp' => time() + 60,
            'nonce' => 'nonce-' . $sub,
            'given_name' => 'Given' . $sub,
            'family_name' => 'Family' . $sub,
            'email' => "user{$sub}@lms.example.org",
            'https://purl.imsglobal.org/spec/lti/claim/roles' => [$role],
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => '1',
        ];
    }

    /**
     * Insert a policy and return it as a policy object.
     *
     * @param array $overrides Field overrides.
     * @return policy
     */
    protected function create_policy(array $overrides = []): policy {
        global $DB;
        $record = (object) array_merge([
            'name' => 'Test policy',
            'scope' => 'site',
            'scopeid' => 0,
            'kind' => 'scheduled',
            'priority' => 0,
            'enabled' => 1,
            'timezone' => 'UTC',
            'recurrence' => 'once',
            'localstart' => null,
            'localend' => null,
            'modelearner' => 1,
            'modeinstructor' => null,
            'usermodified' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);
        $record->id = $DB->insert_record('local_ltiaccountmerge_policy', $record);
        return policy::from_record($record);
    }

    /**
     * Unix timestamp of a UTC date-time string.
     *
     * @param string $text Y-m-d H:i:s in UTC.
     * @return int
     */
    protected static function utc(string $text): int {
        return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Current stored modes of a tool.
     *
     * @param int $toolid Tool id.
     * @return int[] [learner, instructor]
     */
    protected function stored_modes(int $toolid): array {
        global $DB;
        $tool = $DB->get_record('enrol_lti_tools', ['id' => $toolid], '*', MUST_EXIST);
        return [
            $tool->provisioningmodelearner === null ? null : (int) $tool->provisioningmodelearner,
            $tool->provisioningmodeinstructor === null ? null : (int) $tool->provisioningmodeinstructor,
        ];
    }

    /**
     * A lock factory that never grants a lock (simulates a concurrent holder).
     *
     * @return \core\lock\lock_factory
     */
    protected function busy_lock_factory(): \core\lock\lock_factory {
        return new class implements \core\lock\lock_factory {
            /**
             * Constructor.
             *
             * @param string $type Unused.
             */
            public function __construct($type = '') {
                unset($type);
            }

            /**
             * Always available.
             *
             * @return bool
             */
            public function is_available() {
                return true;
            }

            /**
             * Locks are not supported.
             *
             * @return bool
             */
            public function supports_timeout() {
                return true;
            }

            /**
             * Locks are not supported.
             *
             * @return bool
             */
            public function supports_auto_release() {
                return true;
            }


            /**
             * Never grants the lock.
             *
             * @param string $resource Resource.
             * @param int $timeout Timeout.
             * @param int $maxlifetime Lifetime.
             * @return bool
             */
            public function get_lock($resource, $timeout, $maxlifetime = 86400) {
                return false;
            }

            /**
             * Nothing to release.
             *
             * @param \core\lock\lock $lock Lock.
             * @return bool
             */
            public function release_lock(\core\lock\lock $lock) {
                return true;
            }
        };
    }
}
