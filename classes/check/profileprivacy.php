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

use core\check\check;
use core\check\result;

/**
 * Security check: the protection of contact details on theme_remui profile pages is still effective.
 *
 * {@see \local_alborhansignup\local\profile_privacy} relies on core calling plugin callbacks (only when
 * forceloginforprofiles is on) and on RemUI's profile layout calling user_can_view_profile().
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profileprivacy extends check {
    /**
     * Link to the setting that most often breaks the protection.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'sitepolicies']),
            get_string('sitepolicies', 'admin')
        );
    }

    /**
     * Result of the check.
     *
     * @return result
     */
    public function get_result(): result {
        global $CFG;

        $theme = (string) ($CFG->theme ?? '');
        [$status, $key] = self::evaluate($theme, !empty($CFG->forceloginforprofiles), self::remui_layout_source());
        return new result(
            $status,
            get_string('checkprofileprivacy_' . $key, 'local_alborhansignup'),
            get_string('checkprofileprivacy_details', 'local_alborhansignup')
        );
    }

    /**
     * Evaluate the site state.
     *
     * @param string $theme site theme
     * @param bool $forceloginforprofiles value of $CFG->forceloginforprofiles
     * @param string|null $layoutsource source of theme/remui/layout/mypublic.php, null when missing
     * @return array [result status, lang string suffix]
     */
    public static function evaluate(string $theme, bool $forceloginforprofiles, ?string $layoutsource): array {
        if ($theme !== 'remui') {
            return [result::NA, 'notremui'];
        }
        if (!$forceloginforprofiles) {
            return [result::ERROR, 'forcelogin'];
        }
        if ($layoutsource === null || !str_contains($layoutsource, 'user_can_view_profile(')) {
            return [result::ERROR, 'layoutchanged'];
        }
        return [result::OK, 'ok'];
    }

    /**
     * Source of RemUI's profile layout file.
     *
     * @return string|null
     */
    private static function remui_layout_source(): ?string {
        $dir = \core_component::get_plugin_directory('theme', 'remui');
        $file = $dir ? $dir . '/layout/mypublic.php' : null;
        if (!$file || !is_readable($file)) {
            return null;
        }
        $source = file_get_contents($file);
        return $source === false ? null : $source;
    }
}
