@local @local_alborhansignup
Feature: Mobile phone country picker on the profile editing form
  In order to keep phone numbers consistent
  As an administrator editing a user
  I need to change the phone number with the country picker and have it stored in E.164

  Background:
    Given the following config values are set as admin:
      | showuseridentity | email,phone1 |

  @javascript
  Scenario: The profile editing form shows the stored number in the picker
    Given the following "users" exist:
      | username | firstname | lastname | email                | phone1        |
      | student1 | Sam       | One      | student1@example.com | +971501234567 |
    When I am on the "student1" "user > editing" page logged in as "admin"
    # The phone field is in the collapsed "Optional" section of the profile form.
    And I expand all fieldsets
    Then the "class" attribute of ".iti__selected-country .iti__flag" "css_element" should contain "iti__ae"
    And the field "phone1" matches value "+971 50 123 4567"
    And the "phone1" phone field should contain the number "+971501234567"
    And I set the "phone1" phone field to "+966501234567"
    And the "class" attribute of ".iti__selected-country .iti__flag" "css_element" should contain "iti__sa"
    And I press "Update profile"
    And I navigate to "Users > Accounts > Browse list of users" in site administration
    And I should see "+966501234567" in the "student1@example.com" "table_row"

  @javascript
  Scenario: Saving the profile without touching the phone keeps the stored value
    # An invalid legacy value (the user_created observer leaves it as it is) that the picker displays differently
    # ("+966 5012345"): it must be saved exactly as stored.
    Given the following "users" exist:
      | username | firstname | lastname | email                | city | phone1      |
      | student2 | Sam       | Two      | student2@example.com | Riga | +9665012345 |
    When I am on the "student2" "user > editing" page logged in as "admin"
    And I set the field "City/town" to "Jeddah"
    And I press "Update profile"
    And I navigate to "Users > Accounts > Browse list of users" in site administration
    Then I should see "+9665012345" in the "student2@example.com" "table_row"
    And I should not see "+966 5012345"
