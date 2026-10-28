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
 * End-to-end stories for local_ltiaccountmerge on the two-site stack.
 *
 * Site A (Moodle 4.5) is the LTI platform, site B (Moodle 5.3) is the tool and has this plugin installed.
 *
 *  1. A policy window flips the learner provisioning mode from "automatic" to "existing account only" and back.
 *     The optional pre-launch apply step (after_config callback) makes a launch at the boundary see the right mode.
 *  2. The preview page shows the effective mode for any instant.
 *  3. An administrator links an unbound LTI identity to an existing account through the plugin UI; the next
 *     launch of that platform user lands in that account.
 *  4. The optional first-launch hint is shown above the core account-binding dialog, only when enabled.
 *  5. axe-core (the copy bundled with Moodle) finds no WCAG 2.1 A/AA violation on the plugin's pages and on the hint.
 *
 * Needs: the stack from the seed file, psql access to B's database and Moodle CLI access to B (see lib.js for
 * the overridable defaults). The tests are serial and clean up after themselves.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const lib = require('./lib');

const seed = lib.loadSeed();
const siteA = seed.urls.a;
const siteB = seed.urls.b;
const admin = seed.admins.b;
const uuid = seed.lti.tool.resource_uuid;
const P = lib.prefix();

/** axe-core as bundled with Moodle (used by Moodle's own Behat accessibility step); overridable. */
const AXE_PATH = process.env.LTIAM_AXE_PATH || '/home/claude/moodle53/public/lib/behat/axe/axe.min.js';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Run axe-core on a part of the current page and return the violations (id, impact, nodes).
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} selector CSS selector of the region to analyse.
 * @returns {Promise<string[]>} One line per violation.
 */
async function axeViolations(page, selector) {
  await page.addScriptTag({ content: fs.readFileSync(AXE_PATH, 'utf8') });
  const result = await page.evaluate(async ({ selector, tags }) => {
    // eslint-disable-next-line no-undef
    const out = await axe.run({ include: [[selector]] }, { runOnly: { type: 'tag', values: tags } });
    return out.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`);
  }, { selector, tags: AXE_TAGS });
  return result;
}

/** Window length in seconds; long enough for one complete launch on a busy machine. */
const WINDOW_SECONDS = 120;

/**
 * Stored core provisioning modes of the seeded resource.
 *
 * @returns {number[]} [learner, instructor].
 */
function storedModes() {
  const row = lib.sqlB(
    `select provisioningmodelearner||'|'||provisioningmodeinstructor from ${P}enrol_lti_tools where uuid='${uuid}'`);
  return row.split('|').map(Number);
}

/**
 * Number of auto-provisioned users on B.
 *
 * @returns {number} Count.
 */
function ltiUsersOnB() {
  return Number(lib.sqlB(`select count(*) from ${P}user where auth='lti' and deleted=0`));
}

/**
 * Launch the seeded activity as a platform user and return the page after the launch settled.
 *
 * @param {import('@playwright/test').Browser} browser The browser.
 * @param {string} key Seed user key on A.
 * @param {RegExp} expected URL the launch must reach on B.
 * @returns {Promise<{ctx: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>}
 */
async function launchAs(browser, key, expected) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await lib.login(page, siteA, lib.getUser(seed, key));
  await page.goto(seed.lti_activity.view_url);
  await lib.waitForUrl(page, expected);
  return { ctx, page };
}

test.describe.serial('local_ltiaccountmerge', () => {
  let baseline;
  let container;

  test.beforeAll(() => {
    baseline = storedModes();
    // The stories need learners that are not bound on B yet (earlier launches may have bound them) and a launch in the
    // same window (the identity is read from the top-level page afterwards).
    const ids = ['studentA1', 'studentA2', 'studentA3'].map((key) => lib.getUser(seed, key).id).join(',');
    const subs = ids.split(',').map((id) => `'${id}'`).join(',');
    // Their earlier B accounts are renamed (the auto-created user name is deterministic and would collide with a new
    // account) and their bindings removed. Run `e2e-reset` to restore the stack's baseline afterwards.
    const bound = `select userid from ${P}auth_lti_linked_login where issuer='${seed.lti.iss}' and sub in (${subs})`;
    lib.sqlB(`update ${P}user set username = username || '.e2e' || id where auth='lti' and username like 'enrol\\_lti\\_13\\_%' `
      + `and (id in (${bound}) or id not in (select userid from ${P}auth_lti_linked_login))`);
    lib.sqlB(`delete from ${P}auth_lti_linked_login where issuer='${seed.lti.iss}' and sub in (${subs})`);
    container = lib.sqlA(`select launchcontainer from ${P}lti where id=${seed.lti_activity.instance}`);
    lib.sqlA(`update ${P}lti set launchcontainer=5 where id=${seed.lti_activity.instance}`);
    lib.sqlB(`delete from ${P}local_ltiaccountmerge_policy`);
    lib.sqlB(`delete from ${P}local_ltiaccountmerge_applied`);
    lib.setConfigB('prelaunchapply', '1');
  });

  test.afterAll(() => {
    lib.sqlB(`delete from ${P}local_ltiaccountmerge_policy`);
    lib.sqlB(`delete from ${P}local_ltiaccountmerge_applied`);
    lib.sqlB(`update ${P}enrol_lti_tools set provisioningmodelearner=${baseline[0]}, provisioningmodeinstructor=${baseline[1]} `
      + `where uuid='${uuid}'`);
    lib.setConfigB('prelaunchapply', '0');
    lib.sqlA(`update ${P}lti set launchcontainer=${container} where id=${seed.lti_activity.instance}`);
  });

  test('a policy window flips learner launches from automatic to existing-account-only and back', async ({ browser }) => {
    // The seeded resource starts with automatic learner provisioning.
    expect(baseline[0]).toBe(1);

    const start = new Date(Date.now() - 3600 * 1000);
    const end = new Date(Date.now() + WINDOW_SECONDS * 1000);

    const adminCtx = await browser.newContext();
    const adminPage = await adminCtx.newPage();
    await lib.login(adminPage, siteB, admin);
    await adminPage.goto(`${siteB}/local/ltiaccountmerge/policy_edit.php`);
    await adminPage.fill('input[name="name"]', 'E2E exam window');
    await adminPage.selectOption('select[name="timezone"]', 'UTC');
    await adminPage.selectOption('select[name="recurrence"]', 'once');
    await adminPage.fill('input[name="localstart"]', lib.utcText(start));
    await adminPage.fill('input[name="localend"]', lib.utcText(end));
    await adminPage.selectOption('select[name="modelearner"]', '3');
    await adminPage.click('#id_submitbutton');
    await expect(adminPage.locator('body')).toContainText('E2E exam window');
    await expect(adminPage.locator('body')).toContainText('Policy saved.');

    // Inside the window: the launch reads mode 3, so an unbound learner is NOT provisioned automatically.
    const usersBefore = ltiUsersOnB();
    const first = await launchAs(browser, 'studentA1', /tool-b\.test\/auth\/lti\/login\.php/);
    expect(storedModes()[0]).toBe(3);
    expect(ltiUsersOnB()).toBe(usersBefore);
    expect(Date.now(), 'first launch was too slow for the policy window').toBeLessThan(end.getTime() - 5000);
    await first.ctx.close();

    // After the window: the baseline is restored before core reads it, the learner is created automatically.
    const wait = end.getTime() + 2000 - Date.now();
    if (wait > 0) {
      await new Promise((resolve) => setTimeout(resolve, wait));
    }
    const second = await launchAs(browser, 'studentA2', /tool-b\.test\/course\/view\.php/);
    expect(storedModes()[0]).toBe(1);
    expect(ltiUsersOnB()).toBe(usersBefore + 1);
    await second.ctx.close();
    await adminCtx.close();
  });

  test('the preview page shows the effective mode for any instant', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await lib.login(page, siteB, admin);
    await page.goto(`${siteB}/local/ltiaccountmerge/preview.php`);
    await page.selectOption('select[name="timezone"]', 'UTC');
    // Inside the (now expired) window of the previous story.
    const inside = new Date(Date.now() - 600 * 1000);
    await page.fill('input[name="localtime"]', lib.utcText(inside));
    await page.click('#id_submitbutton');
    await expect(page.locator('table.generaltable')).toContainText('Prompt: existing account only');
    // Far in the future nothing governs the resource any more.
    await page.fill('input[name="localtime"]', '2099-01-01 12:00:00');
    await page.click('#id_submitbutton');
    await expect(page.locator('table.generaltable')).toContainText('Not governed');
    await ctx.close();
  });

  test('an administrator links an unbound identity through the plugin UI and the next launch lands in that account', async ({ browser }) => {
    const student = lib.getUser(seed, 'studentA3');
    const target = lib.getUser(seed, 'teacherB');
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await lib.login(page, siteB, admin);
    await page.goto(`${siteB}/local/ltiaccountmerge/link.php`);
    await page.fill('input[name="issuer"]', seed.lti.iss);
    await page.fill('input[name="sub"]', String(student.id));
    await page.fill('input[name="username"]', target.username);
    await page.selectOption('select[name="route"]', 'direct');
    await page.click('#id_submitbutton');
    // Pre-flight page, then the explicit attestation.
    await expect(page.locator('body')).toContainText(target.username);
    await page.check('input[type="checkbox"][name="attested"]');
    await page.fill('textarea[name="reason"]', 'E2E: verified in person');
    await page.click('#id_submitbutton');
    await expect(page.locator('body')).toContainText('The LTI identity was linked to the account.');
    expect(Number(lib.sqlB(
      `select count(*) from ${P}auth_lti_linked_login where userid=${target.id} and issuer='${seed.lti.iss}'`))).toBe(1);
    await ctx.close();

    const usersBefore = ltiUsersOnB();
    const launch = await launchAs(browser, 'studentA3', /tool-b\.test\/course\/view\.php/);
    const userId = await launch.page.evaluate(() => window.M.cfg.userId);
    expect(Number(userId), 'the launch must land in the linked account, not in a new user').toBe(target.id);
    expect(ltiUsersOnB()).toBe(usersBefore);
    await launch.ctx.close();

    // A second link attempt for the same identity is refused with a support ID; nothing changes.
    const again = await browser.newContext();
    const apage = await again.newPage();
    await lib.login(apage, siteB, admin);
    await apage.goto(`${siteB}/local/ltiaccountmerge/link.php`);
    await apage.fill('input[name="issuer"]', seed.lti.iss);
    await apage.fill('input[name="sub"]', String(student.id));
    await apage.fill('input[name="username"]', target.username);
    await apage.click('#id_submitbutton');
    await expect(apage.locator('body')).toContainText(/already linked/i);
    await again.close();

    lib.sqlB(`delete from ${P}auth_lti_linked_login where userid=${target.id} and issuer='${seed.lti.iss}'`);
  });
  test('the optional hint appears on the core account-binding dialog and only when enabled', async ({ browser }) => {
    // AR-G1: the dialog itself cannot be replaced; the plugin only adds a notice above it through the output hook.
    lib.sqlB(`update ${P}enrol_lti_tools set provisioningmodelearner=3 where uuid='${uuid}'`);
    try {
      lib.setConfigB('firsthint', '0');
      const off = await launchAs(browser, 'studentA1', /tool-b\.test\/auth\/lti\/login\.php/);
      await expect(off.page.locator('[data-ltiam-hint]')).toHaveCount(0);
      await off.ctx.close();

      lib.setConfigB('firsthint', '1');
      const on = await launchAs(browser, 'studentA1', /tool-b\.test\/auth\/lti\/login\.php/);
      const hint = on.page.locator('[data-ltiam-hint]');
      await expect(hint).toBeVisible();
      await expect(hint).toContainText('Signing in from your learning platform');
      // The core dialog is still there and untouched.
      await expect(on.page.locator('body')).toContainText(/existing account|new account|Account/i);
      await on.ctx.close();
    } finally {
      lib.setConfigB('firsthint', '0');
      lib.sqlB(`update ${P}enrol_lti_tools set provisioningmodelearner=${baseline[0]} where uuid='${uuid}'`);
    }
  });
  test('the plugin pages and the hint have no WCAG 2.1 A/AA violations that axe-core can detect', async ({ browser }) => {
    // Automated check only (axe-core finds roughly a third of accessibility problems); no screen-reader or keyboard test.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await lib.login(page, siteB, admin);
    const pages = [
      '/local/ltiaccountmerge/policies.php',
      '/local/ltiaccountmerge/policy_edit.php',
      '/local/ltiaccountmerge/preview.php',
      '/local/ltiaccountmerge/link.php',
      '/local/ltiaccountmerge/texts.php',
      '/local/ltiaccountmerge/text_edit.php?code=firstloginhint',
      '/local/ltiaccountmerge/audit.php',
      '/local/ltiaccountmerge/simulate.php',
      '/local/ltiaccountmerge/error.php?code=launcherror&sid=LTIAM-0123ABCD',
    ];
    const problems = [];
    for (const path of pages) {
      await page.goto(`${siteB}${path}`);
      const violations = await axeViolations(page, '#region-main');
      violations.forEach((v) => problems.push(`${path}: ${v}`));
    }
    expect(problems, problems.join('\n')).toEqual([]);
    await ctx.close();

    // The hint above the core dialog.
    lib.sqlB(`update ${P}enrol_lti_tools set provisioningmodelearner=3 where uuid='${uuid}'`);
    try {
      lib.setConfigB('firsthint', '1');
      const on = await launchAs(browser, 'studentA1', /tool-b\.test\/auth\/lti\/login\.php/);
      expect(await axeViolations(on.page, '[data-ltiam-hint]')).toEqual([]);
      await on.ctx.close();
    } finally {
      lib.setConfigB('firsthint', '0');
      lib.sqlB(`update ${P}enrol_lti_tools set provisioningmodelearner=${baseline[0]} where uuid='${uuid}'`);
    }
  });
});
