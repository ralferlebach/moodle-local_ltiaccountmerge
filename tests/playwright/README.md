# local_ltiaccountmerge: Playwright end-to-end tests

End-to-end stories on the two-site LTI stack: site A (Moodle 4.5) is the LTI platform, site B (Moodle 5.3) is the tool
and has this plugin installed. All site data (URLs, admin and user credentials, course and resource ids, LTI
registration) comes from a JSON seed file named by `LTI_SEED` (default `/home/claude/e2e/state/seed.json`).

## Stories (`ltiaccountmerge.spec.js`, serial)

1. **Policy window flips the mode.** An administrator creates a one-off site policy through the UI (learner mode
   "existing account only", window ends in 120 s) with the pre-launch apply step on. A learner launch from A inside the
   window ends at the account-binding dialog on B and creates no user. After the window, a launch of a second learner is
   provisioned automatically, and the stored resource mode is back at its baseline.
2. **Preview.** The preview page shows the policy's mode for an instant inside the window and "Not governed" far in
   the future.
3. **Administrator links an unbound identity.** Through `link.php` (attestation and reason) the identity of student A3 is
   linked to the B account of `teacherB`; the next launch of student A3 lands in that account without creating a user;
   a second attempt is refused as "already linked". The test removes the binding afterwards.

4. **First-launch hint.** With the learner mode set to "existing account only", an unbound learner lands on the core
   account-binding dialog on B. With the setting `firsthint` off there is no notice; with it on, the notice from the
   error catalogue is shown above the (unchanged) core dialog. This is the supported fallback for gate AR-G1.

5. **Automated accessibility check.** axe-core (bundled with Moodle, `LTIAM_AXE_PATH` overrides the path) runs on the main
   region of every plugin page and on the hint with the tags WCAG 2.0/2.1 A and AA. This is an automated check only; it
   finds a fraction of the possible problems.

## First launch in the real core flow (`firstlaunch.spec.js`, audit item AR-02)

Both directions, serial: **5.3 as tool** (platform A 4.5 -> tool B 5.3, the stack's seeded registration) and **4.5 as
tool** (platform B 5.3 -> tool A 4.5, a reverse registration created by `seed_reverse.php` for the run and removed
afterwards; site A's settings `allowframembedding`, `enrol_lti` and `auth_lti` are restored). Per direction:

1. mode 1 (automatic): the first launch provisions an `auth=lti` account, no dialog;
2. mode 2 (new or existing): the core binding dialog with "Create an account for me" and "Log in", this plugin's notice
   above it (`firsthint` on); "Create an account for me" creates and binds an account;
3. mode 3 (existing only): the core dialog without the create option; "Log in" with an existing tool account, then
   "Link this account" binds exactly that account;
4. launch error (resource disabled): the core error page with the notice and without the learner's e-mail, names, user
   name or subject; the plugin's own error page shows only the support ID.

Screenshots of every state: `$LTIAM_EVIDENCE` (default `tests/playwright/test-results/evidence`). Run it under the stack
lock: `flock /tmp/e2e_stack.lock npx playwright test -c .../playwright.config.js firstlaunch.spec.js`.

## Requirements

* the stack from `/home/claude/e2e` (`e2e-up`, `e2e-seed`, plugin mirrored into both sites with `e2e-mirror`);
* `psql` access to the databases of both sites and Moodle CLI access to B. Defaults match that stack and can be
  overridden: `LTIAM_A_DB_PORT` (5432) / `LTIAM_A_DB_NAME` (moodle), `LTIAM_B_DB_PORT` (5433) / `LTIAM_B_DB_NAME`
  (moodle53), `LTIAM_A_DB_USER|PASS` and `LTIAM_B_DB_USER|PASS` (moodle / moodle), `LTIAM_DB_PREFIX` (mdl_) and
  `LTIAM_B_CLI` (shell command prefix that runs `admin/cli/cfg.php` on B).

The specs change state on the stack: they unbind (and rename the auto-created B accounts of) student A1 to A3, switch the
seeded activity to "same window" for the run, and use `prelaunchapply`. Everything except the unbound accounts is restored
at the end; run `e2e-reset` afterwards to return the stack to its seeded baseline.

## Run

```bash
# with the stack's own Playwright installation
cd /home/claude/e2e && NODE_PATH=/home/claude/e2e/node_modules npx playwright test \
  -c /home/claude/work/moodle-local_ltiaccountmerge/tests/playwright/playwright.config.js
# or standalone
cd tests/playwright && npm install && npx playwright install --with-deps chromium && npm test
# syntax check only
cd tests/playwright && npm run check
```

## Status

See `docs/TESTING.md` for whether these specs have been executed. The spec timing (a 120 s window and two launches)
assumes a first launch takes well under a minute; on a very slow machine raise `WINDOW_SECONDS`.
