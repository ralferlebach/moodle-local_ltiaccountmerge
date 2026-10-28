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

use local_ltiaccountmerge\local\merge\executor;
use local_ltiaccountmerge\local\merge_service;

/**
 * Callbacks for the two hooks of tool_mergeusers (finding F1 of the 0.2.0 release gates).
 *
 * Without them, tool_mergeusers' generic table merger moves auth_lti_linked_login.userid itself, inside its own
 * transaction and before its success event, so this plugin's conflict rule ("never give the survivor a second identity
 * of the same platform") never applied and the setting mergemovebindings could not stop the move.
 *
 *  - add_settings_before_merging: the binding table is excluded from the generic processing, and so are this plugin's
 *    append-only audit trail, its reconciliation journal and its merge-job journal.
 *  - after_merged_all_tables: the binding decision of {@see merge_service} runs inside the merge transaction. A conflict
 *    keeps the identity on the merged-away account for an administrator and is journalled as such (status partial), the
 *    merge itself goes on (same semantics as FlexAccess: report, do not block). An unexpected failure adds an error
 *    message, which makes tool_mergeusers roll back the WHOLE merge.
 *
 * Both hook classes exist only when tool_mergeusers is installed. Core registers callbacks for unknown hook class
 * names without complaint and never dispatches them (proven in mergeusers_callbacks_test). The parameters are therefore
 * not type-hinted with tool_mergeusers classes; the methods rely only on the public contract of the two hooks.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mergeusers_callbacks {
    /** @var string Hook class of tool_mergeusers that collects database settings. */
    public const SETTINGS_HOOK = 'tool_mergeusers\\hook\\add_settings_before_merging';

    /** @var string Hook class of tool_mergeusers that runs after all tables, inside the merge transaction. */
    public const AFTER_HOOK = 'tool_mergeusers\\hook\\after_merged_all_tables';

    /** @var string[] Tables taken out of tool_mergeusers' generic processing, keyed by name. */
    public const EXCLUDED_TABLES = [
        'auth_lti_linked_login' => 'auth_lti_linked_login',
        'local_ltiaccountmerge_audit' => 'local_ltiaccountmerge_audit',
        'local_ltiaccountmerge_merge' => 'local_ltiaccountmerge_merge',
        'local_ltiaccountmerge_job' => 'local_ltiaccountmerge_job',
    ];

    /**
     * Settings this plugin contributes to tool_mergeusers.
     *
     * The table names are used as keys on purpose: tool_mergeusers combines the layers with array_replace_recursive(),
     * and a numerically keyed list would replace the entries of its default list with the same index (for example
     * "user_preferences" at index 0) instead of adding to it.
     *
     * @return array Settings in tool_mergeusers' format.
     */
    public static function merge_settings(): array {
        return ['exceptions' => self::EXCLUDED_TABLES];
    }

    /**
     * Callback for tool_mergeusers\hook\add_settings_before_merging.
     *
     * @param object $hook The hook; uses add_raw_settings(array).
     * @return void
     */
    public static function add_settings_before_merging(object $hook): void {
        $hook->add_raw_settings(self::merge_settings());
    }

    /**
     * Whether merges of accounts with LTI identities must go through the reviewed workflow (setting requireconsent).
     *
     * @return bool
     */
    public static function consent_required(): bool {
        $value = get_config('local_ltiaccountmerge', 'requireconsent');
        return $value === false || (bool) (int) $value;
    }

    /**
     * Callback for tool_mergeusers\hook\after_merged_all_tables.
     *
     * Runs inside the merge transaction of tool_mergeusers. In order:
     *  1. A merge started by this plugin's workflow ({@see executor}) marks its job (inmerge = 1). The flag is part of the
     *     merge transaction, so after a crash it tells the recovery whether the merge was committed.
     *  2. A merge of accounts with LTI identities that was NOT consented through the workflow is refused while the setting
     *     requireconsent is on: the error message makes tool_mergeusers roll the whole merge back.
     *  3. The LTI bindings and this plugin's own state follow the kept account ({@see merge_service}).
     *
     * @param object $hook The hook; uses the read-only properties toid and fromid, add_log() and add_error_message().
     * @return void
     */
    public static function after_merged_all_tables(object $hook): void {
        global $DB;
        $from = (int) $hook->fromid;
        $to = (int) $hook->toid;
        try {
            $job = executor::running_job($from, $to);
            if ($job) {
                $DB->set_field('local_ltiaccountmerge_job', 'inmerge', 1, ['id' => $job->id]);
            } else if (self::consent_required() && executor::involves_lti($from, $to)) {
                $hook->add_error_message(get_string('mergeconsentrequired', 'local_ltiaccountmerge'));
                return;
            }
            $result = merge_service::reconcile_within_merge($from, $to);
        } catch (\Throwable $e) {
            $hook->add_error_message(get_string('mergehookerror', 'local_ltiaccountmerge', s($e->getMessage())));
            return;
        }
        $hook->add_log(get_string('mergehooklog', 'local_ltiaccountmerge', (object) [
            'status' => $result->status,
            'moved' => $result->bindingsmoved,
            'conflicts' => $result->bindingconflicts + $result->targetduplicates,
            'skipped' => $result->bindingsskipped,
            'requests' => $result->requestscancelled + $result->requestshanded,
        ]));
    }
}
