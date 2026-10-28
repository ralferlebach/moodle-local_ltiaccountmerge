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

/*
 * Audit item AR-02: the three core provisioning modes in a REAL first launch, on both tool versions.
 *
 *  - "5.3 as tool": site A (Moodle 4.5) is the platform, site B (Moodle 5.3) the tool (the stack's seeded registration).
 *  - "4.5 as tool": site B (Moodle 5.3) is the platform, site A (Moodle 4.5) the tool, through a reverse registration that
 *    seed_reverse.php creates for the run and removes afterwards.
 *
 * Per direction: mode 1 (automatic) provisions without a dialog; mode 2 (new or existing) shows the CORE binding dialog with
 * both options and, when the setting firsthint is on, this plugin's notice above it ("Create an account for me" is used);
 * mode 3 (existing only) shows the core dialog without the create option; the learner logs in to an existing tool account
 * and links it. Finally the resource is disabled: the core launch error page carries the notice and shows no personal data
 * of the launching user. Screenshots of every state go to $LTIAM_EVIDENCE (default test-results/evidence).
 *
 * Run only under the stack lock: flock /tmp/e2e_stack.lock npx playwright test ... firstlaunch.spec.js
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const lib = require('./lib');

const seed = lib.loadSeed();
const P = lib.prefix();
const EVIDENCE = process.env.LTIAM_EVIDENCE || path.join(__dirname, 'test-results', 'evidence');
const REVERSE = path.join(__dirname, 'seed_reverse.php');
const STATE = path.join(os.tmpdir(), `ltiam-reverse-${process.pid}.json`);
const ENV = '. /home/claude/e2e/bin/e2e-env.sh;';

/**
 * Run a CLI script on a site of the stack.
 *
 * @param {string} site a or b.
 * @param {string} args Script and arguments.
 * @returns {string} Output.
 */
function cli(site, args) {
  return execFileSync('bash', ['-c', `${ENV} moodle_cli ${site} ${args}`], { encoding: 'utf8', stdio: 'pipe' });
}

/**
 * Set a setting of this plugin on a site.
 *
 * @param {string} site a or b.
 * @param {string} name Setting.
 * @param {string} value Value.
 */
function setConfig(site, name, value) {
  cli(site, `admin/cli/cfg.php --component=local_ltiaccountmerge --name=${name} --set=${value}`);
}

/**
 * Save a screenshot as evidence.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string} name File name without extension.
 */
async function evidence(page, name) {
  fs.mkdirSync(EVIDENCE, { recursive: true });
  await page.screenshot({ path: path.join(EVIDENCE, `${name}.png`), fullPage: true });
}

/**
 * The two directions. Each describes the platform side (where learners log in and open the activity) and the tool side
 * (where the first launch happens and this plugin is installed).
 */
const directions = [
  {
    key: '53tool',
    title: 'Moodle 5.3 as tool (platform 4.5)',
    tool: 'b',
    platform: 'a',
    setup() {
      const learners = ['studentA1', 'studentA2', 'studentA3'].map((k) => lib.getUser(seed, k));
      // Earlier runs may have bound these learners on B: rename their accounts (deterministic user names) and unbind them.
      const subs = learners.map((u) => `'${u.id}'`).join(',');
      const bound = `select userid from ${P}auth_lti_linked_login where issuer='${seed.lti.iss}' and sub in (${subs})`;
      lib.sqlB(`update ${P}user set username = username || '.fl' || id where auth='lti' and username like 'enrol\\_lti\\_13\\_%' `
        + `and id in (${bound})`);
      lib.sqlB(`delete from ${P}auth_lti_linked_login where issuer='${seed.lti.iss}' and sub in (${subs})`);
      this.container = lib.sqlA(`select launchcontainer from ${P}lti where id=${seed.lti_activity.instance}`);
      lib.sqlA(`update ${P}lti set launchcontainer=5 where id=${seed.lti_activity.instance}`);
      return {
        platformUrl: seed.urls.a,
        toolUrl: seed.urls.b,
        iss: seed.lti.iss,
        viewUrl: seed.lti_activity.view_url,
        uuid: seed.lti.tool.resource_uuid,
        toolCourse: seed.courses.b.id,
        learners: learners.map((u) => ({ username: u.username, password: u.password, email: u.email,
          firstname: u.firstname, lastname: u.lastname, id: u.id })),
        existing: JSON.parse(cli('b', `${REVERSE} --stage=existing --username=ltiamexisting53`)),
      };
    },
    teardown() {
      lib.sqlA(`update ${P}lti set launchcontainer=${this.container} where id=${seed.lti_activity.instance}`);
    },
    sql: (s) => lib.sqlB(s),
  },
  {
    key: '45tool',
    title: 'Moodle 4.5 as tool (platform 5.3, reverse registration)',
    tool: 'a',
    platform: 'b',
    setup() {
      if (fs.existsSync(STATE)) {
        fs.unlinkSync(STATE);
      }
      cli('b', `${REVERSE} --stage=platform1 --state=${STATE} --a=${seed.urls.a}`);
      cli('a', `${REVERSE} --stage=tool --state=${STATE}`);
      cli('b', `${REVERSE} --stage=platform2 --state=${STATE}`);
      cli('a', 'admin/cli/purge_caches.php');
      const st = JSON.parse(fs.readFileSync(STATE, 'utf8'));
      return {
        platformUrl: seed.urls.b,
        toolUrl: seed.urls.a,
        iss: st.platform.url,
        viewUrl: st.platform.viewurl,
        uuid: st.tool.uuid,
        toolCourse: st.tool.courseid,
        learners: st.platform.learners.slice(0, 3).map((u) => ({ ...u, lastname: 'Reverse' })),
        existing: st.tool.existing,
      };
    },
    teardown() {
      try {
        cli('a', `${REVERSE} --stage=cleanup-tool --state=${STATE}`);
      } finally {
        cli('b', `${REVERSE} --stage=cleanup-platform --state=${STATE}`);
      }
    },
    sql: (s) => lib.sqlA(s),
  },
];

for (const dir of directions) {
  test.describe.serial(`first launch, ${dir.title}`, () => {
    let ctx;
    let baseline;

    /**
     * Launch the activity as a platform learner in a fresh browser context.
     *
     * @param {import('@playwright/test').Browser} browser Browser.
     * @param {object} learner Platform account.
     * @returns {Promise<import('@playwright/test').Page>} Page after the launch arrived on the tool.
     */
    async function launch(browser, learner) {
      const context = await browser.newContext();
      const page = await context.newPage();
      await lib.login(page, ctx.platformUrl, learner);
      await page.goto(ctx.viewUrl);
      await page.waitForURL((u) => u.href.startsWith(ctx.toolUrl), { timeout: 60000 });
      await page.waitForLoadState('networkidle').catch(() => {});
      return page;
    }

    /**
     * Newest binding of the platform on the tool.
     *
     * @returns {{id: number, userid: number, auth: string}|null} Binding.
     */
    function newestBinding() {
      const row = dir.sql(`select b.id||'|'||b.userid||'|'||u.auth from ${P}auth_lti_linked_login b join ${P}user u on u.id=b.userid `
        + `where b.issuer='${ctx.iss}' order by b.id desc limit 1`);
      if (!row) {
        return null;
      }
      const [id, userid, auth] = row.split('|');
      return { id: Number(id), userid: Number(userid), auth };
    }

    /**
     * Set the learner provisioning mode of the published resource (as an administrator's edit would).
     *
     * @param {number} mode 1, 2 or 3.
     */
    function setMode(mode) {
      dir.sql(`update ${P}enrol_lti_tools set provisioningmodelearner=${mode} where uuid='${ctx.uuid}'`);
    }

    /**
     * No personal data of the launching learner on the page.
     *
     * @param {import('@playwright/test').Page} page Page.
     * @param {object} learner Platform account.
     * @param {string} where Label.
     */
    async function expectNoPii(page, learner, where) {
      const text = await page.locator('body').innerText();
      for (const value of [learner.email, learner.firstname, learner.lastname, learner.username]) {
        expect(text, `${where}: must not show ${value}`).not.toContain(value);
      }
      expect(text, `${where}: must not show the platform user id as a subject`).not.toMatch(new RegExp(`\\bsub\\W+${learner.id}\\b`));
    }

    test.beforeAll(() => {
      ctx = dir.setup();
      baseline = dir.sql(`select provisioningmodelearner||'|'||status from ${P}enrol_lti_tools t join ${P}enrol e on e.id=t.enrolid `
        + `where t.uuid='${ctx.uuid}'`).split('|').map(Number);
      dir.sql(`delete from ${P}local_ltiaccountmerge_policy`);
      dir.sql(`delete from ${P}local_ltiaccountmerge_applied`);
      setConfig(dir.tool, 'prelaunchapply', '0');
      setConfig(dir.tool, 'firsthint', '1');
    });

    test.afterAll(() => {
      try {
        dir.sql(`update ${P}enrol_lti_tools set provisioningmodelearner=${baseline[0]} where uuid='${ctx.uuid}'`);
        dir.sql(`update ${P}enrol set status=${baseline[1]} where id=(select enrolid from ${P}enrol_lti_tools where uuid='${ctx.uuid}')`);
        setConfig(dir.tool, 'firsthint', '0');
      } finally {
        dir.teardown();
      }
    });

    test(`${dir.key}: mode 1 (automatic) provisions a new account without any dialog`, async ({ browser }) => {
      setMode(1);
      const before = newestBinding();
      const page = await launch(browser, ctx.learners[0]);
      await expect(page).toHaveURL(new RegExp(`/course/view\\.php\\?id=${ctx.toolCourse}`), { timeout: 60000 });
      await expect(page.locator('#lti_adv_account_binding_options')).toHaveCount(0);
      const after = newestBinding();
      expect(after && (!before || after.id > before.id)).toBeTruthy();
      expect(after.auth).toBe('lti');
      await evidence(page, `${dir.key}-mode1-course`);
      await page.context().close();
    });

    test(`${dir.key}: mode 2 shows the core dialog (new or existing) with the plugin's notice; a new account is created`,
      async ({ browser }) => {
        setMode(2);
        const before = newestBinding();
        const page = await launch(browser, ctx.learners[1]);
        await expect(page).toHaveURL(/\/auth\/lti\/login\.php/);
        const dialog = page.locator('#lti_adv_account_binding_options');
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('input[name="new_account"]')).toBeVisible();
        await expect(dialog.locator('input[name="existing_account"]')).toBeVisible();
        const hint = page.locator('[data-ltiam-hint="1"]');
        await expect(hint).toBeVisible();
        // The notice is above the core dialog, not a replacement of it.
        const hintBox = await hint.boundingBox();
        const dialogBox = await dialog.boundingBox();
        expect(hintBox.y).toBeLessThan(dialogBox.y);
        await expectNoPii(page, ctx.learners[1], 'binding dialog (mode 2)');
        await evidence(page, `${dir.key}-mode2-dialog-with-notice`);
        await dialog.locator('input[name="new_account"]').click();
        await page.waitForLoadState('networkidle').catch(() => {});
        await evidence(page, `${dir.key}-mode2-created`);
        const after = newestBinding();
        expect(after && (!before || after.id > before.id)).toBeTruthy();
        expect(after.auth).toBe('lti');
        await page.context().close();
      });

    test(`${dir.key}: mode 3 shows the core dialog without "create"; the learner links an existing account`, async ({ browser }) => {
      setMode(3);
      const before = newestBinding();
      const page = await launch(browser, ctx.learners[2]);
      await expect(page).toHaveURL(/\/auth\/lti\/login\.php/);
      const dialog = page.locator('#lti_adv_account_binding_options');
      await expect(dialog).toBeVisible();
      await expect(dialog.locator('input[name="new_account"]')).toHaveCount(0);
      await expect(page.locator('[data-ltiam-hint="1"]')).toBeVisible();
      await expectNoPii(page, ctx.learners[2], 'binding dialog (mode 3)');
      await evidence(page, `${dir.key}-mode3-dialog-existing-only`);
      await dialog.locator('input[name="existing_account"]').click();
      await page.waitForURL(/\/login\/index\.php/, { timeout: 30000 });
      await page.fill('#username', ctx.existing.username);
      await page.fill('#password', ctx.existing.password);
      await page.click('#loginbtn');
      await page.waitForURL(/\/auth\/lti\/login\.php/, { timeout: 30000 });
      // Logged in now: the core dialog offers to link exactly this account.
      const link = page.locator('#lti_adv_account_binding_options input[name="existing_account"]');
      await expect(link).toBeVisible();
      await evidence(page, `${dir.key}-mode3-link-this-account`);
      await link.click();
      await page.waitForLoadState('networkidle').catch(() => {});
      const after = newestBinding();
      expect(after && (!before || after.id > before.id)).toBeTruthy();
      expect(after.userid).toBe(Number(ctx.existing.id));
      await evidence(page, `${dir.key}-mode3-linked`);
      await page.context().close();
    });

    test(`${dir.key}: a launch error stays safe - core error page with the notice and without personal data`,
      async ({ browser }) => {
        dir.sql(`update ${P}enrol set status=1 where id=(select enrolid from ${P}enrol_lti_tools where uuid='${ctx.uuid}')`);
        const context = await browser.newContext();
        const page = await context.newPage();
        await lib.login(page, ctx.platformUrl, ctx.learners[0]);
        await page.goto(ctx.viewUrl);
        await page.waitForURL((u) => u.href.startsWith(`${ctx.toolUrl}/enrol/lti/launch.php`), { timeout: 60000 });
        await page.waitForLoadState('networkidle').catch(() => {});
        const body = await page.locator('body').innerText();
        expect(body).toMatch(/invalid|not available|could not|error/i);
        await expect(page.locator('[data-ltiam-hint="1"]')).toBeVisible();
        await expectNoPii(page, ctx.learners[0], 'launch error page');
        await evidence(page, `${dir.key}-error-disabled-resource`);
        // The plugin's own error page: support ID only, no identity.
        await page.goto(`${ctx.toolUrl}/local/ltiaccountmerge/error.php?code=launcherror&sid=LTIAM-0123ABCD`);
        await expect(page.locator('body')).toContainText('LTIAM-0123ABCD');
        await expectNoPii(page, ctx.learners[0], 'plugin error page');
        await evidence(page, `${dir.key}-error-plugin-page`);
        await context.close();
      });
  });
}
