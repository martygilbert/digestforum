@mod @mod_digestforum @digestforumreport @digestforumreport_summary
Feature: Attachments count column data available
  In order to gather data on users' digestforum attachments
  As a teacher
  I need to view accurate attachment count data in the digestforum summary report

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
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C2     | editingteacher |
    And the following "activities" exist:
      | activity | name   | course | idnumber   |
      | digestforum    | digestforum1 | C1     | digestforum1C1   |
      | digestforum    | digestforum2 | C1     | digestforum2C1   |
      | digestforum    | digestforum1 | C2     | digestforum1C2   |
    And the following digestforum discussions exist in course "Course 1":
      | user     | digestforum  | name        | message  | attachments        | inlineattachments |
      | teacher1 | digestforum1 | discussion1 | message1 | att1.jpg, att2.txt |                   |
      | teacher1 | digestforum2 | discussion2 | message2 | att3.jpg           | in1.jpg           |
      | student1 | digestforum1 | discussion3 | message3 | att4.jpg           | in2.jpg           |
      | student2 | digestforum1 | discussion4 | message4 |                    |                   |
    And the following digestforum replies exist in course "Course 1":
      | user     | digestforum  | discussion  | message  | attachments        | inlineattachments |
      | teacher1 | digestforum1 | discussion1 | reply1   | att5.jpg           | in3.txt           |
      | teacher1 | digestforum1 | discussion1 | reply2   | att5.jpg           | in3.txt           |
      | teacher1 | digestforum2 | discussion2 | reply2   | att6.jpg           |                   |
      | student1 | digestforum1 | discussion3 | reply3   | att7.jpg, att8.jpg | in2.jpg           |
      | student2 | digestforum1 | discussion4 | reply4   |                    |                   |
    And the following digestforum discussions exist in course "Course 2":
      | user     | digestforum  | name        | message  | attachments        | inlineattachments |
      | teacher1 | digestforum1 | discussion1 | message1 | att1.jpg, att2.txt |                   |
    When I am on the digestforum1C1 "digestforum activity" page logged in as teacher1
    And I navigate to "Reports" in current page administration
    Then "Teacher 1" row "Number of attachments" column of "digestforumreport_summary_table" table should contain "6"
    And "Student 1" row "Number of attachments" column of "digestforumreport_summary_table" table should contain "5"
    And "Student 2" row "Number of attachments" column of "digestforumreport_summary_table" table should contain "0"
