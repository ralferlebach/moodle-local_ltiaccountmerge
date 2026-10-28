/*
 * This file is part of Moodle - https://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Helpers for the local_ltiaccountmerge browser suite.
 *
 * The suite runs against the two-site stack: site A (LTI platform) launches into site B (LTI tool),
 * where this plugin is installed. Everything site specific comes from the JSON seed file named by
 * LTI_SEED (default /home/claude/e2e/state/seed.json); nothing is hard-coded except overridable defaults.
 */

const fs = require('fs');
const { execFileSync } = require('child_process');

const SEED_PATH = process.env.LTI_SEED || '/home/claude/e2e/state/seed.json';

/**
 * Read the seed file.
 *
 * @returns {object} Parsed seed.
 */
function loadSeed() {
  return JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
}

/**
 * Find a seed user by key or username.
 *
 * @param {object} seed The seed.
 * @param {string} key User key (for example studentA1) or username.
 * @returns {object} The user.
 */
function getUser(seed, key) {
  const user = seed.users.find((u) => u.key === key || u.username === key);
  if (!user) {
    throw new Error(`Seed has no user "${key}"`);
  }
  return user;
}

/**
 * Log in through the Moodle login form of a site, retrying because a late-initialising password
 * component can reset the field after it was filled.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} baseUrl Site URL without trailing slash.
 * @param {{username: string, password: string}} cred Credentials.
 * @returns {Promise<void>}
 */
async function login(page, baseUrl, cred) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(`${baseUrl}/login/index.php`);
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.fill('#username', cred.username);
    await page.fill('#password', cred.password);
    if ((await page.inputValue('#username')) !== cred.username || (await page.inputValue('#password')) !== cred.password) {
      continue;
    }
    await page.click('#loginbtn');
    const left = await page
      .waitForURL((u) => !/\/login\/index\.php$/.test(u.pathname), { timeout: 20000 })
      .then(() => true, () => false);
    if (left) {
      await page.waitForLoadState('load');
      return;
    }
  }
  throw new Error(`Login failed for ${cred.username} at ${baseUrl}`);
}

/**
 * Run a SQL statement against the database of a site and return trimmed stdout.
 *
 * @param {string} side 'A' (platform) or 'B' (tool). Connection details are overridable per side:
 *     LTIAM_<side>_DB_PORT, LTIAM_<side>_DB_NAME, LTIAM_<side>_DB_USER, LTIAM_<side>_DB_PASS.
 * @param {string} sql The statement.
 * @returns {string} Output with fields separated by "|".
 */
function sql(side, sql) {
  const defaults = side === 'A' ? ['5432', 'moodle'] : ['5433', 'moodle53'];
  const port = process.env[`LTIAM_${side}_DB_PORT`] || defaults[0];
  const name = process.env[`LTIAM_${side}_DB_NAME`] || defaults[1];
  const user = process.env[`LTIAM_${side}_DB_USER`] || 'moodle';
  const pass = process.env[`LTIAM_${side}_DB_PASS`] || 'moodle';
  return execFileSync('psql', ['-h', '127.0.0.1', '-p', port, '-U', user, name, '-At', '-F', '|', '-c', sql], {
    env: { ...process.env, PGPASSWORD: pass },
  }).toString().trim();
}

/**
 * Run SQL on tool site B.
 *
 * @param {string} statement The statement.
 * @returns {string} Output.
 */
function sqlB(statement) {
  return sql('B', statement);
}

/**
 * Run SQL on platform site A.
 *
 * @param {string} statement The statement.
 * @returns {string} Output.
 */
function sqlA(statement) {
  return sql('A', statement);
}

/** @returns {string} Table prefix of both sites. */
function prefix() {
  return process.env.LTIAM_DB_PREFIX || 'mdl_';
}

/**
 * Set a plugin setting on site B through Moodle's CLI (which also invalidates the config cache; a
 * plain SQL update would leave the cached value in place).
 *
 * @param {string} name Setting name of component local_ltiaccountmerge.
 * @param {string} value Value.
 * @returns {void}
 */
function setConfigB(name, value) {
  const cli = process.env.LTIAM_B_CLI
    || '. /home/claude/e2e/bin/e2e-env.sh; moodle_cli b admin/cli/cfg.php';
  execFileSync('bash', ['-c',
    `${cli} --component=local_ltiaccountmerge --name=${name} --set=${value}`], { stdio: 'pipe' });
}

/**
 * Format a Date as UTC "Y-m-d H:i:s", the wall-clock format of the policy form.
 *
 * @param {Date} date The instant.
 * @returns {string} Formatted text.
 */
function utcText(date) {
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Wait until `page` reaches a URL matching `re`; fail fast when the platform side shows an error.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {RegExp} re Expected URL.
 * @param {number} timeout Milliseconds.
 * @returns {Promise<void>}
 */
async function waitForUrl(page, re, timeout = 60000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    if (page.frames().some((f) => re.test(f.url()))) {
      return;
    }
    const alert = page.locator('.alert-danger:visible, .errorbox:visible').first();
    if (await alert.count().catch(() => 0)) {
      const text = (await alert.innerText().catch(() => '')).trim().replace(/\s+/g, ' ');
      if (text) {
        throw new Error(`Did not reach ${re}; error shown at ${page.url()}: ${text}`);
      }
    }
    await page.waitForTimeout(500);
  }
  throw new Error(`Timeout waiting for ${re}; now at ${page.url()}`);
}

module.exports = { SEED_PATH, loadSeed, getUser, login, sqlA, sqlB, prefix, setConfigB, utcText, waitForUrl };
