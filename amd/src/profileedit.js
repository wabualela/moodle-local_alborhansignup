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

/**
 * Make the "edit" pencil of the theme_remui profile page open the core profile editing page.
 *
 * RemUI's inline editor saves the profile with a raw database update and rejects phone numbers in E.164, so the
 * pencil opens user/edit.php (or editadvanced.php) instead, where the phone country picker and the server-side
 * validation apply. The click is caught in the capture phase, before RemUI's own handler on the pencil.
 *
 * @module     local_alborhansignup/profileedit
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const PENCIL = '.edit-profile-icon-wrapper .edw-icon-Edit';

/** @type {string|null} */
let editurl = null;

let listening = false;

/**
 * Open the core profile editing page instead of RemUI's inline editor.
 *
 * @param {Event} event
 */
const openEditPage = (event) => {
    if (!editurl || !event.target.closest(PENCIL)) {
        return;
    }
    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    window.location.assign(editurl);
};

/**
 * Redirect the pencil(s) of the page.
 *
 * @param {string} url core profile editing URL
 * @param {string} label accessible name of the pencil
 */
export const init = (url, label) => {
    editurl = url;
    document.querySelectorAll(PENCIL).forEach((pencil) => {
        // RemUI renders a bare span: make it a keyboard-reachable link.
        pencil.setAttribute('role', 'link');
        pencil.setAttribute('tabindex', '0');
        pencil.setAttribute('aria-label', label);
        pencil.setAttribute('title', label);
    });
    if (!listening) {
        document.addEventListener('click', openEditPage, true);
        document.addEventListener('keydown', openEditPage, true);
        listening = true;
    }
};
