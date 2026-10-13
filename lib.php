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
 * Core calls these legacy callbacks by name (login/signup.php and the web service layer); the logic lives in
 * {@see \local_alborhansignup\local\form_customiser}, {@see \local_alborhansignup\local\webservice_guard} and
 * {@see \local_alborhansignup\local\profile_privacy}.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_alborhansignup\local\form_customiser;
use local_alborhansignup\local\profile_privacy;
use local_alborhansignup\local\webservice_guard;

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

/**
 * Web service override (external_api::call_external_function() and webservice_base_server::execute()).
 *
 * Never replaces a function; throws for the functions blocked by webservice_guard.
 *
 * @param stdClass $externalfunctioninfo the function being called
 * @param array $params validated parameters
 * @return bool always false, so core runs the real function
 */
function local_alborhansignup_override_webservice_execution($externalfunctioninfo, $params): bool {
    webservice_guard::check((string) ($externalfunctioninfo->name ?? ''));
    return false;
}

/**
 * Profile access callback (user_can_view_profile()): hides contact details on theme_remui profile pages from other users.
 *
 * @param stdClass $user the profile owner
 * @param stdClass|null $course course of the profile page, if any
 * @param context|null $usercontext context of the profile owner
 * @return int one of the \core\user::VIEWPROFILE_* constants
 */
function local_alborhansignup_control_view_profile($user, $course = null, $usercontext = null): int {
    return profile_privacy::control_view_profile();
}

/**
 * Security checks (Site administration > Reports > Security checks).
 *
 * @return \core\check\check[]
 */
function local_alborhansignup_security_checks(): array {
    return [new \local_alborhansignup\check\profileprivacy()];
}
