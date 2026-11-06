@mod @mod_digestforum
Feature: Students can edit or delete their digestforum posts within a set time limit
  In order to refine digestforum posts
  As a user
  I need to edit or delete my digestforum posts within a certain period of time after posting

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity   | name                   | course  | idnumber  |
      | digestforum      | Test digestforum name        | C1      | digestforum     |

  Scenario: Edit digestforum post
    Given the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name               | message          |
      | student1 | digestforum | Forum post subject | This is the body |
    And I am on the "Test digestforum name" "digestforum activity" page logged in as "student1"
    And I follow "Forum post subject"
    And I follow "Edit"
    When I set the following fields to these values:
      | Subject | Edited post subject |
      | Message | Edited post body |
    And I press "Save changes"
    And I wait to be redirected
    Then I should see "Edited post subject"
    And I should see "Edited post body"

  Scenario: Delete digestforum post
    Given the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name               | message          |
      | student1 | digestforum | Forum post subject | This is the body |
    And I am on the "Test digestforum name" "digestforum activity" page logged in as "student1"
    And I follow "Forum post subject"
    When I follow "Delete"
    And I press "Continue"
    Then I should not see "Forum post subject"

  @block_recent_activity
  Scenario: Time limit expires
    Given the following "blocks" exist:
      | blockname       | contextlevel | reference | pagetypepattern | defaultregion |
      | recent_activity | Course       | C1        | course-view-*   | side-pre      |
    And the following config values are set as admin:
      | maxeditingtime | 1 |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name               | message          | timemodified       |
      | student1 | digestforum | Forum post subject | This is the body | ##now +1 second##  |
    And I am on the "Course 1" course page logged in as student1
    And I should see "New digest forum posts:" in the "Recent activity" "block"
    And I should see "Forum post subject" in the "Recent activity" "block"
    When I wait "2" seconds
    And I follow "Forum post subject"
    Then I should not see "Edit" in the "region-main" "region"
    And I should not see "Delete" in the "region-main" "region"
