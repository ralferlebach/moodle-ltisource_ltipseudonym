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

  Scenario: Remove is shown as absent only where the tool does not share the claim, and LTI 1.x tools as not covered
    Given the following "mod_lti > tool types" exist:
      | name         | lti_typename | baseurl                          | lti_ltiversion | lti_sendname | lti_coursevisible |
      | Quiet tool   | Quiet tool   | https://quiet.example.com/launch | 1.3.0          | 0            | 1                 |
      | Sharing tool | Sharing tool | https://share.example.com/launch | 1.3.0          | 1            | 1                 |
      | Old tool     | Old tool     | https://old.example.com/launch   | LTI-1p0        | 1            | 1                 |
    And I log in as "admin"
    And I visit "/mod/lti/source/ltipseudonym/policy.php"
    Then I should see "Not covered by the policies (LTI 1.0/1.1/2.0): Old tool"
    And I should see "What a tool receives per category. Pass:"
    When I click on "Edit" "link" in the "Sharing tool" "table_row"
    And I set the field "Pseudonym type" to "Random token (persisted) - Identifiability: low"
    And I set the field "Names" to "Remove (claim absent; the launch is refused while Moodle core still builds it)"
    And I set the field "I understand that this changes the identity delivered to the tool" to "1"
    And I press "Save changes"
    Then I should see "Removal is impossible: the tool registration makes Moodle core send this claim"
    And I visit "/mod/lti/source/ltipseudonym/policy.php"
    And I click on "Edit" "link" in the "Quiet tool" "table_row"
    And I set the field "Pseudonym type" to "Random token (persisted) - Identifiability: low"
    And I set the field "Names" to "Remove (claim absent; the launch is refused while Moodle core still builds it)"
    And I set the field "I understand that this changes the identity delivered to the tool" to "1"
    And I press "Save changes"
    And I visit "/mod/lti/source/ltipseudonym/policy.php"
    Then I should see "Names: remove, absent (the tool registration does not share it)" in the "Quiet tool" "table_row"
    And I should not see "Names: remove" in the "Sharing tool" "table_row"
