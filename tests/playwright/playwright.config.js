/**
 * Playwright configuration for the local_ltiaccountmerge browser tests.
 *
 * The suite needs the two-site LTI stack (platform A, tool B) described in README.md. Site URLs
 * come from the seed file, so no baseURL is configured.
 *
 * @module     local_ltiaccountmerge/playwright.config
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js/,
  timeout: 240000,
  expect: { timeout: 20000 },
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  use: {
    ignoreHTTPSErrors: false,
    launchOptions: { args: ['--proxy-bypass-list=*.platform-a.test;*.tool-b.test;localhost;127.0.0.1'] },
    viewport: { width: 1280, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
