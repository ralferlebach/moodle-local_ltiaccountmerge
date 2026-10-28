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

namespace local_ltiaccountmerge\output;

/**
 * Renderer of local_ltiaccountmerge. All markup lives in Mustache templates.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * An error page body: branded, catalogue text and the support ID.
     *
     * @param string $code Error code.
     * @param string|null $supportid Support ID or null.
     * @return string
     */
    public function error_page(string $code, ?string $supportid): string {
        $code = \local_ltiaccountmerge\local\error_catalogue::normalise($code);
        $text = \local_ltiaccountmerge\local\error_catalogue::get($code);
        $brand = \local_ltiaccountmerge\local\error_catalogue::branding();
        return $this->render_from_template('local_ltiaccountmerge/error_page', [
            'title' => $text['title'],
            'body' => $text['body'],
            'brandname' => $brand['name'],
            'logourl' => $brand['logourl'],
            'hassupport' => $brand['support'] !== '',
            'support' => $brand['support'],
            'hassupportid' => $supportid !== null,
            'supportid' => $supportid,
            'code' => $code,
        ]);
    }

    /**
     * The effective-mode preview table.
     *
     * @param array $data Template context.
     * @return string
     */
    public function mode_preview(array $data): string {
        return $this->render_from_template('local_ltiaccountmerge/mode_preview', $data);
    }

    /**
     * Link pre-flight card.
     *
     * @param array $data Template context.
     * @return string
     */
    public function link_preview(array $data): string {
        return $this->render_from_template('local_ltiaccountmerge/link_preview', $data);
    }

    /**
     * Merge simulation report.
     *
     * @param \stdClass $report Report from the simulator.
     * @param bool $onlyaffected Hide tables with no rows for either account.
     * @param array $exporturls Export links (csv, json).
     * @return string
     */
    public function merge_report(\stdClass $report, bool $onlyaffected, array $exporturls): string {
        $rows = [];
        $hidden = 0;
        foreach ($report->tables as $row) {
            if ($onlyaffected && $row['status'] === 'ok') {
                $hidden++;
                continue;
            }
            $rows[] = [
                'table' => $row['table'],
                'component' => $row['component'] !== '' ? $row['component'] : get_string('unknown', 'local_ltiaccountmerge'),
                'owner' => get_string('owner_' . $row['owner'], 'local_ltiaccountmerge'),
                'category' => $row['category'],
                'fields' => implode(', ', $row['fields']),
                'rowssource' => $row['rowssource'],
                'rowstarget' => $row['rowstarget'],
                'collisions' => $row['collisions'],
                'status' => get_string('status_' . $row['status'], 'local_ltiaccountmerge'),
                'statuscode' => $row['status'],
                'reason' => $row['reason'],
            ];
        }
        $conflicts = [];
        foreach ($report->conflicts as $c) {
            $conflicts[] = ['table' => $c['table'], 'type' => get_string('status_' . $c['type'], 'local_ltiaccountmerge'),
                'detail' => $c['detail']];
        }
        return $this->render_from_template('local_ltiaccountmerge/merge_report', [
            'source' => s($report->source->username),
            'target' => s($report->target->username),
            'verdict' => get_string('verdict_' . $report->verdict, 'local_ltiaccountmerge'),
            'verdictcode' => $report->verdict,
            'blocked' => $report->blocked,
            'noexecutor' => get_string('noexecutor', 'local_ltiaccountmerge'),
            'hasconflicts' => !empty($conflicts),
            'conflicts' => $conflicts,
            'tables' => $rows,
            'hidden' => $hidden,
            'hashidden' => $hidden > 0,
            'csvurl' => $exporturls['csv'] ?? '',
            'jsonurl' => $exporturls['json'] ?? '',
            'generated' => userdate($report->generated),
        ]);
    }
    /**
     * Preview (dry run) of a real merge.
     *
     * @param \stdClass $report Report from {@see \local_ltiaccountmerge\local\merge_preview::build()}.
     * @return string
     */
    public function merge_preview(\stdClass $report): string {
        $s = 'local_ltiaccountmerge';
        $categories = [];
        foreach ($report->categories as $cat) {
            $categories[] = [
                'category' => get_string('cat_' . $cat['category'], $s),
                'tables' => $cat['tables'],
                'affected' => $cat['affected'],
                'rowssource' => $cat['rowssource'],
                'rowstarget' => $cat['rowstarget'],
                'status' => get_string('tstatus_' . $cat['status'], $s),
                'statuscode' => $cat['status'],
                'required' => in_array($cat['category'], \local_ltiaccountmerge\local\merge_preview::REQUIRED_CATEGORIES, true),
            ];
        }
        $tables = [];
        $hidden = 0;
        foreach ($report->tables as $row) {
            if ($row['rowssource'] === 0 && $row['rowstarget'] === 0) {
                $hidden++;
                continue;
            }
            $tables[] = [
                'table' => $row['table'],
                'component' => $row['component'] !== '' ? $row['component'] : get_string('unknown', $s),
                'category' => get_string('cat_' . $row['category'], $s),
                'fields' => implode(', ', $row['fields']),
                'strategy' => get_string('strategy_' . $row['strategy'], $s),
                'declaredby' => $row['declaredby'] ? implode(', ', $row['declaredby']) : '-',
                'handler' => $row['handler'] !== '' ? $row['handler'] : '-',
                'rowssource' => $row['rowssource'],
                'rowstarget' => $row['rowstarget'],
                'collisions' => $row['collisions'],
                'status' => get_string('tstatus_' . $row['status'], $s),
                'statuscode' => $row['status'],
                'reason' => get_string('reason_' . $row['reason'], $s),
            ];
        }
        $list = function (array $items): array {
            $out = [];
            foreach ($items as $item) {
                $out[] = ['table' => $item['table'], 'reason' => get_string('reason_' . $item['reason'], 'local_ltiaccountmerge')];
            }
            return $out;
        };
        return $this->render_from_template('local_ltiaccountmerge/merge_preview', [
            'source' => s($report->source->username),
            'target' => s($report->target->username),
            'available' => $report->available,
            'verdict' => get_string('mverdict_' . $report->verdict, $s),
            'verdictcode' => $report->verdict,
            'blocked' => in_array($report->verdict, ['blocked', 'unavailable'], true),
            'hasblockers' => !empty($report->blockers),
            'blockers' => $list($report->blockers),
            'hasconflicts' => !empty($report->conflicts),
            'conflicts' => $list($report->conflicts),
            'categories' => $categories,
            'tables' => $tables,
            'hidden' => $hidden,
            'hashidden' => $hidden > 0,
            'hash' => substr($report->hash, 0, 12),
            'toolversion' => $report->toolversion,
            'generated' => userdate($report->generated),
        ]);
    }

    /**
     * One merge job.
     *
     * @param \stdClass $job Job row.
     * @param \moodle_url $cancelurl Cancel action (used only while the job waits).
     * @return string
     */
    public function merge_job(\stdClass $job, \moodle_url $cancelurl): string {
        $s = 'local_ltiaccountmerge';
        $summary = json_decode((string) $job->preview, true) ?: [];
        $tables = [];
        foreach ((array) ($summary['tables'] ?? []) as $row) {
            $tables[] = [
                'table' => $row['table'],
                'strategy' => get_string('strategy_' . $row['strategy'], $s),
                'status' => get_string('tstatus_' . $row['status'], $s),
                'rowssource' => (int) $row['rowssource'],
                'rowstarget' => (int) $row['rowstarget'],
            ];
        }
        return $this->render_from_template('local_ltiaccountmerge/merge_job', [
            'id' => (int) $job->id,
            'source' => self::user_label((int) $job->sourceuserid),
            'target' => self::user_label((int) $job->targetuserid),
            'status' => get_string('jstatus_' . $job->status, $s),
            'statuscode' => $job->status,
            'outcome' => $job->outcome !== null && $job->outcome !== '' ? get_string('joutcome_' . $job->outcome, $s) : '-',
            'recovered' => (bool) $job->recovered,
            'supportid' => (string) $job->supportid,
            'hassupportid' => !empty($job->supportid),
            'mergelogid' => (int) $job->mergelogid,
            'timeconsented' => userdate($job->timeconsented),
            'timestarted' => $job->timestarted ? userdate($job->timestarted) : '-',
            'timefinished' => $job->timefinished ? userdate($job->timefinished) : '-',
            'consentby' => self::user_label((int) $job->consentby),
            'cancancel' => $job->status === 'consented',
            'cancelurl' => $cancelurl->out(false),
            'tables' => $tables,
            'hastables' => !empty($tables),
        ]);
    }

    /**
     * Recent merge jobs.
     *
     * @param array $jobs Job rows.
     * @param \moodle_url $url Page URL.
     * @return string
     */
    public function merge_jobs(array $jobs, \moodle_url $url): string {
        $s = 'local_ltiaccountmerge';
        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = [
                'id' => (int) $job->id,
                'url' => (new \moodle_url($url, ['job' => $job->id]))->out(false),
                'source' => self::user_label((int) $job->sourceuserid),
                'target' => self::user_label((int) $job->targetuserid),
                'status' => get_string('jstatus_' . $job->status, $s),
                'statuscode' => $job->status,
                'time' => userdate($job->timemodified),
            ];
        }
        return $this->render_from_template('local_ltiaccountmerge/merge_jobs', ['jobs' => $rows, 'hasjobs' => !empty($rows)]);
    }

    /**
     * Username of an account for administrators, or its id when it no longer exists.
     *
     * @param int $userid User id.
     * @return string
     */
    protected static function user_label(int $userid): string {
        $user = \core_user::get_user($userid, 'id, username, deleted');
        if (!$user || $user->deleted) {
            return '#' . $userid;
        }
        return s($user->username) . ' (#' . $userid . ')';
    }
}
