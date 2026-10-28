# docs/ index

| Document | Content |
| --- | --- |
| [DOCUMENTATION.md](DOCUMENTATION.md) | Architecture, feasibility gates and their evidence, configuration, data model, non-goals. |
| [ACCEPTANCE.md](ACCEPTANCE.md) | One row per acceptance criterion with status class, evidence test, date and the tree/database combinations that ran. |
| [TESTING.md](TESTING.md) | How to run every test layer and the test matrix. |
| [SECURITY.md](SECURITY.md) | Threat model, controls with their evidence tests, known limits. |
| [PRIVACY.md](PRIVACY.md) | Stored personal data, retention, export and deletion. |
| [NAMING.md](NAMING.md) | Naming rules, alternatives and the recommendation. |
| [CHANGELOG.md](CHANGELOG.md) | Release notes. |
| `environment-setup.md` | Background on the container setup the plugins are developed in (written for another plugin of the same author; the commands name that plugin, the procedure applies unchanged). |
| `prompt-templates/`, `sessions/` | Inherited from the plugin template: prompts for working sessions and one record file per session. |

Status classes used in ACCEPTANCE.md: **code present**, **automated tests (local)**, **CI passed** (not executed),
**functionally accepted** (none), **not verified**. Only the first two are claimed for this release (0.2.0).
