# Security notes: local_ltiaccountmerge

Report vulnerabilities privately to the maintainer (Ralf Erlebach) or through a private GitHub security advisory; do not
open a public issue with exploit details. This plugin is **ALPHA** and has not had an independent security review.

## Threat model

The plugin changes three things that matter for security: *which account a platform identity logs in as* (linking),
*which first-launch mode core applies* (policies) and, since 0.3.0, *whether two accounts are merged* (reviewed merge through
`tool_mergeusers`, irreversible). All are privileged. Everything else (error pages, simulation, audit) must
not leak identities or let an editor inject script.

## Controls implemented

| Area | Control | Evidence test |
| --- | --- | --- |
| Authorisation | Every page calls `admin_externalpage_setup()` and `require_capability()` in the system context; all capabilities are administrator-only except `linkown` (needs the setting `userlinking` on **and** a login as the target account). Capabilities carry risk bitmasks (`RISK_CONFIG`, `RISK_XSS`, `RISK_PERSONAL`). | `plugin_test::test_capabilities`, `test_default_role_permissions`, `link_service_test::test_requires_capability`, `link_request_test::test_create_requires_capability`, `test_confirm_requires_linkown_capability` |
| CSRF | Moodle forms carry the sesskey; the delete and simulation actions use `confirm_sesskey()` / `require_sesskey()`. | Review; not covered by an automated browser test (Behat not executed). |
| Linking, admin route | Explicit attestation checkbox and a reason are mandatory and audited. | `link_service_test::test_requires_attestation_and_reason` |
| Linking, owner route | The user must be logged in **as the target** (a "log in as" session does not count) and hold `linkown`; a one-time code of 8 characters from a look-alike-free alphabet; the code is stored only as `HMAC-SHA256(code, site identifier)`, compared with `hash_equals`; lifetime 15 minutes; 5 wrong attempts lock the request; a new request cancels an older pending one. The code mail contains no identity. | `link_request_test::*` (`test_wrong_codes_lock_the_request`, `test_expired_request`, `test_other_user_cannot_confirm`, `test_mail_leaks_no_identity`) |
| Identity checks | Issuer must be registered in `enrol_lti_app_registration` (no SSRF, nothing is fetched); issuer and sub are validated for length and characters. | `test_unknown_issuer_refused`, `test_bad_identity_refused` |
| Target checks | Site administrators, suspended, deleted, guest and `nologin` accounts are refused. A same name or e-mail never links. | `test_bad_targets_refused`, `test_same_name_and_email_do_not_link` |
| Integrity | Only `create_user_binding()` is used; an existing binding is never changed; a lock per identity plus a transaction with a post-condition (exactly one row) makes the write atomic; a competing writer rolls everything back; repeats are idempotent. | `test_race_competing_binding_rolls_back`, `test_repeat_is_idempotent`, `test_busy_lock_fails_closed`, `test_existing_binding_to_other_account_refused`, `test_target_taken_by_other_identity_of_same_issuer` |
| Error output | `error.php` accepts only a catalogue code (anything else becomes `launcherror`) and a support ID in the exact format `LTIAM-XXXXXXXX`; no raw input is echoed. | `error_catalogue_test::test_error_page_rendering`, `test_support_id` |
| Editable texts (XSS) | A tag and attribute allow-list that **rejects** instead of sanitising: scripts, event handlers, `javascript:` and data URLs, unknown elements, comments and CDATA are refused on save and re-checked on output, so a tampered row is not rendered. Editing needs the separate capability `managetexts`. | `error_catalogue_test::test_xss_rejected` (data provider), `test_safe_html_accepted`, `test_tampered_row_is_not_rendered` |
| Pre-launch hook | Acts only for two exact script paths, never during install or upgrade, off by default, swallows errors. The script path comes from the web server; the prefix pattern prevents `/../` and suffix tricks. | `hook_callbacks_test::test_is_launch_script` (data provider), `test_applies_only_on_launch_scripts`, `test_off_by_default`, `test_failure_is_swallowed` |
| Secrets and logs | No secret, token, code or raw LTI identifier is written to the audit trail or to logs; identities appear only as a 16-hex pseudonymous reference (truncated SHA-256). | `audit` unit use in `link_service_test`, `provider_test::test_export` |
| Merge simulation | Read-only; names the accounts only to users with `simulatemerge`; the event is logged. | `merge_simulator_test::test_simulation_is_read_only` |
| Merge executor | Runs only inside a `tool_mergeusers` merge (its `after_merged_all_tables` hook) and from the ad hoc task queued by its success event (no web entry point, no user input); a failure rolls back the whole merge; ids are cast to int; one transaction, one lock per pair; touches exactly one core column (`auth_lti_linked_login.userid`), never overwrites a conflicting binding, never rewrites the audit trail; journal and audit contain user ids and counters, no LTI identity. Trust assumption: the event comes from an installed `tool_mergeusers`; any code that can trigger that event can queue a reconciliation. | `merge_service_test::*`, `ar_gates_test::test_ar_g4_executor_is_event_driven_and_touches_one_core_table`, `mergeusers_callbacks_test::*` |
| Reviewed merge | Capability `merge` (`RISK_DATALOSS`, `RISK_PERSONAL`, `RISK_CONFIG`, no archetype) plus `tool/mergeusers:mergeusers` when the tool is installed; sesskey on every form and the cancel action; consent bound to the preview fingerprint, the typed username of the account to merge away and an explicit acceptance of conflicts; a site administrator can never be merged away; blocked previews cannot be consented; undeclared plugin tables block (no blind user-id update); execution only in an ad hoc task / CLI, one at a time, journalled before the transaction, marked inside it; crash recovery from the mark; every step audited (consent, refusal, execution, recovery, cancel) with ids and codes only. Merges of LTI accounts started outside the workflow are rolled back (`requireconsent`). | `merge_executor_test::*`, `merge_preview_test::test_blockers`, Behat `ui_negative.feature`, integration harness (real tool) |
| First-launch hint | Static catalogue text through the HTML allow-list; the support contact is escaped by Mustache; acts on three exact script names; errors are swallowed. | `hook_callbacks_test::test_hint_*` |
| Apply locking | Per-resource transaction with an optimistic re-check, short lock wait for launches, failure audit. | `mode_applier_test::*` (section 3.2 of DOCUMENTATION.md) |

## Known limits and residual risks

* **No rate limit per client.** The attempt counter is per request, not per IP or per user; a determined administrator
  could create requests repeatedly (each is audited).
* **Pre-launch hook cost and ordering.** It runs before the launch handler starts, with the permissions of the web request
  and before login. It performs no user-controlled input processing. Real multi-process contention is not proven by the
  local tests (see TESTING.md).
* **Linking changes who a person is on the site.** The administrator attestation is a trust decision; the plugin cannot
  verify it. The owner route proves control of the account, not of the platform identity: whoever the administrator
  enters as `sub` gets linked once the owner confirms.
* Behat (non-JavaScript) and one Playwright run exist (see ACCEPTANCE.md); no CI run, no accessibility or penetration test.
* **Direct write to a core table.** The merge executor updates `auth_lti_linked_login.userid` because core has no API. A
  future core schema change would break it; the setting `mergemovebindings` turns it off.
* **Excluding tables from `tool_mergeusers`.** The plugin removes `auth_lti_linked_login` (and its own audit and journal
  tables) from that tool's generic processing. An administrator can undo that with the tool's `excluded_exceptions`
  setting; the plugin then only reports the resulting double identities (`targetduplicates`), it does not repair them.
* No Content-Security-Policy is set by the plugin; the error page loads no external resources.
* **Trust in plugin declarations.** The preview trusts what a plugin declares for its tables in `add_settings_before_merging`
  (and what an administrator declares in `tool_mergeusers`' custom settings). It cannot verify that a declared handling is
  semantically right; it only refuses tables nobody declared.
* **Core and standard plugin tables** get `tool_mergeusers`' own handling (its defaults); the preview lists them but does not
  block them. Their correctness is `tool_mergeusers`' responsibility.
* **`requireconsent` can be switched off** by a site administrator; then `tool_mergeusers` merges LTI accounts without the
  preview (the 0.2.0 behaviour: the bindings are still reconciled inside the merge).
* **Crash recovery depends on session-bound locks.** With `$CFG->lock_factory` set to the database-record factory, the lock of
  a dead process expires only after its lifetime; the job then stays `running` until the lock expires.
