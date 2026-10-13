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

use core_external\external_api;

/**
 * Tests for the web service guard.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(webservice_guard::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_alborhansignup_override_webservice_execution')]
final class webservice_guard_test extends \advanced_testcase {
    /**
     * Blocked functions throw.
     */
    public function test_check_blocks_signup_user(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('webservicesignupdisabled', 'local_alborhansignup'));
        webservice_guard::check('auth_email_signup_user');
    }

    /**
     * The theme_remui inline profile editor service is blocked too.
     */
    public function test_check_blocks_remui_profile_save(): void {
        $this->expectException(\moodle_exception::class);
        webservice_guard::check('theme_remui_save_user_profile_settings');
    }

    /**
     * Other functions are left alone.
     */
    public function test_check_allows_other_functions(): void {
        webservice_guard::check('auth_email_get_signup_settings');
        webservice_guard::check('core_webservice_get_site_info');
        $this->assertNotContains('auth_email_get_signup_settings', webservice_guard::BLOCKED_FUNCTIONS);
    }

    /**
     * An anonymous AJAX call to auth_email_signup_user is refused and creates no account.
     */
    public function test_signup_user_web_service_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('registerauth', 'email');

        $response = external_api::call_external_function('auth_email_signup_user', [
            'username' => 'support',
            'password' => 'Passw0rd!',
            'firstname' => 'Fake',
            'lastname' => 'Support',
            'email' => 'attacker@example.com',
        ], true);

        $this->assertTrue($response['error']);
        $this->assertSame('webservicesignupdisabled', $response['exception']->errorcode);
        $this->assertFalse($DB->record_exists('user', ['username' => 'support']));
        $this->assertFalse($DB->record_exists('user', ['email' => 'attacker@example.com']));
    }

    /**
     * Other anonymous signup web services keep working.
     */
    public function test_signup_settings_web_service_still_works(): void {
        $this->resetAfterTest();
        set_config('registerauth', 'email');

        $response = external_api::call_external_function('auth_email_get_signup_settings', [], true);

        $this->assertFalse($response['error'], json_encode($response['exception'] ?? null));
    }
}
