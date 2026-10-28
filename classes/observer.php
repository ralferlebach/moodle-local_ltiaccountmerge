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

/**
 * Event observers of local_ltiaccountmerge.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * An external tool (tool_mergeusers) merged two accounts: queue the reconciliation of this plugin's own state.
     *
     * The observer only records the pair. The work runs in {@see task\reconcile_merge}, never inside the request of the
     * merging plugin. The event only exists when tool_mergeusers is installed.
     *
     * @param \core\event\base $event Event carrying other['usersinvolved'] with toid and fromid.
     * @return void
     */
    public static function user_merged(\core\event\base $event): void {
        $involved = (array) ($event->other['usersinvolved'] ?? []);
        $to = (int) ($involved['toid'] ?? 0);
        $from = (int) ($involved['fromid'] ?? 0);
        if ($to > 0 && $from > 0 && $to !== $from) {
            task\reconcile_merge::queue($from, $to);
        }
    }

    /**
     * tool_mergeusers reports a failed (rolled back) merge: record it in the audit trail when LTI accounts were involved.
     *
     * Audit rows written inside the merge transaction are rolled back with it, so a refusal by this plugin's callback
     * (no consent) would otherwise leave no trace in this plugin's audit trail. Only ids and the tool's log id are kept.
     * Merges of the reviewed workflow are audited by {@see local\merge\executor}.
     *
     * @param \core\event\base $event Event carrying other['usersinvolved'] and other['logid'].
     * @return void
     */
    public static function user_merge_failed(\core\event\base $event): void {
        $involved = (array) ($event->other['usersinvolved'] ?? []);
        $to = (int) ($involved['toid'] ?? 0);
        $from = (int) ($involved['fromid'] ?? 0);
        if ($to <= 0 || $from <= 0 || $to === $from) {
            return;
        }
        // A merge of the reviewed workflow is audited by the executor itself.
        if (local\merge\executor::running_job($from, $to) || !local\merge\executor::involves_lti($from, $to)) {
            return;
        }
        local\audit::log('mergefailed', local\audit::FAILED, 'merge', 0, $to, [
            'source' => $from,
            'mergelogid' => (int) ($event->other['logid'] ?? 0),
        ]);
    }
}
