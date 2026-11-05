@mod @mod_digestforum
Feature: Digest forum: A user can control their default discussion subscription settings
  In order to automatically subscribe to discussions
  As a user
  I can choose my default subscription preference

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                   | autosubscribe |
      | student1 | Student   | One      | student.one@example.com | 1             |
      | student2 | Student   | Two      | student.one@example.com | 0             |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
      | student2 | C1 | student |
    And the following "activities" exist:
      | activity   | name                   | course | idnumber | type    |
      | digestforum      | Test digestforum name        | C1     | digestforump1  | general |
    And I am on the "Test digestforum name" "digestforum activity editing" page logged in as admin
    And I set the following fields to these values:
      | Subscription mode | Optional subscription |
    And I press "Save and return to course"

  Scenario: Creating a new discussion in an optional digestforum follows user preferences
    Given I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    When I click on "Add discussion topic" "link"
    And I click on "Advanced" "button"
    Then "input[name=discussionsubscribe]:checked" "css_element" should exist
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student2
    And I click on "Add discussion topic" "link"
    And I click on "Advanced" "button"
    And "input[name=discussionsubscribe]:not(:checked)" "css_element" should exist

  Scenario: Replying to an existing discussion in an optional digestforum follows user preferences
    Given the following digestforum discussions exist in course "Course 1":
      | user  | digestforum           | name              | message           |
      | admin | Test digestforum name | Test post subject | Test post message |
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I follow "Test post subject"
    When I follow "Reply"
    Then "input[name=discussionsubscribe]:checked" "css_element" should exist
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student2
    And I follow "Test post subject"
    And I follow "Reply"
    And "input[name=discussionsubscribe]:not(:checked)" "css_element" should exist

  Scenario: Creating a new discussion in an automatic digestforum follows digestforum subscription
    Given I am on the "Test digestforum name" "digestforum activity editing" page logged in as admin
    And I set the following fields to these values:
      | Subscription mode | Auto subscription |
    And I press "Save and return to course"
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    When I click on "Add discussion topic" "link"
    And I click on "Advanced" "button"
    Then "input[name=discussionsubscribe]:checked" "css_element" should exist
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student2
    And I click on "Add discussion topic" "link"
    And I click on "Advanced" "button"
    And "input[name=discussionsubscribe]:checked" "css_element" should exist

  Scenario: Replying to an existing discussion in an automatic digestforum follows digestforum subscription
    Given the following digestforum discussions exist in course "Course 1":
      | user  | digestforum           | name              | message           |
      | admin | Test digestforum name | Test post subject | Test post message |
    And I am on the "Test digestforum name" "digestforum activity editing" page logged in as admin
    And I set the following fields to these values:
      | Subscription mode | Optional subscription |
    And I press "Save and return to course"
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I follow "Test post subject"
    When I follow "Reply"
    Then "input[name=discussionsubscribe]:checked" "css_element" should exist
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student2
    And I follow "Test post subject"
    And I follow "Reply"
    And "input[name=discussionsubscribe]:not(:checked)" "css_element" should exist

  @javascript
  Scenario: Replying to an existing discussion in an automatic digestforum which has been unsubscribed from follows user preferences
    Given the following digestforum discussions exist in course "Course 1":
      | user  | digestforum           | name              | message           |
      | admin | Test digestforum name | Test post subject | Test post message |
    And I am on the "Test digestforum name" "digestforum activity editing" page logged in as admin
    And I set the following fields to these values:
      | Subscription mode | Auto subscription |
    And I press "Save and return to course"
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I click on "input[id^=subscription-toggle]" "css_element" in the "Test post subject" "table_row"
    And I follow "Test post subject"
    When I follow "Reply"
    And I click on "Advanced" "button"
    And "input[name=discussionsubscribe][checked]" "css_element" should exist
    And I am on the "Test digestforum name" "digestforum activity" page logged in as student2
    And I click on "input[id^=subscription-toggle]" "css_element" in the "Test post subject" "table_row"
    And I follow "Test post subject"
    And I follow "Reply"
    And I click on "Advanced" "button"
    And "input[name=discussionsubscribe]:not([checked])" "css_element" should exist
