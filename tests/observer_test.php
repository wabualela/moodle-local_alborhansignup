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

/**
 * Tests for the user_created observer.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Create an account the way admin tools do, triggering user_created.
     *
     * @param array $fields user fields
     * @return int user id
     */
    private function create_account(array $fields): int {
        global $CFG;
        static $count = 0;
        $count++;
        return user_create_user((object) ($fields + [
            'username' => 'observer' . $count,
            'firstname' => 'First',
            'lastname' => 'Last',
            'email' => 'observer' . $count . '@example.com',
            'mnethostid' => $CFG->mnet_localhost_id,
            'confirmed' => 1,
        ]));
    }

    /**
     * A valid phone number of a new account is stored in E.164.
     */
    public function test_new_account_number_is_normalised(): void {
        global $DB;
        $this->resetAfterTest();

        $userid = $this->create_account(['phone1' => '0501234567', 'country' => 'SA']);

        $this->assertSame('+966501234567', $DB->get_field('user', 'phone1', ['id' => $userid]));
    }

    /**
     * Invalid and empty phone numbers are left as they are.
     */
    public function test_other_values_are_kept(): void {
        global $DB;
        $this->resetAfterTest();

        $invalid = $this->create_account(['phone1' => '12345']);
        $empty = $this->create_account([]);

        $this->assertSame('12345', $DB->get_field('user', 'phone1', ['id' => $invalid]));
        $this->assertSame('', $DB->get_field('user', 'phone1', ['id' => $empty]));
    }
}
