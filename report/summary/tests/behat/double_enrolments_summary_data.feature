@mod @mod_digestforum @digestforumreport @digestforumreport_summary
Feature: digestforum report shows post/reply/word counts correctly
  In order to gather data on users' digestforum activities
  As a teacher
  I need to view accurate digestforum summary report when students have more than 1 enrolment

  Scenario: Add discussions and replies with attached files
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
      | Course 2 | C2        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           | enrol  |
      | teacher1 | C1     | editingteacher | manual |
      | student1 | C1     | student        | manual |
      | student1 | C1     | student        | self   |
      | student2 | C1     | student        | manual |
      | student2 | C1     | student        | self   |
      | teacher1 | C2     | editingteacher | manual |
    And the following "activities" exist:
      | activity | name   | course | idnumber |
      | digestforum    | digestforum1 | C1     | digestforum1   |
      | digestforum    | digestforum2 | C1     | digestforum2   |
      | digestforum    | digestforum1 | C2     | digestforum1   |
    And the following digestforum discussions exist in course "Course 1":
      | user     | digestforum  | name        | message     | attachments        | inlineattachments |
      | teacher1 | digestforum1 | discussion1 | message1    | att1.jpg, att2.txt |                   |
      | teacher1 | digestforum2 | discussion2 | message2    | att3.jpg           | in1.jpg           |
      | student1 | digestforum1 | discussion3 | my message3 | att4.jpg           | in2.jpg           |
      | student2 | digestforum1 | discussion4 | my message4 |                    |                   |
    And the following digestforum replies exist in course "Course 1":
      | user     | digestforum  | discussion  | message   | attachments        | inlineattachments |
      | teacher1 | digestforum1 | discussion1 | reply1    | att5.jpg           | in3.txt           |
      | teacher1 | digestforum1 | discussion1 | reply2    | att5.jpg           | in3.txt           |
      | teacher1 | digestforum2 | discussion2 | reply2    | att6.jpg           |                   |
      | student1 | digestforum1 | discussion3 | my reply3 | att7.jpg, att8.jpg | in2.jpg           |
      | student2 | digestforum1 | discussion4 | my reply4 |                    |                   |
      | student2 | digestforum1 | discussion4 | my reply5 |                    |                   |
    And the following digestforum discussions exist in course "Course 2":
      | user     | digestforum  | name        | message  | attachments        | inlineattachments |
      | teacher1 | digestforum1 | discussion1 | message1 | att1.jpg, att2.txt |                   |
    When I am on the "Course 1" course page logged in as teacher1
    And I follow "digestforum1"
    And I navigate to "Reports" in current page administration
    Then "Teacher 1" row "Number of attachments" column of "digestforumreport_summary_table" table should contain "6"
    And "Student 1" row "Number of attachments" column of "digestforumreport_summary_table" table should contain "5"
    And "Student 1" row "Word count" column of "digestforumreport_summary_table" table should contain "4"
    And "Student 1" row "Character count" column of "digestforumreport_summary_table" table should contain "18"
    And "Student 1" row "Number of discussions posted" column of "digestforumreport_summary_table" table should contain "1"
    And "Student 1" row "Number of replies posted" column of "digestforumreport_summary_table" table should contain "1"
    And "Student 2" row "Number of attachments" column of "digestforumreport_summary_table" table should contain "0"
    And "Student 2" row "Word count" column of "digestforumreport_summary_table" table should contain "6"
    And "Student 2" row "Character count" column of "digestforumreport_summary_table" table should contain "26"
    And "Student 2" row "Number of discussions posted" column of "digestforumreport_summary_table" table should contain "1"
    And "Student 2" row "Number of replies posted" column of "digestforumreport_summary_table" table should contain "2"
