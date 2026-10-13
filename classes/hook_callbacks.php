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

use core\hook\output\before_http_headers;
use core_user\hook\before_user_updated;
use local_alborhansignup\local\page_assets;
use local_alborhansignup\local\phone;

/**
 * Hook callbacks for local_alborhansignup.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hook_callbacks {
    /**
     * Store a changed phone number (phone1) in E.164 whenever a user record is updated (user_update_user()).
     *
     * Unchanged values are left alone, so legacy numbers are only normalised when the user saves a new one;
     * numbers that are not valid are stored as typed. Never throws: this hook runs on every user update.
     *
     * @param before_user_updated $hook
     */
    public static function before_user_updated(before_user_updated $hook): void {
        $user = $hook->user;
        if (!isset($user->phone1)) {
            return;
        }
        $raw = (string) $user->phone1;
        if (trim($raw) === '' || $raw === (string) ($hook->currentuserdata->phone1 ?? '')) {
            return;
        }
        $e164 = phone::to_e164($raw, $user->country ?? $hook->currentuserdata->country ?? null);
        if ($e164 !== null) {
            $user->phone1 = $e164;
        }
    }

    /**
     * Add the phone country picker and the RemUI profile pencil redirect to the pages that need them.
     *
     * Runs before the page header (and its CSS links) is printed, on every page: keep it cheap.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        try {
            page_assets::add_to_page($hook->renderer->get_page());
        } catch (\Throwable $e) {
            // Never break a page for the phone picker: the plain field and the server-side validation still work.
            debugging('local_alborhansignup: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
