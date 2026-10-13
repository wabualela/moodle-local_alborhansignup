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
 * Tests for the before_user_updated hook callback.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
final class hook_callbacks_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Create a user whose phone1 holds a value stored before this plugin normalised numbers.
     *
     * The generator triggers user_created, which normalises phone1, so the legacy value is written directly.
     *
     * @param string $phone legacy phone1 value
     * @return \stdClass user record
     */
    private function create_user_with_legacy_phone(string $phone): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'phone1', $phone, ['id' => $user->id]);
        return $user;
    }

    /**
     * A changed, valid phone number is stored in E.164.
     */
    public function test_changed_number_is_normalised(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['country' => 'SA']);

        user_update_user((object) ['id' => $user->id, 'phone1' => '050 123 4567'], false, false);

        $this->assertSame('+966501234567', $DB->get_field('user', 'phone1', ['id' => $user->id]));
    }

    /**
     * The user's country is the hint for numbers without an international prefix.
     */
    public function test_country_hint(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['country' => 'EG']);

        user_update_user((object) ['id' => $user->id, 'phone1' => '01001234567'], false, false);

        $this->assertSame('+201001234567', $DB->get_field('user', 'phone1', ['id' => $user->id]));
    }

    /**
     * Unchanged legacy values, invalid values and empty values are stored as they are.
     */
    public function test_other_values_are_kept(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->create_user_with_legacy_phone('0501234567');

        // Saving the profile without touching the phone keeps the legacy value.
        user_update_user((object) ['id' => $user->id, 'phone1' => '0501234567', 'city' => 'Jeddah'], false, false);
        $this->assertSame('0501234567', $DB->get_field('user', 'phone1', ['id' => $user->id]));

        user_update_user((object) ['id' => $user->id, 'phone1' => '12345'], false, false);
        $this->assertSame('12345', $DB->get_field('user', 'phone1', ['id' => $user->id]));

        user_update_user((object) ['id' => $user->id, 'phone1' => ''], false, false);
        $this->assertSame('', $DB->get_field('user', 'phone1', ['id' => $user->id]));
    }

    /**
     * Updates that do not include phone1 are not affected.
     */
    public function test_update_without_phone(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->create_user_with_legacy_phone('0501234567');

        user_update_user((object) ['id' => $user->id, 'city' => 'Jeddah'], false, false);

        $this->assertSame('0501234567', $DB->get_field('user', 'phone1', ['id' => $user->id]));
    }
}
