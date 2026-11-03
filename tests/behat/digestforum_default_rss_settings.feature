@mod @mod_digestforum @core_rss
Feature: Verify that default RSS settings for digestforums are correctly applied.
  In order to ensure consistent RSS configuration across new digestforums
  As an admin
  I need to verify that the default RSS values set at site level appear when creating a new digestforum.

  Background:
    Given the following "courses" exist:
      | fullname        | shortname |
      | RSS Test Course | RSS101    |
    And the following config values are set as admin:
      | enablerssfeeds       | 1 |
      | digestforum_enablerssfeeds | 1 |
      | digestforum_rsstype        | 1 |
      | digestforum_rssarticles    | 5 |

  Scenario: Admin sets default digestforum RSS values and verifies them on new digestforum creation
    Given I log in as "admin"
    When I am on "RSS101" course homepage with editing mode on
    And I add a digestforum activity to course "RSS101" section "New section"
    And I expand all fieldsets
    Then the field "RSS feed for this activity" matches value "Discussions"
    And the field "Number of RSS recent articles" matches value "5"
