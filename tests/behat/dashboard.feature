@local @local_reportfeed
Feature: The Reportfeed dashboard
  In order to find and set up Reportfeed without hunting through menus
  As an administrator
  I need one home page with the status, the next step and the main buttons

  Scenario: The dashboard shows the status and the next step
    Given I log in as "admin"
    When I visit "/local/reportfeed/index.php"
    Then I should see "Reportfeed is OFF: nothing is sent."
    And I should see "setup steps done"
    And I should see "Next step:"
    And I should see "Add a schedule"
    And I should see "No schedules yet."
    And I should see "Nothing has run yet."

  Scenario: The administrator can switch Reportfeed on from the dashboard
    Given I log in as "admin"
    And I visit "/local/reportfeed/index.php"
    When I press "Turn on"
    Then I should see "Reportfeed is switched on."
    And I should see "Reportfeed is ON."
    And I should see "Turn off"

  Scenario: Reportfeed has its own section under Reports
    Given I log in as "admin"
    When I navigate to "Reports > Reportfeed > Dashboard" in site administration
    Then I should see "Reportfeed is" in the "region-main" "region"
    And I should see "Schedules" in the ".nav-pills" "css_element"

  Scenario: A schedule shows how many people it sends to
    Given I log in as "admin"
    And the following config values are set as admin:
      | allowexternal | 1 | local_reportfeed |
    And I visit "/local/reportfeed/schedule.php"
    And I set the field "Name" to "Outside only"
    And I set the field "Other email addresses (not Moodle users)" to "someone@example.com"
    And I press "Save changes"
    When I visit "/local/reportfeed/index.php"
    Then I should see "Outside only"
    And I should see "1 person(s)"

  Scenario: Send test to me says which address it went to
    Given I log in as "admin"
    And the following config values are set as admin:
      | allowexternal | 1 | local_reportfeed |
    And I visit "/local/reportfeed/schedule.php"
    And I set the field "Name" to "Try me"
    And I set the field "Other email addresses (not Moodle users)" to "someone@example.com"
    And I press "Save changes"
    When I visit "/local/reportfeed/schedules.php"
    And I press "Send test to me"
    Then I should see "were handed to Moodle mail for moodle@example.com"
    And I should see "look in the spam folder"

  Scenario: Send test to all recipients asks first and names every address
    Given I log in as "admin"
    And the following config values are set as admin:
      | allowexternal | 1 | local_reportfeed |
    And I visit "/local/reportfeed/schedule.php"
    And I set the field "Name" to "Everyone"
    And I set the field "Other email addresses (not Moodle users)" to "someone@example.com"
    And I press "Save changes"
    When I visit "/local/reportfeed/schedules.php"
    And I press "Send test to all recipients"
    Then I should see "someone@example.com"
    And I should see "Learner data leaves Moodle"
    And I press "Continue"
    And I should see "were handed to Moodle mail for: someone@example.com"
