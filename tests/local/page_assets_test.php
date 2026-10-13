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

use context_system;
use moodle_page;
use moodle_url;

/**
 * Tests for the page assets (phone country picker, RemUI profile pencil).
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(page_assets::class)]
final class page_assets_test extends \advanced_testcase {
    /**
     * The picker is added to the pages with an editable phone1 field only.
     *
     * @param string $pagetype
     * @param string $pagelayout
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('is_phone_page_provider')]
    public function test_is_phone_page(string $pagetype, string $pagelayout, bool $expected): void {
        $this->assertSame($expected, page_assets::is_phone_page($pagetype, $pagelayout));
    }

    /**
     * Data provider for test_is_phone_page().
     *
     * @return array
     */
    public static function is_phone_page_provider(): array {
        return [
            'signup' => ['login-signup', 'login', true],
            'own profile' => ['user-edit', 'admin', true],
            'admin edit user' => ['user-editadvanced', 'admin', true],
            'new admin (maintenance layout)' => ['user-editadvanced', 'maintenance', false],
            'enrolment page' => ['enrol-index', 'incourse', true],
            'upload users' => ['admin-tool-uploaduser-index', 'admin', false],
            'profile page' => ['user-profile', 'mypublic', false],
            'front page' => ['site-index', 'frontpage', false],
        ];
    }

    /**
     * The picker configuration points to the vendored bundle and lists Saudi Arabia first.
     */
    public function test_phone_config(): void {
        $this->resetAfterTest();

        $config = page_assets::phone_config();

        $script = '/local/alborhansignup/thirdparty/intl-tel-input/js/intlTelInputWithUtils.min.js';
        $this->assertStringContainsString($script, $config['scripturl']);
        $this->assertSame('sa', $config['initialcountry']);
        $this->assertSame('sa', $config['countryorder'][0]);
        $this->assertContains('il', $config['excludecountries']);
        $this->assertSame('en', $config['locale']);
        $this->assertSame(get_string('PS', 'countries'), $config['countrynames']['ps']);
        // Well under the 1024-character limit of js_call_amd() arguments.
        $this->assertLessThan(1024, strlen(json_encode($config)));
    }

    /**
     * Users edit their own profile on user/edit.php, administrators on user/editadvanced.php.
     */
    public function test_profile_edit_url(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->setUser($user);
        $this->assertSame(
            (new moodle_url('/user/edit.php', ['id' => $user->id, 'returnto' => 'profile']))->out(false),
            page_assets::profile_edit_url(SITEID)->out(false)
        );
        $this->assertSame(
            (new moodle_url('/user/edit.php', ['id' => $user->id, 'returnto' => 'profile', 'course' => $course->id]))->out(false),
            page_assets::profile_edit_url($course->id)->out(false)
        );

        $this->setAdminUser();
        $this->assertSame(
            (new moodle_url('/user/editadvanced.php', ['id' => get_admin()->id, 'course' => SITEID, 'returnto' => 'profile']))
                ->out(false),
            page_assets::profile_edit_url(SITEID)->out(false)
        );
    }

    /**
     * The signup page gets the picker CSS, strings and AMD module; other pages get nothing.
     */
    public function test_add_to_page(): void {
        $this->resetAfterTest();

        $signup = $this->create_page('/login/signup.php', 'login');
        page_assets::add_to_page($signup);
        $this->assertStringContainsString('local_alborhansignup/phoneinput', $this->amd_code($signup));
        $cssurls = array_keys($this->get_property($signup, 'cssurls'));
        $this->assertNotEmpty(preg_grep('~/thirdparty/intl-tel-input/css/intlTelInput\.min\.css~', $cssurls));
        $this->assertArrayHasKey('phone1invalid', $this->get_property($signup, 'stringsforjs')['local_alborhansignup']);
        // RemUI is not the theme here, so no pencil redirect.
        $this->assertStringNotContainsString('local_alborhansignup/profileedit', $this->amd_code($signup));

        $index = $this->create_page('/index.php', 'frontpage');
        page_assets::add_to_page($index);
        $this->assertStringNotContainsString('local_alborhansignup', $this->amd_code($index));
        $this->assertEmpty($this->get_property($index, 'cssurls'));
    }

    /**
     * The RemUI pencil redirect is only for theme_remui profile pages.
     */
    public function test_is_remui_profile_page_other_theme(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(page_assets::is_remui_profile_page($this->create_page('/user/profile.php', 'mypublic')));
    }

    /**
     * A page with the given URL and layout.
     *
     * @param string $url
     * @param string $layout
     * @return moodle_page
     */
    private function create_page(string $url, string $layout): moodle_page {
        $page = new moodle_page();
        $page->set_context(context_system::instance());
        $page->set_url(new moodle_url($url));
        $page->set_pagelayout($layout);
        return $page;
    }

    /**
     * AMD code collected for the page.
     *
     * @param moodle_page $page
     * @return string
     */
    private function amd_code(moodle_page $page): string {
        return implode("\n", $this->get_property($page, 'amdjscode'));
    }

    /**
     * A protected property of the page requirements manager.
     *
     * @param moodle_page $page
     * @param string $name
     * @return mixed
     */
    private function get_property(moodle_page $page, string $name): mixed {
        return (new \ReflectionProperty($page->requires, $name))->getValue($page->requires);
    }
}
