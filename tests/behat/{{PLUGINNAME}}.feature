@{{PLUGINTYPE}} @{{COMPONENT}}
Feature: {{PLUGINTITLE}}
  In order to verify the plugin works
  As an administrator
  I need the plugin to be installed and reachable

  Scenario: The plugin is installed
    Given I log in as "admin"
    And I navigate to "Plugins > Plugins overview" in site administration
    Then I should see "{{PLUGINTITLE}}"
