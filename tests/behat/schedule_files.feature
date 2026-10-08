@local @local_reportfeed
Feature: Choosing the learner files of a schedule
  In order to send only what the receiving system needs
  As an administrator
  I need to pick which learner files a schedule contains

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | hrperson | Hanna     | Receiver | hrperson@example.com |
    And the following "roles" exist:
      | shortname | name       | archetype |
      | hrfeed    | HR receive |           |
    And the following "permission overrides" exist:
      | capability                       | permission | role   | contextlevel | reference |
      | local/reportfeed:receivehrfeed   | Allow      | hrfeed | System       |           |
    And the following "role assigns" exist:
      | user     | role   | contextlevel | reference |
      | hrperson | hrfeed | System       |           |
    And I log in as "admin"

  @javascript
  Scenario: A schedule with no file at all is refused
    When I visit "/local/reportfeed/schedule.php"
    And I set the field "Name" to "No files"
    And I set the field "Learners by course: one row per learner and course" to ""
    And I set the field "Learner summary: one row per learner" to ""
    And I press "Save changes"
    Then I should see "Choose at least one file, here or in the activity-level files."

  @javascript
  Scenario: The learner roster can be the only file
    When I visit "/local/reportfeed/schedule.php"
    And I set the field "Name" to "Roster only"
    And I set the field "Learners by course: one row per learner and course" to ""
    And I set the field "Learner summary: one row per learner" to ""
    And I set the field "Learner roster: who is a learner now, and what changed" to "1"
    And I set the field "Recipients" to "Hanna Receiver"
    And I press "Save changes"
    Then I should see "Roster only" in the "region-main" "region"
    And I should see "Learner roster" in the "Roster only" "table_row"
