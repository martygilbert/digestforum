@mod @mod_digestforum
Feature: Forum subscription toggle in the digestforum action bar
  In order to quickly manage my digestforum subscription without page reloads
  As a user
  I need the digestforum action bar to show a toggle that subscribes and unsubscribes via AJAX

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                   |
      | student1 | Student   | One      | student.one@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |

  @javascript
  Scenario: The subscription toggle is shown unchecked when a user is not subscribed to an optional digestforum
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 0               |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Subscribe to digest forum" "field" should exist
    And the field "Subscribe to digest forum" matches value "0"

  @javascript
  Scenario: The subscription toggle is shown checked when a user is auto-subscribed to a digestforum
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 2               |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Subscribe to digest forum" "field" should exist
    And the field "Subscribe to digest forum" matches value "1"

  Scenario: The subscription toggle is not shown when subscriptions are forced
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 1               |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Subscribe to digest forum" "field" should not exist

  Scenario: The subscription toggle is not shown when subscriptions are disabled
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 3               |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Subscribe to digest forum" "field" should not exist

  @javascript
  Scenario: Subscribing via the digestforum action bar toggle shows a visible toast notification
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 0               |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I click on "Subscribe to digest forum" "field"
    Then I should see "You will be notified of new posts in the digest forum 'Test digestforum name'."

  @javascript
  Scenario: Unsubscribing via the digestforum action bar toggle shows a visible toast notification
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 2               |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I click on "Subscribe to digest forum" "field"
    Then I should see "Student One will NOT be notified of new posts in 'Test digestforum name'"

  @javascript
  Scenario: Subscribing via the digestforum action bar toggle updates all discussions
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 0               |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | course | user  | name                  | message               |
      | digestforum1 | C1     | admin | Test post subject one | Test post message one |
      | digestforum1 | C1     | admin | Test post subject two | Test post message two |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Subscribe to this discussion" "checkbox" should exist in the "Test post subject one" "table_row"
    And "Subscribe to this discussion" "checkbox" should exist in the "Test post subject two" "table_row"
    When I click on "Subscribe to digest forum" "field"
    Then "Unsubscribe from this discussion" "field" should exist in the "Test post subject one" "table_row"
    And "Unsubscribe from this discussion" "field" should exist in the "Test post subject two" "table_row"

  @javascript
  Scenario: Discussion toggle works after subscribing all via the digestforum action bar toggle
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 0               |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | course | user  | name                  | message               |
      | digestforum1 | C1     | admin | Test post subject one | Test post message one |
      | digestforum1 | C1     | admin | Test post subject two | Test post message two |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Subscribe to this discussion" "checkbox" should exist in the "Test post subject one" "table_row"
    And "Subscribe to this discussion" "checkbox" should exist in the "Test post subject two" "table_row"
    When I click on "Subscribe to digest forum" "field"
    And "Unsubscribe from this discussion" "field" should exist in the "Test post subject one" "table_row"
    And "Unsubscribe from this discussion" "field" should exist in the "Test post subject two" "table_row"
    When I click on "Unsubscribe from this discussion" "field" in the "Test post subject one" "table_row"
    Then "Subscribe to this discussion" "field" should exist in the "Test post subject one" "table_row"
    And "Unsubscribe from this discussion" "field" should exist in the "Test post subject two" "table_row"

  @javascript
  Scenario: Unsubscribing via the digestforum action bar toggle updates all discussions
    Given the following "activity" exists:
      | activity       | digestforum           |
      | course         | C1              |
      | idnumber       | digestforum1          |
      | name           | Test digestforum name |
      | type           | general         |
      | forcesubscribe | 2               |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | course | user  | name                  | message               |
      | digestforum1 | C1     | admin | Test post subject one | Test post message one |
      | digestforum1 | C1     | admin | Test post subject two | Test post message two |
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then "Unsubscribe from this discussion" "checkbox" should exist in the "Test post subject one" "table_row"
    And "Unsubscribe from this discussion" "checkbox" should exist in the "Test post subject two" "table_row"
    When I click on "Subscribe to digest forum" "field"
    Then "Subscribe to this discussion" "checkbox" should exist in the "Test post subject one" "table_row"
    And "Subscribe to this discussion" "checkbox" should exist in the "Test post subject two" "table_row"
