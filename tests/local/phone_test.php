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

/**
 * Tests for phone number validation and E.164 normalisation.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(phone::class)]
final class phone_test extends \advanced_testcase {
    /**
     * Typed numbers are normalised to E.164, or rejected.
     *
     * @param string $raw number as typed
     * @param string|null $country country hint
     * @param string|null $e164 expected E.164 number, null when invalid
     * @param bool $mobile expected result of is_valid_mobile()
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numbers_provider')]
    public function test_numbers(string $raw, ?string $country, ?string $e164, bool $mobile): void {
        $this->assertSame($e164, phone::to_e164($raw, $country));
        $this->assertSame($mobile, phone::is_valid_mobile($raw, $country));
    }

    /**
     * Data provider for test_numbers().
     *
     * @return array
     */
    public static function numbers_provider(): array {
        return [
            'international' => ['+966501234567', null, '+966501234567', true],
            'formatted' => ['+966 50 123 4567', null, '+966501234567', true],
            'national, default region SA' => ['0501234567', null, '+966501234567', true],
            'national without trunk prefix' => ['501234567', null, '+966501234567', true],
            'international dialling prefix 00' => ['00966501234567', null, '+966501234567', true],
            'Arabic-Indic digits' => ["\u{0660}\u{0665}\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}", null,
                '+966501234567', true],
            'Persian digits' => ["\u{06F0}\u{06F5}\u{06F0}\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}\u{06F7}", null,
                '+966501234567', true],
            'Saudi landline is valid but not mobile' => ['0112345678', null, '+966112345678', false],
            'Egyptian mobile' => ['+201001234567', null, '+201001234567', true],
            'national number with country hint' => ['01001234567', 'EG', '+201001234567', true],
            'unknown country hint falls back to SA' => ['0501234567', 'XX', '+966501234567', true],
            'fixed line or mobile (North America)' => ['+1 650 253 0000', null, '+16502530000', true],
            'too short' => ['12345', null, null, false],
            'too long' => ['+96650123456789', null, null, false],
            'not a number' => ['abc', null, null, false],
            'empty' => ['', null, null, false],
        ];
    }

    /**
     * The region is the country when libphonenumber knows it, SA otherwise.
     */
    public function test_region(): void {
        $this->assertSame('EG', phone::region('eg'));
        $this->assertSame('SA', phone::region('XX'));
        $this->assertSame('SA', phone::region(''));
        $this->assertSame('SA', phone::region(null));
    }
}
