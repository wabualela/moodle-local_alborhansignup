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

use core\event\user_created;
use local_alborhansignup\local\phone;

/**
 * Event observers for local_alborhansignup.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * Store the phone number of a new account in E.164.
     *
     * Covers accounts created outside the signup form (admin "Add a new user", CSV upload, web services):
     * core has no hook before user creation, so the value is fixed right after the insert.
     *
     * @param user_created $event
     */
    public static function user_created(user_created $event): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $event->objectid], 'id, phone1, country');
        if (!$user || trim($user->phone1) === '') {
            return;
        }
        $e164 = phone::to_e164($user->phone1, $user->country);
        if ($e164 !== null && $e164 !== $user->phone1) {
            $DB->set_field('user', 'phone1', $e164, ['id' => $user->id]);
        }
    }
}
