@mod @mod_digestforum
Feature: QandA digestforum discussion type
  In order to let students first see other replies to a post after replying themselves
  As a teacher
  I need to create a digestforum of qand a type

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | name          | intro                     | type  | course | idnumber | showimmediately |
      | digestforum    | Q and A digest forum | Q and A digest forum description | qanda | C1     | digestforum    | 0               |
    And the following "mod_digestforum > discussions" exist:
      | digestforum | name         | subject      | message                              | user     |
      | digestforum | Discussion 1 | Discussion 1 | Discussion contents 1, first message | student1 |
      | digestforum | Discussion 2 | Discussion 2 | Discussion contents 2, first message | teacher1 |
    And the following "mod_digestforum > posts" exist:
      | parentsubject | subject                 | message                               | user     |
      | Discussion 1  | Reply 1 to discussion 1 | Discussion contents 1, second message | student2 |
      | Discussion 2  | Reply 1 to discussion 2 | Discussion contents 2, second message | student2 |

  Scenario: Students can see all replies to own discussions
    When I am on the "Q and A digest forum" "digestforum activity" page logged in as student1
    And I follow "Discussion 1"
    Then I should see "Reply 1 to discussion 1"

  Scenario: Teachers can see all replies
    When I am on the "Q and A digest forum" "digestforum activity" page logged in as teacher1
    And I follow "Discussion 1"
    Then I should see "Reply 1 to discussion 1"

  Scenario: Students can't see replies to discussions from other users they didn't reply to yet
    When I am on the "Q and A digest forum" "digestforum activity" page logged in as student1
    And I follow "Discussion 2"
    Then I should not see "Reply 1 to discussion 2"

  Scenario: Students can't see replies to discussions from other users when they have replied but they are within the maximum editing time
    Given the following "mod_digestforum > posts" exist:
      | parentsubject | subject                 | message                              | user     |
      | Discussion 2  | Reply 2 to discussion 1 | Discussion contents 2, third message | student1 |
    When I am on the "Q and A digest forum" "digestforum activity" page logged in as student1
    And I follow "Discussion 2"
    Then I should not see "Reply 1 to discussion 2"

  Scenario: Students can see replies to discussions from other users when they have replied and they can't edit the post anymore
    Given the following "mod_digestforum > posts" exist:
      | parentsubject | subject                 | message                              | user     | created           |
      | Discussion 2  | Reply 2 to discussion 2 | Discussion contents 2, third message | student1 | ##now +1 second## |
    And the following config values are set as admin:
      | maxeditingtime | 1 |
    And I wait "2" seconds
    When I am on the "Q and A digest forum" "digestforum activity" page logged in as student1
    And I follow "Discussion 2"
    Then I should see "Reply 1 to discussion 2"

  Scenario: Students can see replies to discussions from other users when they have replied regardless of editing time when showimmediately option is set
    Given the following "activities" exist:
      | activity | name                          | intro                     | type  | course | idnumber | showimmediately |
      | digestforum    | Q and A digest forum showimmediately | Q and A digest forum description | qanda | C1     | digestforum2   | 1               |
    And the following "mod_digestforum > discussions" exist:
      | digestforum  | name         | subject      | message                              | user     |
      | digestforum2 | Discussion 3 | Discussion 3 | Discussion contents 3, first message | teacher1 |
    And the following "mod_digestforum > posts" exist:
      | parentsubject | subject                 | message                               | user     |
      | Discussion 3  | Reply 1 to discussion 3 | Discussion contents 3, second message | student2 |
      | Discussion 3  | Reply 2 to discussion 3 | Discussion contents 3, second message | student1 |
    When I am on the "Q and A digest forum showimmediately" "digestforum activity" page logged in as student1
    And I follow "Discussion 3"
    Then I should see "Reply 1 to discussion 3"
