@mod @mod_digestforum
Feature: Digest forum: New discussions and discussions with recently added replies are displayed first
  In order to use digestforum as a discussion tool
  As a user
  I need to see currently active discussions first

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname  | email                 |
      | teacher1  | Teacher   | 1         | teacher1@example.com  |
      | student1  | Student   | 1         | student1@example.com  |
    And the following "courses" exist:
      | fullname  | shortname | category  |
      | Course 1  | C1        | 0         |
    And the following "course enrolments" exist:
      | user      | course    | role            |
      | teacher1  | C1        | editingteacher  |
      | student1  | C1        | student         |
    And the following "activity" exists:
      | course   | C1                   |
      | activity | digestforum                |
      | name     | Course general digestforum |
      | idnumber | digestforum1               |
    #
    # Add three posts into the blog.
    #
    And the following "mod_digestforum > discussions" exist:
      | user     | digestforum  | name         | message                 | timemodified      |
      | student1 | digestforum1 | Forum post 1 | This is the first post  | ##now +1 second## |
      | student1 | digestforum1 | Forum post 2 | This is the second post | ##now +2 second## |
      | student1 | digestforum1 | Forum post 3 | This is the third post  | ##now +3 second## |

  #
  # We need javascript/wait to prevent creation of the posts in the same second. The threads
  # would then ignore each other in the prev/next navigation as the Forum is unable to compute
  # the correct order.
  #
  @javascript
  Scenario: Replying to a digestforum post or editing it puts the discussion to the front
    Given I am on the "Course general digestforum" "digestforum activity" page logged in as student1
    #
    # Edit one of the digestforum posts.
    #
    And I follow "Forum post 2"
    And I click on "Edit" "link" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' digestforumpost ')][contains(., 'Forum post 2')]" "xpath_element"
    And I set the following fields to these values:
      | Subject | Edited digestforum post 2     |
    And I press "Save changes"
    And I wait to be redirected
    #
    # Reply to another digestforum post.
    #
    And I am on the "Course general digestforum" "digestforum activity" page logged in as teacher1
    And I follow "Forum post 1"
    When I reply "Forum post 1" post from "Course general digestforum" digestforum with:
      | Message | Reply to the first post |
    And I am on the "Course general digestforum" "digestforum activity" page
    #
    # Make sure the order of the digestforum posts is as expected, with most recent new participation first (ie excluding edits).
    #
    Then I should see "Forum post 1" in the "//tr[contains(concat(' ', normalize-space(@class), ' '), ' discussion ')][position()=1]" "xpath_element"
    And I should see "Forum post 3" in the "//tr[contains(concat(' ', normalize-space(@class), ' '), ' discussion ')][position()=2]" "xpath_element"
    And I should see "Edited digestforum post 2" in the "//tr[contains(concat(' ', normalize-space(@class), ' '), ' discussion ')][position()=3]" "xpath_element"
    #
    # Make sure the next/prev navigation uses the same order of the posts.
    #
    And I follow "Forum post 3"
    And "//a[@aria-label='Next discussion: Forum post 1']" "xpath_element" should exist
    And "//a[@aria-label='Previous discussion: Edited digestforum post 2']" "xpath_element" should exist
