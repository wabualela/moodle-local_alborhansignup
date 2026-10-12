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

namespace local_alborhansignup\local;

use MoodleQuickForm;

/**
 * Unit tests for the signup form customiser.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(form_customiser::class)]
final class form_customiser_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Create an empty form to manipulate.
     *
     * @return MoodleQuickForm
     */
    private function create_form(): MoodleQuickForm {
        return new MoodleQuickForm('test', 'post', '');
    }

    /**
     * Names of the form elements, in display order, restricted to the given names.
     *
     * @param MoodleQuickForm $mform
     * @param string[] $only element names to keep
     * @return string[]
     */
    private function element_names(MoodleQuickForm $mform, array $only): array {
        $names = array_map(fn($element) => $element->getName(), $mform->_elements);
        return array_values(array_intersect($names, $only));
    }

    /**
     * The username is the trimmed, lower-cased e-mail address.
     *
     * @param string $email
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('username_from_email_provider')]
    public function test_username_from_email(string $email, string $expected): void {
        $this->assertSame($expected, form_customiser::username_from_email($email));
    }

    /**
     * Data provider for test_username_from_email().
     *
     * @return array
     */
    public static function username_from_email_provider(): array {
        return [
            'lower case is kept' => ['student@example.com', 'student@example.com'],
            'upper case is lowered' => ['Student.Name@Example.COM', 'student.name@example.com'],
            'surrounding spaces are trimmed' => ['  student@example.com ', 'student@example.com'],
        ];
    }

    /**
     * Characters Moodle rejects in usernames are stripped unless extended username characters are allowed.
     */
    public function test_username_from_email_extended_chars(): void {
        $this->resetAfterTest();

        set_config('extendedusernamechars', 0);
        $this->assertSame('ab@example.com', form_customiser::username_from_email('a+b@example.com'));

        set_config('extendedusernamechars', 1);
        $this->assertSame('a+b@example.com', form_customiser::username_from_email('a+b@example.com'));
    }

    /**
     * The last existing element keeps its place and the others are moved before it, in order.
     */
    public function test_reorder(): void {
        $mform = $this->create_form();
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $mform->addElement('text', $name, $name);
            $mform->setType($name, PARAM_RAW);
        }
        $mform->addRule('a', null, 'required', null, 'client');

        form_customiser::reorder($mform, ['c', 'missing', 'a', 'e']);

        $this->assertSame(['b', 'd', 'c', 'a', 'e'], $this->element_names($mform, ['a', 'b', 'c', 'd', 'e']));
        // Moving an element keeps its validation rules.
        $this->assertArrayHasKey('a', $mform->_rules);
    }

    /**
     * Fewer than two existing elements leaves the form untouched.
     */
    public function test_reorder_nothing_to_do(): void {
        $mform = $this->create_form();
        foreach (['a', 'b'] as $name) {
            $mform->addElement('text', $name, $name);
            $mform->setType($name, PARAM_RAW);
        }

        form_customiser::reorder($mform, ['b', 'missing']);

        $this->assertSame(['a', 'b'], $this->element_names($mform, ['a', 'b']));
    }

    /**
     * Only the headers added for custom profile field categories are removed.
     */
    public function test_remove_category_headers(): void {
        $mform = $this->create_form();
        $mform->addElement('header', 'category_1', 'Category one');
        $mform->addElement('header', 'category_22', 'Category two');
        $mform->addElement('header', 'otherheader', 'Other');
        $mform->addElement('text', 'category_3', 'Not a header');
        $mform->setType('category_3', PARAM_RAW);

        form_customiser::remove_category_headers($mform);

        $this->assertFalse($mform->elementExists('category_1'));
        $this->assertFalse($mform->elementExists('category_22'));
        $this->assertTrue($mform->elementExists('otherheader'));
        $this->assertTrue($mform->elementExists('category_3'));
    }

    /**
     * The username field becomes hidden, optional and fixed to an empty value.
     */
    public function test_hide_username(): void {
        $mform = $this->create_form();
        $mform->addElement('text', 'username', 'Username');
        $mform->setType('username', PARAM_RAW);
        $mform->addRule('username', null, 'required', null, 'client');
        $mform->addElement('text', 'email', 'Email');
        $mform->setType('email', PARAM_RAW);

        form_customiser::hide_username($mform);

        $this->assertSame('hidden', $mform->getElement('username')->getType());
        // The "required" rule is dropped (QuickForm keeps the name in _required, which only affects rendering).
        $this->assertArrayNotHasKey('username', $mform->_rules);
        // A posted value must never be able to choose the username.
        $this->assertSame('', $mform->_constantValues['username']);
    }

    /**
     * Without an e-mail field the username cannot be derived, so it stays visible.
     */
    public function test_hide_username_without_email(): void {
        $mform = $this->create_form();
        $mform->addElement('text', 'username', 'Username');
        $mform->setType('username', PARAM_RAW);

        form_customiser::hide_username($mform);

        $this->assertSame('text', $mform->getElement('username')->getType());
    }

    /**
     * An existing account for the e-mail address is reported under the e-mail field.
     */
    public function test_validate(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['username' => 'taken@example.com', 'email' => 'taken@example.com']);

        $errors = form_customiser::validate(['email' => 'Taken@Example.com']);
        $this->assertSame(['email' => get_string('emailaccountexists', 'local_alborhansignup')], $errors);

        $this->assertSame([], form_customiser::validate(['email' => 'free@example.com']));
    }

    /**
     * Missing or malformed addresses are left to core's own e-mail validation.
     */
    public function test_validate_ignores_invalid_email(): void {
        $this->assertSame([], form_customiser::validate([]));
        $this->assertSame([], form_customiser::validate(['email' => 'not-an-email']));
    }

    /**
     * The username is always derived from the e-mail address, whatever was submitted.
     */
    public function test_post_signup(): void {
        $user = (object) ['email' => 'New.Student@Example.com', 'username' => ''];
        form_customiser::post_signup($user);
        $this->assertSame('new.student@example.com', $user->username);

        $user = (object) ['email' => 'new.student@example.com', 'username' => 'support'];
        form_customiser::post_signup($user);
        $this->assertSame('new.student@example.com', $user->username);
    }

    /**
     * Without an e-mail address the user record is left untouched.
     */
    public function test_post_signup_without_email(): void {
        $user = (object) ['username' => 'someone'];
        form_customiser::post_signup($user);
        $this->assertSame('someone', $user->username);
    }
}
