moodle-local_ltiaccountmerge
================================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-local_ltiaccountmerge/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-local_ltiaccountmerge/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

A local plugin that helps a Moodle site acting as an **LTI 1.3 tool** (published resources of
`enrol_lti`, accounts created by `auth_lti`) to handle the first launch of external users: it switches the core
provisioning mode on a schedule, lets an administrator (or the account owner) link an LTI identity to an existing
account outside the core dialog, serves translatable and safe error pages with a support ID, and runs **reviewed account
merges** of LTI accounts through `tool_mergeusers`: preview (dry run), explicit consent, crash-recovery journal.

**Status: ALPHA (0.3.0, version 2026102700).** Read [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) for what is proven and
what is not. In particular: **linking** (adding an LTI identity to an existing account) and **merging** (two Moodle
accounts become one) are strictly separate functions. The plugin never changes user ids itself: a merge is executed by
`tool_mergeusers` in one transaction; this plugin decides whether it may run (preview, no blind user-id update), records
the consent and the outcome, handles the LTI bindings inside the merge and recovers interrupted merges. Without
`tool_mergeusers` there is no merge (reduced function: read-only simulation). The plugin cannot replace the core
first-login dialog (no extension point); it can add a notice above it. GitHub CI has not been executed by us; nothing is
"functionally accepted" yet.


Requirements
------------

This plugin requires Moodle 4.5+ (`2024100700`) and is tested against Moodle 4.5 and 5.3 (PHP 8.1+), on PostgreSQL and
MariaDB. It needs the core plugins `auth_lti` and `enrol_lti` to be useful, but declares no dependency on any other
plugin of its family (see "Plugin family").

Optional: `tool_mergeusers` (version 2026081302 or later, with the hooks `add_settings_before_merging` and
`after_merged_all_tables`) for the merge workflow. It is not a declared dependency: without it the merge page says so and
offers no merge.


Motivation for this plugin
--------------------------

Core Moodle offers three first-launch modes for users coming from an LTI 1.3 platform: create the account automatically,
ask "new or existing account", or ask "existing account only". The mode is a property of each published resource, it
cannot change over time, the dialog cannot be replaced by a plugin, errors are terse and an identity once bound
cannot be added afterwards by an administrator. This plugin fills those gaps **without forking core** and states
clearly where core gives no extension point (the "feasibility gates" in [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md)).


Installation
------------

Install the plugin like any other plugin to folder

    /local/ltiaccountmerge

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.

After installation the plugin changes nothing: no policy exists, the pre-launch step and the account-owner linking
are switched off. The setting "Require the reviewed workflow for LTI accounts" is on: `tool_mergeusers` (if installed)
can then merge accounts with an LTI identity only through this plugin's merge page or CLI.

**Upgrade from 0.2.0 (version 2026102502):** a normal Moodle upgrade. The step `2026102700` creates the table
`local_ltiaccountmerge_job` and sets `requireconsent` (on) if it is not set yet. Nothing else changes.


Migration and rename plan
-------------------------

| Source | What it is | Path to this release |
| --- | --- | --- |
| GitHub `development`/`main` of this repository, commit `d5b40e1` | Uninitialised plugin template (`{{COMPONENT}}`, never installable) | Replace the repository content with this release. Delete the template-only files listed in `db/removed_files.txt` (the stale-files CI job fails while one of them exists): `init_plugin.py`, `classes/local/README.md`, `lang/de|en/{{PLUGINNAME}}.php`, `tests/behat/{{PLUGINNAME}}.feature`, `tests/load/seed_large.php`, `tests/load/{{PLUGINNAME}}-*`, `tests/playwright/helpers.js`, `tests/playwright/package-lock.json`, `tests/playwright/seed.php`. The template workflows `load-jmeter.yml`, `load-k6.yml` and `playwright.yml` belong to the CI owner (see docs/TESTING.md); they reference template paths. |
| `local_ltiaccountreconcile` 0.1.0 alpha (former component name, ZIP of 2026-10-24) | A different Moodle component | **Fresh install, nothing migrated.** Moodle cannot upgrade one component into another. On a site that runs it: uninstall it (Site administration -> Plugins -> Plugins overview -> Uninstall, or `php admin/cli/uninstall_plugins.php --plugins=local_ltiaccountreconcile --run`). Its uninstall hook writes the provisioning-mode baselines back to the published resources; its policies, texts, link requests, journal and audit trail are deleted. Remove its directory, then install this plugin. Re-create policies and texts by hand if needed. |
| `local_ltiaccountmerge` 0.2.0 (2026102502) | Previous release of this component | Normal upgrade (step `2026102700`). |

The component name `local_ltiaccountmerge` is final (owner decision 2026-10-25, see docs/NAMING.md).


Branch policy
-------------

* `development` is the work branch: every change, patch, refactoring and test addition lands there first, and all
  acceptance evidence refers to the exact `development` commit and its CI run.
* `main` is the release branch. It is updated only by a deliberate promotion pull request from `development` after the
  functional acceptance and a green `development` CI; the full release CI then runs again on the exact `main` commit.
* No direct work on `main`. Releases are tagged on `main`. Each plugin of the family has its own semantic version (no
  lock-step).


Usage & Settings
----------------

The plugin adds the category **Site administration -> Plugins -> Local plugins -> LTI Account Merge** with:

* **Provisioning policies** - scheduled windows (site, one resource or one course; timezone aware; one-off or daily;
  priority) and manual overrides with a mandatory expiry. A policy sets the learner and/or the instructor mode.
* **Preview effective mode** - shows the mode every published resource has at any instant you type in.
* **Link LTI identity** - administrator attestation or a one-time code mailed to the account owner.
* **Error texts** - per-language overrides of the error pages (a small HTML allow-list; scripts are rejected).
* **Merge accounts** - the reviewed merge workflow (needs `tool_mergeusers`): build the preview, read the categories
  (grades, attempts, completion, enrolments, groups, LTI bindings, pseudonyms, completion sync) and every affected table
  with its handling, then consent (type the username of the account to be merged away, confirm irreversibility, accept
  listed conflicts). The merge runs in the background; the page shows the job and its outcome. CLI:
  `php local/ltiaccountmerge/cli/merge.php --help`.
* **Audit trail** and **Merge simulation** (read-only, tool-independent inventory, changes nothing).

Settings (Site administration -> Plugins -> Local plugins -> LTI Account Merge -> Settings):

* **Apply the mode just before a launch (prelaunchapply)** - off by default. Registers the effect described under
  "How this plugin works".
* **Let account owners link their own identity (userlinking)** - off by default.
* **First-launch hint (firsthint)** - off by default. Adds the text of the catalogue entry `firstloginhint` above the
  core account-binding dialog and the LTI launch error pages. It does not replace or alter the core dialog.
* **Move LTI bindings after a merge (mergemovebindings)** - on by default. See "After an account merge" below.
* **Require the reviewed workflow for LTI accounts (requireconsent)** - on by default. `tool_mergeusers` merges of
  accounts that hold an LTI identity (or were created by an LTI launch) that were not consented through this plugin are
  rolled back with an explanation. Other merges are not affected.
* **Audit retention in days (auditretention)** - 0 keeps the audit trail forever.
* **Brand name**, **Support contact** and **Logo** shown on the plugin's own pages.

If you want to learn more about using local plugins in Moodle, please see https://docs.moodle.org/en/Local_plugins.


Capabilities
------------

This plugin introduces these capabilities. None is granted to a standard role except `linkown`; site administrators
hold all of them implicitly.

* **local/ltiaccountmerge:managepolicy** - create, change, delete policies and use the preview.
* **local/ltiaccountmerge:managetexts** - edit the error texts (risk: XSS, hence a separate capability).
* **local/ltiaccountmerge:link** - link an identity as an administrator who attests the ownership.
* **local/ltiaccountmerge:linkown** - confirm a one-time code for one's own account (default: authenticated user;
  effective only when the setting `userlinking` is on).
* **local/ltiaccountmerge:viewaudit** - see the audit trail.
* **local/ltiaccountmerge:simulatemerge** - run the read-only merge simulation.
* **local/ltiaccountmerge:merge** - preview, consent to and cancel real merges (risk: data loss, personal data,
  configuration). With `tool_mergeusers` installed, `tool/mergeusers:mergeusers` is required as well.


Scheduled Tasks
---------------

* **\local_ltiaccountmerge\task\apply_modes** - every minute: writes the effective mode into the published
  resources and restores the original value when no policy applies. Enabled by default; it does nothing while no
  policy exists.
* **\local_ltiaccountmerge\task\reconcile_merge** (ad hoc) - queued by the observer when `tool_mergeusers` reports a
  finished merge; reconciles this plugin's state for that pair (retried while the pair is locked).
* **\local_ltiaccountmerge\task\cleanup** - daily at 03:17: expires pending link requests (clearing the stored
  identity), deletes finished requests after 30 days and applies the audit retention.
* **\local_ltiaccountmerge\task\recover_merges** - every 5 minutes: resolves merge jobs whose process died (committed ->
  merged, rolled back -> aborted, the unfinished `tool_mergeusers` log entry is corrected) and expires consents older
  than one day.
* **\local_ltiaccountmerge\task\execute_merge** (ad hoc, one at a time) - executes a consented merge job.


How this plugin works
---------------------

* **Policies are live, not copied.** The mode for any instant is computed by a pure function from the stored policies
  (override > priority > specificity course > resource > site > id). Half-open windows (`start <= t < end`); wall-clock
  times are interpreted in the policy's timezone, so daylight saving time is handled (a time in a gap moves forward, an
  ambiguous time means its first occurrence).
* **Applying it to core.** `enrol/lti/launch.php` reads `provisioningmodelearner` / `provisioningmodeinstructor` from
  `enrol_lti_tools` with no hook (gate AR-G2). The only official way to change them is
  `enrol_lti_plugin::update_instance()`, which the minute task uses. The original value is remembered and restored.
  With a launch at the exact boundary second the task can be up to 59 s late; the optional **pre-launch apply** step
  (an `after_config` hook callback that acts only for `/enrol/lti/launch.php` and `/enrol/lti/launch_deeplink.php`)
  closes that gap. It has a cost: a lock and a few queries before each launch, and `launch_deeplink.php` does not read
  the mode at all, so that script merely keeps the stored value current.
* **Bindings are never changed.** Linking only *adds* a row through `auth_plugin_lti::create_user_binding()`. Core has
  no API to remove or move a binding (AR-G3), so the plugin offers none. A change of policy never rebinds anyone.
* **Link versus merge.** Linking adds an LTI identity to an existing account (`auth_plugin_lti::create_user_binding()`,
  link page or one-time code). Merging makes two accounts one; it is always done by `tool_mergeusers`.
* **Reviewed merge (since 0.3.0).** The preview reproduces `tool_mergeusers`' own table selection (its defaults, every
  plugin's hook settings, the administrator's custom settings) and blocks a merge that would update user ids of a plugin
  table nobody declared (no blind user-id update), a unique key without a declared compound index, or a site
  administrator as the account to merge away. Consent is bound to the preview's fingerprint; a change before the run
  ends the job as "stale" without any change. The job is journalled before the merge transaction; inside it the plugin's
  callback marks the job, so after a crash the recovery task knows whether the merge was committed. One merge runs at a
  time; at most one open job per account.
* **After an account merge.** Inside the merge transaction (`after_merged_all_tables`) the LTI bindings of the
  merged-away account move to the survivor (core has no API for that, so one column of `auth_lti_linked_login` is updated
  directly; an identity is never moved onto an account that already has another identity of the same platform - it stays
  on the merged-away account and is reported), pending link requests are settled, "modified by" references follow the
  survivor and the audit trail is never rewritten. A failure of that step rolls back the whole merge. The success event
  queues an idempotent fallback task. `tool_mergeusers` is not installed in the shared test trees: unit tests use
  stand-ins of its hook classes; the real plugin runs in `tests/integration` on scratch databases together with the
  sibling plugins (results in docs/TESTING.md).
* **Core dialog.** The "new or existing account" dialog of `auth_lti` can only be replaced by a theme (AR-G1). This
  plugin offers its own link page instead and does not claim to replace the core screen. The optional hint is a notice
  added through the output hook `before_standard_top_of_body_html_generation`; it is not a replacement.

Details, evidence and limits: [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md), [docs/SECURITY.md](docs/SECURITY.md),
[docs/PRIVACY.md](docs/PRIVACY.md), [docs/TESTING.md](docs/TESTING.md), [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md),
[docs/NAMING.md](docs/NAMING.md).


Plugin family
-------------

This plugin belongs to a family of LTI helpers for Moodle. **Every member is independently installable: none requires
another, none calls another's code.** Where members cooperate, they only do so through standard Moodle events or
read-only services, and each works fine when the others are absent.

| Plugin | Purpose |
| --- | --- |
| `local_ltiidentity` | Lets LTI users (or their teachers) store a real name and optionally an e-mail address in their existing user record, so that launches do not leave placeholders behind. |
| `ltisource_ltipseudonym` | Platform side: opaque, scoped pseudonyms instead of internal user ids as the LTI `sub`, with minimal disclosure towards external tools. |
| `local_ltiaccountmerge` (this plugin) | Scheduled provisioning policies, identity linking, error pages, reviewed account merge with `tool_mergeusers`. |
| `local_ltixsembed` | Using an LTI tool (for example another Moodle) inside an iframe without relying on unpartitioned third-party cookies. |
| `tool_ltidiagnostics` | Diagnostics and data-exchange audit for LTI connections. |
| `tool_ltigroupautoenrol` (existing, separate) | Automatic group enrolment for LTI users. |

The names of the first five are provisional (see each plugin's `docs/NAMING.md`).


Theme support
-------------

This plugin acts mostly behind the scenes and renders its own pages with Moodle's standard templates, so it should work
with all Moodle themes. It is developed and tested on Moodle Core's Boost theme.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-local_ltiaccountmerge


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-local_ltiaccountmerge/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide
per-case support. When an error page shows a **Support ID** (`LTIAM-XXXXXXXX`), please quote it: it identifies the
audit entry without exposing any personal data.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and
published as-is to the community. Please issue feature proposals and pull requests on Github:
https://github.com/ralferlebach/moodle-local_ltiaccountmerge/issues
https://github.com/ralferlebach/moodle-local_ltiaccountmerge/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept
that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

The plugin declares support for Moodle 4.5 to 5.3 (`$plugin->supported = [405, 503]`). Our local runs cover 4.5 and 5.3
(PostgreSQL and MariaDB); the full 4.5-5.3 matrix is run by the release lead (see [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md)).
The core files this plugin relies on (`auth/lti/login.php`, `auth_lti\output\renderer`, `enrol/lti/launch.php`, the hook
classes) are byte-identical in 4.5 to 5.2; 5.3 differs only in dependency-injection calls (docs/DOCUMENTATION.md,
section 3.5). If you are using a legacy version of Moodle, we advise against installing this plugin.


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be
managed through AMOS (https://lang.moodle.org). As the plugin creator, we continue to maintain the German translation.


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.


Maintainers
-----------

The plugin is maintained by\
Ralf Erlebach


Copyright
---------

The copyright of this plugin is held by\
Ralf Erlebach

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
