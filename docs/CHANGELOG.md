# Changelog

## 0.3.0 (alpha) - 2026-10-27

Family audit 2026-10-27 (development branches), items AR-01 to AR-04 and FAM-00/01/03/05. Version `2026102700`.

### Release and repository (AR-01)
* This implementation replaces the uninitialised template that is on the GitHub `development` branch (commit `d5b40e1`,
  `{{COMPONENT}}` placeholders, never installable). Component `local_ltiaccountmerge`, version `2026102700`, release
  `0.3.0`, `requires` 4.5, `supported` [405, 503]. The template files that must be deleted on `development` when this
  release is pushed are listed in README ("Migration and rename plan").
* Upgrade step `2026102700` from the last delivered build `2026102502` (0.2.0): new table `local_ltiaccountmerge_job`,
  new setting `requireconsent` (on). Test: `upgrade_test` (0.2.0 state rebuilt, step repeated). No upgrade path from the
  0.1.0 alpha of the former component `local_ltiaccountreconcile` (unchanged decision): fresh install, nothing migrated.
* README: branch policy (development = work branch, main = release branch) and the migration/rename plan.
* `db/removed_files.txt` lists the template-only files of the `development` branch; `install_test` asserts none exists.

### Added: reviewed account merge (AR-03)
* Merge page `merge.php` and CLI `cli/merge.php`: preview (dry run) -> explicit consent -> execution by `tool_mergeusers`
  in an ad hoc task -> journal. Capability `local/ltiaccountmerge:merge` (`RISK_DATALOSS | RISK_PERSONAL | RISK_CONFIG`);
  with the tool installed `tool/mergeusers:mergeusers` is required as well.
* `local\merge_preview`: reproduces the table selection of the installed `tool_mergeusers` (defaults, the settings every
  plugin contributes through `add_settings_before_merging`, the administrator's custom settings, `excluded_exceptions`) and
  lists per table the strategy (generic, compound index, dedicated merger, handled by its plugin inside the merge, excluded),
  who declared it, the rows of both accounts and collisions; the categories grades, attempts, completion, enrolments,
  groups, LTI bindings, pseudonyms (`ltisource_ltipseudonym`) and completion sync (`local_lticompletionsync`) are always
  listed. **No blind user-id update:** a plugin table that would only get the generic update without any declaration blocks
  the merge; so does a unique key without a declared compound index and a site administrator as the account to merge away.
* Consent is bound to the preview's fingerprint (decisions, not counters), the typed username of the account to merge
  away and, for conflicts, an explicit acceptance; at most one open job per account.
* Crash-recovery journal (`local_ltiaccountmerge_job`): the job is marked running before the merge transaction; the
  `after_merged_all_tables` callback sets `inmerge = 1` inside it. Scheduled task `recover_merges` (every 5 minutes)
  resolves jobs of dead processes (lock free): committed -> succeeded (recovered), rolled back -> aborted; the unfinished log
  entry of `tool_mergeusers` is corrected. Stale previews and consents older than one day end without any change.
* Setting `requireconsent` (default on): `tool_mergeusers` merges of accounts with an LTI identity (or `auth = lti`) that
  were not consented through this workflow are rolled back by the callback, with an explanation; observer
  `user_merged_failure` records them in the audit trail. Other accounts are not affected.
* Tests: `merge_preview_test`, `merge_executor_test`, `upgrade_test`, privacy (`test_merge_jobs`); integration harness
  `tests/integration` with the REAL `tool_mergeusers` and the sibling plugins on scratch databases (preview, refusals,
  conflicts, crash inside and after the transaction with `kill -9`, competing consents and executions, cron path, no identity
  mix-up / foreign grading).

### Added: schedule evidence (AR-04)
* Integration harness part "schedule": real `admin/cli/scheduled_task.php` runs of `apply_modes` at window boundaries
  (switching automatic <-> prompt with bound accounts and a launch probe through `auth_plugin_lti::complete_login()`),
  overlapping windows, a manual change of the resource by an administrator, daylight saving time under `faketime`
  (Europe/Berlin spring and autumn), parallel task processes, a task process killed while it writes, and a resource that
  cannot be written. Behat `ui_negative.feature` (policy form refusals, forged requests, missing capabilities, merge page
  without the tool) with the step class `tests/behat/behat_local_ltiaccountmerge.php` (refusals that core renders as an
  exception page; a forged deletion request).
* `mode_applier` processes the published resources in id order (deterministic order of the per-resource transactions).

### Added: first launch in the real core flow (AR-02)
* Playwright `firstlaunch.spec.js`: the three core provisioning modes in a real first launch on both tool versions (5.3
  as tool with 4.5 as platform, and 4.5 as tool with 5.3 as platform), core binding dialog, the optional notice, an error
  state without personal data. Per-version API/hook reference in DOCUMENTATION.md; the core dialog stays core's (declared
  function-limited alternative).

## 0.2.0 (alpha) - 2026-10-25

### Post-rename cleanup (2026-10-25, version unchanged)
* Internal abbreviation derived from the old name replaced: `ltiar`/`LTIAR` -> `ltiam`/`LTIAM` (CSS classes `local-ltiam-*`/`ltiam-*`, element ids and data attributes in the templates, the support ID format, now `LTIAM-XXXXXXXX`, Playwright environment variables `LTIAM_*`, test fixtures). A support ID of the old format is not accepted any more (fresh install only, so none exists).
* CI workflows: the reduced and full matrices (4.5 to 5.3, PostgreSQL and MariaDB) are unchanged. The dev pipeline's code checker runs without an installed Moodle and now gets the plugin path (`moodle-plugin-ci codechecker ... plugin`); without it the blocking step stopped with "Not enough arguments (missing: plugin)". Header comments state the real matrix; template wording about sibling plugins was removed. Validated with a YAML parser and a path/name check (see TESTING.md).

Renamed (owner decision 2026-10-25)
* Renamed from `local_ltiaccountreconcile` (install path `local/ltiaccountreconcile`) to `local_ltiaccountmerge` (install path
  `local/ltiaccountmerge`); no upgrade path from 0.1.0 alpha. 0.2.0 is a fresh install: uninstall the old plugin (its uninstall
  hook writes the provisioning-mode baselines back to the published resources; its policies, texts, link requests, journal and
  audit trail are deleted) and install this one. Changed with the name: tables `local_ltiaccountmerge_policy|_applied|_audit|
  _request|_text|_merge`, capabilities `local/ltiaccountmerge:*`, settings, language files, namespaces, templates, events, tasks,
  the tool_mergeusers exclusions (keyed by the new table names), URLs `/local/ltiaccountmerge/...`, Behat tag
  `@local_ltiaccountmerge`; display name "LTI Account Merge".
* `db/upgrade.php` has no steps any more (the 0.1.0 steps, the pre-release table adoption and the 2026102502 journal fix
  referred to the old tables); `tests/upgrade_test.php` and `tools/upgrade_from_010.sh` were removed, `tests/install_test.php`
  checks the fresh install (no-op upgrade function, all six tables equal install.xml, setting defaults, release). The journal
  marking that the 2026102502 step applied to old rows is done at merge time by `merge_service` (`targetduplicates`, status
  `partial`), which stays covered by `merge_service_test` and `mergeusers_callbacks_test`.

Rework after the 0.2.0 re-audit (items FAM-01, AR-01, AR-02, AR-03, FAM-04). Version 2026102502 (release stays 0.2.0).

Fixed after the release gates (finding F1, medium; build 2026102501 -> 2026102502)
* With the real `tool_mergeusers`, the LTI binding rules never applied: its generic table merger moved
  `auth_lti_linked_login.userid` itself before the success event, so a merge of two different identities of the same
  platform left both on the survivor (journal `done`, 0 conflicts), and `mergemovebindings = 0` could not prevent the move.
  Now the plugin excludes that table (and its own audit trail and journal) from the generic merger through the hook
  `add_settings_before_merging`, and makes the binding decision inside the merge transaction through
  `after_merged_all_tables` (`merge_service::reconcile_within_merge`). A conflict keeps the identity on the merged-away
  account and is journalled (`partial`) and audited; the merge goes on. A failure of the step rolls back the whole merge.
  The ad hoc task stays as the idempotent fallback; it no longer re-audits an unchanged open conflict.
* New result counter `targetduplicates` (the survivor holds two identities of one platform, whatever put them there):
  status `partial`, reported, never repaired by guessing.
* Tests: `mergeusers_callbacks_test` (registration without `tool_mergeusers`, settings layering, inline move, Gate 2
  pair 2, setting off, generic-merger safety net, whole-merge rollback, busy pair); seven
  negative controls (ACCEPTANCE.md section 6).

Added
* Merge executor (AR-02), modelled on auth_flexaccess: an observer for `\tool_mergeusers\event\user_merged_success`
  queues a deduplicated ad hoc task `reconcile_merge`; `local\merge_service` is idempotent, serialised per pair with a lock,
  runs in one delegated transaction, moves the LTI bindings of the merged-away account to the survivor (setting
  `mergemovebindings`, conflicts reported not overwritten), cancels its pending link requests, re-points "modified by",
  never rewrites the audit trail, and records every pair in the new table `local_ltiaccountmerge_merge`
  (privacy provider extended). It is not a general user merge.
* Optional first-launch hint (AR-01, setting `firsthint`, catalogue code `firstloginhint`, template `first_launch_hint`) shown
  above the core account-binding dialog and the LTI launch error pages through the output hook. Does not replace the dialog.
* `db/uninstall.php`: baselines are written back before the bookkeeping disappears.
* Tests: merge service, observer and task, fresh install, multi-process lock, failure isolation, optimistic
  concurrency, adjacent and overlapping windows, unusual DST transitions, hint hook, gate evidence for the new claims;
  Behat and Playwright scenarios; `tests/load/apply_race.php` (multi-process boundary race).

Changed
* Version 0.2.0 (`2026102502`, fresh install, see "Renamed").
* `mode_applier` (AR-03): the instant is read after the lock was obtained (a waiting launch no longer evaluates a stale
  instant); the pre-launch path waits at most 3 s and records a lock timeout in the audit trail; a failing resource is rolled
  back, audited and no longer stops the others; an optimistic re-check inside the transaction skips a round instead of
  overwriting a mode an administrator saved in between.
* Texts of the merge simulation page no longer suggest a merge is coming "in this version": they state that the plugin does
  not merge and what it reconciles afterwards.
* Documentation brought in line with the executed test layers (Behat and Playwright had been run for 0.1.0).

Fixed
* A launch that waited for the lock across a window boundary used the instant from before the wait.
* A failure while writing one resource left its delegated transaction open.

Removed
* Nothing user-visible. Only the sentence "no merge executor in this version" was replaced; this plugin still does not merge accounts itself.


## 0.1.0 (alpha) - 2026-10-24

First feature release of local_ltiaccountreconcile (replaces the template scaffold).

* Provisioning policies: site, resource and course scope, timezone aware windows (one-off, daily), priority, manual
  overrides with expiry, learner and instructor mode, live resolver, preview page.
* Application to core via `enrol_lti_plugin::update_instance()` (minute task) and an optional `after_config`
  pre-launch step (default off); baseline is remembered and restored.
* Link workflow outside the core dialog: administrator attestation and one-time code, add-only through
  `auth_plugin_lti::create_user_binding()`, audit and support IDs.
* Error catalogue with admin-editable, translatable, allow-listed HTML; branding logo.
* Read-only merge simulation with conflict matrix, adapter declarations and CSV/JSON export.
* Audit trail, privacy provider, six capabilities, four events, two scheduled tasks, English and German strings.
* Feasibility-gate evidence tests AR-G1 to AR-G4.
* Database tables use the full component prefix (`local_ltiaccountreconcile_*`); version 2026102401.
* Repository: removed template workflows and files that could not work for this plugin (load tests, k6 / JMeter /
  Playwright workflows with cloned sibling repositories, the sibling version lock-step job).
