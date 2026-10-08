@local @local_reportfeed
Feature: Getting started checklist
  In order to get a first report out
  As an administrator
  I need a page that lists the setup steps and what is still missing

  Scenario: The checklist shows every step with a live status
    Given I log in as "admin"
    When I visit "/local/reportfeed/start.php"
    Then I should see "Getting started" in the "region-main" "region"
    And I should see "1. Set up outgoing email in Moodle"
    And I should see "5. Give someone the HR feed capability"
    And I should see "9. Check the first real run"
    And I should see "To do"

  Scenario: Switching Reportfeed on turns its step to done
    Given I log in as "admin"
    And the following config values are set as admin:
      | enabled | 1 | local_reportfeed |
    When I visit "/local/reportfeed/start.php"
    Then I should see "Done" in the "4. Switch Reportfeed on and review the settings" "list_item"

  Scenario: The schedules page links to the checklist
    Given I log in as "admin"
    When I visit "/local/reportfeed/schedules.php"
    Then I should see "New here? Open the getting started checklist."
