@mod @mod_digestforum @javascript
Feature: A student can favourite a discussion via the digestforum settings menu

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activity" exists:
      | course   | C1              |
      | activity | digestforum           |
      | name     | Test digestforum name |
      | idnumber | digestforum1          |
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum  | name         | message                              |
      | admin    | digestforum1 | Discussion 1 | Discussion contents 1, first message |
    And the following "mod_digestforum > posts" exist:
      | user     | parentsubject | subject                 | message                               |
      | admin    | Discussion 1  | Reply 1 to discussion 1 | Discussion contents 1, second message |
      | student1 | Discussion 1  | Reply 2 to discussion 1 | Discussion contents 1, third message  |

  Scenario: Student can favourite a discussion from within an individual discussion
    Given I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    When I open the action menu in "[data-container='discussion-tools']" "css_element"
    And I click on "[title='Star this discussion']" "css_element"
    And I wait "3" seconds
    And I open the action menu in "[data-container='discussion-tools']" "css_element"
    And I click on "[title='Unstar this discussion']" "css_element"

  Scenario: Student can favourite a discussion from the discussion list
    When I am on the "Test digestforum name" "digestforum activity" page logged in as student1
    And I click on "Discussion 1" action menu
    And I click on "[title='Star this discussion']" "css_element"
    And I click on "Discussion 1" action menu
    And I click on "[title='Unstar this discussion']" "css_element"
