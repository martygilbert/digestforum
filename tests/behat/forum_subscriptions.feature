@mod @mod_digestforum
Feature: A user can control their own subscription preferences for a digestforum
  In order to receive notifications for things I am interested in
  As a user
  I need to choose my digestforum subscriptions

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Student   | One      | student.one@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity | course | idnumber | name            | type    |
      | digestforum    | C1     | digestforum1   | Test digestforum name | general |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | course | user  | name              | message           |
      | digestforum1 | C1     | admin | Test post subject | Test post message |
    And I am on the "Test digestforum name" "digestforum activity editing" page logged in as admin

  Scenario: A disallowed subscription digestforum cannot be subscribed to
    Given I set the following fields to these values:
      | Subscription mode | Subscription disabled |
    And I press "Save and return to course"
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then I should not see "Subscribe to this digestforum"
    And I should not see "Unsubscribe from this digestforum"
    And "You are subscribed to this discussion. Click to unsubscribe." "link" should not exist in the "Test post subject" "table_row"
    And "You are not subscribed to this discussion. Click to subscribe." "link" should not exist in the "Test post subject" "table_row"

  Scenario: A forced subscription digestforum cannot be subscribed to
    Given I set the following fields to these values:
      | Subscription mode | Forced subscription |
    And I press "Save and return to course"
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then I should not see "Subscribe to this digestforum"
    And I should not see "Unsubscribe from this digestforum"
    And "You are subscribed to this discussion. Click to unsubscribe." "link" should not exist in the "Test post subject" "table_row"
    And "You are not subscribed to this discussion. Click to subscribe." "link" should not exist in the "Test post subject" "table_row"

  @javascript
  Scenario: An optional digestforum can be subscribed to
    Given I set the following fields to these values:
      | Subscription mode | Optional subscription |
    And I press "Save and return to course"
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    Then I should see "Subscribe to digestforum"
    And I click on "Subscribe to digestforum" "field"
    And the field "Subscribe to digestforum" matches value "1"
    And I am on the "Course 1" "course > activities > digestforum" page
    And "Unsubscribe from digestforum" "field" should exist in the "Test digestforum name" "table_row"

  @javascript
  Scenario: An Automatic digestforum can be unsubscribed from
    Given I set the following fields to these values:
      | Subscription mode | Auto subscription |
    And I press "Save and return to course"
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I should see "Subscribe to digestforum"
    And I click on "Subscribe to digestforum" "field"
    And the field "Subscribe to digestforum" matches value "0"
    And I am on the "Course 1" "course > activities > digestforum" page
    And "Subscribe to digestforum" "field" should exist in the "Test digestforum name" "table_row"
