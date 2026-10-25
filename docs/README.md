moodle-plugintemplate
=====================

A starting point for new Moodle plugins: the plugin skeleton plus the complete quality and test
infrastructure that a plugin needs to be reviewable and releasable.

It is not a scaffolding generator. Everything here has been used in production on a four-plugin
Moodle project and each piece exists because something went wrong without it - the comments in the
files say what.


What you get
------------

**Plugin skeleton** - `version.php`, `db/upgrade.php`, `db/access.php`, English and German language
files, a null privacy provider and a first Behat scenario. Enough to install and pass review.

**Quality gates** (`makefile`, `phpcs.xml`, `tools/`)

* `make check` - PHPCS Moodle standard, PHPDoc checker, Mustache, ESLint, PHPMD, PHPCPD
* `make fix` - automatic style and PHPDoc fixes

**CI pipelines** (`.github/workflows/`)

* `moodle-plugin-ci-dev.yml` - fast feedback on the development branch
* `moodle-plugin-ci-main.yml` - full matrix across supported Moodle versions, PostgreSQL and
  MariaDB, plus release gates: version lockstep, release artefact, coverage floor, stale files and
  an aggregating release gate
* `playwright.yml` - browser journeys and accessibility checks with axe
* `load-k6.yml`, `load-jmeter.yml` - read load and a concurrency race

**Test infrastructure**

* `tests/coverage.php` - coverage scope, and `tools/coverage_gate.php` to enforce a floor
* `tests/playwright/` - configuration, shared helpers and a seed script
* `tests/load/` - k6 plans and a JMeter plan
* `tests/behat/` - a first scenario


Getting started
---------------

    git clone https://github.com/{{GITHUBUSER}}/moodle-plugintemplate.git moodle-enrol_attendancegate
    cd moodle-enrol_attendancegate
    rm -rf .git && git init

    python3 init_plugin.py --type enrol --name attendancegate \
        --title "Attendance gate" --author "Jane Doe" --github janedoe

The script replaces every placeholder in file contents and file names, checks that none is left
over and then deletes itself. Supported types: auth, block, enrol, local, mod, qtype, report, theme
and tool - the install path is derived from the type.

Afterwards:

1. Add `db/install.xml` if the plugin needs its own tables.
2. Run `make check`.
3. Mirror the plugin into a Moodle tree and run the tests there - several coding-standard sniffs
   only fire when the plugin sits inside a Moodle tree.


What the comments in this template are about
--------------------------------------------

Each of these cost a red pipeline at least once:

* **Check inside a Moodle tree.** `moodle.Files.LangFilesOrdering` and
  `moodle.PHPUnit.TestCaseCovers` stay silent when a standalone directory is checked. "Green
  locally" then means nothing.
* **Use the CI's tool version.** An older local `moodle-cs` accepts what the CI rejects.
* **No `DEFAULT=""` on `CHAR NOT NULL`.** XMLDB emits a debugging message, and moodle-plugin-ci
  treats any debugging message during PHPUnit initialisation as a failure.
* **Dependencies are minimum versions.** An exact pin breaks installation as soon as a sibling
  plugin lags by one release, which is unavoidable while repositories are pushed one after another.
* **Bind the test server to `127.0.0.1`.** `localhost` may resolve to `::1`, where PHP's built-in
  server is not listening - the client then reports HTTP 0.
* **Start the server in the step that uses it.** A background server does not reliably survive the
  step that started it.
* **`if: always()` on aggregating jobs.** A skipped job is never re-run by "re-run failed jobs" and
  keeps the pipeline red for good.
* **Ship `db/upgrade.php` from day one.** Moodle installs without it; the plugins directory rejects
  the upload.
* **`db/removed_files.txt`.** A plugin package never deletes anything, so a file removed upstream
  survives in a repository updated in place. The `stale-files` job makes that visible.
* **Ask capabilities in the context the action belongs to.** A course-level decision asked in the
  system context ignores role switching, because Moodle suspends the administrator bypass only for
  the context being switched.


Requirements
------------

Moodle 4.5+, PHP 8.1+, Node.js for the browser tests, and `moodle-plugin-ci` version 4 for the
local checks.


Licence
-------

GNU GPL v3 or later, matching Moodle itself.
