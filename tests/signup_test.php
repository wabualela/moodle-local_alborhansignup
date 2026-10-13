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

namespace local_alborhansignup;

use local_alborhansignup\local\form_customiser;

/**
 * Integration tests: the plugin callbacks driven through the real core signup form and auth_email.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(form_customiser::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_alborhansignup_extend_signup_form')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_alborhansignup_validate_extend_signup_form')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_alborhansignup_post_signup_requests')]
final class signup_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/login/lib.php');
        require_once($CFG->dirroot . '/login/signup_form.php');
        require_once($CFG->dirroot . '/user/editlib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        parent::setUpBeforeClass();
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('registerauth', 'email');
    }

    /**
     * The QuickForm object behind a moodleform.
     *
     * @param \login_signup_form $form
     * @return \MoodleQuickForm
     */
    private function get_mform(\login_signup_form $form): \MoodleQuickForm {
        return (new \ReflectionProperty($form, '_form'))->getValue($form);
    }

    /**
     * Create the two custom profile fields the site shows on the signup form.
     */
    private function create_signup_profile_fields(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_custom_profile_field_category(['name' => 'Certificate']);
        $generator->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'certname',
            'name' => 'Name on certificate',
            'categoryid' => $category->id,
            'signup' => 1,
        ]);
        $generator->create_custom_profile_field([
            'datatype' => 'menu',
            'shortname' => 'gender',
            'name' => 'Gender',
            'categoryid' => $category->id,
            'signup' => 1,
            'param1' => "Male\nFemale",
        ]);
    }

    /**
     * Valid signup data, as posted by the browser.
     *
     * @param array $overrides
     * @return array
     */
    private function signup_data(array $overrides = []): array {
        return $overrides + [
            'username' => '',
            'password' => 'Passw0rd!',
            'email' => 'New.Student@Example.com',
            'email2' => 'New.Student@Example.com',
            'phone1' => '050 123 4567',
            'firstname' => 'New',
            'lastname' => 'Student',
            'city' => 'Riyadh',
            'country' => 'SA',
        ];
    }

    /**
     * Profile fields join the core fieldset, every field follows FIELD_ORDER and the username is hidden.
     */
    public function test_signup_form_layout(): void {
        $this->create_signup_profile_fields();

        $mform = $this->get_mform(new \login_signup_form());

        $names = array_map(fn($element) => (string) $element->getName(), $mform->_elements);
        $this->assertEmpty(preg_grep('/^category_\d+$/', $names));
        $required = ['firstname', 'lastname', 'profile_field_certname', 'email', 'email2', 'phone1', 'password',
            'profile_field_gender', 'city', 'country'];
        foreach ($required as $name) {
            $this->assertContains($name, $names);
        }
        $expected = array_values(array_intersect(form_customiser::FIELD_ORDER, $names));
        $this->assertSame($expected, array_values(array_intersect($names, form_customiser::FIELD_ORDER)));
        $this->assertSame('hidden', $mform->getElement('username')->getType());
    }

    /**
     * A full signup creates the account with the lower-cased e-mail address as username,
     * even when the hidden username field was tampered with, and the phone number in E.164.
     */
    public function test_signup_creates_account(): void {
        global $DB;

        $sink = $this->redirectEmails();
        \login_signup_form::mock_submit($this->signup_data(['username' => 'support']));
        $form = new \login_signup_form();
        $user = $form->get_data();
        $this->assertNotNull($user, 'Validation errors: ' . json_encode($this->get_mform($form)->_errors));
        $this->assertSame('', $user->username);

        $user = signup_setup_new_user($user);
        core_login_post_signup_requests($user);
        $this->assertTrue(get_auth_plugin('email')->user_signup($user, false));

        $created = $DB->get_record('user', ['email' => 'New.Student@Example.com'], '*', MUST_EXIST);
        $this->assertSame('new.student@example.com', $created->username);
        $this->assertSame('+966501234567', $created->phone1);
        $this->assertFalse($DB->record_exists('user', ['username' => 'support']));
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * An e-mail address that already has an account is rejected under the e-mail field.
     */
    public function test_signup_rejects_existing_account(): void {
        $this->getDataGenerator()->create_user([
            'username' => 'new.student@example.com',
            'email' => 'other@example.com',
        ]);

        \login_signup_form::mock_submit($this->signup_data());
        $form = new \login_signup_form();

        $this->assertNull($form->get_data());
        $errors = $this->get_mform($form)->_errors;
        $this->assertSame(get_string('emailaccountexists', 'local_alborhansignup'), $errors['email'] ?? null);
    }

    /**
     * The phone number is required.
     */
    public function test_signup_requires_phone(): void {
        \login_signup_form::mock_submit($this->signup_data(['phone1' => '']));
        $form = new \login_signup_form();

        $this->assertNull($form->get_data());
        $this->assertArrayHasKey('phone1', $this->get_mform($form)->_errors);
    }

    /**
     * A phone number that is not a valid mobile number is rejected under the phone field.
     */
    public function test_signup_rejects_invalid_phone(): void {
        \login_signup_form::mock_submit($this->signup_data(['phone1' => '0112345678']));
        $form = new \login_signup_form();

        $this->assertNull($form->get_data());
        $errors = $this->get_mform($form)->_errors;
        $this->assertSame(get_string('phone1invalid', 'local_alborhansignup'), $errors['phone1'] ?? null);
    }
}
