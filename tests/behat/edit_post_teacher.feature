@mod @mod_digestforum
Feature: Teachers can edit or delete any digestforum post
  In order to refine the digestforum contents
  As a teacher
  I need to edit or delete any user's digestforum posts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity   | name              | course | idnumber |
      | digestforum      | Test digestforum name   | C1     | digestforum1   |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | course | user     | name                 | message              |
      | digestforum1 | C1     | teacher1 | Teacher post subject | Teacher post message |
    And the following "mod_digestforum > posts" exist:
      | user     | parentsubject        | subject              | message              |
      | student1 | Teacher post subject | Student post subject | Student post message |

  Scenario: A teacher can delete another user's posts
    When I am on the "Test digestforum name" "digestforum activity" page logged in as teacher1
    And I follow "Teacher post subject"
    And I click on "Delete" "link" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' digestforumpost ')][contains(., 'Student post subject')]" "xpath_element"
    And I press "Continue"
    Then I should not see "Student post subject"
    And I should not see "Student post message"

  Scenario: A teacher can edit another user's posts
    When I am on the "Test digestforum name" "digestforum activity" page logged in as teacher1
    And I follow "Teacher post subject"
    And I click on "Edit" "link" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' digestforumpost ')][contains(., 'Student post subject')]" "xpath_element"
    And I set the following fields to these values:
      | Subject | Edited student subject |
    And I press "Save changes"
    And I wait to be redirected
    Then I should see "Edited student subject"
    And I should see "Edited by Teacher 1 - original submission"

  Scenario: A student can't edit or delete another user's posts
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I follow "Teacher post subject"
    Then I should not see "Edit" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' digestforumpost ')][contains(., 'Teacher post subject')]" "xpath_element"
    And I should not see "Delete" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' digestforumpost ')][contains(., 'Teacher post subject')]" "xpath_element"
