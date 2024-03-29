@mod @mod_digestforum
Feature: Set a certain number of discussions as a completion condition for a digestforum
  In order to ensure students are participating on digestforums
  As a teacher
  I need to set a minimum number of discussions to mark the digestforum activity as completed

  Scenario: Set X number of discussions as a condition
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@example.com |
      | teacher1 | Teacher | 1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I navigate to "Edit settings" in current page administration
    And I set the following fields to these values:
      | Enable completion tracking | Yes |
    And I press "Save and display"
    When I add a "Forum" to section "1" and I fill the form with:
      | Forum name | Test digestforum name |
      | Description | Test digestforum description |
      | Completion tracking | Show activity as complete when conditions are met |
      | completionview               | 0 |
      | completionpostsenabled       | 0 |
      | completiondiscussionsenabled | 1 |
      | completiondiscussions        | 2 |
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    Then the "Test digestforum name" "digestforum" activity with "auto" completion should be marked as not complete
    And I add a new discussion to "Test digestforum name" digestforum with:
      | Subject | Post 1 subject |
      | Message | Body 1 content |
    And I add a new discussion to "Test digestforum name" digestforum with:
      | Subject | Post 2 subject |
      | Message | Body 2 content |
    And I am on "Course 1" course homepage
    Then the "Test digestforum name" "digestforum" activity with "auto" completion should be marked as complete
    And I log out
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And "Student 1" user has completed "Test digestforum name" activity
