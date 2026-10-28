# Privacy: local_ltiaccountmerge

The plugin implements the Moodle Privacy API (`\local_ltiaccountmerge\privacy\provider`: metadata, plugin and userlist
provider). It stores as little personal data as it can.

## What is stored

| Table | Personal data | Purpose | Retention |
| --- | --- | --- | --- |
| `local_ltiaccountmerge_audit` | Actor user id, affected user id, action, outcome, support ID, a JSON detail without secrets (an identity appears only as a 16-hex pseudonymous reference) | Accountability for linking, policy and simulation actions; support | Until deleted, or older than the setting `auditretention` days (0 = keep). |
| `local_ltiaccountmerge_request` | Target user id, requesting administrator id, hashed identity; the readable issuer and sub **only while a request is pending**; HMAC of the one-time code | Challenge-code linking | Issuer and sub are cleared when the request finishes or expires; finished rows are deleted after 30 days. |
| `local_ltiaccountmerge_policy`, `local_ltiaccountmerge_text` | The id of the user who last modified the record | Who changed a policy or text | Until the user is deleted (the id is then reset to 0). |
| `local_ltiaccountmerge_merge` | Source and surviving user id of a reconciled merge, status, attempts, counters | Idempotence, retry and reporting of the merge executor | Until one of the users is deleted through the privacy API (row removed); no LTI identity is stored. |
| `local_ltiaccountmerge_job` | Source and kept user id of a merge of the reviewed workflow, the consenting administrator's id, status, times; the preview summary holds table names and counters only | Consent record, crash recovery and report of real merges | Until one of the users is deleted through the privacy API (row removed; the consenting administrator is detached). |
| `local_ltiaccountmerge_applied` | None | Baseline of a provisioning mode | Removed when the baseline is restored. |
| Files (`brandlogo`) | None (a site-level logo) | Branding | Until replaced. |

The plugin copies no profile data (name, e-mail) of users. The e-mail with the one-time code goes to the address that is
already in the account; the code mail contains no LTI identity.

## Data subject requests

* **Export** (`export_user_data`): the user's audit rows (as actor or as affected user), requests (as target or requester),
  reconciliation-journal rows, merge jobs (merged away, kept, or consented by the user) and the names/codes of policies
  and texts they last modified, under the system context.
* **Deletion of one user** (`delete_data_for_user`, `delete_data_for_users`): requests, merge-journal rows and merge jobs of the
  user are removed (a job the user consented to is kept with the consenting id reset to 0); the audit
  trail is kept but detached (actor and affected user reset to 0, detail cleared where it concerned the user) so the
  accountability record survives without identifying the person; `usermodified` of policies and texts is reset to 0.
* **Deletion for a whole context** (`delete_data_for_all_users_in_context`, system context): requests are removed and the
  audit trail is detached from all people.
* Core's `user_delete` leaves no dangling reference in the requests table
  (`provider_test::test_delete_user_core_api_leaves_no_dangling_reference_in_requests`).

Evidence: `tests/privacy/provider_test.php` (metadata, contexts, user list, export, delete for user, delete for users and
context, `test_merge_jobs`).

## Not covered

* The core table `auth_lti_linked_login` is owned by `auth_lti`; its privacy handling is core's.
* Data of other plugins is not processed by this plugin. A real merge is executed by `tool_mergeusers` (its own privacy
  provider and log); this plugin moves one core binding column inside that merge and its own rows only; the audit trail keeps the original actor and affected user ids (append-only) and is detached from a person only
  by a deletion request.
* The legal basis, a record of processing activities and retention periods for the audit trail are decisions of the
  operating institution; the plugin provides the retention setting but has no default.
