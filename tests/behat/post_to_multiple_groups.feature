@mod @mod_digestforum
Feature: A user with access to multiple groups should be able to post a copy of a message to all the groups they have access to
  In order to post to all groups a user has access to
  As a user
  I need to have the option to post a copy of a message to all groups

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student1@example.com |
      | student2 | Student | 2 | student2@example.com |
      | student3 | Student | 3 | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
      | Course 2 | C2 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | teacher1 | C2 | editingteacher |
      | student1 | C1 | student |
      | student1 | C2 | student |
      | student2 | C1 | student |
      | student2 | C2 | student |
      | student3 | C1 | student |
      | student3 | C2 | student |
    And the following "groups" exist:
      | name | course | idnumber |
      | Group A | C1 | C1G1 |
      | Group B | C1 | C1G2 |
      | Group C | C1 | C1G3 |
      | Group A | C2 | C2G1 |
      | Group B | C2 | C2G2 |
      | Group C | C2 | C2G3 |
    And the following "groupings" exist:
      | name | course | idnumber |
      | G1 | C2 | G1 |
    And the following "group members" exist:
      | user | group |
      | teacher1 | C1G1 |
      | teacher1 | C1G2 |
      | teacher1 | C1G3 |
      | teacher1 | C2G1 |
      | teacher1 | C2G1 |
      | student1 | C1G1 |
      | student1 | C2G1 |
      | student1 | C2G2 |
      | student2 | C1G1 |
      | student2 | C1G2 |
      | student3 | C1G1 |
      | student3 | C1G2 |
      | student3 | C1G3 |
    And the following "grouping groups" exist:
      | grouping | group |
      | G1       | C2G1 |
      | G1       | C2G2 |
    And the following "activities" exist:
      | activity   | name                   | intro             | course | idnumber     | groupmode | grouping |
      | digestforum      | No group digestforum         | Test digestforum name   | C1     | digestforum        | 0         |          |
      | digestforum      | Separate group digestforum   | Test digestforum name   | C1     | digestforum        | 1         |          |
      | digestforum      | Visible group digestforum    | Test digestforum name   | C1     | digestforum        | 2         |          |
      | digestforum      | Groupings digestforum        | Test digestforum name   | C2     | digestforum        | 1         | G1       |

  Scenario: Teacher is able to post a copy of a message to all groups in a separate group digestforum
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I add a new discussion to "Separate group digestforum" digestforum with:
      | Subject | Discussion 1 |
      | Message | test |
      | Post a copy to all groups | 1 |
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    When I follow "Separate group digestforum"
    Then I should see "Discussion 1"
    And I log out
    And I log in as "student2"
    And I am on "Course 1" course homepage
    And I follow "Separate group digestforum"
    And I should see "Discussion 1"
    And I log out
    And I log in as "student3"
    And I am on "Course 1" course homepage
    And I follow "Separate group digestforum"
    And I should see "Discussion 1"

  Scenario: Teacher is able to post a copy of a message to all groups in a visible group digestforum
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I add a new discussion to "Visible group digestforum" digestforum with:
      | Subject | Discussion 1 |
      | Message | test |
      | Post a copy to all groups | 1 |
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    When I follow "Visible group digestforum"
    Then I should see "Discussion 1"
    And I log out
    And I log in as "student2"
    And I am on "Course 1" course homepage
    And I follow "Visible group digestforum"
    And I should see "Discussion 1"
    And I log out
    And I log in as "student3"
    And I am on "Course 1" course homepage
    And I follow "Visible group digestforum"
    And I should see "Discussion 1"

  Scenario: Teacher is unable to post a copy of a message to all groups in a no group digestforum
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "No group digestforum"
    And I press "Add a new discussion topic"
    Then I should not see "Post a copy to all groups"

  Scenario: Posts to all groups that have groupings should only display within the grouping and not to other groups
    Given I log in as "teacher1"
    And I am on "Course 2" course homepage
    And I add a new discussion to "Groupings digestforum" digestforum with:
      | Subject | Discussion 1 |
      | Message | test |
      | Post a copy to all groups | 1 |
    And I log out
    And I log in as "student1"
    And I am on "Course 2" course homepage
    When I follow "Groupings digestforum"
    Then I should see "Discussion 1"
    And I log out
    And I log in as "student2"
    And I am on "Course 2" course homepage
    And I follow "Groupings digestforum"
    And I should not see "Discussion 1"
