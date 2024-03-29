@mod @mod_digestforum
Feature: Posting to all groups in a separate group discussion is restricted to users with access to all groups
  In order to post to all groups in a digestforum with separate groups
  As a teacher
  I need to have the accessallgroups capability

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | noneditor1 | Non-editing teacher | 1 | noneditor1@example.com |
      | noneditor2 | Non-editing teacher | 2 | noneditor2@example.com |
      | student1 | Student | 1 | student1@example.com |
      | student2 | Student | 2 | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | noneditor1 | C1 | teacher |
      | noneditor2 | C1 | teacher |
      | student1 | C1 | student |
      | student2 | C1 | student |
    And the following "groups" exist:
      | name | course | idnumber |
      | Group A | C1 | G1 |
      | Group B | C1 | G2 |
      | Group C | C1 | G3 |
    And the following "group members" exist:
      | user | group |
      | teacher1 | G1 |
      | teacher1 | G2 |
      | noneditor1 | G1 |
      | noneditor1 | G2 |
      | noneditor1 | G3 |
      | noneditor2 | G1 |
      | noneditor2 | G2 |
      | student1 | G1 |
      | student2 | G1 |
      | student2 | G2 |
    And the following "activities" exist:
      | activity   | name                   | intro                         | course | idnumber     | groupmode |
      | digestforum      | Standard digestforum name    | Standard digestforum description    | C1     | sepgroups    | 1         |

  Scenario: Teacher with accessallgroups can view all groups
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I follow "Standard digestforum name"
    Then the "Separate groups" select box should contain "All participants"
    Then the "Separate groups" select box should contain "Group A"
    Then the "Separate groups" select box should contain "Group B"
    Then the "Separate groups" select box should contain "Group C"

  Scenario: Teacher with accessallgroups can select any group when posting
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    When I click on "Add a new discussion topic" "button"
    Then the "Group" select box should contain "All participants"
    And the "Group" select box should contain "Group A"
    And the "Group" select box should contain "Group B"
    And the "Group" select box should contain "Group C"
    And I should see "Post a copy to all groups"

  Scenario: Teacher with accessallgroups can post in groups they are a member of
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    And I select "Group A" from the "Separate groups" singleselect
    When I click on "Add a new discussion topic" "button"
    Then I should see "Post a copy to all groups"
    And I set the following fields to these values:
      | Subject | Teacher 1 -> Group B  |
      | Message | Teacher 1 -> Group B  |
      # Change the group in the post form.
      | Group   | Group B               |
    And I press "Post to digestforum"
    And I wait to be redirected
    # We should be redirected to the group that we selected when posting.
    And the field "Separate groups" matches value "Group B"
    And I should see "Group B" in the "Teacher 1 -> Group B" "table_row"
    And I should not see "Group A" in the "Teacher 1 -> Group B" "table_row"
    And I should not see "Group C" in the "Teacher 1 -> Group B" "table_row"
    # It should also be displayed under All participants
    And I select "All participants" from the "Separate groups" singleselect
    And I should see "Group B" in the "Teacher 1 -> Group B" "table_row"
    And I should not see "Group A" in the "Teacher 1 -> Group B" "table_row"
    And I should not see "Group C" in the "Teacher 1 -> Group B" "table_row"
    # It should not be displayed in Groups A, or C.
    And I select "Group A" from the "Separate groups" singleselect
    And I should not see "Teacher 1 -> Group B"
    And I select "Group C" from the "Separate groups" singleselect
    And I should not see "Teacher 1 -> Group B"

  Scenario: Teacher with accessallgroups can post in groups they are not a member of
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    And I select "Group A" from the "Separate groups" singleselect
    When I click on "Add a new discussion topic" "button"
    Then I should see "Post a copy to all groups"
    And I set the following fields to these values:
      | Subject | Teacher 1 -> Group C  |
      | Message | Teacher 1 -> Group C  |
      | Group   | Group C               |
    And I press "Post to digestforum"
    And I wait to be redirected
    # We should be redirected to the group that we selected when posting.
    And the field "Separate groups" matches value "Group C"
    # We redirect to the group posted in automatically.
    And I should see "Group C" in the "Teacher 1 -> Group C" "table_row"
    And I should not see "Group A" in the "Teacher 1 -> Group C" "table_row"
    And I should not see "Group B" in the "Teacher 1 -> Group C" "table_row"
    # It should also be displayed under All participants
    And I select "All participants" from the "Separate groups" singleselect
    And I should see "Group C" in the "Teacher 1 -> Group C" "table_row"
    And I should not see "Group A" in the "Teacher 1 -> Group C" "table_row"
    And I should not see "Group B" in the "Teacher 1 -> Group C" "table_row"
    # It should not be displayed in Groups A, or B.
    And I select "Group A" from the "Separate groups" singleselect
    And I should not see "Teacher 1 -> Group C"
    And I select "Group B" from the "Separate groups" singleselect
    And I should not see "Teacher 1 -> Group C"

  Scenario: Teacher with accessallgroups can post to all groups
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    When I click on "Add a new discussion topic" "button"
    And I set the following fields to these values:
      | Subject                   | Teacher 1 -> Post to all  |
      | Message                   | Teacher 1 -> Post to all  |
      | Post a copy to all groups | 1                       |
    And I press "Post to digestforum"
    And I wait to be redirected
    # Posting to all groups means that we should be redirected to the page we started from.
    And the field "Separate groups" matches value "All participants"
    And I select "Group A" from the "Separate groups" singleselect
    Then I should see "Group A" in the "Teacher 1 -> Post to all" "table_row"
    And I should not see "Group B" in the "Teacher 1 -> Post to all" "table_row"
    And I should not see "Group C" in the "Teacher 1 -> Post to all" "table_row"
    And I select "Group B" from the "Separate groups" singleselect
    And I should see "Group B" in the "Teacher 1 -> Post to all" "table_row"
    And I should not see "Group A" in the "Teacher 1 -> Post to all" "table_row"
    And I should not see "Group C" in the "Teacher 1 -> Post to all" "table_row"
    And I select "Group C" from the "Separate groups" singleselect
    And I should see "Group C" in the "Teacher 1 -> Post to all" "table_row"
    And I should not see "Group A" in the "Teacher 1 -> Post to all" "table_row"
    And I should not see "Group B" in the "Teacher 1 -> Post to all" "table_row"
    # No point testing the "All participants".

  Scenario: Students in one group can only post in their group
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    When I follow "Standard digestforum name"
    Then I should see "Group A"
    And I click on "Add a new discussion topic" "button"
    And I should see "Group A"
    And I should not see "Group B"
    And I should not see "Group C"
    And I should not see "Post a copy to all groups"
    And I set the following fields to these values:
      | Subject | Student -> B |
      | Message | Student -> B |
    And I press "Post to digestforum"
    And I wait to be redirected
    And I should see "Group A" in the "Student -> B" "table_row"
    And I should not see "Group B" in the "Student -> B" "table_row"

  Scenario: Students in multiple group can post in all of their group individually
    Given I log in as "student2"
    And I am on "Course 1" course homepage
    When I follow "Standard digestforum name"
    And I select "Group A" from the "Separate groups" singleselect
    And I click on "Add a new discussion topic" "button"
    And the "Group" select box should not contain "All participants"
    And the "Group" select box should contain "Group A"
    And the "Group" select box should contain "Group B"
    And the "Group" select box should not contain "Group C"
    And I should not see "Post a copy to all groups"
    And I set the following fields to these values:
      | Subject | Student -> B  |
      | Message | Student -> B  |
      | Group   | Group B       |
    And I press "Post to digestforum"
    And I wait to be redirected
    # We should be redirected to the group that we selected when posting.
    And the field "Separate groups" matches value "Group B"
    And I should see "Group B" in the "Student -> B" "table_row"
    And I should not see "Group A" in the "Student -> B" "table_row"
    And I select "Group A" from the "Separate groups" singleselect
    And I should not see "Student -> B"
    # Now try posting in Group A (starting at Group B)
    And I select "Group B" from the "Separate groups" singleselect
    And I click on "Add a new discussion topic" "button"
    And the "Group" select box should not contain "All participants"
    And the "Group" select box should contain "Group A"
    And the "Group" select box should contain "Group B"
    And the "Group" select box should not contain "Group C"
    And I should not see "Post a copy to all groups"
    And I set the following fields to these values:
      | Subject | Student -> A  |
      | Message | Student -> A  |
      | Group   | Group A       |
    And I press "Post to digestforum"
    And I wait to be redirected
    # We should be redirected to the group that we selected when posting.
    And the field "Separate groups" matches value "Group A"
    And I should see "Group A" in the "Student -> A" "table_row"
    And I should not see "Group B" in the "Student -> A" "table_row"
    And I select "Group B" from the "Separate groups" singleselect
    And I should not see "Student -> A"

  Scenario: Teacher in all groups but without accessallgroups can only post in their groups
    And I log in as "admin"
    And I set the following system permissions of "Non-editing teacher" role:
      | moodle/site:accessallgroups | Prohibit |
    And I log out
    Given I log in as "noneditor1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    When I click on "Add a new discussion topic" "button"
    Then the "Group" select box should not contain "All participants"
    And the "Group" select box should contain "Group A"
    And the "Group" select box should contain "Group B"
    And I should see "Post a copy to all groups"

  Scenario: Teacher in some groups and without accessallgroups can only post in their groups
    And I log in as "admin"
    And I set the following system permissions of "Non-editing teacher" role:
      | moodle/site:accessallgroups | Prohibit |
    And I log out
    Given I log in as "noneditor1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    When I click on "Add a new discussion topic" "button"
    Then the "Group" select box should not contain "All participants"
    And the "Group" select box should contain "Group A"
    And the "Group" select box should contain "Group B"
    And I should see "Post a copy to all groups"

  Scenario: Students can view all participants discussions in separate groups mode
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I add a new discussion to "Standard digestforum name" digestforum with:
      | Subject | Forum post to all participants |
      | Message | This is the body |
      | Group   | All participants |
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Standard digestforum name"
    Then I should see "Forum post to all participants"
