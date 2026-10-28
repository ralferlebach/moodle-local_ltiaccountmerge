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

namespace local_ltiaccountmerge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_ltiaccountmerge.
 *
 * Stored per person: audit rows (as actor or as affected user), link requests (target account, requester and, until
 * the request is finished, the LTI identity), and the "modified by" user ids of policies and texts. Everything lives
 * in the system context. Deletion keeps the audit trail but detaches it from the person (user ids set to 0, details
 * cleared); requests of the person are deleted; "modified by" references are zeroed.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the stored data.
     *
     * @param collection $collection The collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_ltiaccountmerge_audit', [
            'timecreated' => 'privacy:metadata:audit:timecreated',
            'actorid' => 'privacy:metadata:audit:actorid',
            'action' => 'privacy:metadata:audit:action',
            'relateduserid' => 'privacy:metadata:audit:relateduserid',
            'outcome' => 'privacy:metadata:audit:outcome',
            'supportid' => 'privacy:metadata:audit:supportid',
            'detail' => 'privacy:metadata:audit:detail',
        ], 'privacy:metadata:audit');
        $collection->add_database_table('local_ltiaccountmerge_request', [
            'issuer' => 'privacy:metadata:request:issuer',
            'sub' => 'privacy:metadata:request:sub',
            'targetuserid' => 'privacy:metadata:request:targetuserid',
            'requestedby' => 'privacy:metadata:request:requestedby',
            'status' => 'privacy:metadata:request:status',
            'timecreated' => 'privacy:metadata:request:timecreated',
        ], 'privacy:metadata:request');
        $collection->add_database_table('local_ltiaccountmerge_merge', [
            'sourceuserid' => 'privacy:metadata:merge:sourceuserid',
            'targetuserid' => 'privacy:metadata:merge:targetuserid',
            'status' => 'privacy:metadata:merge:status',
            'timecreated' => 'privacy:metadata:merge:timecreated',
        ], 'privacy:metadata:merge');
        $collection->add_database_table('local_ltiaccountmerge_job', [
            'sourceuserid' => 'privacy:metadata:job:sourceuserid',
            'targetuserid' => 'privacy:metadata:job:targetuserid',
            'consentby' => 'privacy:metadata:job:consentby',
            'status' => 'privacy:metadata:job:status',
            'timeconsented' => 'privacy:metadata:job:timeconsented',
        ], 'privacy:metadata:job');
        $collection->add_database_table('local_ltiaccountmerge_policy', [
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:policy');
        $collection->add_database_table('local_ltiaccountmerge_text', [
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:text');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        return $collection;
    }

    /**
     * Contexts holding data about a user: the system context if there is any.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Whether any table references the user.
     *
     * @param int $userid User id.
     * @return bool
     */
    protected static function user_has_data(int $userid): bool {
        global $DB;
        $params = ['a' => $userid, 'r' => $userid];
        return $DB->record_exists_select('local_ltiaccountmerge_audit', 'actorid = :a OR relateduserid = :r', $params)
            || $DB->record_exists_select(
                'local_ltiaccountmerge_request',
                'targetuserid = :t OR requestedby = :q',
                ['t' => $userid, 'q' => $userid]
            )
            || $DB->record_exists_select(
                'local_ltiaccountmerge_merge',
                'sourceuserid = :s OR targetuserid = :t',
                ['s' => $userid, 't' => $userid]
            )
            || $DB->record_exists_select(
                'local_ltiaccountmerge_job',
                'sourceuserid = :s OR targetuserid = :t OR consentby = :c',
                ['s' => $userid, 't' => $userid, 'c' => $userid]
            )
            || $DB->record_exists('local_ltiaccountmerge_policy', ['usermodified' => $userid])
            || $DB->record_exists('local_ltiaccountmerge_text', ['usermodified' => $userid]);
    }

    /**
     * Users with data in the system context.
     *
     * @param userlist $userlist The user list.
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('actorid', 'SELECT actorid FROM {local_ltiaccountmerge_audit} WHERE actorid <> 0', []);
        $userlist->add_from_sql(
            'relateduserid',
            'SELECT relateduserid FROM {local_ltiaccountmerge_audit} WHERE relateduserid <> 0',
            []
        );
        $userlist->add_from_sql('targetuserid', 'SELECT targetuserid FROM {local_ltiaccountmerge_request}', []);
        $userlist->add_from_sql('requestedby', 'SELECT requestedby FROM {local_ltiaccountmerge_request}', []);
        $userlist->add_from_sql('sourceuserid', 'SELECT sourceuserid FROM {local_ltiaccountmerge_merge}', []);
        $userlist->add_from_sql('targetuserid', 'SELECT targetuserid FROM {local_ltiaccountmerge_merge}', []);
        $userlist->add_from_sql('sourceuserid', 'SELECT sourceuserid FROM {local_ltiaccountmerge_job}', []);
        $userlist->add_from_sql('targetuserid', 'SELECT targetuserid FROM {local_ltiaccountmerge_job}', []);
        $userlist->add_from_sql('consentby', 'SELECT consentby FROM {local_ltiaccountmerge_job} WHERE consentby <> 0', []);
        $userlist->add_from_sql(
            'usermodified',
            'SELECT usermodified FROM {local_ltiaccountmerge_policy} WHERE usermodified <> 0',
            []
        );
        $userlist->add_from_sql(
            'usermodified',
            'SELECT usermodified FROM {local_ltiaccountmerge_text} WHERE usermodified <> 0',
            []
        );
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        $system = \context_system::instance();
        if (!in_array($system->id, $contextlist->get_contextids())) {
            return;
        }
        $audit = [];
        foreach (
            $DB->get_records_select(
                'local_ltiaccountmerge_audit',
                'actorid = :a OR relateduserid = :r',
                ['a' => $userid, 'r' => $userid],
                'id'
            ) as $row
        ) {
            $audit[] = [
                'time' => \core_privacy\local\request\transform::datetime($row->timecreated),
                'action' => $row->action,
                'outcome' => $row->outcome,
                'role' => trim(((int) $row->actorid === $userid ? 'actor ' : '') .
                    ((int) $row->relateduserid === $userid ? 'related' : '')),
                'supportid' => $row->supportid,
                'detail' => $row->detail,
            ];
        }
        $requests = [];
        foreach (
            $DB->get_records_select(
                'local_ltiaccountmerge_request',
                'targetuserid = :t OR requestedby = :q',
                ['t' => $userid, 'q' => $userid],
                'id'
            ) as $row
        ) {
            $requests[] = [
                'status' => $row->status,
                'created' => \core_privacy\local\request\transform::datetime($row->timecreated),
                'issuer' => $row->issuer,
                'sub' => $row->sub,
                'target' => (int) $row->targetuserid === $userid,
                'requester' => (int) $row->requestedby === $userid,
            ];
        }
        $merges = [];
        foreach (
            $DB->get_records_select(
                'local_ltiaccountmerge_merge',
                'sourceuserid = :s OR targetuserid = :t',
                ['s' => $userid, 't' => $userid],
                'id'
            ) as $row
        ) {
            $merges[] = [
                'status' => $row->status,
                'merged away' => (int) $row->sourceuserid === $userid,
                'surviving' => (int) $row->targetuserid === $userid,
                'created' => \core_privacy\local\request\transform::datetime($row->timecreated),
            ];
        }
        $jobs = [];
        foreach (
            $DB->get_records_select(
                'local_ltiaccountmerge_job',
                'sourceuserid = :s OR targetuserid = :t OR consentby = :c',
                ['s' => $userid, 't' => $userid, 'c' => $userid],
                'id'
            ) as $row
        ) {
            $jobs[] = [
                'status' => $row->status,
                'merged away' => (int) $row->sourceuserid === $userid,
                'kept' => (int) $row->targetuserid === $userid,
                'consented by this user' => (int) $row->consentby === $userid,
                'consented' => \core_privacy\local\request\transform::datetime($row->timeconsented),
            ];
        }
        $modified = [
            'policies' => array_values(
                $DB->get_fieldset_select('local_ltiaccountmerge_policy', 'name', 'usermodified = :u', ['u' => $userid])
            ),
            'texts' => array_values(
                $DB->get_fieldset_select('local_ltiaccountmerge_text', 'code', 'usermodified = :u', ['u' => $userid])
            ),
        ];
        writer::with_context($system)->export_data([get_string('pluginname', 'local_ltiaccountmerge')], (object) [
            'audit' => $audit,
            'requests' => $requests,
            'merges' => $merges,
            'mergejobs' => $jobs,
            'modified' => $modified,
        ]);
    }

    /**
     * Delete all data for all users in a context (system): requests, merge-journal and merge-job rows are removed, the audit trail
     * is detached from people.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_ltiaccountmerge_request');
        $DB->delete_records('local_ltiaccountmerge_merge');
        $DB->delete_records('local_ltiaccountmerge_job');
        $DB->execute('UPDATE {local_ltiaccountmerge_audit} SET actorid = 0, relateduserid = 0, detail = NULL');
        $DB->execute('UPDATE {local_ltiaccountmerge_policy} SET usermodified = 0');
        $DB->execute('UPDATE {local_ltiaccountmerge_text} SET usermodified = 0');
    }

    /**
     * Delete data of one user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $system = \context_system::instance();
        if (in_array($system->id, $contextlist->get_contextids())) {
            self::delete_user($contextlist->get_user()->id);
        }
    }

    /**
     * Delete data of several users.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user((int) $userid);
        }
    }

    /**
     * Remove or detach everything about one user.
     *
     * @param int $userid User id.
     */
    protected static function delete_user(int $userid): void {
        global $DB;
        $params = ['t' => $userid, 'q' => $userid];
        $DB->delete_records_select('local_ltiaccountmerge_request', 'targetuserid = :t OR requestedby = :q', $params);
        $DB->delete_records_select('local_ltiaccountmerge_merge', 'sourceuserid = :t OR targetuserid = :q', $params);
        // A deleted job of a merge that is running makes the merge roll back (no consent found): fail safe.
        $DB->delete_records_select('local_ltiaccountmerge_job', 'sourceuserid = :t OR targetuserid = :q', $params);
        $DB->execute('UPDATE {local_ltiaccountmerge_job} SET consentby = 0 WHERE consentby = :u', ['u' => $userid]);
        // Audit rows stay as evidence but no longer point at the person; the pseudonymous identity reference goes too.
        $DB->execute(
            'UPDATE {local_ltiaccountmerge_audit} SET relateduserid = 0, detail = NULL WHERE relateduserid = :u',
            ['u' => $userid]
        );
        $DB->execute('UPDATE {local_ltiaccountmerge_audit} SET actorid = 0 WHERE actorid = :u', ['u' => $userid]);
        $DB->execute('UPDATE {local_ltiaccountmerge_policy} SET usermodified = 0 WHERE usermodified = :u', ['u' => $userid]);
        $DB->execute('UPDATE {local_ltiaccountmerge_text} SET usermodified = 0 WHERE usermodified = :u', ['u' => $userid]);
    }
}
