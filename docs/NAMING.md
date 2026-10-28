# Naming: local_ltiaccountmerge

## Decision (owner, 2026-10-25): `local_ltiaccountmerge`

The owner renamed the plugin from `local_ltiaccountreconcile` to **`local_ltiaccountmerge`** (install path `local/ltiaccountmerge`), effective with release 0.2.0.
Names the outcome administrators look for (merging and linking accounts created by LTI launches) and is shorter.

* Checked against the 4.5 and 5.3 sources: valid Frankenstyle name (lower-case letters and digits, starts with a letter, no
  underscore in the name part); tables `local_ltiaccountmerge_policy` (28), `_applied` (29), `_audit` (27), `_request` (29), `_text` (26), `_merge` (27 characters). The table-name limit is `xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH(10) = 53`
  in both trees; the historical 28-character limit no longer applies to Moodle 4.5+ and is quoted below only as history.
* No upgrade path: Moodle cannot upgrade one component into another, so 0.2.0 is a fresh install; a 0.1.0 alpha site uninstalls
  `local_ltiaccountreconcile` first (see README, Installation, and CHANGELOG 0.2.0).
* Everything below this section is the earlier analysis (history), written while the name was provisional; its recommendation
  is superseded by this decision.

## History: earlier analysis

The name is **provisional**. This document lists the Moodle rules that constrain it, five alternatives with pros and
cons, and a recommendation.

## Rules checked (against the Moodle 4.5 and 5.3 sources)

* Frankenstyle plugin name: `<type>_<name>`, the name part lowercase letters and digits only, starting with a letter
  (`core_component::is_valid_plugin_name`). Underscores are not allowed in the name part.
* Database tables: `xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH` (the DB identifier limit minus the site's table
  prefix, at most 10 characters), so a table name may be up to 53 characters with the longest prefix. The older "28
  characters" rule is obsolete.
* `moodle-plugin-ci validate` (used by `ltirun ... static`) additionally requires **every table name to start with the full component
  name**. With the current name that gives `local_ltiaccountreconcile_request` (33 characters), which is allowed; a name
  longer than about 40 characters would leave no room for table suffixes.
* Plugin type fit: `auth_` plugins register an authentication method, `enrol_` plugins an enrolment method, `tool_`
  plugins are administrator tools (Server section), `local_` plugins are everything else without its own type.

## Alternatives

| # | Name | Characters | Pros | Cons |
| --- | --- | --- | --- | --- |
| 1 | `local_ltiaccountreconcile` (current) | 25 | Matches the specification and the folder names already in use; "reconcile" covers linking and a later merge (R4); unambiguous LTI scope; table names fit with the full prefix. | Long. Promises more than the MVP delivers (no merge). "Reconcile" is jargon. |
| 2 | `local_ltifirstlaunch` | 20 | Describes exactly what the MVP does: everything around the first launch of an external user (mode, link, errors). Shorter. | Does not cover a later merge of existing duplicate accounts; "first launch" is not obvious for the link and simulation pages. |
| 3 | `local_ltiprovisioning` | 21 | Short and clear for the scheduler, which is the most visible feature. | Hides linking, error pages and simulation; sounds like creating accounts, which core already does. |
| 4 | `local_ltiaccountlink` | 20 | Precise for the safest feature (add-only linking); short; no false promise of merging. | Does not cover scheduling or error texts; "link" could be read as hyperlink. |
| 5 | `tool_ltiaccountreconcile` | 24 | Admin pages naturally live under the Server administration of an admin tool; capabilities are administrator-centred. | The pages for account owners (code confirmation) and the public error page are not administration; `tool_` plugins are not expected to host hooks and user-facing pages; the folder moves to `admin/tool/`. |
| 6 | `auth_ltiaccountreconcile` | 24 | Sits next to `auth_lti`. | Wrong type: it registers no authentication method, and a disabled-by-default auth plugin would confuse administrators. Rejected. |

## Recommendation

Keep **`local_ltiaccountreconcile`** for 0.x. Reasons: the specification and the sibling documents already use it,
renaming after the first release costs every installation a migration of settings and tables, and the name stays
correct if the optional R4 (merge with per-plugin adapters) is ever built. The type `local` is the right one: the plugin
has user-facing pages, a hook callback, a scheduled task and admin pages, which no single specialised type fits.

If the project decides **before the first publication** that a merge will never be built, rename to
`local_ltifirstlaunch` (alternative 2): it then describes the product honestly, it is five characters shorter and the
table names stay valid under the full-component-prefix rule. Doing so requires changing the folder name, the component
in every file, the table names and the language file name; the test suite would find omissions (it checks that all lang
strings and table names resolve).
