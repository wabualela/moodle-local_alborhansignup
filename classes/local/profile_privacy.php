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
 * Keeps contact details on theme_remui profile pages away from other users.
 *
 * RemUI's profile layout (theme/remui/layout/mypublic.php) puts the whole user record into its template and shows
 * the phone number, e-mail, address and more to every viewer, ignoring $CFG->showuseridentity, $CFG->hiddenuserfields
 * and the e-mail display preference. Its only access check is a call to user_can_view_profile(). When that call comes
 * from RemUI's layout and the viewer is not the profile owner (owners never reach plugin callbacks), the callback says
 * "prevent": RemUI then skips its "About me" block and hidden edit form, and the page still shows core's profile
 * details, which apply core's privacy rules. Every other caller of user_can_view_profile() is unaffected.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class profile_privacy {
    /** @var string RemUI profile layout file, relative to the Moodle code root. */
    public const REMUI_LAYOUT = '/theme/remui/layout/mypublic.php';

    /** @var int How many stack frames to inspect; RemUI's layout is 4 frames above the callback. */
    private const TRACE_DEPTH = 8;

    /**
     * Callback for user_can_view_profile() (control_view_profile).
     *
     * @return int one of the \core\user::VIEWPROFILE_* constants
     */
    public static function control_view_profile(): int {
        global $PAGE;

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH);
        if (self::should_hide((int) $PAGE->state, (string) $PAGE->pagelayout, $trace)) {
            return user::VIEWPROFILE_PREVENT;
        }
        return user::VIEWPROFILE_DO_NOT_PREVENT;
    }

    /**
     * Whether the user_can_view_profile() call described by the stack trace is RemUI's profile layout check.
     *
     * @param int $pagestate moodle_page::STATE_* of the current page
     * @param string $pagelayout layout of the current page
     * @param array $trace debug_backtrace() frames
     * @return bool
     */
    public static function should_hide(int $pagestate, string $pagelayout, array $trace): bool {
        // RemUI's layout file runs while the page header is being printed.
        if ($pagestate !== moodle_page::STATE_PRINTING_HEADER || $pagelayout !== 'mypublic') {
            return false;
        }
        foreach ($trace as $frame) {
            // The frame of a call records the file the call was made from.
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (($frame['function'] ?? '') === 'user_can_view_profile' && str_ends_with($file, self::REMUI_LAYOUT)) {
                return true;
            }
        }
        return false;
    }
}
