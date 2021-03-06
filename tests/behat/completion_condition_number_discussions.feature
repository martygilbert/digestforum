@mod @mod_digestforum
Feature: Set a certain number of discussions as a completion condition for a digestforum
  In order to ensure students are participating on digestforums
  As a teacher
  I need to set a minimum number of discussions to mark the digestforum activity as completed

  @javascript
  Scenario: Set X number of discussions as a condition
    Given the following "users" exists:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@asd.com |
      | teacher1 | Teacher | 1 | teacher1@asd.com |
    And the following "courses" exists:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exists:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And I log in as "admin"
    And I set the following administration settings values:
      | Enable completion tracking | 1 |
      | Enable conditional access | 1 |
    And I log out
    And I log in as "teacher1"
    And I follow "Course 1"
    And I turn editing mode on
    And I follow "Edit settings"
    And I fill the moodle form with:
      | Enable completion tracking | Yes |
    And I press "Save changes"
    When I add a "Forum" to section "1" and I fill the form with:
      | Forum name | Test digestforum name |
      | Description | Test digestforum description |
      | Completion tracking | Show activity as complete when conditions are met |
      | completiondiscussionsenabled | 1 |
      | completiondiscussions | 2 |
    And I log out
    And I log in as "student1"
    And I follow "Course 1"
    Then I hover "//li[contains(concat(' ', @class, ' '), ' modtype_digestforum ')]/descendant::img[@alt='Not completed: Test digestforum name']" "xpath_element"
    And I add a new discussion to "Test digestforum name" digestforum with:
      | Subject | Post 1 subject |
      | Message | Body 1 content |
    And I add a new discussion to "Test digestforum name" digestforum with:
      | Subject | Post 2 subject |
      | Message | Body 2 content |
    And I follow "Course 1"
    And I hover "//li[contains(concat(' ', @class, ' '), ' modtype_digestforum ')]/descendant::img[contains(@alt, 'Completed: Test digestforum name')]" "xpath_element"
    And I log out
    And I log in as "teacher1"
    And I follow "Course 1"
    And "Student 1" user has completed "Test digestforum name" activity
