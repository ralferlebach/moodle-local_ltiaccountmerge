# Acceptance: local_ltiaccountmerge 0.3.0 (alpha)

Release 0.3.0, version 2026102700, family audit 2026-10-27 (development branches), items AR-01 to AR-04 and FAM.
Status classes: **code present** · **automated tests (local)** (executed by us in the development container) · **CI passed**
(GitHub Actions of the owner: **not executed by us**; this release has no development CI run yet) · **functionally accepted**
(owner sign-off: **none**) · **not verified** · **core gate open** (needs a core change). Skips are never counted as green;
fixtures alone never count for an E2E item. Combinations: `45/pg` = Moodle 4.5 + PostgreSQL 16, `45/ma` = 4.5 + MariaDB
11.4, `53/pg` = 5.3 + PostgreSQL 17, `53/ma` = 5.3 + MariaDB 11.4. Commands and raw counts: [TESTING.md](TESTING.md).
Development SHA: none yet (delivered as a ZIP; the owner pushes it to `development`), so the local evidence below refers to
the delivered source tree; the owner's development CI on the resulting SHA is separate, later evidence.

## 1. Audit items (section 3.3)

| Item | Outcome | Status | Code location | Evidence (tests) | Honest boundary |
| --- | --- | --- | --- | --- | --- |
| **AR-01** [P0] real component/version, language packs, complete source and test structure, migration/rename plan | **done.** Component `local_ltiaccountmerge`, version `2026102700`, release `0.3.0`, requires 4.5, supported [405, 503]; en and de language packs with identical keys; classes, db, templates, tests (unit, integration, Behat, Playwright, load), docs. README "Migration and rename plan": template replaced (template-only files listed in `db/removed_files.txt`), no upgrade path from `local_ltiaccountreconcile` 0.1.0 alpha (fresh install, nothing migrated), normal upgrade from 0.2.0. Upgrade step `2026102700` | automated tests (local); CI passed: not executed by us | `version.php`; `db/upgrade.php:35-77`; `db/install.xml` (table `local_ltiaccountmerge_job`); `db/removed_files.txt`; README | `install_test` (schema = install.xml, defaults, version/release/CHANGELOG, no `{{...}}` placeholder, no template leftover), `upgrade_test` (2026102502 -> 2026102700 from a rebuilt 0.2.0 state, setting kept, repeatable), `error_catalogue_test::test_language_files_have_identical_keys`, `test_all_used_strings_are_defined`; static checks (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings) on 45/pg, 45/ma, 53/pg, 53/ma | "CI install on 4.5-5.3": the owner's CI has not run this source; our local runs cover 4.5 and 5.3 only (5.0-5.2: the lead's full matrix). The CI workflows are owned by the CI agent (template workflows `load-*.yml`, `playwright.yml` still reference template paths). |
| **AR-02** [P0] three provisioning modes in a real first launch; pages in the real core flow; safe error state; API/hook reference per version | **done within the core boundary.** Real first launches on the two-site stack in both directions (5.3 as tool; 4.5 as tool via reverse registration): mode 1 provisions without dialog; mode 2 shows the CORE dialog (both options) with this plugin's notice above it; mode 3 shows the core dialog without "create", login + "Link this account" binds the existing account; a disabled resource gives the core error page with the notice and no personal data; the plugin's error page shows only the support ID. Per-version reference: DOCUMENTATION.md 3.5 | E2E (local stack, 2026-10-27): 8 / 8 passed | `classes/hook_callbacks.php` (`before_standard_top_of_body_html_generation`, `HINT_SCRIPTS`); `templates/first_launch_hint.mustache`; `error.php` | Playwright `firstlaunch.spec.js` (8 tests, screenshots); `ar_gates_test::test_ar_g1_*`; `core_modes_test` (real `complete_login()`), `hook_callbacks_test::test_hint_*` | **Function-limited alternative, declared:** core offers no hook to replace or translate the binding dialog or the core launch error texts (AR-G1 negative on 4.5 to 5.3, identical files); the plugin adds a notice and its own link and error pages. 5.0-5.2: by code identity, not executed. Accessibility: automated axe-core only (`ltiaccountmerge.spec.js` story 5). |
| **AR-03** [P0] merge and link-only strictly separated; merge through tool_mergeusers with preview, consent, crash-recovery journal, no blind user-id update; multi-plugin integration; rollback/conflict/concurrency protocols | **done.** Separate pages, capabilities and code for linking and merging. Preview of the real `tool_mergeusers` configuration (eight required categories, strategies, declarations, blockers for undeclared plugin tables); consent bound to the fingerprint, typed username, conflict acceptance; job journal with in-transaction mark; recovery task; consent rule for merges started elsewhere; LTI bindings handled inside the merge. Protocols: DOCUMENTATION.md 3.4 | automated tests (local) incl. integration with the real `tool_mergeusers` (4.5 and 5.3, PostgreSQL and MariaDB) | `classes/local/merge_preview.php`, `classes/local/merge/executor.php`, `classes/local/merge/mergeusers_runner.php`, `classes/mergeusers_callbacks.php:105-129`, `classes/observer.php`, `classes/task/execute_merge.php`, `classes/task/recover_merges.php`, `merge.php`, `cli/merge.php` | `merge_preview_test` (7 methods), `merge_executor_test` (8), `mergeusers_callbacks_test`, `provider_test::test_merge_jobs`, Behat `ui_negative.feature`; integration `tests/integration/run.sh` merge part (157 / 157 checks OK on 4.5, merge part of 222 OK on 5.3; all four combos) | `tool_mergeusers` is not installed in the shared PHPUnit trees (unit tests use a stand-in with the tool's transaction semantics); the real tool runs only in the integration harness. The UI page `merge.php` with the tool installed was rendered by the harness (`step.php render`), not clicked through in a browser. `local_lticompletionsync` declares its tables itself (observed: strategy "owner"); `tool_ltidiagnostics_obs` is **undeclared** and blocks merges of users with diagnostics observations until its owner (or an administrator) declares it. Semantic correctness of other plugins' declarations is theirs. |
| **AR-04** [P1] automatic <-> prompt without losing links, manual admin change, overlap, DST, concurrent cron and launch, failed task; real scheduled-task, parallel and UI negative tests | **done.** Real `scheduled_task.php` runs on scratch sites; parallel task processes and launches; killed task; unwritable resource; DST under `faketime`; Behat negative UI tests | automated tests (local) (4.5 and 5.3, PostgreSQL and MariaDB) | `classes/local/mode_applier.php` (now ordered by resource id), `classes/local/policy.php::wall_to_ts()`, `classes/task/apply_modes.php` | integration schedule part (65 / 65 checks OK per 4.5 combo; included in the 222 per 5.3 combo); `ui_negative.feature` (19 / 19 scenarios on 4.5 and 5.3 PostgreSQL); PHPUnit `policy_test`, `mode_applier_test`, `hook_callbacks_test` (exact boundary second, unchanged) | Launch probes in the harness call `auth_plugin_lti::complete_login()` in CLI processes (real core code, no HTTP); real HTTP launches at a boundary with a parallel cron loop were not combined in one stack run. DST runs start the process clock at the instant and let it run (instants before a boundary 30 s early). A per-resource failure does not fail the task (deliberate, documented). |
| **FAM-00** branch policy | README "Branch policy" | code present | README | - | Rulesets on GitHub are the owner's task. |
| **FAM-01** component/source/release map | README migration table; this file | code present | README, CHANGELOG | - | |
| **FAM-02** no lock-step, optional integrations degrade | no dependency declared; without `tool_mergeusers` the merge page shows the reduced function | automated tests (local) | `version.php`, `merge.php` | `merge_preview_test::test_environment`, Behat "merge page ... without a merge tool" | |
| **FAM-03** account merge with all active identity mappings and a completion outbox, no identity mix-up or foreign grading | **done** for the merge side (pseudonym mappings of `ltisource_ltipseudonym`, outbox and receiver state of `local_lticompletionsync`, grades, groups, attempts, bindings) | automated tests (local), integration | see AR-03 | integration `pair1`, `pair2`, `final` | Single scratch site per run (platform and tool tables on one site); the two-site launch chain after a merge was not run. |
| **FAM-05** evidence separation | this file separates local runs, owner CI (not executed) and acceptance (none) | - | - | - | |


## 2. Local runs of 0.3.0 (2026-10-27)

All runs on 2026-10-27 with the final sources. Local evidence only; owner CI has not run.

| Layer | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
| --- | --- | --- | --- | --- |
| `ltirun all` (phplint, validate, savepoints, mustache, codechecker, phpdoc, phpunit) | all OK; 297 tests, 1852 assertions | all OK; 297 tests, 1852 assertions | all OK; 297 tests, 1868 assertions (30 PHPUnit deprecations) | all OK; 297 tests, 1868 assertions (30 PHPUnit deprecations) |
| Integration with the real `tool_mergeusers` 2026081302 (`tests/integration/run.sh`, scratch sites) | merge 157 OK + schedule 65 OK, 0 FAIL | merge 157 OK + schedule 65 OK, 0 FAIL | 222 OK, 0 FAIL | 222 OK, 0 FAIL |
| Behat (`ltibehat`, non-JS) | 19 / 19 scenarios, 182 steps | not run | 19 / 19 scenarios, 182 steps | not run |
| Playwright `firstlaunch.spec.js` (two-site stack A = 4.5, B = 5.3, both directions) | 8 / 8 passed (2.9 min), screenshots kept as evidence | | | |

Per-cell 4.5 integration: the merge part was re-run after a seeding fix (the 4.5 question generator needs the Composer
autoloader in CLI); the schedule part is from the run before that fix, which does not touch the schedule part.
`ltifullmatrix` was not run (brief). 5.0 to 5.2 not executed.

## 3. Negative controls (mutation checks, 0.3.0)

One defect per run in the work directory, `ltirun 53 mariadb` with the filter
`merge_executor_test|merge_preview_test`, then byte-identical restore and re-mirror (tree identical to the work directory
afterwards). RED = at least one test failed, which is the expected result.

| ID | Defect | Result | Failing tests |
| --- | --- | --- | --- |
| M1 | undeclared plugin table no longer blocks (blind user-id update allowed) | RED | test_blockers, test_consent_refusals |
| M2 | consent not bound to the preview fingerprint | RED | test_consent_refusals |
| M3 | preview not re-checked before the run (stale consent executes) | RED | test_stale_and_expired |
| M4 | no in-transaction mark (crash recovery cannot tell committed from rolled back) | RED | test_consent_and_execute |
| M5 | recovery ignores the in-transaction mark (committed merge reported as aborted) | RED | test_recovery |
| M6 | merges of LTI accounts without consent are not refused | RED | test_merge_without_consent_is_refused_for_lti_accounts |
| M7 | recovery takes over jobs whose owner is alive | RED | test_recovery |
| M8 | second open job per account allowed | RED | test_consent_refusals |
| M9 | no site-wide execution lock (parallel merges) | RED | test_concurrency_and_cancel |

## 4. Open risks and boundaries

- `tool_ltidiagnostics_obs` (tool_ltidiagnostics) is not declared to `tool_mergeusers`: the preview blocks merges of users
  with diagnostics observations. The integration harness declares it as an administrator (`step.php declare-undeclared`);
  the owning plugin should declare it (lead coordinates).
- With `requireconsent` on (default from 0.3.0), merges of LTI accounts started outside this plugin (tool_mergeusers UI or
  CLI, scripts such as a gate that merges directly) are rolled back with "consent required". Use `cli/merge.php` or switch
  the setting off for such scripts.
- `tool_mergeusers` is not part of the shared PHPUnit trees; the real tool is exercised only in the integration harness
  (scratch sites, private tree). CI has no job for that harness yet (needs a workflow change by the CI owner).
- The core binding dialog and core launch errors cannot be replaced or translated by a plugin (no hook, 4.5 to 5.3).
- 5.0 to 5.2: covered by code identity of the core files, not executed locally.
- DST checks use `faketime` (installed via apt on this machine) with a running clock; a frozen clock hangs Moodle loops.
- Owner CI has not run any of this; all results here are local.

## History: acceptance record of 0.2.0 (2026-10-25)

Status classes (project rules, 0.2.0): **code present** (implemented, no test run claimed), **automated tests (local)**
(run by the author in the development container), **CI passed** (GitHub Actions: **not executed**, no run exists),
**functionally accepted** (signed off by the owner: **none**), **not verified**.

Nothing in this release is "CI passed" or "functionally accepted". Combinations: `45/pg` = Moodle 4.5 + PostgreSQL 16,
`45/ma` = 4.5 + MariaDB 11.4, `53/pg` = 5.3 + PostgreSQL 17, `53/ma` = 5.3 + MariaDB 11.4. Counts, dates and commands:
[TESTING.md](TESTING.md). Date of the 0.2.0 runs: 2026-10-25.

### Post-rename cleanup re-run (2026-10-25, version 2026102502 unchanged)

| Run | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
|---|---|---|---|---|
| `ltirun ... local_ltiaccountmerge all` (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings, PHPUnit) | OK, 259 tests, 1371 assertions | OK, 259 tests, 1371 assertions | OK, 259 tests, 1387 assertions; 26 deprecations (accepted) | OK, 259 tests, 1387 assertions; 26 deprecations (accepted) |
| `ltibehat ... all` (non-JS) | 11 scenarios / 99 steps passed | 11 scenarios / 99 steps passed | 11 scenarios / 99 steps passed | 11 scenarios / 99 steps passed |

* Internal abbreviation derived from the old name replaced by `ltiam`/`LTIAM` (old form: CHANGELOG; support ID format `LTIAM-XXXXXXXX`, CSS classes, ids, data attributes, Playwright variables). `error_catalogue_test`, `hook_callbacks_test` and the Behat error-page scenario cover the new names.
* Uninstall: no gap. Own tables, settings, tasks (scheduled and ad hoc) and the brand logo file area are removed by core; `db/uninstall.php` already restores the provisioning modes. Uninstall leftovers, checked for real: on separate scratch databases (`moodle_upg` 4.5/PostgreSQL and `moodle53_upg` 5.3/PostgreSQL, gate data plus seeded plugin state) all five family plugins were uninstalled with `admin/cli/uninstall_plugins.php --run` and the core tables that can hold plugin state were searched for rows naming the component (config, user preferences, files, scheduled and ad hoc tasks, capabilities and role capabilities, message providers, external functions, events, tables, cache definitions); then reinstalled. Result on both: nothing left except `local_ltixsembed/consumed` in the cache configuration, which core rebuilds from the `db/caches.php` files on disk at the next upgrade (its data is purged by the uninstall).
* CI workflows: validated locally (YAML parser, job graph, matrix 4.5 to 5.3 x PostgreSQL/MariaDB, names and referenced paths; see
  TESTING.md "CI workflows"). CI itself: **not executed**.
* Playwright: re-run on the two-site stack by the lead on 2026-10-25 after the abbreviation change: 5/5 stories passed (Chromium, A = 4.5/pgsql, B = 5.3/pgsql).

### 0. Re-audit 0.2.0: item by item

| Item | Outcome | Status | Evidence | Honest boundary |
| --- | --- | --- | --- | --- |
| FAM-01 release truth | `version.php` 2026102502 / `0.2.0`; **renamed** by owner decision 2026-10-25 (former component name: CHANGELOG.md, NAMING.md); **no upgrade path from 0.1.0 alpha**, `db/upgrade.php` has no steps; CHANGELOG, README, DOCUMENTATION, this file updated | automated tests (local) | `install_test::*` (3 tests: no-op upgrade function, all six tables equal install.xml and setting defaults after a fresh install, metadata/CHANGELOG say 0.2.0 and the rename); fresh install after uninstalling the old plugin on all four test databases | The former upgrade tests (0.1.0 data, pre-release tables, F1 journal marking) and `tools/upgrade_from_010.sh` were removed with the upgrade steps. Uninstall of this plugin tested at function level (`test_uninstall_restores_baselines`), not through the admin UI; the uninstall of the old component (same hook) ran on the four test databases. Restore of a course backup: not verified. Moodle 5.0-5.2: not verified. |
| AR-01 first-login and error pages | Boundary stated: the core dialog and core launch errors cannot be replaced, restyled or translated (no extension point, proven). Supported fallback implemented: optional notice above the dialog and the launch error pages (`firsthint`, catalogue code `firstloginhint`) | automated tests (local) for the hook and its gating; Playwright story 4 for the rendered page (see TESTING.md for the run) | `ar_gates_test::test_ar_g1_*` (4 tests), `hook_callbacks_test::test_hint_*` (5 tests), `core_modes_test` (three core modes against the real `complete_login()`), Behat "first-launch hint text is editable" | The notice is not a replacement: it is generic text, shown to everybody who sees those pages, and only where the theme renders the standard top-of-body output (Boost does). No claim of general customisability of core screens. 5.0-5.2 not run. |
| AR-02 merge executor | Reconciliation for `tool_mergeusers` merges: since the F1 fix the binding decision runs INSIDE the merge transaction through the tool's two hooks (binding table excluded from its generic merger; `after_merged_all_tables` -> `merge_service::reconcile_within_merge`); the success event still queues a deduplicated ad hoc task as idempotent fallback; per-pair lock, one transaction, journal table; modelled on FlexAccess (report conflicts, do not block) | automated tests (local) with stand-ins of the tool's hook classes; real `tool_mergeusers`: run by the lead's Gate 2 against 2026102501 only (it found F1); the fix is verified with the real plugin by the Gate 2 re-run after the rename (`/home/claude/gates`, run `rename2-20261025-1834`, real `tool_mergeusers`, all four combinations): pair 1 binding moved inside the merge, pair 2 conflict kept on A2 and journalled `partial`, re-queue deduplicated, re-run a no-op (two expectations of the gate script were revised to the documented fix behaviour first, see /home/claude/gates/gate2_verify.php) | `merge_service_test::*` (14 tests: binding move, idempotence, already-moved, conflict, duplicate handling, setting off, requests, audit kept, invalid pairs, busy lock, full rollback and retry, column accounting vs `install.xml`, observer and task, incomplete payloads, retry while locked), `ar_gates_test::test_ar_g4_executor_*`, `mergeusers_callbacks_test::*` (9 tests: registration quiet without the tool on 4.5 and 5.3, settings layering keeps the tool's defaults, settings callback, inline move, Gate 2 pair 2 conflict kept and reported, setting off, generic-merger safety net, whole-merge rollback, busy pair left to the task) | **Not a general merge.** Grades, attempts, enrolments, groups, files and other plugins' tables are the merging tool's job. `tool_mergeusers` is not installed in the test trees: hooks are exercised with stand-ins that copy its hook contracts (`tests/fixtures/*_standin.php`) inside an emulated merge transaction (same order: tables, hook, commit only without error message), and the observer with a stand-in event. A conflict leaves the identity on the merged-away (usually suspended) account until an administrator decides. An administrator can re-enable the table in `tool_mergeusers` (`excluded_exceptions`); then the plugin only reports (`targetduplicates`). One core column (`auth_lti_linked_login.userid`) is written directly because core has no API. The audit trail is deliberately not rewritten. |
| AR-03 window boundaries, races, side effects | Lock wait, fresh instant after the wait, optimistic re-check, per-resource rollback and isolation, restore on uninstall, DST and overlap tests | automated tests (local) | `mode_applier_test::*` (see DOCUMENTATION.md 3.2), `policy_test::test_unusual_transitions` and 3 more DST tests, `hook_callbacks_test::test_launch_at_window_*`, real second PHP process holding the lock | A burst of real parallel launches through the two-site stack is **not tested**; `tests/load/apply_race.php` (8 processes at the boundary) is run by hand, result in TESTING.md. A launch that already read the mode keeps it. The pre-launch step applies all governed resources. Backup/restore of a course while a policy is applied: not verified. |
| FAM-04 production quality | Checklist below | automated tests (local) for the items marked, otherwise not verified | section 5 | Accessibility: only automated axe-core on the plugin pages and the hint (Playwright story 5), no screen-reader or keyboard test; no penetration test. |

Negative controls (mutation checks) for this release: see section 6.

### 1. Acceptance criteria of the specification (section E)

| # | Criterion | Status | Evidence | Combos run | Remarks |
| --- | --- | --- | --- | --- | --- |
| E1 | The three core provisioning modes were tested against all new modes; existing bindings stay stable | automated tests (local) | `core_modes_test::test_mode_matrix_against_real_login` (3 baselines x 3 policy modes, real `complete_login()`), `core_modes_test::test_policy_change_never_rebinds`, `mode_applier_test::test_only_the_two_modes_change_and_uuid_is_stable`, `test_no_other_configuration_is_lost` | all four | The plugin has no new "modes": it drives the three core modes. |
| E2a | Date and time including DST and the boundary second | automated tests (local) | `policy_test::test_boundary_second`, `test_dst_*`, `test_unusual_transitions` (8 data sets), `test_daily_window_starting_in_the_overlap`, `test_no_second_is_lost_or_doubled_around_the_gap`, `mode_applier_test::test_window_boundaries_apply_and_restore`, `test_windows_over_whole_dst_days`, `test_adjacent_and_overlapping_windows`, `hook_callbacks_test::test_launch_at_window_start_sees_new_mode_only_with_hook` | all four | Playwright story 1 repeats the boundary with real launches (one stack). |
| E2b | ... and simultaneous launches | automated tests (local) for locking with a real second process; **not verified** for a burst of real launches | `mode_applier_test::test_lock_held_by_another_process`, `test_waiting_process_uses_a_fresh_instant`, `test_busy_lock_changes_nothing`, `test_concurrent_manual_save_is_not_overwritten`, `link_service_test::test_busy_lock_fails_closed`, `test_race_competing_binding_rolls_back` | all four | Burst via `tests/load/apply_race.php` by hand only. |
| E3a | Customised UI localised | automated tests (local) | `error_catalogue_test::test_every_code_is_localised_en_and_de`, `test_language_files_have_identical_keys`, `test_all_used_strings_are_defined`, `hook_callbacks_test::test_hint_text_is_localised_editable_and_safe` | all four | German strings were written by the author, not reviewed by a native translator. |
| E3b | ... accessible | partly: automated tests (local) for what axe-core can detect; the rest **not verified** | Mustache templates use `caption`, `scope`, `role="status"`, `role="note"` and semantic headings; Playwright story 5 runs axe-core (WCAG 2.0/2.1 A and AA tags) on the main region of every plugin page and on the hint | - | Automated detection covers only a fraction of the problems. No screen-reader, keyboard-only or zoom test was run. |
| E3c | ... safe and without identity leaks | automated tests (local) | `error_catalogue_test::test_xss_rejected`, `test_tampered_row_is_not_rendered`, `test_error_page_rendering`, `link_request_test::test_mail_leaks_no_identity`; Behat "error page ... unknown code", "override an error text but scripts are rejected" | all four (Behat too) | |
| E4 | Merge simulation names all conflicts; unknown plugin tables block a full merge | automated tests (local) | `merge_simulator_test::*` (names conflicts, fake plugin table blocks, read-only, exports, inventory) | all four | The simulator cannot know semantic conflicts of unknown plugins (it blocks instead). |
| E5a | Permissions | automated tests (local) | `plugin_test::test_capabilities`, `test_default_role_permissions`, `test_delegated_role_reaches_only_its_admin_page`, `link_service_test::test_requires_capability`, `link_request_test::*` | all four | |
| E5b | Proof of ownership | automated tests (local) | `link_request_test::test_full_challenge_flow`, `test_other_user_cannot_confirm`, `test_wrong_codes_lock_the_request`, `test_expired_request` | all four | |
| E5c | Repeated requests and race conditions | automated tests (local) (single process plus one real second process for the apply lock) | `link_request_test::test_new_request_cancels_older_pending_one`, `link_service_test::test_repeat_is_idempotent`, `test_race_competing_binding_rolls_back` | all four | Multi-process races of the link flow not tested. |
| E6a | Practical cases with grades, enrolments, groups | partly: automated tests (local) for the simulation; the executor reconciles only this plugin's state | `merge_simulator_test::test_names_conflicts_for_shared_course_and_grades`, `merge_service_test::*` | all four | No grades, enrolments or groups are moved by this plugin. |
| E6b | ... restore, privacy, logs | privacy: automated tests (local); restore: **not verified** | `provider_test::*` (incl. the merge journal), `merge_simulator_test::test_inventory_is_broad` | all four | |
| E7 | All no-fork gates checked for 4.5 to 5.3 | partly: 4.5 and 5.3 automated tests (local); 5.0 to 5.2 **not verified** | `ar_gates_test::*` (10 tests) | all four | |

### 2. Requirements (Lastenheft AR-L, Pflichtenheft AR-P)

| ID | Requirement | Status | Evidence / remark |
| --- | --- | --- | --- |
| AR-L01 | Branding, multilingual, predefined texts | automated tests (local) | `error_catalogue_test::test_branding`, `test_lookup_order_and_fallback`; logo via `pluginfile` (code present; no pluginfile download test) |
| AR-L02 | Safe error states with support ID | automated tests (local) | `error_catalogue_test::test_support_id`, `link_service_test::test_bad_identity_refused`, Behat error page |
| AR-L03 | Schedule by timezone and by role / deployment / course | automated tests (local) | `resolver_test::*`, `mode_applier_test::test_scopes_and_legacy`, Playwright story 1 (site scope only in the browser) |
| AR-L04 | Administrator-initiated reconciliation with preview | automated tests (local) | `link_service_test::test_preview_does_not_write`, Playwright story 3, Behat. Reconciliation = linking; the merge is done by a merge tool, this plugin reconciles after it |
| AR-L05 | Explicit identity confirmation | automated tests (local) | `link_service_test::test_requires_attestation_and_reason`, Playwright story 3 |
| AR-L06 | No automatic merge by equal name or e-mail | automated tests (local) | `link_service_test::test_same_name_and_email_do_not_link` |
| AR-L07 | Existing LTI bindings preserved | automated tests (local) | `link_service_test::test_existing_binding_to_other_account_refused`, `core_modes_test::test_policy_change_never_rebinds`, `merge_service_test::test_conflict_is_reported_and_nothing_is_overwritten` |
| AR-L08 | Roles, groups, grades, attempts, logs, plugin foreign keys treated cleanly | **partly**: this plugin's own foreign keys (policy, text, request, audit, journal) are all accounted for (`test_every_user_column_is_accounted_for`); the LTI binding table is handled inside the `tool_mergeusers` transaction since the F1 fix; all other tables are inventoried by the simulation and left to the merging tool; **not verified** end to end with the real `tool_mergeusers` after the fix | `mergeusers_callbacks_test::*` |
| AR-L09 | Complete audit trail | automated tests (local) | `mode_applier_test::test_event_and_audit`, `test_failure_of_one_resource_is_isolated`, `link_service_test::*`, `merge_service_test::test_binding_follows_the_survivor` |
| AR-L10 | Fail-safe and rollback | automated tests (local) | `link_service_test::test_race_competing_binding_rolls_back`, `merge_service_test::test_failure_rolls_back_everything`, `mergeusers_callbacks_test::test_failure_rolls_back_the_whole_merge`, `mode_applier_test::test_failure_of_one_resource_is_isolated`, `hook_callbacks_test::test_failure_is_swallowed` |
| AR-P01 | Configurable UI templates with limited HTML, CSP/XSS protection, translations | automated tests (local) | `error_catalogue_test::test_xss_rejected`, `test_safe_html_accepted`; no Content-Security-Policy header is sent (not implemented) |
| AR-P02 | Error catalogue (launch error, invalid token, expired session, taken target, missing consent, merge conflict) | code present for the entries, automated tests (local) for rendering | `error_catalogue_test::test_codes`, `test_every_code_is_localised_en_and_de`. The plugin raises only its own flow codes; core's own launch errors cannot be intercepted (AR-G1) |
| AR-P03 | Policy scheduler with intervals, DST, priority, preview, manual override expiry, mode computed live | automated tests (local) | see E2a; `resolver_test::test_override_wins_until_expiry`; preview page Behat and Playwright story 2 |
| AR-P04 | Link workflow with proof and atomic change through the core API | automated tests (local) | see E5; `ar_gates_test::test_ar_g3_create_user_binding_semantics` |
| AR-P05 | Merge workflow: inventory, conflict matrix, operator approval, maintenance lock, backups | **partly**: inventory and conflict matrix (simulation); event-driven reconciliation executor; operator approval, maintenance lock and backups are **not implemented** | |
| AR-P06 | Data strategy per resource | partly: statuses ok / preserve / review / conflict / blocked per table in the simulation; the executor acts on its own tables and one core column only | `merge_simulator_test::test_review_and_preserve_statuses`, `merge_service_test::*` |
| AR-P07 | A missing confirmed security check ends the process without data change | automated tests (local) | `link_service_test::test_requires_attestation_and_reason`, `link_request_test::test_wrong_codes_lock_the_request`, `merge_service_test::test_invalid_pairs` |
| AR-G1 | Core binding dialog replaceable? | answered: **no**; notice above it possible | `ar_gates_test::test_ar_g1_*` |
| AR-G2 | Mode settable before core reads it? | answered: no hook; official writer + optional `after_config` step works | `ar_gates_test::test_ar_g2_*`, `hook_callbacks_test::*`, Playwright story 1 |
| AR-G3 | Later link core-conformant? | partly: add-only through the public API; no remove / move API (the executor's one direct column update is the documented exception) | `ar_gates_test::test_ar_g3_*` |
| AR-G4 | Generic merge? | answered: **no** (negative); event-driven reconciliation instead | `ar_gates_test::test_ar_g4_*` |

### 3. Quality and platform items

| Item | Status | Evidence |
| --- | --- | --- |
| Installs cleanly on 4.5 and 5.3 (PostgreSQL, MariaDB) | automated tests (local) | fresh install through `ltirun ... upgrade` after uninstalling the old component on all four test databases; `install_test`; Moodle 5.0 to 5.2 not verified |
| Static checks (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings) | automated tests (local) | see TESTING.md matrix |
| Privacy provider | automated tests (local) | `provider_test::*` |
| Capabilities with risk bitmasks, events, scheduled tasks, observer | automated tests (local) | `plugin_test::*`, `merge_service_test::test_observer_*` |
| Language: `lang/en` and `lang/de` identical keys | automated tests (local) | `error_catalogue_test::test_language_files_have_identical_keys` |
| Behat (non-JS) | automated tests (local) | TESTING.md |
| Playwright end-to-end | automated tests (local), one stack only (A 4.5/pg to B 5.3/pg), 5 / 5 stories passed on 2026-10-25 | TESTING.md; `tests/playwright/ltiaccountmerge.spec.js` |
| GitHub Actions | code present, **CI = not executed** | The workflows were adapted but never run |
| Accessibility audit | automated axe-core check only (story 5); manual audit **not verified** | |
| Uninstall, backup / restore | uninstall: automated tests (local) at function level, plus a real uninstall on scratch databases (4.5 and 5.3, PostgreSQL) 2026-10-25 with no leftovers (see "Post-rename cleanup re-run"); backup / restore: **not verified** | |
| Multi-process concurrency (parallel launches) | partly: second real process holds the lock (automated); burst script by hand; burst through the stack **not verified** | `tests/load/README.md` |
| Real LTI platforms other than the Moodle 4.5 test platform | **not verified** | |
| Real `tool_mergeusers` | automated tests (local): Gate 2 re-run after the rename (`/home/claude/gates`, run `rename2-20261025-1834`, real `tool_mergeusers`, all four combinations): green (pair 1 main scenario, pair 2 conflict reported `partial`, pair 3, dedup, re-run no-op). Earlier, against 2026102501, pair 2 was red (finding F1). | `/home/claude/gates/out/rename2-20261025-1834/<45\|53>/<pgsql\|mariadb>/g2_verify.txt` |

### 4. Defects found and fixed in this rework

* **F1 (release gates, medium).** With the real `tool_mergeusers`, the binding rules of `merge_service` never applied: the
  tool's generic merger moved `auth_lti_linked_login.userid` before the success event, so two identities of one platform
  ended on the survivor with a `done` journal, and `mergemovebindings = 0` could not stop the move. Fix in 2026102502: the
  table is excluded through `add_settings_before_merging`; the decision runs in `after_merged_all_tables` inside the merge
  transaction (conflict kept on the merged-away account, journalled `partial`, audited; failure -> error -> whole merge
  rolled back); post-state check `targetduplicates`. Evidence class:
  automated tests (local) with stand-ins; real plugin: Gate 2 re-run after the rename (`/home/claude/gates`, run `rename2-20261025-1834`, real `tool_mergeusers`, all four combinations), green.
* The task re-audited and rewrote the counters of an unchanged open conflict on every run; it now only counts the attempt.

* A launch that waited for the apply lock across a window boundary evaluated the instant from before the wait (could apply
  the old mode); the clock is now read after the lock was obtained.
* A failure while writing one resource left its delegated transaction open and aborted the run for all other resources.
* The edit of an administrator between the applier's snapshot and its write could be overwritten (lost update).
* (Before the rename) upgrade path for development databases with a pre-release table layout; obsolete since 0.2.0 is a
  fresh install, the step was removed.
* Earlier (0.1.0): users holding only one of the plugin's capabilities could not reach its page; the link page showed an
  empty form when the identity was already linked; tables were renamed to the full component prefix.

### 5. FAM-04 production-quality checklist

| Topic | Result | Evidence |
| --- | --- | --- |
| Rights and contexts | system context, `require_capability` on every page; executor has no web entry | `plugin_test::*`, `link_service_test::test_requires_capability` |
| CSRF | forms carry sesskey; delete/simulate actions `require_sesskey()` | review; Behat submits the forms; no dedicated CSRF test |
| SSRF | the plugin fetches nothing | by design (issuer checked against registered platforms) |
| Sensitive data in logs | no secret or raw LTI identifier in audit, journal or events | `provider_test::test_export`, `merge_service_test` (journal holds ids and counters) |
| API instead of SQL shortcuts | enrol API for the modes; `create_user_binding()` for linking; **one direct column update** on `auth_lti_linked_login` in the executor (no core API), switchable | `ar_gates_test::test_ar_g4_executor_is_event_driven_and_touches_one_core_table` |
| Language strings | no hard-coded UI strings; en = de keys | `test_language_files_have_identical_keys`, `test_all_used_strings_are_defined` |
| PostgreSQL / MariaDB | full suite on both | TESTING.md matrix |
| Accessibility | semantic templates; automated axe-core check (Playwright story 5); **no screen-reader or keyboard test** | partly verified |
| Race / concurrency | see AR-03; `tests/load/apply_race.php 8` PASS on all four combinations | section 0, TESTING.md |
| Test data cleanup | PHPUnit resets; Playwright and `apply_race.php` clean up; `e2e-reset` restores the stack | TESTING.md |
| Performance / N+1 | the pre-launch step runs one policy query and one resource query per launch; no N+1 beyond one `effective()` call per resource (in-memory); not measured | not verified |

### 6. Negative controls (mutation checks)

Method: `/home/claude/.../scratchpad/mut/apply.py` (not shipped) applied seven one-line defects to the working tree, the
named tests were run on Moodle 4.5 / PostgreSQL via `ltirun ... phpunit --filter`, then the originals were restored (byte
comparison with the backups) and the complete suite was run again (green, see TESTING.md). Run on 2026-10-25.

| # | Defect introduced | Finding | Test that turned red | Result |
| --- | --- | --- | --- | --- |
| M1 | `mode_applier::apply_all` reads the clock before waiting for the lock (the pre-0.2.0 behaviour) | AR-03 stale instant | `mode_applier_test::test_waiting_process_uses_a_fresh_instant` | red (as intended) |
| M2 | no rollback when a resource fails inside the transaction | AR-03 half-written state | `mode_applier_test::test_failure_of_one_resource_is_isolated` | red |
| M3 | optimistic re-read inside the transaction disabled | AR-03 lost update vs. manual save | `mode_applier_test::test_concurrent_manual_save_is_not_overwritten` | red |
| M4 | merge no longer detects a conflicting binding of the survivor | AR-02 overwrite | `merge_service_test::test_conflict_is_reported_and_nothing_is_overwritten` | red |
| M5 | first-login hint on every script, not only the three | AR-01 scope of the notice | `hook_callbacks_test::test_hint_scripts`, `test_hint_is_added_only_when_enabled_and_only_on_its_scripts` | red (both) |
| M6 | upgrade step does not adopt pre-release tables | FAM-01 upgrade | former `upgrade_test::test_upgrade_adopts_prerelease_tables` (removed with the rename) | red (before the rename) |
| M7 | merge reports work although nothing is left to do (not idempotent) | AR-02 idempotence | `merge_service_test::test_idempotent` | red |

All seven defects were detected: 8 of the 10 selected tests turned red (one error, seven failures). The two that stayed green
are `merge_service_test::test_failure_rolls_back_everything` (the merge rollback; none of the seven defects touches it) and
`mode_applier_test::test_idempotent`, which the name filter also selected. No mutation was run on Moodle 5.3 or MariaDB, and none against the Behat or Playwright layers.

### F1 fix (2026102502)

Method: `/home/claude/.../scratchpad/mut2/one.py` (not shipped) applied ONE defect at a time, ran
`ltirun 45 pgsql ... phpunit --filter 'mergeusers_callbacks_test|test_upgrade_marks_merges'` (10 tests), restored the file and
compared it byte for byte with the backup. Run on 2026-10-25, Moodle 4.5 / PostgreSQL only.

| # | Defect introduced | Test(s) that turned red | Result |
| --- | --- | --- | --- |
| N1 | `auth_lti_linked_login` not excluded from the generic merger | `test_settings_extend_the_default_exceptions`, `test_settings_callback_adds_the_exclusions`, `test_setting_off_moves_nothing` | red (3) |
| N2 | exclusions sent as a plain (numerically keyed) list, which would overwrite `user_preferences` in the tool's defaults | `test_settings_extend_the_default_exceptions` | red (1) |
| N3 | `after_merged_all_tables` callback does nothing (the 2026102501 situation) | `test_binding_moves_inside_the_merge`, `test_same_platform_conflict_is_kept_and_reported`, `test_setting_off_moves_nothing`, `test_identities_moved_by_the_generic_merger_are_reported`, `test_failure_rolls_back_the_whole_merge` | red (5) |
| N4 | a failure of the step is swallowed without an error message (the tool would then crash on commit instead of rolling back cleanly) | `test_failure_rolls_back_the_whole_merge` | red (1) |
| N5 | post-state check `targetduplicates` disabled | `test_identities_moved_by_the_generic_merger_are_reported` | red (1) |
| N6 | upgrade step 2026102502 marks nothing | former `upgrade_test::test_upgrade_marks_merges_that_left_two_identities_of_one_platform` (removed with the rename) | red (1, before the rename) |
| N7 | old journal rule (an unchanged open conflict is rewritten and re-audited) | `test_same_platform_conflict_is_kept_and_reported` | red (1) |

All seven detected. While running N4 the first time, the test left the PHPUnit database with a renamed table (an open
transaction rolled back the rename-back); the test helper now force-rolls back a failed commit before renaming, the table
was renamed back by hand, and N4 to N7 were re-run on the repaired database.

A real defect found by the matrix itself (not a planted one, before the rename): on PostgreSQL the adopted pre-release rows left
the id sequence at 1 (former upgrade test). It was fixed then; the upgrade step no longer exists.
