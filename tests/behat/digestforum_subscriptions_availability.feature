@mod @mod_digestforum
Feature: Digest forum: As a teacher I need to see an accurate list of subscribed users
  In order to see who is subscribed to a digestforum
  As a teacher
  I need to view the list of subscribed users

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher  | Teacher   | Teacher  | teacher@example.com |
      | student1 | Student   | 1        | student.1@example.com |
      | student2 | Student   | 2        | student.2@example.com |
      | student3 | Student   | 3        | student.3@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher  | C1 | editingteacher |
      | student1 | C1 | student |
      | student2 | C1 | student |
      | student3 | C1 | student |
    And the following "groups" exist:
      | name | course | idnumber |
      | Group 1 | C1 | G1 |
      | Group 2 | C1 | G2 |
    And the following "group members" exist:
      | user        | group |
      | student1    | G1    |
      | student2    | G2    |
    And the following "groupings" exist:
      | name        | course | idnumber |
      | Grouping 1  | C1     | GG1      |
    And the following "grouping groups" exist:
      | grouping | group |
      | GG1      | G1    |
    And the following "activities" exist:
      | activity | course | idnumber | type    | name           | forcesubscribe |
      | digestforum    | C1     | 1        | general | Forced Forum 1 | 1              |
      | digestforum    | C1     | 0001     | general | Forced Forum 2 |                |
      | digestforum    | C1     | 0002     | general | Forced Forum 3 | 2              |
    And I log in as "teacher"
    And I am on "Course 1" course homepage with editing mode on

  @javascript
  Scenario: A forced digestforum lists all subscribers
    When I am on the "Forced Forum 1" "digestforum activity" page
    And I navigate to "Subscriptions" in current page administration
    Then I should see "Student 1"
    And I should see "Teacher Teacher"
    And I should see "Student 2"
    And I should see "Student 3"
    And I am on the "Forced Forum 1" "digestforum activity editing" page
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Grouping" "button" in the "Add restriction..." "dialogue"
    And I set the field with xpath "//select[@name='id']" to "Grouping 1"
    And I press "Save and display"
    And I navigate to "Subscriptions" in current page administration
    And I should see "Student 1"
    And I should see "Teacher Teacher"
    And I should not see "Student 2"
    And I should not see "Student 3"

  Scenario: A forced digestforum does not allow to edit the subscribers
    Given I am on the "Forced Forum 2" "digestforum activity editing" page
    And I set the following fields to these values:
      | Subscription mode | Forced subscription |
      | Availability      | Show on course page |
    And I press "Save and return to course"
    And I am on the "Forced Forum 2" "digestforum activity" page
    And I navigate to "Subscriptions" in current page administration
    Then I should see "Teacher Teacher"
    And I should see "Student 1"
    And I should see "Student 2"
    And I should see "Student 3"

  Scenario: A forced and hidden digestforum lists only teachers
    Given I am on the "Forced Forum 2" "digestforum activity editing" page
    And I set the following fields to these values:
      | Subscription mode | Forced subscription |
      | Availability      | Hide on course page |
    And I press "Save and return to course"
    And I am on the "Forced Forum 2" "digestforum activity" page
    And I navigate to "Subscriptions" in current page administration
    Then I should see "Teacher Teacher"
    And I should not see "Student 1"
    And I should not see "Student 2"
    And I should not see "Student 3"

  @javascript
  Scenario: An automatic digestforum lists all subscribers
    When I am on the "Forced Forum 3" "digestforum activity" page
    And I navigate to "Subscriptions" in current page administration
    Then I should see "Student 1"
    And I should see "Teacher Teacher"
    And I should see "Student 2"
    And I should see "Student 3"
    And I am on the "Forced Forum 3" "digestforum activity editing" page
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Grouping" "button" in the "Add restriction..." "dialogue"
    And I set the field with xpath "//select[@name='id']" to "Grouping 1"
    And I press "Save and display"
    And I navigate to "Subscriptions" in current page administration
    And I should see "Student 1"
    And I should see "Teacher Teacher"
    And I should not see "Student 2"
    And I should not see "Student 3"
