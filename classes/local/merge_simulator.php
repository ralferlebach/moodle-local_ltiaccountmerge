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
 * Merge SIMULATION (AR-P05): inventory, conflict matrix and verdict for merging user account A into B.
 *
 * This class only READS. It performs no write whatsoever (asserted by a test through the DML write counter). The MVP
 * deliberately has no merge executor (R4, gate AR-G4): a generic "update every userid" is unsafe, so the verdict is
 * always "full merge not available", and the simulation tells the operator why and what a future per-plugin adapter
 * would have to cover.
 *
 * Inventory sources, combined:
 *  - the live database metadata (every table and column that exists, including tables no install.xml describes);
 *  - the install.xml files of core, subsystems and all installed plugins (owner component, foreign keys to user.id,
 *    unique keys/indexes used for collision detection).
 *
 * Status per table: ok (no rows of either user), preserve (immutable audit/log data that must not be rewritten),
 * review (rows exist, operator strategy needed), conflict (unique-key collision between A and B), blocked (rows exist
 * in a table of a third-party or unknown component without a registered adapter).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class merge_simulator {
    /** @var string Table without rows for either user. */
    public const OK = 'ok';
    /** @var string Immutable audit data, keep as is. */
    public const PRESERVE = 'preserve';
    /** @var string Rows exist, strategy to be decided. */
    public const REVIEW = 'review';
    /** @var string Unique-key collision. */
    public const CONFLICT = 'conflict';
    /** @var string Unknown / third-party table with rows: blocks a full merge. */
    public const BLOCKED = 'blocked';

    /** @var string[] Tables whose rows are audit/log evidence and must never be rewritten (AR-P06). */
    protected const IMMUTABLE = ['log', 'config_log', 'logstore_standard_log', 'grade_grades_history', 'grade_items_history',
        'grade_categories_history', 'grade_outcomes_history', 'scale_history', 'local_ltiaccountmerge_audit'];

    /** @var string[] Prefix => data category (AR-P06 resource list). */
    protected const CATEGORIES = [
        'grade_grades' => 'grades', 'grade_' => 'grades', 'question_attempt' => 'attempts', 'quiz_attempts' => 'attempts',
        'quiz_' => 'attempts', 'assign_' => 'submissions', 'assignsubmission_' => 'submissions', 'badge_' => 'badges',
        'competency_' => 'competency', 'course_modules_completion' => 'completion', 'course_completions' => 'completion',
        'user_enrolments' => 'enrolments', 'enrol_' => 'enrolments', 'groups_members' => 'groups', 'role_assignments' => 'roles',
        'post' => 'notes', 'tool_dataprivacy' => 'privacy', 'logstore_' => 'logs', 'log' => 'logs',
        'auth_lti_linked_login' => 'ltibinding',
    ];

    /** @var string[] Components that declared an adapter or an explicit "no merge needed" statement. */
    protected $adaptedcomponents;

    /** @var array|null Cached schema scan. */
    protected $schema;

    /**
     * Constructor.
     *
     * @param string[] $adaptedcomponents Third-party components for which an adapter exists. The plugin itself is always
     *                                    covered (its audit is preserved, the rest is reviewed).
     */
    public function __construct(array $adaptedcomponents = []) {
        $this->adaptedcomponents = array_merge(['local_ltiaccountmerge'], $adaptedcomponents);
    }

    /**
     * Scan install.xml files of core, subsystems and plugins.
     *
     * @return array table => ['component' => string, 'userfks' => string[], 'unique' => string[][]]
     */
    protected function scan_schema(): array {
        global $CFG;
        if ($this->schema !== null) {
            return $this->schema;
        }
        $dirs = ['core' => $CFG->dirroot . '/lib'];
        foreach (\core_component::get_core_subsystems() as $name => $dir) {
            if ($dir) {
                $dirs['core_' . $name] = $dir;
            }
        }
        foreach (\core_component::get_plugin_types() as $type => $unused) {
            foreach (\core_component::get_plugin_list($type) as $name => $dir) {
                $dirs[$type . '_' . $name] = $dir;
            }
        }
        $schema = [];
        foreach ($dirs as $component => $dir) {
            $file = $dir . '/db/install.xml';
            if (!is_readable($file)) {
                continue;
            }
            $xml = @simplexml_load_file($file);
            if (!$xml || !isset($xml->TABLES->TABLE)) {
                continue;
            }
            foreach ($xml->TABLES->TABLE as $table) {
                $entry = ['component' => $component, 'userfks' => [], 'unique' => []];
                foreach ($table->KEYS->KEY ?? [] as $key) {
                    $type = (string) $key['TYPE'];
                    $fields = array_map('trim', explode(',', (string) $key['FIELDS']));
                    if (
                        in_array($type, ['foreign', 'foreign-unique'], true) && (string) $key['REFTABLE'] === 'user'
                            && count($fields) === 1
                    ) {
                        $entry['userfks'][] = $fields[0];
                    }
                    if (in_array($type, ['unique', 'foreign-unique', 'primary'], true) && $type !== 'primary') {
                        $entry['unique'][] = $fields;
                    }
                }
                foreach ($table->INDEXES->INDEX ?? [] as $index) {
                    if ((string) $index['UNIQUE'] === 'true') {
                        $entry['unique'][] = array_map('trim', explode(',', (string) $index['FIELDS']));
                    }
                }
                $schema[(string) $table['NAME']] = $entry;
            }
        }
        $this->schema = $schema;
        return $schema;
    }

    /**
     * Public view of the install.xml inventory (also used by {@see merge_preview}).
     *
     * @return array table => ['component' => string, 'userfks' => string[], 'unique' => string[][]]
     */
    public function schema(): array {
        return $this->scan_schema();
    }

    /**
     * Public view of the owner classification (also used by {@see merge_preview}).
     *
     * @param string|null $component Owner component from install.xml, null when unknown.
     * @return string core, standard, adapter, thirdparty or unknown
     */
    public function owner_of(?string $component): string {
        return $this->owner_class($component);
    }

    /**
     * Whether a column name looks like a reference to a user.
     *
     * @param string $name Column name.
     * @return bool
     */
    protected static function looks_like_user_column(string $name): bool {
        return (bool) preg_match('/userid|^user_id$|^usermodified$|^user$|^userfrom$|^userto$/i', $name);
    }

    /**
     * Data category of a table.
     *
     * @param string $table Table name (no prefix).
     * @return string
     */
    protected static function category(string $table): string {
        foreach (self::CATEGORIES as $prefix => $category) {
            if ($table === $prefix || strpos($table, $prefix) === 0) {
                return $category;
            }
        }
        return 'other';
    }

    /**
     * Classify the owner of a table.
     *
     * @param string|null $component Owner component from install.xml, null when unknown.
     * @return string core, standard, adapter, thirdparty or unknown
     */
    protected function owner_class(?string $component): string {
        if ($component === null) {
            return 'unknown';
        }
        if (in_array($component, $this->adaptedcomponents, true)) {
            return 'adapter';
        }
        if ($component === 'core' || strpos($component, 'core_') === 0) {
            return 'core';
        }
        $info = \core_plugin_manager::instance()->get_plugin_info($component);
        return ($info && $info->is_standard()) ? 'standard' : 'thirdparty';
    }

    /**
     * Run the simulation.
     *
     * @param int $sourceid Account that would be merged away (A).
     * @param int $targetid Account that would survive (B).
     * @return \stdClass Report.
     * @throws \moodle_exception For identical or unusable accounts.
     */
    public function simulate(int $sourceid, int $targetid): \stdClass {
        global $DB;
        if ($sourceid === $targetid) {
            throw new \moodle_exception('err_samemerge', 'local_ltiaccountmerge');
        }
        $users = [];
        foreach ([$sourceid, $targetid] as $id) {
            $user = $DB->get_record('user', ['id' => $id], 'id, username, deleted, suspended');
            if (!$user || $user->deleted) {
                throw new \moodle_exception('err_mergeuser', 'local_ltiaccountmerge');
            }
            $users[$id] = $user;
        }
        $schema = $this->scan_schema();
        $rows = [];
        foreach ($DB->get_tables(false) as $table) {
            if ($table === 'user') {
                continue;
            }
            $columns = $DB->get_columns($table);
            $fields = [];
            foreach ($columns as $name => $column) {
                $isfk = isset($schema[$table]) && in_array($name, $schema[$table]['userfks'], true);
                if (($isfk || self::looks_like_user_column($name)) && $column->meta_type === 'I') {
                    $fields[] = $name;
                }
            }
            if (!$fields) {
                continue;
            }
            $rows[] = $this->inspect_table($table, $fields, $schema[$table] ?? null, $sourceid, $targetid);
        }
        $order = [self::BLOCKED => 0, self::CONFLICT => 1, self::REVIEW => 2, self::PRESERVE => 3, self::OK => 4];
        usort($rows, function ($a, $b) use ($order) {
            return [$order[$a['status']], $a['table']] <=> [$order[$b['status']], $b['table']];
        });
        $counts = array_fill_keys(array_keys($order), 0);
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }
        $conflicts = [];
        foreach ($rows as $row) {
            if ($row['status'] === self::BLOCKED || $row['status'] === self::CONFLICT) {
                $conflicts[] = [
                    'table' => $row['table'],
                    'type' => $row['status'],
                    'detail' => $row['reason'],
                ];
            }
        }
        $verdict = $counts[self::BLOCKED] ? 'blocked' : ($counts[self::CONFLICT] ? 'conflicts' :
            ($counts[self::REVIEW] ? 'review' : 'clear'));
        return (object) [
            'generated' => time(),
            'source' => (object) ['id' => $sourceid, 'username' => $users[$sourceid]->username],
            'target' => (object) ['id' => $targetid, 'username' => $users[$targetid]->username],
            'tables' => $rows,
            'conflicts' => $conflicts,
            'counts' => $counts,
            'verdict' => $verdict,
            'blocked' => $counts[self::BLOCKED] > 0,
            'blockedtables' => array_column(array_filter($rows, function ($r) {
                return $r['status'] === self::BLOCKED;
            }), 'table'),
            // The MVP has no merge executor: R4 is out of scope until a data-integrity acceptance (gate AR-G4).
            'fullmergeavailable' => false,
        ];
    }

    /**
     * Inspect one table.
     *
     * @param string $table Table name.
     * @param string[] $fields User columns.
     * @param array|null $meta Schema entry or null when no install.xml describes the table.
     * @param int $a Source user.
     * @param int $b Target user.
     * @return array Row of the matrix.
     */
    protected function inspect_table(string $table, array $fields, ?array $meta, int $a, int $b): array {
        global $DB;
        $rowsa = 0;
        $rowsb = 0;
        foreach ($fields as $field) {
            $record = $DB->get_record_sql(
                "SELECT SUM(CASE WHEN {$field} = :a1 THEN 1 ELSE 0 END) AS ca, SUM(CASE WHEN {$field} = :b1 THEN 1 ELSE 0 END) AS cb
                   FROM {{$table}}
                  WHERE {$field} IN (:a2, :b2)",
                ['a1' => $a, 'b1' => $b, 'a2' => $a, 'b2' => $b]
            );
            $rowsa += (int) $record->ca;
            $rowsb += (int) $record->cb;
        }
        $component = $meta['component'] ?? null;
        $owner = $this->owner_class($component);
        $collisions = 0;
        $keys = [];
        if ($meta && $rowsa > 0 && $rowsb > 0) {
            foreach ($meta['unique'] as $key) {
                $userfields = array_values(array_intersect($key, $fields));
                if (count($userfields) !== 1) {
                    continue;
                }
                $count = $this->count_collisions($table, $key, $userfields[0], $a, $b);
                if ($count > 0) {
                    $collisions += $count;
                    $keys[] = '(' . implode(', ', $key) . ')';
                }
            }
        }
        $status = self::OK;
        $reason = 'no rows of either account';
        if ($rowsa + $rowsb > 0) {
            if (in_array($table, self::IMMUTABLE, true) || strpos($table, 'logstore_') === 0) {
                $status = self::PRESERVE;
                $reason = 'immutable audit/log data: keep as evidence, never rewrite';
            } else if (in_array($owner, ['unknown', 'thirdparty'], true)) {
                $status = self::BLOCKED;
                $reason = $owner === 'unknown' ? 'table is not described by any installed component and has no adapter'
                    : "no migration adapter for component {$component}";
            } else if ($collisions > 0) {
                $status = self::CONFLICT;
                $reason = "{$collisions} colliding row(s) on unique key " . implode(' and ', $keys);
            } else {
                $status = self::REVIEW;
                $reason = 'rows exist; per-resource strategy must be chosen by the operator';
            }
        }
        return [
            'table' => $table,
            'component' => $component ?? '',
            'owner' => $owner,
            'category' => self::category($table),
            'fields' => $fields,
            'rowssource' => $rowsa,
            'rowstarget' => $rowsb,
            'collisions' => $collisions,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * Number of rows of A whose unique-key partner row of B exists.
     *
     * @param string $table Table.
     * @param string[] $key Unique key fields.
     * @param string $userfield The user column inside the key.
     * @param int $a Source.
     * @param int $b Target.
     * @return int
     */
    protected function count_collisions(string $table, array $key, string $userfield, int $a, int $b): int {
        global $DB;
        $others = array_values(array_diff($key, [$userfield]));
        if (!$others) {
            return 1;
        }
        $on = [];
        foreach ($others as $field) {
            $on[] = "ta.{$field} = tb.{$field}";
        }
        $sql = "SELECT COUNT(1)
                  FROM {{$table}} ta
                  JOIN {{$table}} tb ON " . implode(' AND ', $on) . "
                 WHERE ta.{$userfield} = :a AND tb.{$userfield} = :b";
        return (int) $DB->count_records_sql($sql, ['a' => $a, 'b' => $b]);
    }

    /**
     * CSV export of the matrix.
     *
     * @param \stdClass $report Report from simulate().
     * @return string
     */
    public static function to_csv(\stdClass $report): string {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['table', 'component', 'owner', 'category', 'fields', 'rows_source', 'rows_target', 'collisions',
            'status', 'reason'], ',', '"', '\\');
        foreach ($report->tables as $row) {
            fputcsv($handle, [$row['table'], $row['component'], $row['owner'], $row['category'], implode('|', $row['fields']),
                $row['rowssource'], $row['rowstarget'], $row['collisions'], $row['status'], $row['reason']], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * JSON export of the whole report.
     *
     * @param \stdClass $report Report from simulate().
     * @return string
     */
    public static function to_json(\stdClass $report): string {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
