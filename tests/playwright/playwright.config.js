// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Playwright configuration for the {{COMPONENT}} browser tests.
 *
 * @module     {{COMPONENT}}/playwright.config
 * @copyright  2026 {{AUTHOR}}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 60000,
  expect: { timeout: 10000 },
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL: process.env.FLEXACCESS_BASE_URL || 'http://localhost:8000',
    headless: true,
    // Captured on every run, not just on failure: a green run's trace documents the intended
    // journey end to end and its screenshots are usable as illustrations.
    screenshot: 'on',
    trace: 'on',
    // A recording of the failing run makes a diagnosis possible without reproducing it locally.
    video: 'on',
  },
  reporter: [['list'], ['html', { open: 'never' }]],
});
