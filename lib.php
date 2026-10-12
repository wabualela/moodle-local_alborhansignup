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
 * Core calls these legacy callbacks by name from login/signup.php; the logic lives in
 * {@see \local_alborhansignup\local\form_customiser}.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_alborhansignup\local\form_customiser;

/**
 * Extend the signup form (core_login_extend_signup_form()).
 *
 * @param MoodleQuickForm $mform
 */
function local_alborhansignup_extend_signup_form(MoodleQuickForm $mform): void {
    form_customiser::extend($mform);
}

/**
 * Extra signup validation (core_login_validate_extend_signup_form()).
 *
 * @param array $data submitted form data
 * @return array element name => error message
 */
function local_alborhansignup_validate_extend_signup_form(array $data): array {
    return form_customiser::validate($data);
}

/**
 * Post-signup hook (core_login_post_signup_requests()), run before the account is created.
 *
 * @param stdClass $user validated signup data (modified in place)
 */
function local_alborhansignup_post_signup_requests($user): void {
    if ($user instanceof stdClass) {
        form_customiser::post_signup($user);
    }
}
