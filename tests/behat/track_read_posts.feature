@mod @mod_digestforum
Feature: A teacher can set one of 3 possible options for tracking read digestforum posts
  In order to ease the digestforum posts follow up
  As a user
  I need to distinct the unread posts from the read ones

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | trackforums |
      | student1 | Student   | 1        | student1@example.com | 1           |
      | student2 | Student   | 2        | student2@example.com | 0           |
      | teacher1 | Teacher   | 1        | teacher1@example.com | 1           |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |

  Scenario: Tracking digestforum posts off
    Given the following "activity" exists:
      | activity      | digestforum                           |
      | course        | C1                              |
      | idnumber      | digestforum1                          |
      | type          | general                         |
      | name          | Test digestforum name                 |
      | trackingtype  | 0                               |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | digestforum1            |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    When I am on the "Course 1" course page logged in as student1
    Then I should not see "1 unread post"
    And I follow "Test digestforum name"
    And I should not see "Track unread posts"

  Scenario: Tracking digestforum posts optional with user tracking on
    Given the following "activity" exists:
      | activity     | digestforum                  |
      | course       | C1                     |
      | idnumber     | digestforum1                 |
      | name         | Test digestforum name        |
      | type         | general                |
      | trackingtype | 1                      |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | digestforum1            |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    When I am on the "Course 1" course page logged in as student1
    Then I should see "1 unread post"
    And I follow "Test digestforum name"
    And I follow "Don't track unread posts"
    And I wait to be redirected
    And I am on "Course 1" course homepage
    And I should not see "1 unread post"
    And I follow "Test digestforum name"
    And I follow "Track unread posts"
    And I wait to be redirected
    And I click on "1" "link" in the "Admin User" "table_row"
    And I am on "Course 1" course homepage
    And I should not see "1 unread post"

  Scenario: Tracking digestforum posts optional with user tracking off
    Given the following "activity" exists:
      | activity     | digestforum                       |
      | course       | C1                          |
      | idnumber     | 00001                       |
      | name         | Test digestforum name             |
      | type         | general                     |
      | trackingtype | 1                           |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | 00001             |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    When I am on the "Course 1" course page logged in as student2
    Then I should not see "1 unread post"
    And I follow "Test digestforum name"
    And I should not see "Track unread posts"

  Scenario: Tracking digestforum posts forced with user tracking on
    Given the following config values are set as admin:
      | digestforum_allowforcedreadtracking | 1 |
    And the following "activity" exists:
      | activity     | digestforum                       |
      | course       | C1                          |
      | idnumber     | 00001                       |
      | name         | Test digestforum name             |
      | type         | general                     |
      | trackingtype | 2                           |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | 00001            |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    When I am on the "Course 1" course page logged in as student1
    Then I should see "1 unread post"
    And I am on the "Test digestforum name" "digestforum activity" page
    And I should not see "Don't track unread posts"
    And I follow "Test post subject"
    And I am on "Course 1" course homepage
    And I should not see "1 unread post"

  Scenario: Tracking digestforum posts forced with user tracking off
    Given the following config values are set as admin:
      | digestforum_allowforcedreadtracking | 1 |
    And the following "activity" exists:
      | activity     | digestforum                       |
      | course       | C1                          |
      | idnumber     | 00001                       |
      | name         | Test digestforum name             |
      | type         | general                     |
      | trackingtype | 2                           |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | 00001             |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    When I am on the "Course 1" course page logged in as student2
    Then I should see "1 unread post"
    And I am on the "Test digestforum name" "digestforum activity" page
    And I should not see "Don't track unread posts"
    And I follow "Test post subject"
    And I am on "Course 1" course homepage
    And I should not see "1 unread post"

  Scenario: Tracking digestforum posts forced (with force disabled) with user tracking on
    Given the following config values are set as admin:
      | digestforum_allowforcedreadtracking | 1 |
    And the following "activity" exists:
      | activity     | digestforum                     |
      | course       | C1                        |
      | idnumber     | 00001                     |
      | name         | Test digestforum name           |
      | type         | general                   |
      | trackingtype | 2                         |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | 00001             |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    And the following config values are set as admin:
      | digestforum_allowforcedreadtracking | 0 |
    When I am on the "Course 1" course page logged in as student1
    Then I should see "1 unread post"
    And I follow "Test digestforum name"
    And I follow "Don't track unread posts"
    And I wait to be redirected
    And I am on "Course 1" course homepage
    And I should not see "1 unread post"
    And I follow "Test digestforum name"
    And I follow "Track unread posts"
    And I wait to be redirected
    And I click on "1" "link" in the "Admin User" "table_row"
    And I am on "Course 1" course homepage
    And I should not see "1 unread post"

  Scenario: Tracking digestforum posts forced (with force disabled) with user tracking off
    Given the following config values are set as admin:
      | digestforum_allowforcedreadtracking | 1 |
    And the following "activity" exists:
      | activity     | digestforum                  |
      | course       | C1                     |
      | idnumber     | 00001                  |
      | name         | Test digestforum name        |
      | type         | general                |
      | trackingtype | 2                      |
    And the following "mod_digestforum > discussion" exists:
      | digestforum   | 00001             |
      | course  | C1                |
      | user    | admin             |
      | name    | Test post subject |
      | message | Test post message |
    And the following config values are set as admin:
      | digestforum_allowforcedreadtracking | 0 |
    When I am on the "Course 1" course page logged in as student2
    Then I should not see "1 unread post"
    And I follow "Test digestforum name"
    And I should not see "Track unread posts"

  @javascript
  Scenario: Forum grader panel marks posts as read
    Given the following "activity" exists:
      | course      | C1              |
      | activity    | digestforum           |
      | name        | Test digestforum name |
      | idnumber    | digestforum           |
      | grade_digestforum | 100             |
      | scale       | 100             |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name         | subject      | message          |
      | student1 | digestforum | Discussion 1 | Discussion 1 | student1's topic |
      | student2 | digestforum | Discussion 2 | Discussion 2 | student2's topic |
    And the following "mod_digestforum > posts" exist:
      | user     | parentsubject | subject               | message          |
      | student2 | Discussion 1  | Reply to discussion 1 | student2's reply |
      | student1 | Discussion 2  | Reply to discussion 2 | student1's reply |
    When I am on the "Course 1" course page logged in as teacher1
    # We have a discussion created by each student, and each has replied to the other.
    Then I should see "4 unread posts"
    And I am on the "Test digestforum name" "digestforum activity" page
    And I press "Grade users"
    And I press "Close grader"
    And I am on the "Course 1" course page
    # 4 posts minus the 2 viewed in the grader.
    And I should see "2 unread posts"
    And I am on the "Test digestforum name" "digestforum activity" page
    And I press "Grade users"
    # Let's look at a discussion in student1's grader that features a post from student2.
    And I press "View discussion"
    And I click on "Cancel" "button" in the "Discussion 1" "dialogue"
    And I am on the "Course 1" course page
    # Remaining 2 unread posts minus the 1 viewed in the discussion context.
    And I should see "1 unread post"
    And I am on the "Test digestforum name" "digestforum activity" page
    And I press "Grade users"
    # Let's look at the parent post that student1 replied to.
    And I press "View parent post"
    And I am on the "Course 1" course page
    And I should not see "1 unread post"
