@mod @mod_digestforum
Feature: Digest forum: A user can navigate to previous and next discussions
  In order to get go the previous discussion
  As a user
  I need to click on the previous discussion link

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | 1        | teacher1@example.com  |
      | student1 | Student   | 1        | student1@example.com  |
      | student2 | Student   | 2        | student2@example.com  |
    And the following "courses" exist:
      | fullname | shortname  | category  |
      | Course 1 | C1         | 0         |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
      | student2 | C1 | student |
    And the following "groups" exist:
      | name                  | course | idnumber |
      | Group 1 & (EN) < '" > | C1     | G1       |
      | Group 2               | C1     | G2       |
    And the following "group members" exist:
      | user | group |
      | teacher1 | G1 |
      | teacher1 | G2 |
      | student1 | G1 |
      | student2 | G2 |

  Scenario: A user can navigate between discussions
    Given the following "activities" exist:
      | activity   | name                   | course | idnumber     | groupmode |
      | digestforum      | Test digestforum name        | C1     | digestforum        | 0         |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name         | message           |
      | teacher1 | digestforum | Discussion 1 | Test post message |
      | teacher1 | digestforum | Discussion 2 | Test post message |
      | teacher1 | digestforum | Discussion 3 | Test post message |
    And I am on the "Test digestforum name" "digestforum activity" page logged in as teacher1
    When I follow "Discussion 3"
    Then I should not see "Discussion 1"
    And I should see "Discussion 2"
    And I click on "Previous discussion: Discussion 2" "mod_digestforum > Discussion navigation link"
    And I should see "Discussion 1"
    And I should see "Discussion 3"
    And I click on "Previous discussion: Discussion 1" "mod_digestforum > Discussion navigation link"
    And I should see "Discussion 2"
    And I should not see "Discussion 3"
    And I follow "Reply"
    And I set the following fields to these values:
      | Message | Answer to discussion |
    # We need to wait a bit to guarantee that the post is created after the previous ones.
    # because there is a bug in the digestforum_get_discussion_neighbours() when all the discussion
    # last modified times are the same. See MDL-79247 for more details.
    And I wait "1" seconds
    And I press "Post to digest forum"
    And I should not see "Discussion 2"
    And I should see "Discussion 3"
    And I follow "Discussion 3"
    And I should see "Discussion 1"
    And I should see "Discussion 2"
    And I click on "Previous discussion: Discussion 2" "mod_digestforum > Discussion navigation link"
    And I should not see "Discussion 1"
    And I should see "Discussion 3"

  Scenario: A user can navigate between discussions with visible groups
    Given the following "activities" exist:
      | activity   | name                   | course | idnumber     | groupmode |
      | digestforum      | Test digestforum name        | C1     | digestforum        | 2         |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name                 | message           |
      | student1 | digestforum | Discussion 1 Group 0 | Test post message |
      | student1 | digestforum | Discussion 2 Group 0 | Test post message |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name                 | message           | group |
      | student1 | digestforum | Discussion 1 Group 1 | Test post message | G1    |
      | student1 | digestforum | Discussion 2 Group 1 | Test post message | G1    |
      | student1 | digestforum | Discussion 1 Group 2 | Test post message | G2    |
      | student1 | digestforum | Discussion 2 Group 2 | Test post message | G2    |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I select "All participants" from the "Visible groups" singleselect
    And I follow "Discussion 1 Group 0"
    Then I should see "Discussion 2 Group 0"
    And I should not see "Group 1"
    And I should not see "Group 2"
    And I follow "Discussion 2 Group 0"
    And I should see "Discussion 1 Group 0"
    And I should see "Discussion 1 Group 1"
    And I follow "Discussion 1 Group 1"
    And I should see "Discussion 2 Group 0"
    And I should see "Discussion 2 Group 1"
    And I follow "Test digestforum name"
    And I follow "Discussion 1 Group 2"
    And I should see "Discussion 2 Group 1"
    And I should see "Discussion 2 Group 2"
    And I follow "Test digestforum name"
    And I select "Group 1 & (EN) < '" from the "Visible groups" singleselect
    And I should see "Group 1 & (EN) < '\" >"
    And I should not see "&amp;"
    And I follow "Discussion 1 Group 1"
    And I should see "Discussion 2 Group 0"
    And I should see "Discussion 2 Group 1"
    And I follow "Discussion 2 Group 1"
    And I should see "Discussion 1 Group 1"
    And I should not see "Group 2"

  Scenario: A user can navigate between discussions with separate groups
    Given the following "activities" exist:
      | activity   | name                   | course | idnumber     | groupmode |
      | digestforum      | Test digestforum name        | C1     | digestforum        | 1         |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name                 | message           |
      | student1 | digestforum | Discussion 1 Group 0 | Test post message |
      | student1 | digestforum | Discussion 2 Group 0 | Test post message |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum | name                 | message           | group |
      | student1 | digestforum | Discussion 1 Group 1 | Test post message | G1    |
      | student1 | digestforum | Discussion 2 Group 1 | Test post message | G1    |
      | student1 | digestforum | Discussion 1 Group 2 | Test post message | G2    |
      | student1 | digestforum | Discussion 2 Group 2 | Test post message | G2    |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I follow "Discussion 1 Group 1"
    Then I should see "Discussion 2 Group 0"
    And I should see "Discussion 2 Group 1"
    And I follow "Discussion 2 Group 1"
    And I should see "Discussion 1 Group 1"
    And I should not see "Group 2"

  Scenario: Navigation buttons are displayed at top and bottom of discussion
    Given the following "activity" exists:
      | activity   | digestforum                    |
      | course     | C1                       |
      | idnumber   | digestforum1                   |
      | name       | Test Forum               |
      | forcesubscribe | 1                   |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | user     | name              | subject           | message                      |
      | digestforum1 | student1 | First Discussion  | First Discussion  | Content of first discussion  |
      | digestforum1 | student1 | Second Discussion | Second Discussion | Content of second discussion |
      | digestforum1 | student1 | Third Discussion  | Third Discussion  | Content of third discussion  |
    And I am on the "Test Forum" "digestforum activity" page logged in as "student1"
    When I click on "Second Discussion" "link"
    Then I should see "2" node occurrences of type "nav" in the "#page-content" "css_element"
    And "Previous discussion: First Discussion" "mod_digestforum > Discussion navigation link" should exist
    And "Next discussion: Third Discussion" "mod_digestforum > Discussion navigation link" should exist

  Scenario: Navigation buttons allow moving between discussions and we can still rely on labels to navigate
    Given the following "activity" exists:
      | activity   | digestforum                    |
      | course     | C1                       |
      | idnumber   | digestforum1                   |
      | name       | Test Forum               |
      | forcesubscribe | 1                   |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | user     | name              | subject           | message                      |
      | digestforum1 | student1 | First Discussion  | First Discussion  | Content of first discussion  |
      | digestforum1 | student1 | Second Discussion | Second Discussion | Content of second discussion |
      | digestforum1 | student1 | Third Discussion  | Third Discussion  | Content of third discussion  |
    And I am on the "Test Forum" "digestforum activity" page logged in as "student1"
    And I am on the "Test Forum" "digestforum activity" page
    When I click on "Second Discussion" "link"
    And I click on "Previous discussion: First Discussion" "mod_digestforum > Discussion navigation link"
    Then I should see "First Discussion" in the "region-main" "region"
    And I click on "Next discussion: Second Discussion" "mod_digestforum > Discussion navigation link"
    And I should see "Second Discussion" in the "region-main" "region"
    And I click on "Next discussion: Third Discussion" "mod_digestforum > Discussion navigation link"
    Then I should see "Third Discussion" in the "region-main" "region"
    # We can still navigate via the labels we sets for the links.
    And I follow "Second Discussion"
    Then I should see "Second Discussion" in the "region-main" "region"

  Scenario: Navigation is not shown in experimental nested view
    Given I am logged in as "student1"
    And the following "user preferences" exist:
      | user     | preference                    | value |
      | student1 | digestforum_useexperimentalui | 1     |
    And the following "activity" exists:
      | activity   | digestforum                    |
      | course     | C1                       |
      | idnumber   | digestforum1                   |
      | name       | Test Forum               |
      | forcesubscribe | 1                   |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | user     | name              | subject           | message                      |
      | digestforum1 | student1 | First Discussion  | First Discussion  | Content of first discussion  |
      | digestforum1 | student1 | Second Discussion | Second Discussion | Content of second discussion |
      | digestforum1 | student1 | Third Discussion  | Third Discussion  | Content of third discussion  |
    And I am on the "Test Forum" "digestforum activity" page
    When I click on "Second Discussion" "link"
    Then "nav.discussion-nav" "css_element" should not exist

  Scenario: Previous and next buttons are disabled when it is the last discussion link
    Given the following "activity" exists:
      | activity   | digestforum                    |
      | course     | C1                       |
      | idnumber   | digestforum1                   |
      | name       | Test Forum               |
      | forcesubscribe | 1                   |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | user     | name              | subject           | message                      |
      | digestforum1 | student1 | First Discussion  | First Discussion  | Content of first discussion  |
    And I am on the "Test Forum" "digestforum activity" page logged in as "student1"
    When I click on "First Discussion" "link"
    Then the "class" attribute of "prev" "mod_digestforum > Discussion navigation link" should contain "disabled"
    And the "class" attribute of "next" "mod_digestforum > Discussion navigation link" should contain "disabled"
