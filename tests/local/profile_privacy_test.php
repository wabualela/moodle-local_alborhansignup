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
use moodle_page;

/**
 * Tests for the protection of contact details on theme_remui profile pages.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(profile_privacy::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_alborhansignup_control_view_profile')]
final class profile_privacy_test extends \advanced_testcase {
    /**
     * Stack trace of RemUI's profile layout calling user_can_view_profile().
     *
     * @param string $caller file the call was made from
     * @return array
     */
    private static function trace(string $caller): array {
        return [
            [
                'file' => '/srv/moodle/public/user/lib.php',
                'line' => 1321,
                'function' => 'local_alborhansignup_control_view_profile',
            ],
            ['file' => '/srv/moodle/public/user/lib.php', 'line' => 1258, 'function' => 'user_can_view_profile_callbacks'],
            ['file' => $caller, 'line' => 47, 'function' => 'user_can_view_profile'],
            ['file' => '/srv/moodle/public/lib/classes/output/core_renderer.php', 'line' => 877, 'function' => 'include'],
        ];
    }

    /**
     * Only RemUI's own check, while its profile layout is printed, is turned down.
     *
     * @param int $state page state
     * @param string $layout page layout
     * @param string $caller file user_can_view_profile() is called from
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('should_hide_provider')]
    public function test_should_hide(int $state, string $layout, string $caller, bool $expected): void {
        $this->assertSame($expected, profile_privacy::should_hide($state, $layout, self::trace($caller)));
    }

    /**
     * Data provider for test_should_hide().
     *
     * @return array
     */
    public static function should_hide_provider(): array {
        $remui = '/srv/moodle/public/theme/remui/layout/mypublic.php';
        return [
            'RemUI profile layout' => [moodle_page::STATE_PRINTING_HEADER, 'mypublic', $remui, true],
            'Windows path' => [moodle_page::STATE_PRINTING_HEADER, 'mypublic',
                'C:\\moodle\\public\\theme\\remui\\layout\\mypublic.php', true],
            'page header (context header) check' => [moodle_page::STATE_PRINTING_HEADER, 'mypublic',
                '/srv/moodle/public/theme/remui/classes/output/core_renderer.php', false],
            'before the header' => [moodle_page::STATE_BEFORE_HEADER, 'mypublic', $remui, false],
            'in the page body' => [moodle_page::STATE_IN_BODY, 'mypublic', $remui, false],
            'other layout' => [moodle_page::STATE_PRINTING_HEADER, 'standard', $remui, false],
            'other theme layout' => [moodle_page::STATE_PRINTING_HEADER, 'mypublic',
                '/srv/moodle/public/theme/other/layout/mypublic.php', false],
        ];
    }

    /**
     * Outside RemUI's profile layout the callback never prevents access.
     */
    public function test_control_view_profile_elsewhere(): void {
        $this->assertSame(user::VIEWPROFILE_DO_NOT_PREVENT, profile_privacy::control_view_profile());
    }

    /**
     * Classmates can still view each other's profiles through core (participants, forums, ...).
     */
    public function test_user_can_view_profile_unaffected(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $this->resetAfterTest();
        set_config('forceloginforprofiles', 1);

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student1);

        $this->assertTrue(user_can_view_profile($student2, $course));
    }
}
