# Documentation: local_ltiaccountmerge

Status: ALPHA, release 0.3.0 (version 2026102700). Moodle 4.5 to 5.3, PHP 8.1+.
What is proven and what is not: [ACCEPTANCE.md](ACCEPTANCE.md). How to run the tests: [TESTING.md](TESTING.md).

## 1. Scope

The plugin sits on the **tool side** of an LTI 1.3 connection (the Moodle site that publishes resources with `enrol_lti`
and creates accounts with `auth_lti`). It implements the releases R1 to R3 of the specification as far as the
feasibility gates allow. A general, core-provided account merge does not exist (AR-G4). Since 0.3.0 the plugin offers a
**reviewed merge workflow on top of `tool_mergeusers`** (section 3.4): the merge itself is always `tool_mergeusers`'
single-transaction merge; this plugin adds the preview, the consent, the journal with crash recovery and the in-transaction
handling of the LTI bindings. Linking (add an identity to an account) and merging (two accounts become one) are separate
functions with separate pages, capabilities and code.

| Spec item | MVP implementation | Note |
| --- | --- | --- |
| AR-P01 / AR-P02 error pages and texts | `error.php`, error catalogue, per-language overrides, HTML allow-list, branding | The core binding dialog is not replaced (AR-G1). |
| AR-P03 policy scheduler | policies, resolver, minute task, optional pre-launch apply | The mode is computed live; it is written to core by an official API. |
| AR-P04 link workflow | `link.php` (administrator attestation) and `confirm.php` (one-time code) | Only `auth_plugin_lti::create_user_binding()`. Add-only. |
| AR-P05 / AR-P06 merge | `merge.php` / `cli/merge.php`: `local\merge_preview` (dry run with `tool_mergeusers`' real configuration), consent, `local\merge\executor`, `task\execute_merge`, `task\recover_merges`, journal `local_ltiaccountmerge_job`; in-transaction step `mergeusers_callbacks` -> `local\merge_service`. `simulate.php`: tool-independent read-only inventory. | Grades, attempts, enrolments, groups and other plugins' tables are moved by `tool_mergeusers` (its mergers) or by their owning plugin's hook; undeclared plugin tables block. |
| AR-L09 audit | `local_ltiaccountmerge_audit`, events, support IDs | No secrets, pseudonymous references only. |

## 2. Architecture

```
db/hooks.php --after_config--> hook_callbacks::after_config ----+
db/tasks.php --every minute--> task\apply_modes ----------------+--> local\mode_applier --> enrol_lti_plugin::update_instance()
                                                                |         ^                      (enrol_lti_tools.provisioningmode*)
policies.php / policy_edit.php / preview.php --> local\policy, local\resolver (pure, no I/O)
link.php / confirm.php ---------------------> local\link_service --> auth_plugin_lti::create_user_binding()
error.php / texts.php ----------------------> local\error_catalogue + local\html_policy + output\renderer (Mustache)
simulate.php -------------------------------> local\merge_simulator (reads DB metadata + install.xml, writes nothing)
merge.php / cli/merge.php ------------------> local\merge\executor --preview--> local\merge_preview (tool_mergeusers config, read-only)
                                                  |--consent--> local_ltiaccountmerge_job (consented) + task\execute_merge
task\execute_merge -------------------------> executor::execute --> merge\mergeusers_runner --> tool_mergeusers user_merger::merge()
task\recover_merges (every 5 min) ----------> executor::recover (dead jobs: committed -> succeeded, rolled back -> aborted)
db/hooks.php --tool_mergeusers add_settings_before_merging--> mergeusers_callbacks (exclude auth_lti_linked_login, audit, journals)
db/hooks.php --tool_mergeusers after_merged_all_tables-----> mergeusers_callbacks: mark job (inmerge=1) | refuse unconsented LTI
                                                             merge (requireconsent) | local\merge_service::reconcile_within_merge
db/events.php --tool_mergeusers user_merged_failure--> observer::user_merge_failed (audit row after the rollback)
db/events.php --tool_mergeusers user_merged_success--> observer --> task\reconcile_merge (adhoc, deduplicated, fallback) --> local\merge_service
db/hooks.php --before_standard_top_of_body_html_generation--> hook_callbacks (optional first-launch hint)
everything that changes state --------------> local\audit (+ events)
```

* `local\policy` is a value object. `local\resolver` picks the winning policy per role (learner / instructor) for a given
  instant. Neither touches the database except `resolver::from_db()`. Precedence: manual override, then priority, then
  specificity (course before resource before site), then the highest id.
* Windows are half-open: `start <= t < end`. Wall-clock times are converted with `policy::wall_to_ts()`. It is
  deterministic: a time in a DST gap is moved forward by the gap, an ambiguous time takes its first occurrence. A daily
  window may cross midnight. A manual override must be one-off and have an end.
* `local\mode_applier` keeps the baseline in `local_ltiaccountmerge_applied` (the value the administrator had set).
  If a policy governs a role the policy mode is written and remembered as "owned"; when no policy applies any more the
  baseline is written back. If somebody changes the value by hand while a policy owns it, the manual value becomes the
  new baseline. The call runs under a lock (`core\lock`), inside a transaction per resource, and only the two mode columns change.
  Details of the 0.2.0 hardening are in section 3.2.
  Core's `update_instance()` would regenerate the LTI 1.3 `uuid` if none is passed, which would break every published
  launch link, so the existing uuid is always passed (test `test_only_the_two_modes_change_and_uuid_is_stable`).
* `local\link_service` is the only code that writes a binding. See [SECURITY.md](SECURITY.md) for the rules it enforces.
* `local\error_catalogue` returns title and safe HTML for a code. Codes outside the catalogue fall back to
  `launcherror`, so a page never echoes raw input. `local\html_policy` is an allow-list that **rejects** unsafe
  input instead of repairing it. The logo is served by `local_ltiaccountmerge_pluginfile()`.
* `local\merge_simulator` collects every table that has a user-id column from the live DB metadata and
  from `db/install.xml` of all installed plugins, classifies each as ok, preserve (immutable logs), review, conflict or
  blocked, and reports a verdict. A third-party component is blocked unless its name is passed to the simulator's constructor as "adapter exists" (a programmatic
  declaration used by the tests; there is no UI, setting or hook for it yet, and no adapter is shipped).

## 3. Gates and evidence

The specification names four feasibility gates. Each has evidence tests (`tests/gates/ar_gates_test.php`) that run on the
Moodle version under test; they inspect the real core code and API, they do not assume.

| Gate | Question | Result on 4.5 and 5.3 | Evidence test(s) | Consequence in this plugin |
| --- | --- | --- | --- | --- |
| AR-G1 | Can the auth_lti first-login / binding page, prompt or exception output be replaced by a plugin? | **No.** The page is rendered by `auth_lti\output\renderer`, only a theme can override it; no hook or callback sits in the flow. **A notice can be added** above it through the generic output hook. | `test_ar_g1_negative_no_extension_point_in_core_binding_dialog`, `test_ar_g1_negative_no_core_hook_for_lti_binding`, `test_ar_g1_fallback_output_hook_reaches_the_binding_dialog_page`, `test_ar_g1_plugin_ships_no_renderer_override_for_auth_lti`, Playwright story 4 | Own link page outside the core dialog; optional hint (section 3.3). No claim to replace or customise the core screen. |
| AR-G2 | Can the effective provisioning mode be set per launch *before* core reads it? | **No hook.** `enrol/lti/launch.php` reads the two columns directly. **Yes via official writer + `after_config`.** | `test_ar_g2_negative_launch_reads_mode_without_extension_point`, `test_ar_g2_positive_official_writer_exists`, `test_ar_g2_positive_after_config_hook_exists`, `test_launch_at_window_start_sees_new_mode_only_with_hook`, `test_launch_at_window_end_sees_baseline_with_hook` | Minute task (fallback documented by the spec) plus an optional `after_config` pre-launch step, default off. |
| AR-G3 | Is a later account link core-conformant? | **Partly.** `create_user_binding()` / `get_user_binding()` exist and are enough to *add* a binding. There is no API to delete or move one. | `test_ar_g3_create_user_binding_semantics`, `test_ar_g3_partial_binding_api_has_no_remove_or_move` | Add-only linking. Re-binding and removal are not offered (they would need raw writes to `auth_lti_linked_login`). |
| AR-G4 | Is there a generic, atomic merge of two users? | **No core API.** Core only has `delete_user`. `tool_mergeusers` provides an atomic (single transaction) merge with per-table strategies and two hooks. A blind "update every userid" is unsafe. | `test_ar_g4_no_core_user_merge_api`, `merge_preview_test::test_blockers`, `merge_executor_test::*`, integration harness (real `tool_mergeusers`) | Reviewed merge through `tool_mergeusers` with preview, consent, journal and recovery (section 3.4); undeclared plugin tables block. |

### 3.1 The pre-launch apply step (AR-G2 positive)

`\core\hook\after_config` is dispatched at the end of `setup.php`. The callback returns immediately unless
`$_SERVER['SCRIPT_NAME']` equals exactly `/enrol/lti/launch.php` or `/enrol/lti/launch_deeplink.php` (optionally with a
sub-directory prefix of the Moodle URL), the setting `prelaunchapply` is on, and no install or upgrade is running. It then
runs the same `mode_applier` as the task. Any `Throwable` is swallowed (a developer-level debugging message only) so
that a launch is never broken by this plugin (`test_failure_is_swallowed`).

Risks, stated plainly:

* it adds one lock, one small query on the policy table and, when something changes, a transaction with an
  `enrol_instance_updated` event before each launch request;
* two concurrent launches serialise on the lock; a launch waits at most 3 s (the task 5 s); if the lock cannot be taken
  nothing is changed, the launch runs with the stored value and, for a launch, the audit trail records the failure
  (`test_busy_lock_changes_nothing`, `test_lock_held_by_another_process`). A launch that waited reads the clock only after it
  got the lock (`test_waiting_process_uses_a_fresh_instant`);
* `launch_deeplink.php` does not read the provisioning mode (deep linking has its own account rules), so for this script
  the step only keeps the stored values current;
* multi-process behaviour: PHPUnit proves a lock held by a second real PHP process (flock on the lock file core uses).
  A burst of parallel appliers at the boundary second is exercised by `tests/load/apply_race.php` (see TESTING.md), run
  by hand on a development site; it is not part of the PHPUnit suite. The Playwright spec covers one launch per boundary
  side, not a burst;
* the pre-launch step applies the mode of **every** governed resource, not only of the one being launched: at that point
  of the request the launch token has not been read, so the resource is unknown;
* a course backup taken while a policy owns a mode contains the policy's mode, and a restore makes it the baseline of the
  restored resource (no bookkeeping row exists for the new resource). Backup and restore were not exercised.

### 3.2 Window boundaries, races and core-config side effects (0.2.0 item AR-03; 2026-10-27 audit AR-04, see 3.6)

| Concern | Behaviour | Evidence |
| --- | --- | --- |
| Instant evaluated after a wait | `apply_all(null)` reads the clock after the lock was obtained | `test_waiting_process_uses_a_fresh_instant` |
| Lock held by another process | no change, `locked` in the summary, audit row for the pre-launch path only | `test_lock_held_by_another_process` (real second PHP process), `test_busy_lock_changes_nothing` |
| Administrator saves the resource at the same moment | optimistic re-read inside the transaction; the round is skipped and re-evaluated later; the administrator's value becomes the baseline | `test_concurrent_manual_save_is_not_overwritten`, `test_manual_change_becomes_baseline` |
| One resource fails | rollback of its transaction, audit row `failed`, other resources continue, next run retries | `test_failure_of_one_resource_is_isolated` |
| Other settings of the resource and the enrolment instance | untouched, uuid kept | `test_no_other_configuration_is_lost`, `test_only_the_two_modes_change_and_uuid_is_stable` |
| Adjacent and overlapping windows | no gap at the hand-over second, newer policy wins at equal rank, baseline untouched in between | `test_adjacent_and_overlapping_windows` |
| DST | gap moves forward by the gap, overlap takes the first occurrence; 23 h and 25 h days; half-hour shifts; a skipped calendar day | `policy_test::test_unusual_transitions`, `test_daily_window_starting_in_the_overlap`, `test_no_second_is_lost_or_doubled_around_the_gap`, `mode_applier_test::test_windows_over_whole_dst_days`, `test_daily_window_across_dst` |
| Idempotence | a second run at the same instant writes nothing and audits nothing | `test_idempotent` |
| Uninstall | baselines are written back; a value set by hand is kept | `test_uninstall_restores_baselines` |
| Fresh install 0.2.0 (renamed, no upgrade path from 0.1.0 alpha) | all tables and setting defaults present; upgrade function without steps | `install_test::*` |

What is promised to a user: with `prelaunchapply` on, a launch that starts after a boundary second reads the mode of the new
window (proof: `hook_callbacks_test::test_launch_at_window_start_sees_new_mode_only_with_hook` with a real
`complete_login()`, Playwright story 1). With it off the delay is up to one minute (the task period). A launch that is
**already past** `launch.php`'s read of the mode when the boundary passes keeps the old mode; nothing can change that.
Not proven: the 5.0 to 5.2 trees, restore of a course backup, a burst of real parallel launches on the stack.

### 3.3 First-login and error pages: what is possible without a core fork (0.2.0 item AR-01; 2026-10-27 audit AR-02, see 3.5)

* The core dialog ("create a new account" / "use an existing account") is rendered by `auth_lti\output\renderer` from
  `/auth/lti/login.php`. No hook, event, callback or setting exists; only a theme can override the renderer. The plugin
  therefore **cannot replace, restyle or translate** that dialog (AR-G1 negative, proven on 4.5 and 5.3).
* Core errors during a launch (`enrol/lti/launch.php`, `launch_deeplink.php`) are exceptions rendered by core. The plugin
  cannot intercept them either; its error catalogue (`error.php`) is shown only when something links to it. Its own flows
  (linking, challenge) raise catalogue codes with a support ID.
* Best supported fallback, implemented: with the setting `firsthint` on, a notice (catalogue code `firstloginhint`,
  translatable, limited HTML, support contact) is added at the top of the page body on exactly `/auth/lti/login.php`,
  `/enrol/lti/launch.php` and `/enrol/lti/launch_deeplink.php`, through the output hook
  `before_standard_top_of_body_html_generation`. It leaves the dialog and the error text untouched. Evidence: the hook unit
  tests, the gate test that the popup layout used by the dialog calls the hook, and Playwright story 4 (rendered page).
* The three core provisioning modes against real first launches are tested with the real `auth_plugin_lti::complete_login()`
  (`core_modes_test`); changing the policy never rebinds an identity.
* The notice is shown to every user of those scripts, also to those who did not need it; it appears only where the theme
  renders the standard top-of-body output. A theme that omits it shows nothing.

### 3.4 Reviewed account merge (audit item AR-03)

**Linking and merging are strictly separate.** Linking (`link.php`, `confirm.php`, `local\link_service`, capabilities
`link`/`linkown`) only adds an `auth_lti_linked_login` row through `auth_plugin_lti::create_user_binding()`. Merging
(`merge.php`, `cli/merge.php`, `local\merge\executor`, capability `merge`) makes two accounts one and is executed only by
`tool_mergeusers`. The merge never runs without the tool; without it the page shows the reduced function.

**Preview (dry run, `local\merge_preview`).** Built from the installed `tool_mergeusers`: its effective configuration
(`config::instance()`: defaults, every plugin's `add_settings_before_merging` settings, the administrator's
`customdbsettings`, minus `excluded_exceptions`), the same table selection as `user_merger::init()` and the table mergers'
`get_tables_to_skip()`. Each callback of `add_settings_before_merging` is also called on its own (hook manager
`get_callbacks_for_hook()`), so every table shows **who declared** its handling. Per table: owner component (install.xml),
category, user columns the tool would process, strategy (`generic` user-id update, `compound` index merge, `special`
table merger, `owner` = excluded and handled by its plugin inside the merge, `skipped` = excluded and left on the merged-away
account), rows of both accounts, collisions, status and reason. The categories grades, attempts, completion,
enrolments, groups, LTI bindings, pseudonyms and completion sync are always listed.

Blocking rules (no blind user-id update): a table of a non-standard plugin or of no known component with rows of the
account to merge away that would only get the generic update and is declared by nobody (its plugin, `tool_mergeusers`,
an administrator) -> **blocked**; a unique key over one user column (install.xml) with colliding rows and no declared
compound index -> **blocked** (the merge would fail); a site administrator as the account to merge away -> **blocked**.
Conflicts that a documented rule resolves (declared compound index collisions, a second identity of the same platform,
a pseudonym of the same scope) need an explicit acceptance. An administrator can lift an "undeclared" block only by
declaring the table in `tool_mergeusers`' custom settings (shown as "declared by admin").

**Consent.** Bound to the preview's fingerprint (sha256 of the decisions: both account ids, verdict, and per table with rows
of the source its strategy, status, handler and declarations; counters are excluded so that an active account does not
invalidate the consent with every page view), the typed username of the account to merge away and, for conflicts, the
acceptance. Refused (with an audit row, nothing else written): blocked or unavailable preview, other fingerprint, wrong
username, unaccepted conflicts, an account already in an open (consented or running) job. Consents expire after one day.

**Execution and crash-recovery journal (`local_ltiaccountmerge_job`).** `task\execute_merge` (concurrency 1) ->
`executor::execute()`: site-wide execution lock and the job's lock (both `core\lock`, released by the database when a
process dies: PostgreSQL advisory locks, MariaDB `GET_LOCK`); fresh preview, a different fingerprint ends the job `stale`;
then the job is marked `running` and a pending log entry of `tool_mergeusers` is created (both committed BEFORE the merge
transaction); `user_merger::merge($to, $from, $logid)` runs; inside its transaction the callback sets `inmerge = 1` on the
job. Outcome `succeeded` (with the mark) or `failed` (the tool rolled back, support ID). `recover_merges` (every 5 minutes,
also `cli/merge.php --recover`): a `running` job whose lock is free belongs to a dead process -> `inmerge = 1` means
committed -> `succeeded` (recovered) and the binding reconciliation is queued (the success event may not have fired);
`inmerge = 0` means the database rolled back -> `aborted` (nothing changed; new preview and consent needed). An
unfinished `tool_mergeusers` log entry (pending / in progress) is corrected to success / error.

**Protocols.**

| Protocol | Rule | Evidence |
| --- | --- | --- |
| Rollback | Any error inside the merge (a table, a plugin's callback, this plugin's step, the consent rule) makes `tool_mergeusers` roll back the whole transaction, including the `inmerge` mark and this plugin's journal/audit rows written inside it; the refusal is audited after the rollback (`user_merged_failure` observer). | `merge_executor_test::test_failed_merge_rolls_back`, `test_merge_without_consent_is_refused_for_lti_accounts`; integration `direct-refused`, `crash-aborted` |
| Conflict | Same platform, different subject: the identity stays on the merged-away account (journal `partial`, audit); pseudonym of the same scope: kept account's wins, the other is retired (ltisource_ltipseudonym); compound index: the tool keeps one row per key (`uniquekeynewidtomaintain`); all listed in the preview and accepted explicitly. | `merge_preview_test::test_conflicts`, `merge_executor_test::test_consent_refusals`; integration `pair2` |
| Concurrency | One merge at a time (execution lock + task concurrency 1); at most one open job per account (consent lock + check); a second execution of the same job reports `busy`, a finished job is not run again (idempotent); the in-transaction reconciliation uses a per-pair lock. | `merge_executor_test::test_concurrency_and_cancel`; integration `consent-race`, `exec-race` (parallel processes) |
| Crash | Process killed inside the transaction -> database rollback -> `aborted`; killed after the commit -> `succeeded` (recovered); no job stays `running` while its lock is free. | `merge_executor_test::test_recovery`; integration `crash-aborted`, `crash-committed` (`kill -9` with row locks held by a second session) |

**Consent rule for merges started elsewhere** (setting `requireconsent`, default on): a `tool_mergeusers` merge (its UI,
CLI or queued task) of accounts with an LTI identity or `auth = lti` that has no running job of this plugin is refused by
the callback inside the merge transaction (rollback, explanation in the tool's log, audit row afterwards). Merges of other
accounts are unaffected. Off: the 0.2.0 behaviour (reconciliation only).

**LTI bindings inside the merge** (`local\merge_service`, unchanged since the 0.2.0 finding F1): the table is excluded from
the tool's generic merger; the bindings of the merged-away account move to the survivor (one direct column update, no core
API), never onto a second identity of the same platform (kept and reported, `partial`); pending link requests are settled;
"modified by" references follow; the audit trail is never rewritten; the success event queues an idempotent fallback task.

**Multi-plugin integration with the real tool** (`tests/integration/run.sh`): private hard-linked copy of the tree, the
family plugins from the work directories, the real `tool_mergeusers`, scratch databases `<tree>_ltiam`; data of every
category (grade, quiz attempt, activity and course completion, enrolment, group, LTI binding, pseudonym mapping of
`ltisource_ltipseudonym`, outbox and receiver state of `local_lticompletionsync`, link requests) for several pairs and a
bystander. Checks: no row ever changes owner except from a merged-away account to its own kept account (whole run),
bystander grades, binding and pseudonyms unchanged, the merged account's pseudonym resolves to the kept account (scores
land on the same person), conflicts follow their rules. Results: docs/TESTING.md.

### 3.5 First launch in the real core flow: API/hook reference per version (audit item AR-02)

| Core element used or inspected | 4.5 | 5.0 | 5.1 | 5.2 | 5.3 | Use in this plugin |
| --- | --- | --- | --- | --- | --- | --- |
| `auth/lti/login.php` (core binding dialog) | same file | identical | identical | identical | DI calls (`\core\di::get(\core\authentication::class)`) instead of `get_auth_plugin()`; flow unchanged | Not replaceable (no hook); notice added on this script |
| `auth_lti\output\renderer::render_account_binding_options_page()` | yes | identical | identical | identical | identical | Inspected only (theme-overridable, not by plugins) |
| `enrol/lti/launch.php` reads `provisioningmodelearner/instructor` | line 101 | identical | identical | identical | same line; DI calls | No hook: mode written in advance (task / `after_config`) |
| `auth_plugin_lti::complete_login()`, `create_user_binding()`, `get_user_binding()` | yes | identical file | identical | identical | `\core\user::create_user()/update_user()` inside; signatures unchanged | Linking; probes in tests |
| `\core\hook\after_config` | yes | identical | identical | identical | identical | Optional pre-launch apply |
| `\core\hook\output\before_standard_top_of_body_html_generation` | yes | identical | identical | identical | identical | Optional first-launch notice |
| `\core\hook\manager::get_callbacks_for_hook()` | yes | yes | yes | yes | yes | Preview: per-plugin declarations |
| `adhoc_task::get_default_concurrency_limit()` | yes | yes | yes | yes | yes | One merge at a time |
| `core\lock` DB factories (session-level `pg_try_advisory_lock` / `GET_LOCK`) | yes | yes | yes | yes | yes | Liveness for crash recovery |
| `tool_mergeusers` hooks (`add_settings_before_merging`, `after_merged_all_tables`), `user_merger::merge($to, $from, $logid)`, `logger::create_pending_log()` | plugin 2026081302 (requires 4.5) | same | same | same | same | Merge execution |

"identical" = byte-identical file (md5) to 4.5 in the trees `/home/claude/moodle{,50,51,52,53}` on 2026-10-27; "yes" = the
method exists (source inspected). Behaviour was **executed** on 4.5 and 5.3 (PHPUnit, Behat, integration, Playwright);
5.0 to 5.2 are covered by the code identity only, not by runs of ours.

**What appears in the real first launch** (Playwright `firstlaunch.spec.js`, both directions: 5.3 as tool with 4.5 as
platform, and 4.5 as tool with 5.3 as platform through a reverse registration): mode 1 -> account created, no dialog;
mode 2 -> the core dialog with "Create an account for me" and "Log in", this plugin's notice above it; mode 3 -> the core
dialog without the create option, login to an existing account, "Link this account", binding to that account; a disabled
resource -> core launch error page with the notice and without any personal data of the launching learner; the plugin's
own error page shows only the support ID.

**Declared function-limited alternative (no core hook):** the core dialog, its texts and the core launch error texts cannot
be replaced or translated by a plugin. The plugin adds a configurable, translatable notice above them and offers its own
link page and error page outside the core flow.

### 3.6 Schedule evidence with real task runs (audit item AR-04)

`tests/integration/run.sh` (part "schedule") runs the real `admin/cli/scheduled_task.php --execute=\local_ltiaccountmerge\task\apply_modes`
on a scratch site at fixed offsets: switching automatic -> prompt -> automatic for a site window and an overlapping,
higher-priority window of one resource; an administrator changing a resource by hand during an active window (the policy
keeps governing, the manual value becomes the baseline restored afterwards); launch probes through
`auth_plugin_lti::complete_login()` with the stored mode (bound accounts keep landing in the same account in every mode, an
unbound identity follows the mode); four parallel task processes plus three parallel launches at a window boundary
(exactly one change); a task process killed with `kill -9` while it waits on a row lock (the resource before it stays
committed, the locked one is untouched, the next run has no stale lock); a resource that cannot be written (failure audited,
others applied, nothing half-written, applied after the repair); daylight saving time with the process clock set by
`faketime` (Europe/Berlin, the day before, on and after the spring change and on the autumn change; the clock starts at the
chosen instant and runs, so instants before a boundary are taken 30 s early, the exact second is PHPUnit's job). A per-resource failure
does not fail the task as a whole on purpose: a failed scheduled task is delayed by core's fail delay, which would postpone
the healthy resources. A task that dies is simply run again by cron; the per-resource transaction and the lock make that
safe (proven by the kill case).

## 4. Configuration

| Setting (component `local_ltiaccountmerge`) | Default | Meaning |
| --- | --- | --- |
| `prelaunchapply` | 0 | Apply the effective mode just before `launch.php` / `launch_deeplink.php` runs. |
| `firsthint` | 0 | Add the catalogue text `firstloginhint` above the core binding dialog and the launch error pages. |
| `mergemovebindings` | 1 | During a `tool_mergeusers` merge, move LTI bindings of the merged-away account to the survivor (never onto a second identity of the same platform). Off: no binding moves; `tool_mergeusers` is told to leave the table alone either way. |
| `requireconsent` | 1 | `tool_mergeusers` merges of accounts with an LTI identity only through this plugin's reviewed workflow. |
| `userlinking` | 0 | Allow account owners to confirm a one-time code for their own account (capability `linkown`). |
| `auditretention` | 0 | Delete audit rows older than N days (0 keeps them). |
| `brandname`, `supportcontact`, `brandlogo` | empty | Shown on the plugin's own pages. |

Capabilities: see the README. Scheduled tasks: `apply_modes` (every minute), `recover_merges` (every 5 minutes), `cleanup`
(03:17 daily); ad hoc tasks `reconcile_merge`, `execute_merge`.
Events: `mode_applied`, `policy_changed`, `identity_linked`, `merge_simulated`.

## 5. Data model (`db/install.xml`)

* `local_ltiaccountmerge_policy` - scope (site, tool, course), kind (scheduled, override), priority, enabled, timezone,
  recurrence (once, daily), local start and end, learner mode, instructor mode, who/when.
* `local_ltiaccountmerge_applied` - one row per resource while this plugin owns a mode: baseline and applied value.
* `local_ltiaccountmerge_audit` - actor, time, action, target, outcome, support ID, JSON detail (no secrets).
* `local_ltiaccountmerge_request` - pending challenge: hashed issuer and sub (plus the readable values until the request ends),
  target user, HMAC of the code, attempts, expiry.
* `local_ltiaccountmerge_text` - overrides of catalogue texts per code and language.
* `local_ltiaccountmerge_merge` - journal of reconciled merges: source, target, status, attempts, counters (no identities).
* `local_ltiaccountmerge_job` (0.3.0) - merge jobs of the reviewed workflow: source, target, status, preview fingerprint and
  summary (table names and counters only), consenting administrator and time, `inmerge` mark, `tool_mergeusers` log id,
  attempts, recovered flag, outcome code, support ID, times.

## 6. What is deliberately not here

* Merging accounts without `tool_mergeusers`, or any user-id update by this plugin itself (except the documented binding
  column inside a `tool_mergeusers` merge). Deleting a binding or moving one outside a merge (AR-G3).
* Replacing the core "new or existing account" dialog (AR-G1); only a notice can be added.
* Changing LTI 1.1 / legacy resources: only `LTI-1p3` resources are touched.
* Per-launch (instead of per-resource) modes: core stores the mode per resource.

## Installation, upgrade and rename

See the README sections "Installation" and "Migration and rename plan": upgrade from 0.2.0 by the step `2026102700`; no
upgrade path from the 0.1.0 alpha of the former component `local_ltiaccountreconcile` (fresh install, nothing migrated); the
uninitialised template on the GitHub `development` branch is replaced, its template-only files are listed in
`db/removed_files.txt`.
