@mod @mod_digestforum
Feature: Students can edit or delete their digestforum posts within a set time limit
  In order to refine digestforum posts
  As a user
  I need to edit or delete my digestforum posts within a certain period of time after posting

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity   | name                   | intro                   | course  | idnumber  |
      | digestforum      | Test digestforum name        | Test digestforum description  | C1      | digestforum     |
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And I add a new discussion to "Test digestforum name" digestforum with:
      | Subject | Forum post subject |
      | Message | This is the body |

  Scenario: Edit digestforum post
    Given I follow "Forum post subject"
    And I follow "Edit"
    When I set the following fields to these values:
      | Subject | Edited post subject |
      | Message | Edited post body |
    And I press "Save changes"
    And I wait to be redirected
    Then I should see "Edited post subject"
    And I should see "Edited post body"

  Scenario: Delete digestforum post
    Given I follow "Forum post subject"
    When I follow "Delete"
    And I press "Continue"
    Then I should not see "Forum post subject"

  @javascript @block_recent_activity
  Scenario: Time limit expires
    Given I log out
    And I log in as "admin"
    And I navigate to "Security > Site security settings" in site administration
    And I set the field "Maximum time to edit posts" to "1 minutes"
    And I press "Save changes"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "Recent activity" block
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And I should see "New digestforum posts:" in the "Recent activity" "block"
    And I should see "Forum post subject" in the "Recent activity" "block"
    When I wait "61" seconds
    And I follow "Forum post subject"
    Then I should not see "Edit" in the "region-main" "region"
    And I should not see "Delete" in the "region-main" "region"
