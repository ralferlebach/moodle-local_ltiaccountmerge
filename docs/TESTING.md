# Testing: local_ltiaccountmerge

Release 0.3.0 (version 2026102700). Date of the runs recorded here: 2026-10-27 (earlier releases: history at the end).
Results per tree and database: see the matrix below and [ACCEPTANCE.md](ACCEPTANCE.md). Everything was executed in the
development container, **not in GitHub CI** (we neither push nor run CI; the owner's development CI is separate evidence).

## Layers and how to run them

All commands below use the container's shared runner, which mirrors the plugin into a Moodle tree, upgrades, and runs the
checks under a lock per tree and database: `ltirun <45|53> <pgsql|mariadb> local_ltiaccountmerge <step>` with the steps
`mirror`, `upgrade`, `static`, `phpunit [phpunit args]`, `all`. Logs are written to `/tmp/ltirun/<tree>_<db>_local_ltiaccountmerge/`.
Outside the container use the equivalents in your Moodle checkout (`moodle-plugin-ci` or `vendor/bin/phpunit`, see the CI
workflows in `.github/workflows/`).

| Layer | What it proves | Run |
| --- | --- | --- |
| Static | phplint, plugin structure (`validate`), savepoints, Mustache lint, Moodle CodeSniffer with 0 warnings, PHPDoc with 0 warnings | `ltirun 45 pgsql local_ltiaccountmerge static` |
| PHPUnit, unit | `policy`, `resolver`, `modes`: windows, boundaries, DST, daily windows, precedence; no database writes | `ltirun ... phpunit --filter 'policy_test\|resolver_test\|modes_test'` |
| PHPUnit, integration with real core | real `auth_plugin_lti::complete_login()` with the three core modes against policies; real `enrol_lti_plugin::update_instance()`; real `after_config` callback; link service against `create_user_binding()`; race and rollback with real transactions | `ltirun ... phpunit` (whole suite) |
| PHPUnit, gates | AR-G1 to AR-G4 on the Moodle version under test (`tests/gates/ar_gates_test.php`) | `ltirun ... phpunit --filter ar_gates_test` |
| PHPUnit, privacy | provider metadata, export, deletion | `ltirun ... phpunit --filter provider_test` |
| PHPUnit, tool_mergeusers hooks | exclusion of the binding table, decision inside an emulated merge transaction, conflict, rollback of the whole merge, registration without the tool (`tests/mergeusers_callbacks_test.php`) | `ltirun ... phpunit --filter mergeusers_callbacks_test` |
| PHPUnit, install and upgrade | fresh install: all seven tables equal install.xml, setting defaults, release, no template placeholder or template leftover (`install_test`); upgrade 2026102502 -> 2026102700 from a rebuilt 0.2.0 state, repeatable (`upgrade_test`) | `ltirun ... phpunit --filter 'install_test\|upgrade_test'` |
| PHPUnit, merge workflow | preview with `tool_mergeusers`' configuration (categories, strategies, blockers, conflicts, fingerprint, read-only), consent refusals, execution, rollback, stale/expired consent, locks, crash recovery, consent rule in the merge transaction, tasks (`merge_preview_test`, `merge_executor_test`, stand-in `tests/fixtures/fake_runner.php`) | `ltirun ... phpunit --filter 'merge_preview_test\|merge_executor_test'` |
| Integration, real `tool_mergeusers` and real scheduled tasks | `tests/integration/run.sh`: private tree copy, scratch databases, family plugins, real tool, parallel processes, `kill -9`, `faketime` (see `tests/integration/README.md`) | `tests/integration/run.sh <45\|53> pgsql mariadb` |
| Multi-process | eight PHP processes at the boundary second of a window: one write, uuid stable, baseline restored once (development site only) | `php local/ltiaccountmerge/tests/load/apply_race.php 8` in the tree (see `tests/load/README.md`) |
| Behat (non-JavaScript) | admin pages, policy form validation, preview, error page and fallback, text override (also the first-launch hint text) and rejection of script, link page refusal, delegated-role access, merge simulation, new settings; `ui_negative.feature`: override without expiry, daily override, policy without mode, daily window without end, unknown resource, delete without valid sesskey, manager without capabilities, merge page without the tool, forged cancel, consent setting | `ltibehat <tree> <db> local_ltiaccountmerge` |
| Playwright | `ltiaccountmerge.spec.js` (five stories, platform A 4.5 -> tool B 5.3) and `firstlaunch.spec.js` (three core modes and a launch error, both tool versions), see `tests/playwright/README.md` | see below |

The suite is configured with `--fail-on-warning --fail-on-risky --fail-on-skipped`: a skipped test fails the run.

### PHPUnit notes

* Old PHPUnit 9 style (`@covers`, `@dataProvider`, no attributes). Test classes are `final` (Moodle CodeSniffer rule).
* On PostgreSQL PHPUnit wraps each test in a transaction. Tests that need a real rollback or DDL call
  `preventResetByRollback()`.
* On Moodle 5.3 (PHPUnit 11) the run reports 25 deprecations for the doc-comment metadata; they are accepted by the
  project brief and do not fail the run.
* `tests/fixtures/helper.php` drops the debugging message `tool_ltidiagnostics observer failed` before the base class
  checks for unexpected debugging. That message comes from a sibling plugin installed in the shared development trees
  while `auth_lti` creates users; it is not behaviour of this plugin and no other debugging message is dropped.
* Concurrency: PHPUnit is a single process. The lock, transaction and "competing writer" paths are tested with an
  injectable lock factory and test seams that simulate a writer inside the transaction (a resolver that edits the resource
  right after the snapshot; a failing `write()`). Two tests use something real: a second PHP process holds the lock file
  that core's file lock factory uses (`test_lock_held_by_another_process`), and a lock factory that makes the waiter sleep
  across the window start (`test_waiting_process_uses_a_fresh_instant`). Parallel launches through the web stack are not
  tested; `tests/load/apply_race.php` covers parallel appliers at the boundary by hand.
* Tests that need a real rollback or DDL call `preventResetByRollback()`; on PostgreSQL a failing delegated transaction
  inside PHPUnit's own transaction would otherwise poison the rest of the test.
* `merge_service_test` stands in for `tool_mergeusers` (not installed in the trees) with a core event carrying the same
  `usersinvolved` payload; the registration of the observer for the real event name is asserted from the observer cache.
* `mergeusers_callbacks_test` (finding F1) uses the real hook classes of `tool_mergeusers` when they exist and otherwise
  the stand-ins `tests/fixtures/settings_hook_standin.php` and `after_hook_standin.php`, which copy the public contract of
  `tool_mergeusers` 2026081302 (no `markTestSkipped`, no `class_exists` guard around assertions). The merge transaction is
  emulated in the order of `user_merger::merge_users()`: table work, hook, commit only without an error message, otherwise
  rollback. `test_registration_is_quiet_without_tool_mergeusers` proves on both trees that core registers callbacks for the
  absent hook classes without debugging (also on the admin page "Hooks overview").
* Negative controls: see ACCEPTANCE.md section 6 and the procedure below.

### Behat

Non-JavaScript only (`tests/behat/ltiaccountmerge.feature`, `tests/behat/ui_negative.feature`, tags
`@local @local_ltiaccountmerge`). In the container `ltibehat <tree> <db> local_ltiaccountmerge`; by hand:

```bash
cd /home/claude/moodle                      # or /home/claude/moodle53 (use public/admin/... for init there)
LTI_DB=pgsql php admin/tool/behat/cli/init.php
LTI_DB=pgsql php -S 127.0.0.1:8145 -t /home/claude/moodle &       # 8153 and the public/ webroot for the 5.3 tree
LTI_DB=pgsql vendor/bin/behat --config /home/claude/data/moodle_pgsql/bht/behatrun/behat/behat.yml --tags @local_ltiaccountmerge
```

Take the same lock as `ltirun` (`flock /tmp/ltirun_<tree>_<db>.lock ...`) so that nobody mirrors files while the run
initialises. `@javascript` scenarios were deliberately not written (they would need Chrome and run only in CI).

### Playwright

Specs: `tests/playwright/ltiaccountmerge.spec.js` (five stories) and `firstlaunch.spec.js` (AR-02: real first launches in
the three core modes and a launch error, on 5.3 as tool and on 4.5 as tool through `seed_reverse.php`) with helpers in
`lib.js`. Every run that changes the stack must hold `/tmp/e2e_stack.lock`. They read the stack description from
`LTI_SEED` (default `/home/claude/e2e/state/seed.json`). The plugin must be mirrored into both sites first
(`E2E_COMPONENTS=local_ltiaccountmerge /home/claude/e2e/bin/e2e-mirror`).

```bash
cd /home/claude/e2e && . bin/e2e-env.sh && NODE_PATH=/home/claude/e2e/node_modules \
  npx playwright test -c /home/claude/work/moodle-local_ltiaccountmerge/tests/playwright/playwright.config.js
```

The stories change state on the stack (unbind and rename the accounts of students A1 to A3, set the activity to "same
window", switch `firsthint` on and off); run `e2e-reset` afterwards. The first story waits for a 120 s policy window to end,
so a run takes about three minutes.

## Test matrix (this release)

Release 0.3.0 (2026-10-27, final sources):

| Layer | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
| --- | --- | --- | --- | --- |
| Static (phplint, validate, savepoints, mustache, codechecker, phpdoc) | 6 / 6 OK | 6 / 6 OK | 6 / 6 OK | 6 / 6 OK |
| PHPUnit | 297 tests, 1852 assertions, OK | 297 tests, 1852 assertions, OK | 297 tests, 1868 assertions, OK (30 PHPUnit deprecations) | 297 tests, 1868 assertions, OK (30 PHPUnit deprecations) |
| Integration, real `tool_mergeusers` (`tests/integration/run.sh`) | merge 157 + schedule 65 OK | merge 157 + schedule 65 OK | 222 OK | 222 OK |
| Behat (non-JS, 19 scenarios, 182 steps) | 19 / 19 passed | not run | 19 / 19 passed | not run |
| Playwright `firstlaunch.spec.js` | A = 4.5, B = 5.3, both directions: 8 / 8 passed (2.9 min) | not run | (same stack run) | not run |
| Mutation checks M1 to M9 (`merge_executor_test`, `merge_preview_test`) | - | - | - | 9 / 9 RED as expected |

Earlier builds (history):

After the rename (local_ltiaccountmerge, fresh install; 2026-10-25, final sources):

| Layer | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
| --- | --- | --- | --- | --- |
| Static (6 checks) | 6 / 6 OK | 6 / 6 OK | 6 / 6 OK | 6 / 6 OK |
| PHPUnit | 259 tests, 1371 assertions, OK | 259 tests, 1371 assertions, OK | 259 tests, 1387 assertions, OK (26 PHPUnit deprecations) | 259 tests, 1387 assertions, OK (26 PHPUnit deprecations) |
| Family run (`ltifamily`, five suites) | 1369 tests OK | 1369 tests OK | 1369 tests OK | 1369 tests OK |
| Behat (non-JS, 11 scenarios, 99 steps) | 11 / 11 passed | 11 / 11 passed | 11 / 11 passed | 11 / 11 passed |
| Playwright (5 stories) | A = 4.5 / PostgreSQL, B = 5.3 / PostgreSQL: all five passed (5.0 min), re-seeded stack | not run | not run | not run |


Build 2026102502 (F1 fix, 2026-10-25, before the rename; the upgrade checks of that run are obsolete and were removed from this table):

| Layer | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
| --- | --- | --- | --- | --- |
| Static (6 checks) | 6 / 6 OK | 6 / 6 OK | 6 / 6 OK (see note 1) | 6 / 6 OK |
| PHPUnit | 262 tests, 1386 assertions, OK | 262 tests, 1386 assertions, OK | 262 tests, 1402 assertions, OK (26 PHPUnit deprecations) | 262 tests, 1402 assertions, OK (26 PHPUnit deprecations) |
| Behat (non-JS, 11 scenarios, 99 steps) | 11 / 11 passed | not re-run (see below) | not re-run | not re-run |

Build 2026102501 (same day, before the F1 fix; the fix changes only the merge path, settings text and the upgrade step):

| Layer | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
| --- | --- | --- | --- | --- |
| PHPUnit | 252 tests, 1305 assertions, OK | 252 tests, 1305 assertions, OK | 252 tests, 1321 assertions, OK (25 PHPUnit deprecations) | 252 tests, 1321 assertions, OK (25 PHPUnit deprecations) |
| Behat (non-JS, 11 scenarios, 99 steps) | 11 / 11 passed | 11 / 11 passed | 11 / 11 passed | 11 / 11 passed |
| Playwright (5 stories) | A = 4.5 / PostgreSQL, B = 5.3 / PostgreSQL: all five passed in 4.6 min (single stack, one combination only) | not run | not run | not run |
| `tests/load/apply_race.php 8` | PASS | PASS | PASS | PASS |

Behat, Playwright and the race script were not repeated for 2026102502: no feature, page, template or the applier changed;
only a settings description string changed, and Behat on 4.5 / PostgreSQL was re-run as a check.

Note 1: on 5.3 / PostgreSQL the Mustache lint failed twice with "Problem calling HTML validator" (the external validator
was unreachable; every template rendered OK). The third run was green. The templates did not change between the runs.

PHPUnit method counts by file (`function test_*`, data providers multiply them into the run's total): `policy_test` 15,
`resolver_test` 6, `modes_test` 2, `mode_applier_test` 18, `core_modes_test` 2, `hook_callbacks_test` 13,
`link_service_test` 13, `link_request_test` 11, `error_catalogue_test` 13, `merge_simulator_test` 9, `merge_service_test` 14,
`mergeusers_callbacks_test` 9, `install_test` 3 (replaces `upgrade_test` 6), `ar_gates_test` 10, `plugin_test` 9, `provider_test` 7 (155 methods,
259 tests with data sets).

## Honest limits

* Not run: Moodle 5.0, 5.1, 5.2; PHP versions other than 8.3; GitHub Actions (the workflows were adapted but never
  executed here); `@javascript` Behat; manual accessibility audit (axe-core is automated in Playwright story 5 only); an upgrade from the
  0.1.0 alpha (impossible by design after the rename: fresh install only); uninstall through
  the admin UI (function level only); backup and restore; a burst of real parallel launches through the stack; the real
  `tool_mergeusers` inside the PHPUnit suite (not installed in the test trees; the real plugin is exercised only by the release gate, which passed after the rename, run `rename2-20261025-1834`); mutation checks on 5.3 and MariaDB.
* One transient failure was seen and not explained: `hook_callbacks_test::test_launch_at_window_end_sees_baseline_with_hook`
  failed once in a run that also contained other (since fixed) failures; it then passed in all later runs, including
  six consecutive repetitions with the `mode_applier_test` tests.
* One transient static failure was seen when four runner processes ran in parallel: the Mustache lint calls an external
  HTML validator that failed ("Problem calling HTML validator", a network warning that the runner counts as failure), and
  one codechecker run reported failure with an empty report. Both were green when repeated one at a time.

## CI workflows

`.github/workflows/moodle-plugin-ci-dev.yml` (branches except main, reduced matrix 4.5/5.0/5.3) and
`moodle-plugin-ci-main.yml` (main, full matrix 4.5 to 5.3 x PostgreSQL/MariaDB, release artefact, coverage floor, stale
files). None has run (no GitHub access). Checked locally 2026-10-25 with `/home/claude/bin/ltici-check.py` (PyYAML
parse, job graph and `needs`, matrix cells, Behat tag, archive prefix, every referenced plugin path, no clone of plugin
repositories): 0 errors for all five family plugins. No workflow clones another plugin repository (the template's sibling
lockstep job was removed: the family plugins are independent).
