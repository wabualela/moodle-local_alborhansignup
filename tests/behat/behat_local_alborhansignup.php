<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat steps for local_alborhansignup.
 *
 * @package    local_alborhansignup
 * @category   test
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps for the intl-tel-input country picker added to phone fields.
 *
 * @package    local_alborhansignup
 * @category   test
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_alborhansignup extends behat_base {
    /**
     * Choose a country in the country picker of a phone field.
     *
     * The country is given by its ISO 3166-1 alpha-2 code, so the step does not depend on the interface language.
     *
     * @When I select the :iso2 country in the :field phone field
     * @param string $iso2 country code, e.g. "ae"
     * @param string $field field locator: label, id or name of the phone input
     */
    public function i_select_the_country_in_the_phone_field(string $iso2, string $field): void {
        $this->require_javascript();

        $input = $this->find_field($field);
        // The library moves the input into a div.iti wrapper that also holds the selected-country button.
        $wrapper = $input->find('xpath', "/ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' iti ')][1]");
        if (!$wrapper) {
            throw new ExpectationException("The field \"{$field}\" has no country picker", $this->getSession());
        }
        $wrapper->find('css', 'button.iti__selected-country')->click();
        $this->wait_for_pending_js();

        // In dropdown mode the list is inside the wrapper; in fullscreen mode (viewport <= 500px) or with the
        // dropdownParent option it is appended elsewhere, so look in the whole page.
        $iso2 = strtolower(clean_param($iso2, PARAM_ALPHA));
        $this->find('css', "li.iti__country[data-iso2='{$iso2}']")->click();
        $this->wait_for_pending_js();
    }

    /**
     * Replace the number of a phone field, as if the user typed it into an emptied field.
     *
     * Typing over a value that starts with "+" is unreliable with the WebDriver clearing keys (the picker re-adds the
     * dial code), so the number is set through the library, which fires the same "input" event as typing.
     *
     * @When I set the :field phone field to :number
     * @param string $field field locator: label, id or name of the phone input
     * @param string $number number to enter, e.g. "+966501234567"
     */
    public function i_set_the_phone_field_to(string $field, string $number): void {
        $this->require_javascript();

        $id = $this->find_field($field)->getAttribute('id');
        $script = '(function(input) {' .
            ' window.intlTelInput.getInstance(input).setNumber(' . json_encode($number) . ');' .
            '})(document.getElementById(' . json_encode($id) . '));';
        $this->execute_script($script);
        $this->wait_for_pending_js();
    }

    /**
     * Check the number the phone field would submit, in E.164.
     *
     * @Then the :field phone field should contain the number :number
     * @param string $field field locator: label, id or name of the phone input
     * @param string $number expected E.164 number, e.g. "+971501234567"
     */
    public function the_phone_field_should_contain_the_number(string $field, string $number): void {
        $this->require_javascript();

        $id = $this->find_field($field)->getAttribute('id');
        $script = 'return (function(input) {' .
            ' var iti = input && window.intlTelInput && window.intlTelInput.getInstance(input);' .
            ' return iti ? iti.getNumber() : null;' .
            '})(document.getElementById(' . json_encode($id) . '));';
        $actual = $this->evaluate_script($script);
        if ($actual !== $number) {
            throw new ExpectationException(
                "The phone field \"{$field}\" contains the number \"" . var_export($actual, true) . "\", expected \"{$number}\"",
                $this->getSession()
            );
        }
    }
}
