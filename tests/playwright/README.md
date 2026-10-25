# {{COMPONENT}} — Playwright browser tests

Smoke-level end-to-end tests that run against a **live Moodle site** (they cannot run in the
static moodle-plugin-ci pipeline). They log in as an administrator and assert that the {{PLUGINTITLE}}
enrolment method is installed and rendered.

## Run locally

```bash
make playwright                     # from the plugin root; installs Playwright on first run
# or manually:
cd tests/playwright
npm install && npx playwright install --with-deps chromium
eval "$(php seed.php)"              # exports FLEXACCESS_BASE_URL
npm test
```

Override the site or credentials with `FLEXACCESS_BASE_URL`, `FLEXACCESS_ADMIN_USER`,
`FLEXACCESS_ADMIN_PASS`.

## Scope

This is intentionally a smoke test. The full deep-link → policy → temporary-user → enrolment →
persistence end-to-end scenarios are tracked as P0 access work and will be added here as those
flows are completed.
