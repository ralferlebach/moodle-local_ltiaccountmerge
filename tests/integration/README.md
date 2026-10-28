# Integration harness: real tool_mergeusers and real scheduled-task runs

`run.sh <45|53> [pgsql|mariadb ...]` proves the audit items AR-03 (reviewed merge) and AR-04 (provisioning schedule) with
real processes instead of fixtures. It never touches the shared main or PHPUnit databases and never writes into the shared
Moodle trees:

* a **private copy of the Moodle tree** is made with hard links (`rsync --link-dest`, practically no extra disk space) under
  `$LTIAM_BASE/tree<tree>/` (default `/home/claude/data/ltiam_int`); the family plugins are copied from `$LTIAM_WORK`
  (default `/home/claude/work/moodle-<component>`) and `tool_mergeusers` from `$LTIAM_MERGEUSERS`
  (default `/home/claude/ref/moodle-tool_mergeusers`). Because only the private copy changes, no runner lock is taken
  (the lead's `/home/claude/gates/run_tree.sh` installs `tool_mergeusers` into the shared tree and therefore takes them);
* **scratch databases** `<tree db>_ltiam` (merge part) and `<tree db>sched_ltiam` (schedule part) are dropped, recreated and
  installed per run; every PHP helper refuses to start on a database whose name does not end in `_ltiam`.

Output: `$LTIAM_OUT/<run>/<tree>/<db>/` (default `/tmp/ltiam_integration`): `v_*.txt` are the verification results
(`OK` / `FAIL` lines), the JSON files are previews, CLI results and state snapshots. `LTIAM_PARTS="merge"` or
`"schedule"` restricts the run. Needs `psql`, `mariadb`, `faketime` (for the DST runs) and the database servers of the
runner environment.

## Merge part (`seed.php`, `step.php`, `verify.php`, the plugin's `cli/merge.php`)

| Scenario | What runs | Verified |
| --- | --- | --- |
| Consent rule | `tool_mergeusers`' own `user_merger::merge()` for two LTI accounts without consent; then for two ordinary accounts | LTI pair rolled back by this plugin's callback (whole transaction, every row unchanged, tool log `error`, audit row afterwards); ordinary pair merged |
| Preview | `cli/merge.php --preview` for pair 1 | all eight required categories listed; grades, quiz attempt, activity completion, enrolment, group, LTI binding, pseudonym mapping and completion-sync outbox counted; bindings handled by this plugin and pseudonyms by `ltisource_ltipseudonym` inside the merge; undeclared plugin tables block (the only blockers an administrator may lift, here by declaring them in `tool_mergeusers`' custom settings) |
| Refusals | consent with a wrong fingerprint, a wrong username, conflicts not accepted | refused with the expected code, nothing written |
| Pair 1 | consent + run | merged by the real tool; job `succeeded`, in-transaction mark, exactly one tool log entry; no row changed owner except a1 -> b1; grade 80 now b1's, bystander keeps 40; a1's pseudonym resolves to b1 (same token, active), bystander and b1 pseudonyms unchanged; completion outbox rows of a1 now b1's with the same desired state, bystander's unchanged; link requests settled; a1 suspended by the tool |
| Pair 2 (conflicts) | preview `conflicts`, consent with acceptance | identity of the same platform stays on a2 (journal `partial`), b2 keeps its own; a2's pseudonym of the same scope retired, b2's active; one group membership; one outbox row per key |
| Crash inside the transaction | the merge process stalls on a row lock held by a second database session and is killed with `kill -9`; then `--recover` | job `aborted` (recovered, no mark), nothing changed, tool log corrected to `error`; the pair merges afterwards with a new consent |
| Crash after the commit | the merge process is released, commits, then stalls on the tool's own log update (second lock) and is killed | job `succeeded` (recovered, mark present), rows of a6 on b6, binding moved, tool log `success` |
| Competing consents | `a4 -> b4` and `b4 -> c4` consented in parallel | exactly one job, the other refused (`err_merge_open`) |
| Parallel executions | two jobs, and the same job twice, while a merge runs | the second reports `busy`, finishes later; a finished job is not merged again |
| Cron path | consent without `--run`, then `admin/cli/adhoc_task.php --execute` | job executed by the ad hoc task |
| Whole run | snapshots before and after everything | every row of the watched tables either stayed or moved from a merged account to its own kept account; no job left running; every succeeded job has the mark and a successful tool log |

## Schedule part (`sched.php`, `verify.php sched`)

Real `admin/cli/scheduled_task.php --execute=\local_ltiaccountmerge\task\apply_modes` runs at fixed offsets:
automatic -> existing-only (site window) -> new-or-existing (overlapping, higher-priority window of one resource) ->
existing-only -> automatic; an administrator changes a resource by hand during the window (policy keeps governing, the
manual value is restored afterwards); launch probes through `auth_plugin_lti::complete_login()` with the stored mode (bound
accounts always land in the same account, an unbound identity follows the mode); four parallel task processes and three
parallel launches at a window boundary (exactly one change); a task process killed with `kill -9` while it waits on a
locked resource (the resource before it stays committed, the locked one untouched, the next run works); a resource whose
course disappeared (failure audited, others applied, applied after the repair); daylight saving time with the process
clock started by `faketime` at chosen instants (Europe/Berlin daily window 02:30-03:30 on the day before, on and after the
spring change and on the autumn change). The clock runs (a frozen clock makes Moodle's lock and shutdown loops spin), so
the instants just before a boundary are taken 30 s early; the exact boundary second is proven by PHPUnit with an injected
clock.
