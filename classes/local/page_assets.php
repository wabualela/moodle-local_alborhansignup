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
 * Adds the phone country picker and the RemUI profile pencil redirect to the pages that need them.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class page_assets {
    /** @var string[] Page types with an editable phone1 field (upload users is left out on purpose). */
    public const PHONE_PAGETYPES = ['login-signup', 'user-edit', 'user-editadvanced', 'enrol-index'];

    /** @var string Country selected when the field is empty or holds a number without country code. */
    public const INITIAL_COUNTRY = 'sa';

    /** @var string[] Countries listed first in the picker: Saudi Arabia, then the Gulf states. */
    public const COUNTRY_ORDER = ['sa', 'ae', 'kw', 'qa', 'bh', 'om'];

    /** @var string[] Countries not offered (also hidden from the site's country list). */
    public const EXCLUDED_COUNTRIES = ['il', 'eh'];

    /** @var string[] Countries whose name in the picker must match the site's country list (customised names). */
    public const COUNTRY_NAMES_FROM_SITE = ['ps', 'sy'];

    /** @var string[] Strings used by the AMD module, preloaded on the page. */
    public const STRINGS = [
        'iti_clearsearch', 'iti_close', 'iti_countrylist', 'iti_nocountry', 'iti_noresults', 'iti_resultsfew',
        'iti_resultsmany', 'iti_resultsone', 'iti_resultstwo', 'iti_search', 'iti_selectedcountry', 'phone1invalid',
    ];

    /** @var string Path of the vendored intl-tel-input library. */
    private const LIBRARY = '/local/alborhansignup/thirdparty/intl-tel-input';

    /**
     * Add the assets the page needs. Called before the page header is printed.
     *
     * @param moodle_page $page
     */
    public static function add_to_page(moodle_page $page): void {
        if (self::is_phone_page((string) $page->pagetype, (string) $page->pagelayout)) {
            $page->requires->css(self::library_url('/css/intlTelInput.min.css'));
            $page->requires->strings_for_js(self::STRINGS, 'local_alborhansignup');
            $page->requires->js_call_amd('local_alborhansignup/phoneinput', 'init', [self::phone_config()]);
        }
        if (self::is_remui_profile_page($page)) {
            $page->requires->js_call_amd('local_alborhansignup/profileedit', 'init', [
                self::profile_edit_url((int) $page->course->id)->out(false),
                get_string('editmyprofile'),
            ]);
        }
    }

    /**
     * Whether the page has an editable phone1 field.
     *
     * @param string $pagetype
     * @param string $pagelayout
     * @return bool
     */
    public static function is_phone_page(string $pagetype, string $pagelayout): bool {
        // The maintenance layout prints no end-of-page JavaScript (user/editadvanced.php for a new admin).
        return $pagelayout !== 'maintenance' && in_array($pagetype, self::PHONE_PAGETYPES, true);
    }

    /**
     * Whether the page is a theme_remui profile page ("mypublic" layout: user/profile.php, user/view.php,
     * user/profilesys.php) seen by a logged-in user.
     *
     * @param moodle_page $page
     * @return bool
     */
    public static function is_remui_profile_page(moodle_page $page): bool {
        return $page->pagelayout === 'mypublic' && isloggedin() && !isguestuser() && $page->theme->name === 'remui';
    }

    /**
     * Configuration of the phone country picker.
     *
     * @return array
     */
    public static function phone_config(): array {
        return [
            'scripturl' => self::library_url('/js/intlTelInputWithUtils.min.js')->out(false),
            'initialcountry' => self::INITIAL_COUNTRY,
            'countryorder' => self::COUNTRY_ORDER,
            'excludecountries' => self::EXCLUDED_COUNTRIES,
            // Primary language subtag only: Intl.DisplayNames rejects Moodle codes like "en_us_k12".
            'locale' => explode('_', current_language())[0],
            'countrynames' => self::site_country_names(),
        ];
    }

    /**
     * Names of COUNTRY_NAMES_FROM_SITE in the site's country list, keyed by lower-case ISO code.
     *
     * @return array
     */
    private static function site_country_names(): array {
        $countries = get_string_manager()->get_list_of_countries(true);
        $names = [];
        foreach (self::COUNTRY_NAMES_FROM_SITE as $code) {
            if (isset($countries[strtoupper($code)])) {
                $names[$code] = $countries[strtoupper($code)];
            }
        }
        return $names;
    }

    /**
     * Core page where the current user edits their own profile, as linked from core's profile page.
     *
     * @param int $courseid course of the profile page (SITEID on user/profile.php)
     * @return moodle_url
     */
    public static function profile_edit_url(int $courseid): moodle_url {
        global $USER;

        if (has_capability('moodle/user:update', context_system::instance())) {
            return new moodle_url('/user/editadvanced.php', ['id' => $USER->id, 'course' => $courseid, 'returnto' => 'profile']);
        }
        $params = ['id' => $USER->id, 'returnto' => 'profile'];
        if ($courseid && $courseid != SITEID) {
            $params['course'] = $courseid;
        }
        return new moodle_url('/user/edit.php', $params);
    }

    /**
     * URL of a vendored library file, with the plugin version to bust browser caches on upgrade.
     *
     * @param string $path path inside the library folder
     * @return moodle_url
     */
    private static function library_url(string $path): moodle_url {
        return new moodle_url(self::LIBRARY . $path, ['v' => (int) get_config('local_alborhansignup', 'version')]);
    }
}
