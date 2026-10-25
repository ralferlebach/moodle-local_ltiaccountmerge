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
 * Concurrency (write) gate: many simultaneous requests compete for the LAST remaining seat of a
 * capacity-limited {{PLUGINTITLE}} instance.
 *
 * This is the complement to the PHPUnit concurrency tests. Those can prove that the capacity
 * boundary is exact and that the lock is not leaked, but they cannot prove mutual exclusion:
 * PostgreSQL advisory locks are re-entrant within one database session, so a single PHP process can
 * always re-enter its own critical section. Only genuinely parallel requests - separate sessions -
 * exercise the lock, which is what this scenario produces.
 *
 * The gate is the plugin's own behaviour, not just latency: the entry point must hand out the
 * remaining seats exactly once each. Over-granting shows up as more "enrolled" outcomes than there
 * were free seats.
 *
 * Prerequisites: seed a course whose {{PLUGINTITLE}} instance has maxparticipants set and is filled to
 * FREE_SEATS below its cap (see tests/load/seed_large.php for the seeding pattern).
 *
 * Environment: BASE_URL, COURSEID, optional FREE_SEATS (default 1), VUS (default 30).
 *
 * Run: k6 run -e BASE_URL=... -e COURSEID=... tests/load/{{PLUGINNAME}}-capacity-race.js
 */

import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL;
const COURSEID = __ENV.COURSEID;
const FREE_SEATS = Number(__ENV.FREE_SEATS || 1);
const VUS = Number(__ENV.VUS || 30);

const enrolled = new Counter('{{PLUGINNAME}}_enrolled');
const refused = new Counter('{{PLUGINNAME}}_refused');

export const options = {
  scenarios: {
    // All virtual users start at once and fire a single write each: the sharpest possible race.
    stampede: {
      executor: 'shared-iterations',
      vus: VUS,
      iterations: VUS,
      maxDuration: '60s',
    },
  },
  thresholds: {
    // The decisive gate: never hand out more seats than exist.
    {{PLUGINNAME}}_enrolled: [`count<=${FREE_SEATS}`],
    // Losing the race must be a clean refusal, not a 500.
    http_req_failed: ['rate<0.01'],
  },
};

/**
 * Fail fast on a missing or unreachable target, so a broken environment is reported as such
 * instead of surfacing as a wall of failed requests.
 *
 * @returns {void}
 */
export function setup() {
  if (!BASE_URL || !COURSEID) {
    throw new Error('BASE_URL und COURSEID muessen gesetzt sein (-e BASE_URL=... -e COURSEID=...).');
  }
  const probe = http.get(`${BASE_URL}/login/index.php`);
  if (probe.status !== 200) {
    throw new Error(`Ziel nicht erreichbar: ${BASE_URL} lieferte HTTP ${probe.status}.`);
  }
}

export default function () {
  // Step 1: GET the entry page to establish a session and read the sesskey.
  const entry = http.get(`${BASE_URL}/{{PLUGINPATH}}/access.php?courseid=${COURSEID}`, {
    tags: { endpoint: 'access-get' },
  });
  check(entry, { 'entry page returns 200': (r) => r.status === 200 });

  const match = entry.body.match(/sesskey=([A-Za-z0-9]{6,})/);
  if (!match) {
    // Without a sesskey the write cannot be attempted; count it as a failure rather than
    // silently passing, which is exactly the false-positive the JMeter plan used to have.
    check(null, { 'sesskey was extracted': () => false });
    return;
  }

  // Step 2: POST the confirmation. access.php only creates the account on POST with a valid
  // sesskey, so this - and not a GET with query parameters - is the real write path.
  const post = http.post(
    `${BASE_URL}/{{PLUGINPATH}}/access.php?courseid=${COURSEID}`,
    { confirm: '1', sesskey: match[1] },
    {
      tags: { endpoint: 'access-post' },
      // Do not follow the redirect: it is what distinguishes the two outcomes.
      redirects: 0,
      responseCallback: http.expectedStatuses({ min: 200, max: 399 }),
    }
  );

  check(post, { 'write path did not error': (r) => r.status < 500 });

  // Classify by HTTP status, not by page text. A granted seat redirects into the course; a refused
  // one re-renders the entry page. Matching words like "full" or "capacity" missed the real
  // message ("The maximum number of participants has been reached.") and, with redirects followed,
  // both outcomes ended up as 200 - so refusals could be counted as grants.
  if (post.status >= 300 && post.status < 400) {
    enrolled.add(1);
  } else {
    refused.add(1);
  }
}
