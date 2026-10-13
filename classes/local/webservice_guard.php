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

use moodle_exception;

/**
 * Refuses web service functions that would bypass the customised signup form.
 *
 * auth_email_signup_user is declared ajax + loginrequired=false, so anyone can call it anonymously through
 * lib/ajax/service-nologin.php. It runs none of the signup form callbacks: the caller could pick any
 * username and skip every field the form enforces. Self-registration stays available on login/signup.php.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webservice_guard {
    /** @var string[] Web service functions that must not be executed. */
    public const BLOCKED_FUNCTIONS = [
        'auth_email_signup_user',
    ];

    /**
     * Throw if the given web service function is blocked.
     *
     * @param string $functionname web service function name, e.g. auth_email_signup_user
     * @throws moodle_exception
     */
    public static function check(string $functionname): void {
        if (in_array($functionname, self::BLOCKED_FUNCTIONS, true)) {
            throw new moodle_exception('webservicesignupdisabled', 'local_alborhansignup');
        }
    }
}
