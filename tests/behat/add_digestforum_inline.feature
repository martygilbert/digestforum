@mod @mod_digestforum @javascript
Feature: Add digestforum activities and discussions utilizing the inline add discussion form

  Background: Add a digestforum and a discussion attaching files
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | activity    | digestforum                  |
      | course      | C1                     |
      | idnumber    | 0001                   |
      | name        | Test digestforum name        |
      | type        | general                |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | 0001             |
      | course  | C1               |
      | user    | teacher1         |
      | name    | Forum post 1     |
      | message | This is the body |

  Scenario: Student can add a discussion via the inline form
    Given I am on the "Course 1" course page logged in as student1
    Then I add a new discussion to "Test digestforum name" digestforum inline with:
      | Subject | Post with attachment |
      | Message | This is the body     |
