@local @local_alborhansignup
Feature: Mobile phone number with a country picker
  In order to contact students by phone
  As a visitor creating an account or a user editing a profile
  I need to enter a valid mobile phone number that is stored in E.164

  Background:
    Given the following config values are set as admin:
      | registerauth     | email        |
      | passwordpolicy   | 0            |
      | showuseridentity | email,phone1 |

  Scenario Outline: The signup phone number is stored in E.164 without JavaScript
    Given I visit "/login/signup.php"
    When I set the following fields to these values:
      | Email address | <email>   |
      | Email (again) | <email>   |
      | Password      | Test-1234 |
      | First name    | Sara      |
      | Last name     | Ahmed     |
      | Mobile phone  | <typed>   |
    And I press "Create my new account"
    Then I should see "Confirm your account"
    And I log in as "admin"
    And I navigate to "Users > Accounts > Browse list of users" in site administration
    And I should see "<stored>" in the "<email>" "table_row"

    Examples:
      | email             | typed            | stored        |
      | sara1@example.com | 0501234567       | +966501234567 |
      | sara2@example.com | +971 50 123 4567 | +971501234567 |
      | sara3@example.com | ٠٥٠١٢٣٤٥٦٧       | +966501234567 |

  Scenario: A landline number is rejected on the server
    Given I visit "/login/signup.php"
    When I set the following fields to these values:
      | Email address | sara4@example.com |
      | Email (again) | sara4@example.com |
      | Password      | Test-1234         |
      | First name    | Sara              |
      | Last name     | Ahmed             |
      | Mobile phone  | 0112345678        |
    And I press "Create my new account"
    Then I should see "Enter a valid mobile phone number" in the "#id_error_phone1" "css_element"
    And I should not see "Confirm your account"

  @javascript
  Scenario: The country chosen in the picker is stored with the number
    Given I visit "/login/signup.php"
    And the "class" attribute of ".iti__selected-country .iti__flag" "css_element" should contain "iti__sa"
    When I set the following fields to these values:
      | Email address | sara5@example.com |
      | Email (again) | sara5@example.com |
      | Password      | Test-1234         |
      | First name    | Sara              |
      | Last name     | Ahmed             |
    And I select the "ae" country in the "Mobile phone" phone field
    And I set the field "Mobile phone" to "501234567"
    Then the "class" attribute of ".iti__selected-country .iti__flag" "css_element" should contain "iti__ae"
    And the "Mobile phone" phone field should contain the number "+971501234567"
    And I press "Create my new account"
    # With JavaScript only visible text counts; on Moodle 5.1 "Confirm your account" is only in the page title.
    And I should see "An email should have been sent to your address at"
    And I log in as "admin"
    And I navigate to "Users > Accounts > Browse list of users" in site administration
    And I should see "+971501234567" in the "sara5@example.com" "table_row"

  @javascript
  Scenario: An invalid mobile number is reported in the browser
    Given I visit "/login/signup.php"
    And I set the following fields to these values:
      | Email address | sara6@example.com |
      | Email (again) | sara6@example.com |
      | Password      | Test-1234         |
      | First name    | Sara              |
      | Last name     | Ahmed             |
    When I set the field "Mobile phone" to "50123"
    And I take focus off "Mobile phone" "field"
    Then I should see "Enter a valid mobile phone number" in the "#id_error_phone1" "css_element"
    And I start watching to see if a new page loads
    And I press "Create my new account"
    And a new page should not have loaded since I started watching
    And I should see "Enter a valid mobile phone number" in the "#id_error_phone1" "css_element"

  @javascript
  Scenario: A number typed with its international prefix selects the country
    Given I visit "/login/signup.php"
    When I set the field "Mobile phone" to "00971501234567"
    And I take focus off "Mobile phone" "field"
    Then the "class" attribute of ".iti__selected-country .iti__flag" "css_element" should contain "iti__ae"
    And the "Mobile phone" phone field should contain the number "+971501234567"
    And I should not see "Enter a valid mobile phone number"
