# Load and concurrency checks: local_ltiaccountmerge

The template's load tests (k6, JMeter) targeted web-service read endpoints and a capacity race of another plugin. This
plugin has neither: its pages are administration pages used by a handful of people, and its only per-launch work (the
optional pre-launch apply step) is a lock plus a few queries. The template files, their seed script and the `load-k6` /
`load-jmeter` workflows were therefore **removed** rather than left as stubs that cannot run.

## What exists

* `apply_race.php` - a multi-process check for the window boundary. It creates a course, a published LTI 1.3 resource
  and a policy that opens in four seconds, starts N PHP processes (default 8) that wait for the same instant and then run the
  pre-launch apply, and asserts: all processes finish, exactly one of them writes the new mode (one audit row), every process
  afterwards reads the new mode, the uuid is unchanged, and after the window closes the baseline is restored exactly once.
  It removes everything it created. **Development sites only.**

  ```bash
  cd /home/claude/moodle && php local/ltiaccountmerge/tests/load/apply_race.php 8     # 4.5 tree
  cd /home/claude/moodle53 && php public/local/ltiaccountmerge/tests/load/apply_race.php 8   # 5.3 tree
  LTI_DB=mariadb php ... # the other database
  ```

* PHPUnit: `mode_applier_test::test_lock_held_by_another_process` (a second real PHP process holds the lock file),
  `test_busy_lock_changes_nothing`, `test_waiting_process_uses_a_fresh_instant`, `merge_service_test::test_busy_lock`.

## What is missing

A burst of real HTTP launches through the two-site stack at the boundary second (the Playwright stories launch one user per
side of the boundary). It is listed as open in `docs/ACCEPTANCE.md`.
