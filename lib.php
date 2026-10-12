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
 * Callback implementations for local_alborhansignup.
 *
 * Adjusts the core email self-registration form (login/signup.php):
 *  - custom profile fields render inline with the core fields (no collapsible
 *    category header) and all fields follow LOCAL_ALBORHANSIGNUP_ORDER;
 *  - the username field is hidden and the username is derived server-side from
 *    the e-mail address (lower-cased) in the post-signup hook, exactly like the
 *    former auth_flex signup.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Desired display order of the signup fields. Elements missing from the form are skipped;
 * the last existing element of this list keeps its position and the others are placed before it.
 */
define('LOCAL_ALBORHANSIGNUP_ORDER', [
    'firstname',
    'lastname',
    'profile_field_certname',
    'email',
    'email2',
    'passwordpolicyinfo',
    'password',
    'profile_field_gender',
    'city',
    'country',
]);

/**
 * Extend the signup form. Called by core_login_extend_signup_form() after
 * profile_signup_fields() has added the category headers and profile fields.
 *
 * @param MoodleQuickForm $mform
 */
function local_alborhansignup_extend_signup_form($mform): void {
    global $DB;

    // 1. Drop every profile-category header so profile fields join the core fieldset.
    $categoryids = $DB->get_fieldset_select('user_info_category', 'id', '');
    foreach ($categoryids as $categoryid) {
        $header = 'category_' . $categoryid;
        if ($mform->elementExists($header)) {
            $mform->removeElement($header);
        }
    }

    // 2. Re-order the fields.
    local_alborhansignup_reorder($mform, LOCAL_ALBORHANSIGNUP_ORDER);

    // 3. Hide the username field; the username is derived from the e-mail address.
    local_alborhansignup_hide_username($mform);
}

/**
 * Arrange the given elements in the given order, keeping the last existing one in place.
 *
 * @param MoodleQuickForm $mform
 * @param string[] $order element names in the desired display order
 */
function local_alborhansignup_reorder($mform, array $order): void {
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
        // insertElementBefore() stores a reference to $element; break it before the next iteration.
        unset($element);
        $next = $name;
    }
}

/**
 * Replace the visible username element with a hidden, empty one.
 *
 * The real username is set in local_alborhansignup_post_signup_requests(): core calls
 * that hook after validation and right before auth_email::user_signup(). Rewriting the
 * submitted value here (QuickForm filters) does NOT work because moodleform runs
 * definition() first and then _process_submission() -> updateSubmission(), which
 * replaces the whole submitted-values array with a cleaned copy of $_POST.
 *
 * @param MoodleQuickForm $mform
 */
function local_alborhansignup_hide_username($mform): void {
    if (!$mform->elementExists('username') || !$mform->elementExists('email')) {
        return;
    }
    // Drops the element and its "required" rule (removeRules = true). Core's
    // signup_validate_data() does not reject an empty username by itself, so the
    // duplicate check moves to local_alborhansignup_validate_extend_signup_form().
    $mform->removeElement('username');
    $mform->addElement('hidden', 'username', '');
    $mform->setType('username', PARAM_RAW);
}

/**
 * Post-signup hook (core_login_post_signup_requests): set the username from the
 * e-mail address before the auth plugin creates the account.
 *
 * @param stdClass $user validated signup data (object, modified in place)
 */
function local_alborhansignup_post_signup_requests($user): void {
    if (!is_object($user) || empty($user->email)) {
        return;
    }
    if (!isset($user->username) || trim((string)$user->username) === '') {
        $user->username = local_alborhansignup_username_from_email($user->email);
    }
}

/**
 * Username derived from an e-mail address (same rule auth_flex used).
 *
 * @param string $email
 * @return string
 */
function local_alborhansignup_username_from_email(string $email): string {
    // clean_field() lower-cases and, when $CFG->extendedusernamechars is off, strips
    // characters Moodle would otherwise reject in user_create_user().
    return core_user::clean_field(core_text::strtolower(trim($email)), 'username');
}

/**
 * Extra signup validation. Called by core_login_validate_extend_signup_form().
 *
 * Because the username element is hidden, a core "usernameexists" error would be
 * invisible to the user; report the same condition under the e-mail field instead.
 *
 * @param array $data submitted form data
 * @return array element name => error message
 */
function local_alborhansignup_validate_extend_signup_form($data): array {
    global $CFG, $DB;

    $errors = [];
    // Leave malformed addresses to core's own "invalid e-mail" message.
    if (empty($data['email']) || !validate_email($data['email'])) {
        return $errors;
    }
    $username = local_alborhansignup_username_from_email($data['email']);
    if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
        $errors['email'] = get_string('emailaccountexists', 'local_alborhansignup');
    }
    return $errors;
}
