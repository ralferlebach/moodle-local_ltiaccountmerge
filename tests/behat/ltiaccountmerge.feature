@local @local_ltiaccountmerge
Feature: LTI account reconciliation administration
  In order to control how LTI users are provisioned and to help them when something goes wrong
  As an administrator
  I need pages to schedule provisioning policies, link identities, edit error texts and simulate merges

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | learner1 | Lena      | Learner  | learner1@example.com |
      | learner2 | Lars      | Learner  | learner2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |

  Scenario: The plugin pages are available in the site administration
    Given I log in as "admin"
    When I visit "/local/ltiaccountmerge/policies.php"
    Then I should see "Provisioning policies"
    And I should see "Add policy"

  Scenario: An administrator creates a scheduled policy and previews the effective mode
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/policy_edit.php"
    When I set the following fields to these values:
      | Name       | Exam week                |
      | Timezone   | UTC                      |
      | Recurrence | one-off                  |
      | Start (local time) | 2030-05-01 00:00:00      |
      | End (local time, exclusive) | 2030-05-08 00:00:00      |
    And I set the field "Mode for learner launches" to "Prompt: existing account only"
    And I press "Save changes"
    Then I should see "Policy saved."
    And I should see "Exam week"

  Scenario: An invalid window is refused with a clear message
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/policy_edit.php"
    When I set the following fields to these values:
      | Name       | Backwards                |
      | Timezone   | UTC                      |
      | Recurrence | one-off                  |
      | Start (local time) | 2030-05-08 00:00:00      |
      | End (local time, exclusive) | 2030-05-01 00:00:00      |
    And I set the field "Mode for learner launches" to "Automatic account creation only"
    And I press "Save changes"
    Then I should see "The end must be after the start."

  Scenario: The preview page rejects a malformed instant
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/preview.php"
    When I set the field "Instant (local time)" to "tomorrow-ish"
    And I press "Preview"
    Then I should see "Invalid date/time."

  Scenario: A capability granted to a normal user opens exactly that page
    Given I log in as "admin"
    And I set the following system permissions of "Authenticated user" role:
      | capability                           | permission |
      | local/ltiaccountmerge:viewaudit | Allow      |
    And I log out
    When I log in as "learner1"
    And I visit "/local/ltiaccountmerge/audit.php"
    Then I should see "Audit trail"

  Scenario: The error page shows a support ID and falls back for an unknown code
    Given I visit "/local/ltiaccountmerge/error.php?code=busy&sid=LTIAM-0123ABCD"
    Then I should see "Please try again"
    And I should see "LTIAM-0123ABCD"
    When I visit "/local/ltiaccountmerge/error.php?code=%3Cscript%3Ealert(1)%3C%2Fscript%3E&sid=not-a-support-id"
    Then I should see "The launch could not be completed"
    And I should not see "not-a-support-id"

  Scenario: An administrator overrides an error text but scripts are rejected
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/text_edit.php?code=busy"
    When I set the following fields to these values:
      | Title | Calm down                                 |
      | Text (limited HTML) | <p>Please wait <strong>a moment</strong>.</p> |
    And I press "Save changes"
    Then I should see "Text saved."
    When I visit "/local/ltiaccountmerge/error.php?code=busy"
    Then I should see "Calm down"
    And I should see "Please wait a moment."
    When I visit "/local/ltiaccountmerge/text_edit.php?code=busy"
    And I set the field "Text (limited HTML)" to "<p>hi</p><script>alert(1)</script>"
    And I press "Save changes"
    Then I should see "This text contains HTML that is not allowed"

  Scenario: The administrator link form refuses an unknown platform with a support ID
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/link.php"
    When I set the following fields to these values:
      | Issuer (platform URL) | https://unknown.example.org |
      | Platform user id (sub)        | abc-123                     |
      | Username of the existing account | learner1 |
    And I press "Continue"
    Then I should see "The platform is not known"

  Scenario: A merge simulation reports its verdict and changes nothing
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/simulate.php"
    When I set the following fields to these values:
      | Account A (would be merged away) | learner1 |
      | Account B (would remain)         | learner2 |
    And I press "Simulate"
    Then I should see "Result:"
    And I should see "Merge simulation"
    And I should see "Result:" in the ".local-ltiam-merge" "css_element"

  Scenario: The first-launch hint text is editable like any catalogue text and scripts are rejected
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/texts.php"
    Then I should see "Signing in from your learning platform"
    When I visit "/local/ltiaccountmerge/text_edit.php?code=firstloginhint"
    And I set the following fields to these values:
      | Title | Welcome from your platform                |
      | Text (limited HTML) | <p>Ask <strong>support</strong> to link your account.</p> |
    And I press "Save changes"
    Then I should see "Text saved."
    When I visit "/local/ltiaccountmerge/error.php?code=firstloginhint"
    Then I should see "Welcome from your platform"
    When I visit "/local/ltiaccountmerge/text_edit.php?code=firstloginhint"
    And I set the field "Text (limited HTML)" to "<p onclick=\"x()\">hi</p>"
    And I press "Save changes"
    Then I should see "This text contains HTML that is not allowed"

  Scenario: The new settings are on the settings page and the simulation says it does not merge
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=local_ltiaccountmerge_settings"
    Then I should see "Show a hint on the first-launch dialog and launch error pages"
    And I should see "Move LTI identity bindings after an account merge"
    When I visit "/local/ltiaccountmerge/simulate.php"
    Then I should see "It does not merge"
