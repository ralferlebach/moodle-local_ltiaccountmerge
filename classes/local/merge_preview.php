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
 * Dry run of a REAL account merge by tool_mergeusers (audit item AR-03): which tables would change, how, and whether the
 * merge may run at all.
 *
 * Unlike {@see merge_simulator} (a generic, tool-independent inventory), this class reproduces the decisions of the merge
 * tool that will actually run: it reads tool_mergeusers' effective configuration (built-in defaults, the settings every
 * installed plugin contributes through the hook add_settings_before_merging, the administrator's custom settings and the
 * setting excluded_exceptions) and applies the same table selection as tool_mergeusers' user_merger::init(). For every
 * table it reports the strategy (generic user-id update, compound-index merge, special table merger, handled by the
 * owning plugin inside the merge transaction, or skipped), who declared that strategy, the rows of both accounts and the
 * collisions.
 *
 * Fail-safe rules (no blind user-id update, spec AR-P05):
 *  - a table of a non-standard plugin (or of no known component) that has rows of the account to be merged away and would
 *    only get tool_mergeusers' generic user-id update, without any declaration by its owner, the administrator or
 *    tool_mergeusers itself, BLOCKS the merge;
 *  - a table whose install.xml declares a unique index over one user column, where both accounts have colliding rows and no
 *    compound index is declared, BLOCKS the merge (it would fail on the unique index; better refuse up front);
 *  - collisions in declared compound indexes, a second identity of the same platform (LTI binding) and a pseudonym of the
 *    same tool scope are CONFLICTS: the merge may run after an explicit acceptance, the documented rule decides.
 *
 * The class only reads. The fingerprint ({@see self::fingerprint()}) binds the administrator's consent to the decisions,
 * not to volatile counters: a new table with rows, a changed strategy or a new conflict invalidates the consent; more log
 * rows do not.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_preview {
    /** @var string Verdict: the merge may run. */
    public const READY = 'ready';
    /** @var string Verdict: the merge may run after the conflicts were accepted explicitly. */
    public const CONFLICTS = 'conflicts';
    /** @var string Verdict: the merge must not run. */
    public const BLOCKED = 'blocked';
    /** @var string Verdict: no merge tool (tool_mergeusers with hooks) is installed. */
    public const UNAVAILABLE = 'unavailable';

    /** @var string Table status: no rows of the account to be merged away. */
    public const T_NONE = 'none';
    /** @var string Table status: rows move to the kept account. */
    public const T_MOVE = 'move';
    /** @var string Table status: rows move, a documented rule resolves collisions. */
    public const T_CONFLICT = 'conflict';
    /** @var string Table status: rows stay with the merged-away account (table excluded, nobody moves it). */
    public const T_LEFT = 'left';
    /** @var string Table status: blocks the merge. */
    public const T_BLOCKED = 'blocked';

    /** @var string Strategy: tool_mergeusers' generic user-id update. */
    public const S_GENERIC = 'generic';
    /** @var string Strategy: generic merger with a declared compound index (collisions resolved row by row). */
    public const S_COMPOUND = 'compound';
    /** @var string Strategy: a dedicated table merger class of tool_mergeusers. */
    public const S_SPECIAL = 'special';
    /** @var string Strategy: excluded from the generic merger and handled by its owner inside the merge transaction. */
    public const S_OWNER = 'owner';
    /** @var string Strategy: excluded and not handled by anybody. */
    public const S_SKIPPED = 'skipped';

    /** @var string[] Categories the audit requires to be listed, in display order. */
    public const REQUIRED_CATEGORIES = ['grades', 'attempts', 'completion', 'enrolments', 'groups', 'ltibindings',
        'pseudonym', 'completionsync'];

    /** @var string[] Table name prefix => category; first match wins ("log" matches only exactly). */
    protected const CATEGORY_RULES = [
        'auth_lti_linked_login' => 'ltibindings',
        'enrol_lti_users' => 'ltibindings',
        'enrol_lti_user_resource_link' => 'ltibindings',
        'local_ltiaccountmerge_' => 'ltibindings',
        'ltisource_ltipseudonym_' => 'pseudonym',
        'local_lticompletionsync_' => 'completionsync',
        'grade_' => 'grades',
        'quiz_' => 'attempts',
        'question_attempt' => 'attempts',
        'question_usages' => 'attempts',
        'lesson_attempts' => 'attempts',
        'lesson_grades' => 'attempts',
        'scorm_attempt' => 'attempts',
        'scorm_scoes_track' => 'attempts',
        'h5pactivity_attempts' => 'attempts',
        'assign_submission' => 'attempts',
        'assign_grades' => 'attempts',
        'assign_user_' => 'attempts',
        'course_completions' => 'completion',
        'course_completion_crit_compl' => 'completion',
        'course_modules_completion' => 'completion',
        'course_modules_viewed' => 'completion',
        'user_enrolments' => 'enrolments',
        'role_assignments' => 'enrolments',
        'groups_members' => 'groups',
        'logstore_' => 'logs',
        'log' => 'logs',
        'config_log' => 'logs',
    ];

    /** @var array Effective tool configuration: skip, compoundindexes, userfieldnames. */
    private $config;

    /** @var array Table => list of declaring sources (component names, "tool_mergeusers", "admin"). */
    private $declared;

    /** @var string[] Components with an after_merged_all_tables callback. */
    private $handlers;

    /** @var array Table => special table merger class. */
    private $specialtables;

    /** @var string Version of tool_mergeusers, '' when unknown. */
    private $toolversion;

    /** @var string|null Reason why no merge tool is available, null when available. */
    private $unavailable;

    /** @var merge_simulator Source of the install.xml inventory and the owner classification. */
    private $inventory;

    /**
     * Constructor with an explicit configuration (unit tests); production code uses {@see self::from_environment()}.
     *
     * @param array $config ['skip' => string[] tables, 'compoundindexes' => array, 'userfieldnames' => array]
     * @param array $declared Table => list of declaring sources.
     * @param string[] $handlers Components with an after_merged_all_tables callback.
     * @param array $specialtables Table => special merger class.
     * @param string $toolversion Version string of the merge tool.
     * @param string|null $unavailable Reason code when no merge tool is usable.
     */
    public function __construct(
        array $config,
        array $declared,
        array $handlers,
        array $specialtables = [],
        string $toolversion = '',
        ?string $unavailable = null
    ) {
        $this->config = $config + ['skip' => [], 'compoundindexes' => [], 'userfieldnames' => []];
        $this->declared = $declared;
        $this->handlers = array_values(array_unique($handlers));
        $this->specialtables = $specialtables;
        $this->toolversion = $toolversion;
        $this->unavailable = $unavailable;
        $this->inventory = new merge_simulator();
    }

    /**
     * Whether a merge tool with the two hooks this workflow relies on is installed.
     *
     * @return bool
     */
    public static function tool_available(): bool {
        return class_exists('\\tool_mergeusers\\local\\user_merger') && class_exists('\\tool_mergeusers\\local\\config')
            && class_exists('\\tool_mergeusers\\hook\\add_settings_before_merging')
            && class_exists('\\tool_mergeusers\\hook\\after_merged_all_tables');
    }

    /**
     * Preview built from the installed tool_mergeusers (or an "unavailable" preview without it).
     *
     * @return self
     */
    public static function from_environment(): self {
        if (!self::tool_available()) {
            return new self([], [], [], [], '', 'notinstalled');
        }
        $config = \tool_mergeusers\local\config::instance();
        $exceptions = array_values((array) ($config->exceptions ?? []));
        $skip = array_flip($exceptions);
        // Same rule as tool_mergeusers' user_merger::__construct(): the setting excluded_exceptions re-enables tables.
        $excluded = array_flip(explode(',', (string) get_config('tool_mergeusers', 'excluded_exceptions')));
        if (!isset($excluded['none'])) {
            foreach (array_keys($excluded) as $table) {
                unset($skip[$table]);
            }
        }
        $special = [];
        foreach ((array) ($config->tablemergers ?? []) as $table => $class) {
            if ($table === 'default' || !is_string($class) || !class_exists($class)) {
                continue;
            }
            $special[$table] = $class;
            try {
                foreach ((array) (new $class())->get_tables_to_skip() as $other) {
                    $special[$other] = $class;
                }
            } catch (\Throwable $e) {
                // A merger that cannot be built here also fails in the merge itself; listing the table is enough.
                debugging('local_ltiaccountmerge: table merger ' . $class . ' not inspectable', DEBUG_DEVELOPER);
            }
        }
        [$declared, $handlers] = self::declarations();
        $version = (string) (\core_plugin_manager::instance()->get_plugin_info('tool_mergeusers')->versiondisk ?? '');
        return new self([
            'skip' => array_keys($skip),
            'compoundindexes' => (array) ($config->compoundindexes ?? []),
            'userfieldnames' => (array) ($config->userfieldnames ?? []),
        ], $declared, $handlers, $special, $version);
    }

    /**
     * Who declared which table: tool_mergeusers' defaults, the administrator's custom settings and every
     * add_settings_before_merging callback called on its own; and which components handle tables inside the merge.
     *
     * @return array [table => string[] sources, string[] components with an after_merged_all_tables callback]
     */
    private static function declarations(): array {
        $declared = [];
        self::declare_tables($declared, (array) \tool_mergeusers\local\default_db_config::$config, 'tool_mergeusers');
        $custom = json_decode((string) get_config('tool_mergeusers', 'customdbsettings'), true);
        if (is_array($custom)) {
            self::declare_tables($declared, $custom, 'admin');
        }
        $callbacks = self::callback_settings(function () {
            return new \tool_mergeusers\hook\add_settings_before_merging();
        });
        foreach ($callbacks as $component => $settings) {
            self::declare_tables($declared, $settings, $component);
        }
        foreach ($declared as $table => $sources) {
            $declared[$table] = array_values($sources);
        }
        return [$declared, self::after_handlers()];
    }

    /**
     * Record the tables a settings array mentions.
     *
     * @param array $declared Table => sources, updated.
     * @param array $settings Settings in tool_mergeusers' format.
     * @param string $source Declaring source.
     * @return void
     */
    public static function declare_tables(array &$declared, array $settings, string $source): void {
        foreach (['exceptions', 'compoundindexes', 'userfieldnames', 'tablemergers'] as $key) {
            $value = (array) ($settings[$key] ?? []);
            $tables = $key === 'exceptions' ? array_values($value) : array_keys($value);
            foreach ($tables as $table) {
                if (is_string($table) && $table !== 'default') {
                    $declared[$table][$source] = $source;
                }
            }
        }
    }

    /**
     * Settings every enabled add_settings_before_merging callback contributes, each called on a fresh hook object.
     *
     * Core keeps callbacks for hook classes that do not exist, so this also works with a stand-in hook (unit tests).
     *
     * @param callable $newhook Returns a new hook object (add_raw_settings(), get_settings()).
     * @return array Component => settings array.
     */
    public static function callback_settings(callable $newhook): array {
        $out = [];
        $manager = \core\di::get(\core\hook\manager::class);
        foreach ($manager->get_callbacks_for_hook(\local_ltiaccountmerge\mergeusers_callbacks::SETTINGS_HOOK) as $definition) {
            if (!empty($definition['disabled'])) {
                continue;
            }
            try {
                $hook = $newhook();
                call_user_func($definition['callback'], $hook);
                $settings = $hook->get_settings();
                $raw = [];
                foreach (['exceptions', 'compoundindexes', 'userfieldnames', 'tablemergers'] as $key) {
                    $raw[$key] = is_array($settings) ? (array) ($settings[$key] ?? []) : (array) ($settings->$key ?? []);
                }
                $component = (string) $definition['component'];
                $out[$component] = array_replace_recursive($out[$component] ?? [], $raw);
            } catch (\Throwable $e) {
                // A broken callback is the tool's problem in the real merge as well; it declares nothing here.
                debugging('local_ltiaccountmerge: settings callback of ' . $definition['component'] . ' failed', DEBUG_DEVELOPER);
            }
        }
        return $out;
    }

    /**
     * Components with an enabled after_merged_all_tables callback.
     *
     * @return string[]
     */
    public static function after_handlers(): array {
        $handlers = [];
        $manager = \core\di::get(\core\hook\manager::class);
        foreach ($manager->get_callbacks_for_hook(\local_ltiaccountmerge\mergeusers_callbacks::AFTER_HOOK) as $definition) {
            if (empty($definition['disabled'])) {
                $handlers[] = (string) $definition['component'];
            }
        }
        return array_values(array_unique($handlers));
    }

    /**
     * Data category of a table.
     *
     * @param string $table Table name without prefix.
     * @return string
     */
    public static function category(string $table): string {
        foreach (self::CATEGORY_RULES as $rule => $category) {
            // Every rule is a prefix, except "log" (the legacy log table) which must match exactly.
            if ($table === $rule || ($rule !== 'log' && strpos($table, $rule) === 0)) {
                return $category;
            }
        }
        return 'other';
    }

    /**
     * Build the preview for merging $sourceid into $targetid.
     *
     * @param int $sourceid Account to be merged away.
     * @param int $targetid Account to keep.
     * @return \stdClass
     * @throws \moodle_exception For identical, missing or deleted accounts.
     */
    public function build(int $sourceid, int $targetid): \stdClass {
        global $DB;
        if ($sourceid === $targetid) {
            throw new \moodle_exception('err_samemerge', 'local_ltiaccountmerge');
        }
        $users = [];
        foreach ([$sourceid, $targetid] as $id) {
            $user = $DB->get_record('user', ['id' => $id], 'id, username, deleted, suspended, auth');
            if (!$user || $user->deleted || isguestuser($user)) {
                throw new \moodle_exception('err_mergeuser', 'local_ltiaccountmerge');
            }
            $users[$id] = $user;
        }
        $report = (object) [
            'generated' => time(),
            'source' => (object) ['id' => $sourceid, 'username' => $users[$sourceid]->username,
                'siteadmin' => is_siteadmin($sourceid)],
            'target' => (object) ['id' => $targetid, 'username' => $users[$targetid]->username],
            'toolversion' => $this->toolversion,
            'available' => $this->unavailable === null,
            'unavailable' => $this->unavailable,
            'tables' => [],
            'categories' => [],
            'blockers' => [],
            'conflicts' => [],
            'verdict' => self::UNAVAILABLE,
            'hash' => '',
        ];
        if ($this->unavailable !== null) {
            $report->hash = self::fingerprint($report);
            return $report;
        }
        $schema = $this->inventory->schema();
        $skip = array_flip($this->config['skip']);
        $userfieldnames = $this->config['userfieldnames'];
        $defaultfields = (array) ($userfieldnames['default'] ?? []);
        foreach ($DB->get_tables(false) as $table) {
            if ($table === 'user') {
                continue;
            }
            $candidates = array_flip((array) ($userfieldnames[$table] ?? $defaultfields));
            $fields = [];
            foreach ($DB->get_columns($table, false) as $name => $column) {
                if (isset($candidates[$name])) {
                    $fields[] = $name;
                }
            }
            if (!$fields) {
                continue;
            }
            $report->tables[] = $this->inspect(
                $table,
                $fields,
                $schema[$table] ?? null,
                isset($skip[$table]),
                $sourceid,
                $targetid
            );
        }
        usort($report->tables, function (array $a, array $b): int {
            $order = [self::T_BLOCKED => 0, self::T_CONFLICT => 1, self::T_LEFT => 2, self::T_MOVE => 3, self::T_NONE => 4];
            return [$order[$a['status']], $a['table']] <=> [$order[$b['status']], $b['table']];
        });
        $categories = array_fill_keys(self::REQUIRED_CATEGORIES, null);
        foreach ($report->tables as $row) {
            $cat = $row['category'];
            if (!isset($categories[$cat])) {
                $categories[$cat] = null;
            }
            $entry = $categories[$cat] ?? ['category' => $cat, 'tables' => 0, 'affected' => 0, 'rowssource' => 0,
                'rowstarget' => 0, 'status' => self::T_NONE];
            $entry['tables']++;
            $entry['affected'] += $row['rowssource'] > 0 ? 1 : 0;
            $entry['rowssource'] += $row['rowssource'];
            $entry['rowstarget'] += $row['rowstarget'];
            $entry['status'] = self::worse($entry['status'], $row['status']);
            $categories[$cat] = $entry;
            if ($row['status'] === self::T_BLOCKED) {
                $report->blockers[] = ['table' => $row['table'], 'reason' => $row['reason']];
            } else if ($row['status'] === self::T_CONFLICT) {
                $report->conflicts[] = ['table' => $row['table'], 'reason' => $row['reason']];
            }
        }
        foreach ($categories as $cat => $entry) {
            $categories[$cat] = $entry ?? ['category' => $cat, 'tables' => 0, 'affected' => 0, 'rowssource' => 0,
                'rowstarget' => 0, 'status' => self::T_NONE];
        }
        $report->categories = array_values($categories);
        if ($report->source->siteadmin) {
            $report->blockers[] = ['table' => 'user', 'reason' => 'sourceadmin'];
        }
        $report->verdict = $report->blockers ? self::BLOCKED : ($report->conflicts ? self::CONFLICTS : self::READY);
        $report->hash = self::fingerprint($report);
        return $report;
    }

    /**
     * The worse of two table statuses.
     *
     * @param string $a Status.
     * @param string $b Status.
     * @return string
     */
    private static function worse(string $a, string $b): string {
        $rank = [self::T_NONE => 0, self::T_MOVE => 1, self::T_LEFT => 2, self::T_CONFLICT => 3, self::T_BLOCKED => 4];
        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    /**
     * Inspect one table.
     *
     * @param string $table Table.
     * @param string[] $fields User columns the merge tool would process.
     * @param array|null $meta install.xml entry.
     * @param bool $skipped Whether the tool skips the table.
     * @param int $a Source account.
     * @param int $b Target account.
     * @return array
     */
    private function inspect(string $table, array $fields, ?array $meta, bool $skipped, int $a, int $b): array {
        global $DB;
        $rowsa = 0;
        $rowsb = 0;
        foreach ($fields as $field) {
            $record = $DB->get_record_sql(
                "SELECT SUM(CASE WHEN {$field} = :a1 THEN 1 ELSE 0 END) AS ca,
                        SUM(CASE WHEN {$field} = :b1 THEN 1 ELSE 0 END) AS cb
                   FROM {{$table}}
                  WHERE {$field} IN (:a2, :b2)",
                ['a1' => $a, 'b1' => $b, 'a2' => $a, 'b2' => $b]
            );
            $rowsa += (int) $record->ca;
            $rowsb += (int) $record->cb;
        }
        $component = $meta['component'] ?? null;
        $owner = $component === 'local_ltiaccountmerge' ? 'self' : $this->inventory->owner_of($component);
        $declaredby = $this->declared[$table] ?? [];
        // The plugin that handles an excluded table inside the merge: a declaring component with an after_merged_all_tables
        // callback. tool_mergeusers' own callbacks only post-process (regrading, completion) and administrators have none.
        $handler = null;
        if ($skipped) {
            foreach ($declaredby as $source) {
                if (!in_array($source, ['tool_mergeusers', 'admin'], true) && in_array($source, $this->handlers, true)) {
                    $handler = $source;
                    if ($source === $component) {
                        break;
                    }
                }
            }
        }
        $compound = $this->config['compoundindexes'][$table] ?? null;
        if ($skipped) {
            $strategy = $handler !== null ? self::S_OWNER : self::S_SKIPPED;
        } else if (isset($this->specialtables[$table])) {
            $strategy = self::S_SPECIAL;
        } else if ($compound) {
            $strategy = self::S_COMPOUND;
        } else {
            $strategy = self::S_GENERIC;
        }
        $collisions = 0;
        $status = self::T_NONE;
        $reason = 'norows';
        if ($rowsa > 0) {
            $status = self::T_MOVE;
            $reason = 'move_' . $strategy;
            if ($strategy === self::S_SKIPPED) {
                $status = self::T_LEFT;
                $reason = 'left';
            } else if ($strategy === self::S_OWNER) {
                $collisions = $this->owner_conflicts($table, $a, $b);
                if ($collisions > 0) {
                    $status = self::T_CONFLICT;
                    $reason = 'ownerconflict';
                }
            } else if ($strategy === self::S_GENERIC && in_array($owner, ['thirdparty', 'unknown'], true) && !$declaredby) {
                $status = self::T_BLOCKED;
                $reason = 'undeclared';
            } else if ($strategy === self::S_COMPOUND && $rowsb > 0) {
                $collisions = $this->compound_collisions($table, $compound, $a, $b);
                if ($collisions > 0) {
                    $status = self::T_CONFLICT;
                    $reason = 'compoundconflict';
                }
            } else if ($strategy === self::S_GENERIC && $rowsb > 0 && $meta) {
                foreach ($meta['unique'] as $key) {
                    $userfields = array_values(array_intersect($key, $fields));
                    if (count($userfields) !== 1) {
                        continue;
                    }
                    $collisions += $this->unique_collisions($table, $key, $userfields[0], $a, $b);
                }
                if ($collisions > 0) {
                    $status = self::T_BLOCKED;
                    $reason = 'uniqueviolation';
                }
            }
        }
        return [
            'table' => $table,
            'component' => (string) $component,
            'owner' => $owner,
            'category' => self::category($table),
            'fields' => $fields,
            'strategy' => $strategy,
            'handler' => (string) $handler,
            'declaredby' => $declaredby,
            'rowssource' => $rowsa,
            'rowstarget' => $rowsb,
            'collisions' => $collisions,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * Collisions the owner of an excluded table resolves by a documented rule (known owners only).
     *
     * @param string $table Table.
     * @param int $a Source.
     * @param int $b Target.
     * @return int
     */
    private function owner_conflicts(string $table, int $a, int $b): int {
        global $DB;
        if ($table === 'auth_lti_linked_login') {
            // A second identity of the same platform is never moved onto the kept account (merge_service).
            $sql = 'SELECT COUNT(1)
                      FROM {auth_lti_linked_login} s
                     WHERE s.userid = :a
                       AND EXISTS (SELECT 1 FROM {auth_lti_linked_login} t
                                    WHERE t.userid = :b AND t.issuer256 = s.issuer256 AND t.sub256 <> s.sub256)';
            return (int) $DB->count_records_sql($sql, ['a' => $a, 'b' => $b]);
        }
        if ($table === 'ltisource_ltipseudonym_map' && $DB->get_manager()->table_exists($table)) {
            // The pseudonym plugin keeps the kept account's pseudonym and retires the other one of the same scope.
            $sql = 'SELECT COUNT(1)
                      FROM {ltisource_ltipseudonym_map} s
                      JOIN {ltisource_ltipseudonym_map} t ON t.typeid = s.typeid AND t.scopetype = s.scopetype
                           AND t.scopeid = s.scopeid AND t.ptype = s.ptype AND t.version = s.version
                     WHERE s.userid = :a AND t.userid = :b';
            return (int) $DB->count_records_sql($sql, ['a' => $a, 'b' => $b]);
        }
        return 0;
    }

    /**
     * Rows of the source whose declared compound-index partner of the target exists.
     *
     * @param string $table Table.
     * @param array $compound ['userfield' => string[], 'otherfields' => string[]]
     * @param int $a Source.
     * @param int $b Target.
     * @return int
     */
    private function compound_collisions(string $table, array $compound, int $a, int $b): int {
        global $DB;
        $userfields = array_values((array) ($compound['userfield'] ?? []));
        $others = array_values((array) ($compound['otherfields'] ?? []));
        $columns = $DB->get_columns($table, false);
        foreach (array_merge($userfields, $others) as $column) {
            if (!isset($columns[$column])) {
                return 0;
            }
        }
        $total = 0;
        foreach ($userfields as $userfield) {
            $total += $this->unique_collisions($table, array_merge([$userfield], $others), $userfield, $a, $b);
        }
        return $total;
    }

    /**
     * Number of rows of A whose unique-key partner row of B exists.
     *
     * @param string $table Table.
     * @param string[] $key Key fields.
     * @param string $userfield User column inside the key.
     * @param int $a Source.
     * @param int $b Target.
     * @return int
     */
    private function unique_collisions(string $table, array $key, string $userfield, int $a, int $b): int {
        global $DB;
        $others = array_values(array_diff($key, [$userfield]));
        if (!$others) {
            return (int) ($DB->record_exists($table, [$userfield => $a]) && $DB->record_exists($table, [$userfield => $b]));
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
     * Fingerprint of the decisions of a preview (what the administrator consents to).
     *
     * Included: both account ids, availability, verdict, and per table with rows of the source: name, strategy, status,
     * handler and declarations. Excluded on purpose: counters (they change with every page view of an active account).
     *
     * @param \stdClass $report Preview.
     * @return string 64 hex characters.
     */
    public static function fingerprint(\stdClass $report): string {
        $tables = [];
        foreach ($report->tables as $row) {
            if ($row['rowssource'] > 0 || $row['status'] !== self::T_NONE) {
                $tables[] = [$row['table'], $row['strategy'], $row['status'], $row['handler'], $row['declaredby']];
            }
        }
        usort($tables, function ($x, $y) {
            return strcmp($x[0], $y[0]);
        });
        return hash('sha256', json_encode([
            'v' => 1,
            'source' => (int) $report->source->id,
            'target' => (int) $report->target->id,
            'available' => (bool) $report->available,
            'verdict' => $report->verdict,
            'tables' => $tables,
        ]));
    }

    /**
     * Compact summary to keep with a job: categories and affected tables, ids and counters only (no personal data).
     *
     * @param \stdClass $report Preview.
     * @return array
     */
    public static function summary(\stdClass $report): array {
        $tables = [];
        foreach ($report->tables as $row) {
            if ($row['rowssource'] > 0 || $row['rowstarget'] > 0) {
                $tables[] = [
                    'table' => $row['table'],
                    'category' => $row['category'],
                    'strategy' => $row['strategy'],
                    'handler' => $row['handler'],
                    'declaredby' => $row['declaredby'],
                    'status' => $row['status'],
                    'reason' => $row['reason'],
                    'rowssource' => $row['rowssource'],
                    'rowstarget' => $row['rowstarget'],
                    'collisions' => $row['collisions'],
                ];
            }
        }
        return [
            'verdict' => $report->verdict,
            'toolversion' => $report->toolversion,
            'categories' => $report->categories,
            'tables' => $tables,
        ];
    }
}
