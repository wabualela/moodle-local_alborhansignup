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

/**
 * English strings for local_alborhansignup.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['checkprofileprivacy'] = 'Contact details on theme_remui profile pages';
$string['checkprofileprivacy_details'] = 'theme_remui shows the phone number, e-mail address and other contact details on profile pages to every viewer, ignoring the identity field and e-mail display settings. local_alborhansignup makes other users see core\'s profile details instead. This needs "Force users to log in for profiles" (forceloginforprofiles) and RemUI\'s profile layout (theme/remui/layout/mypublic.php) calling user_can_view_profile(). Re-check after every RemUI upgrade.';
$string['checkprofileprivacy_forcelogin'] = 'Contact details are not protected: "Force users to log in for profiles" is off, so theme_remui shows them on every profile page.';
$string['checkprofileprivacy_layoutchanged'] = 'Contact details may not be protected: theme_remui/layout/mypublic.php no longer calls user_can_view_profile(). Review local_alborhansignup for this RemUI version.';
$string['checkprofileprivacy_notremui'] = 'theme_remui is not the site theme.';
$string['checkprofileprivacy_ok'] = 'Other users see core\'s profile details, which follow the identity field and e-mail display settings.';
$string['emailaccountexists'] = 'An account already exists for this email address. Please log in, or use the forgotten-password link.';
$string['iti_clearsearch'] = 'Clear search';
$string['iti_close'] = 'Close';
$string['iti_countrylist'] = 'List of countries';
$string['iti_nocountry'] = 'Select a country for the phone number';
$string['iti_noresults'] = 'No results found';
$string['iti_resultsfew'] = '{$a} results found';
$string['iti_resultsmany'] = '{$a} results found';
$string['iti_resultsone'] = '1 result found';
$string['iti_resultstwo'] = '2 results found';
$string['iti_search'] = 'Search';
$string['iti_selectedcountry'] = 'Change the country of the phone number, selected ${countryName} (${dialCode})';
$string['phone1'] = 'Mobile phone';
$string['phone1invalid'] = 'Enter a valid mobile phone number, including the country code (for example +966501234567).';
$string['phone1required'] = 'Please enter your mobile phone number.';
$string['pluginname'] = 'Alborhan signup form layout';
$string['privacy:metadata'] = 'The Alborhan signup form plugin does not store any personal data itself. The mobile phone number collected on the signup form is stored by Moodle core in the user profile (phone1).';
$string['webservicesignupdisabled'] = 'This action is not available. Please use the pages of the website.';
