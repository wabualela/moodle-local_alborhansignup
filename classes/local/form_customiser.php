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

use core\user;
use core_text;
use MoodleQuickForm;
use stdClass;

/**
 * Customisations of the core email self-registration form (login/signup.php).
 *
 *  - custom profile fields render inline with the core fields (no collapsible
 *    category header) and all fields follow {@see self::FIELD_ORDER};
 *  - the username field is hidden and the username is derived server-side from
 *    the e-mail address (lower-cased), exactly like the former auth_flex signup;
 *  - a required mobile phone number is collected in the core user field phone1 and stored in E.164.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class form_customiser {
    /**
     * Desired display order of the signup fields. Elements missing from the form are skipped;
     * the last existing element of this list keeps its position and the others are placed before it.
     */
    public const FIELD_ORDER = [
        'firstname',
        'lastname',
        'profile_field_certname',
        'email',
        'email2',
        'phone1',
        'passwordpolicyinfo',
        'password',
        'profile_field_gender',
        'city',
        'country',
    ];

    /**
     * Apply every customisation to the signup form. Called from the extend_signup_form callback,
     * after profile_signup_fields() has added the category headers and profile fields.
     *
     * @param MoodleQuickForm $mform
     */
    public static function extend(MoodleQuickForm $mform): void {
        self::remove_category_headers($mform);
        self::add_phone_field($mform);
        self::reorder($mform, self::FIELD_ORDER);
        self::hide_username($mform);
    }

    /**
     * Drop the headers profile_signup_fields() adds for each profile field category
     * ("category_<id>"), so the profile fields join the core fieldset.
     *
     * @param MoodleQuickForm $mform
     */
    public static function remove_category_headers(MoodleQuickForm $mform): void {
        $headers = [];
        foreach ($mform->_elements as $element) {
            $name = (string) $element->getName();
            if ($element->getType() === 'header' && preg_match('/^category_\d+$/', $name)) {
                $headers[] = $name;
            }
        }
        foreach ($headers as $name) {
            $mform->removeElement($name);
        }
    }

    /**
     * Add the required mobile phone field, mapped to the core user field phone1.
     *
     * Same element type and PARAM type as core's profile form (user/editlib.php); the value is validated in
     * {@see self::validate()} and stored in E.164 by {@see self::post_signup()}.
     *
     * @param MoodleQuickForm $mform
     */
    public static function add_phone_field(MoodleQuickForm $mform): void {
        if ($mform->elementExists('phone1')) {
            return;
        }
        // Room for a formatted number ("+966 50 123 4567"); the stored E.164 value is at most 16 characters.
        $mform->addElement('text', 'phone1', get_string('phone1', 'local_alborhansignup'), [
            'maxlength' => 30,
            'size' => 25,
            'autocomplete' => 'tel',
            'inputmode' => 'tel',
        ]);
        $mform->setType('phone1', user::get_property_type('phone1'));
        $mform->addRule('phone1', get_string('phone1required', 'local_alborhansignup'), 'required', null, 'client');
        $mform->setForceLtr('phone1');
    }

    /**
     * Arrange the given elements in the given order, keeping the last existing one in place.
     *
     * @param MoodleQuickForm $mform
     * @param string[] $order element names in the desired display order
     */
    public static function reorder(MoodleQuickForm $mform, array $order): void {
        $existing = array_values(array_filter($order, fn($name) => $mform->elementExists($name)));
        if (count($existing) < 2) {
            return;
        }
        // Walk backwards: move each element directly before the one that should follow it.
        $next = array_pop($existing);
        while (($name = array_pop($existing)) !== null) {
            // Keep validation rules and the "required" marker: removeRules = false.
            $element = $mform->removeElement($name, false);
            $mform->insertElementBefore($element, $next);
            // The insertElementBefore() call stores a reference to $element; break it before the next iteration.
            unset($element);
            $next = $name;
        }
    }

    /**
     * Replace the visible username element with a hidden one that is always empty.
     *
     * The real username is set in {@see self::post_signup()}: core calls that hook after
     * validation and right before auth_email::user_signup(). Rewriting the submitted value
     * here (QuickForm filters) does NOT work because moodleform runs definition() first and
     * then _process_submission() -> updateSubmission(), which replaces the whole
     * submitted-values array with a cleaned copy of $_POST.
     *
     * @param MoodleQuickForm $mform
     */
    public static function hide_username(MoodleQuickForm $mform): void {
        if (!$mform->elementExists('username') || !$mform->elementExists('email')) {
            return;
        }
        // Drops the element and its "required" rule (removeRules = true). Core's
        // signup_validate_data() does not reject an empty username by itself, so the
        // duplicate check moves to self::validate().
        $mform->removeElement('username');
        $mform->addElement('hidden', 'username', '');
        $mform->setType('username', PARAM_RAW);
        // Constants override submitted values, so a crafted POST cannot choose the username.
        $mform->setConstant('username', '');
    }

    /**
     * Extra signup validation. Called from the validate_extend_signup_form callback.
     *
     * @param array $data submitted form data
     * @return array element name => error message
     */
    public static function validate(array $data): array {
        return self::validate_email_account($data) + self::validate_phone($data);
    }

    /**
     * Because the username element is hidden, a core "usernameexists" error would be
     * invisible to the user; report the same condition under the e-mail field instead.
     *
     * @param array $data submitted form data
     * @return array element name => error message
     */
    private static function validate_email_account(array $data): array {
        global $CFG, $DB;

        // Leave missing or malformed addresses to core's own e-mail validation.
        if (empty($data['email']) || !validate_email($data['email'])) {
            return [];
        }
        $username = self::username_from_email($data['email']);
        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            return ['email' => get_string('emailaccountexists', 'local_alborhansignup')];
        }
        return [];
    }

    /**
     * The phone number must be a valid mobile number. An empty value is left to the "required" rule.
     *
     * @param array $data submitted form data
     * @return array element name => error message
     */
    private static function validate_phone(array $data): array {
        $raw = trim((string) ($data['phone1'] ?? ''));
        if ($raw === '' || phone::is_valid_mobile($raw, $data['country'] ?? null)) {
            return [];
        }
        return ['phone1' => get_string('phone1invalid', 'local_alborhansignup')];
    }

    /**
     * Post-signup step, run after validation and before the auth plugin creates the account.
     * Called from the post_signup_requests callback.
     *
     *  - the username is always derived from the e-mail address;
     *  - the phone number is stored in E.164.
     *
     * @param stdClass $user validated signup data (modified in place)
     */
    public static function post_signup(stdClass $user): void {
        if (!empty($user->email)) {
            $user->username = self::username_from_email($user->email);
        }
        if (isset($user->phone1)) {
            $e164 = phone::to_e164((string) $user->phone1, $user->country ?? null);
            if ($e164 !== null) {
                $user->phone1 = $e164;
            }
        }
    }

    /**
     * Username derived from an e-mail address (same rule auth_flex used).
     *
     * @param string $email
     * @return string
     */
    public static function username_from_email(string $email): string {
        // The clean_field() call lower-cases and, when $CFG->extendedusernamechars is off, strips
        // characters Moodle would otherwise reject in user_create_user().
        return user::clean_field(core_text::strtolower(trim($email)), 'username');
    }
}
