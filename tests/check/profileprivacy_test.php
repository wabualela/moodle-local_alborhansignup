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

namespace local_alborhansignup\check;

use core\check\result;

/**
 * Tests for the profile privacy security check.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(profileprivacy::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_alborhansignup_security_checks')]
final class profileprivacy_test extends \advanced_testcase {
    /**
     * Each site state gives the expected status.
     *
     * @param string $theme
     * @param bool $forcelogin
     * @param string|null $source
     * @param string $status
     * @param string $key
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('evaluate_provider')]
    public function test_evaluate(string $theme, bool $forcelogin, ?string $source, string $status, string $key): void {
        $this->assertSame([$status, $key], profileprivacy::evaluate($theme, $forcelogin, $source));
    }

    /**
     * Data provider for test_evaluate().
     *
     * @return array
     */
    public static function evaluate_provider(): array {
        $layout = '<?php if (user_can_view_profile($userobject, null, $context)) { }';
        return [
            'other theme' => ['boost', true, null, result::NA, 'notremui'],
            'protected' => ['remui', true, $layout, result::OK, 'ok'],
            'forceloginforprofiles off' => ['remui', false, $layout, result::ERROR, 'forcelogin'],
            'layout changed' => ['remui', true, '<?php echo "no check";', result::ERROR, 'layoutchanged'],
            'layout missing' => ['remui', true, null, result::ERROR, 'layoutchanged'],
        ];
    }

    /**
     * The check is registered and reports "not applicable" on a site without theme_remui.
     */
    public function test_registered_check(): void {
        $all = \core\check\manager::get_security_checks();
        $checks = array_filter($all, fn($check) => $check instanceof profileprivacy);
        $this->assertCount(1, $checks);
        $this->assertSame(result::NA, reset($checks)->get_result()->get_status());
    }
}
