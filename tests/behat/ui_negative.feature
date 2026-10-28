@local @local_ltiaccountmerge
Feature: Negative cases of the policy and merge pages
  In order to keep provisioning policies and account merges safe
  As an administrator
  I need invalid input, missing consent, forged requests and missing permissions to be refused without any change

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | learner1 | Lena      | Learner  | learner1@example.com |
      | learner2 | Lars      | Learner  | learner2@example.com |
      | manager1 | Mia       | Manager  | manager1@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |

  Scenario: A manual override without an expiry is refused
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/policy_edit.php?kind=override"
    When I set the following fields to these values:
      | Name                        | Emergency           |
      | Timezone                    | UTC                 |
      | Recurrence                  | one-off             |
      | Start (local time)          | 2030-05-01 00:00:00 |
    And I set the field "Mode for learner launches" to "Prompt: existing account only"
    And I press "Save changes"
    Then I should see "A manual override needs an expiry time (end)."
    And I visit "/local/ltiaccountmerge/policies.php"
    And I should not see "Emergency"

  Scenario: A manual override must be a one-off window
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/policy_edit.php?kind=override"
    When I set the following fields to these values:
      | Name                        | Daily override |
      | Timezone                    | UTC            |
      | Recurrence                  | daily          |
      | Start (local time)          | 08:00:00       |
      | End (local time, exclusive) | 09:00:00       |
    And I set the field "Mode for learner launches" to "Prompt: existing account only"
    And I press "Save changes"
    Then I should see "A manual override must be a one-off window."

  Scenario: A policy without any mode, a daily window without an end and an unknown resource are refused
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/policy_edit.php"
    When I set the following fields to these values:
      | Name                        | Nothing             |
      | Timezone                    | UTC                 |
      | Recurrence                  | one-off             |
      | Start (local time)          | 2030-05-01 00:00:00 |
      | End (local time, exclusive) | 2030-05-02 00:00:00 |
    And I press "Save changes"
    Then I should see "Set at least one mode (learner or instructor)."
    When I set the following fields to these values:
      | Recurrence                  | daily    |
      | Start (local time)          | 08:00:00 |
      | End (local time, exclusive) |          |
    And I set the field "Mode for learner launches" to "Automatic account creation only"
    And I press "Save changes"
    Then I should see "A daily window needs a start and an end time."
    When I set the following fields to these values:
      | Scope                       | Published resource |
      | Tool or course id           | 987654             |
      | End (local time, exclusive) | 09:00:00           |
    And I press "Save changes"
    Then I should see "The tool or course with this id does not exist."
    And I visit "/local/ltiaccountmerge/policies.php"
    And I should not see "Nothing"

  Scenario: Deleting a policy needs the confirmation with a valid session key
    Given I log in as "admin"
    And I visit "/local/ltiaccountmerge/policy_edit.php"
    And I set the following fields to these values:
      | Name                        | Keep me             |
      | Timezone                    | UTC                 |
      | Recurrence                  | one-off             |
      | Start (local time)          | 2030-05-01 00:00:00 |
      | End (local time, exclusive) | 2030-05-02 00:00:00 |
    And I set the field "Mode for learner launches" to "Prompt: existing account only"
    And I press "Save changes"
    And I should see "Policy saved."
    When I click on "Delete" "link" in the "Keep me" "table_row"
    Then I should see "Delete the policy \"Keep me\"?"
    And I press "Cancel"
    And I should see "Keep me"
    When I request the deletion of the policy "Keep me" with a forged session key
    Then I should see "Delete the policy \"Keep me\"?"
    And I visit "/local/ltiaccountmerge/policies.php"
    And I should see "Keep me"

  Scenario: A manager without the plugin's capabilities reaches none of its pages
    Given I log in as "manager1"
    Then I visit "/local/ltiaccountmerge/merge.php" and it is refused with "Access denied"
    And I visit "/local/ltiaccountmerge/policies.php" and it is refused with "Access denied"
    And I visit "/local/ltiaccountmerge/simulate.php" and it is refused with "Access denied"

  Scenario: The merge page refuses invalid pairs and offers no consent without a merge tool
    Given I log in as "admin"
    When I visit "/local/ltiaccountmerge/merge.php"
    Then I should see "Merge accounts"
    And I should see "Reduced function: no merge can run from here"
    When I set the following fields to these values:
      | Account A (would be merged away) | learner1 |
      | Account B (would remain)         | learner1 |
    And I press "Build preview"
    Then I should see "Choose two different accounts."
    When I set the following fields to these values:
      | Account A (would be merged away) | nobody   |
      | Account B (would remain)         | learner2 |
    And I press "Build preview"
    Then I should see "No account with this username."
    When I set the following fields to these values:
      | Account A (would be merged away) | learner1 |
      | Account B (would remain)         | learner2 |
    And I press "Build preview"
    Then I should see "Unavailable: no merge tool is installed."
    And I should not see "Consent and merge"
    And I should see "No merge has been started through this page yet."

  Scenario: A forged cancel request is refused
    Given I log in as "admin"
    Then I visit "/local/ltiaccountmerge/merge.php?job=1&action=cancel" and it is refused with "A required parameter (sesskey) was missing"
    And I visit "/local/ltiaccountmerge/merge.php?job=1&action=cancel&sesskey=forged" and it is refused with "Your session has most likely timed out"

  Scenario: The consent setting is on the settings page
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=local_ltiaccountmerge_settings"
    Then I should see "Require the reviewed workflow for LTI accounts"
