@ltisource @ltisource_ltipseudonym
Feature: Pseudonym policy administration
  In order to protect learner identities towards LTI tools
  As an administrator
  I need a policy page that is capability protected and honest about its limits

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: The administrator sees the policy overview and the documented login_hint limitation
    When I log in as "admin"
    And I visit "/mod/lti/source/ltipseudonym/policy.php"
    Then I should see "Pseudonym policies"
    And I should see "Known limitation (gate SP-G1, open)"
    And I should see "not end-to-end pseudonymous"
    And I should see "Site default"

  Scenario: The administrator can edit the site default policy
    Given I log in as "admin"
    And I visit "/mod/lti/source/ltipseudonym/editpolicy.php"
    Then I should see "Edit pseudonym policy"
    And I should see "Known limitation (gate SP-G1, open)"
    And I should not see "Acknowledge that AGS and NRPS"

  Scenario: A teacher gets no site administration entry for the policy
    # policy.php without the capability ends in a required_capability_exception page, which Behat cannot assert.
    # The denial is covered by PHPUnit (ui_test, wiring_test); here the visible navigation is checked.
    When I log in as "teacher1"
    And I am on site homepage
    Then I should not see "Site administration"
    And I should not see "Pseudonym policies"
