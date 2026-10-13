# Changelog

All notable changes to local_alborhansignup.

## [Unreleased]

## [1.3.0] - unreleased (2026101302)
### Added
- Required mobile phone field on the signup form, stored in the core user field `phone1` (no custom profile field).
  Validated server-side with the libphonenumber library shipped with core (mobile numbers only; Arabic-Indic digits
  accepted) and stored in E.164, e.g. `+966501234567`.
- `before_user_updated` hook: a changed `phone1` is stored in E.164 when any user record is updated (profile edit,
  admin edit). Unchanged legacy values are left alone.
- `user_created` observer: `phone1` of accounts created outside the signup form (admin, CSV upload, web services) is
  stored in E.164.
- Numbers without a country code that are valid both in the selected country and in Saudi Arabia are rejected as
  ambiguous; numbers with an extension are kept as typed.

## [1.2.3] - 2026-10-13 (2026101301)
### Security
- Block the `auth_email_signup_user` web service. It is callable anonymously through `lib/ajax/service-nologin.php`
  and bypasses the signup form callbacks, so a caller could register any username (or reserve someone else's e-mail
  address as a username). Self-registration remains available on `login/signup.php`. Implemented with the core
  `override_webservice_execution` callback (`\local_alborhansignup\local\webservice_guard`).

## [1.2.2] - 2026-10-13 (2026101300)
### Security
- A crafted POST could choose any username through the hidden username field. The field is now a form constant and
  the username is always derived from the e-mail address.
### Changed
- Logic moved from `lib.php` into the autoloaded class `\local_alborhansignup\local\form_customiser`; the `lib.php`
  callbacks are thin wrappers. The field order is `form_customiser::FIELD_ORDER` (was `LOCAL_ALBORHANSIGNUP_ORDER`).
- Profile-category headers are found by name, without a database query.
- Requires Moodle 5.1 (was 5.0).
### Added
- PHPUnit tests and GitHub Actions CI (moodle-plugin-ci).

## [1.2.1] - 2026-09-20 (2026092000)
### Fixed
- Real signups failed with `invalidusernameblank`: a QuickForm filter set in `definition()` is discarded by
  `moodleform::_process_submission()`. The username is now derived from the e-mail address in the
  `post_signup_requests` callback, after validation and before the account is created.

## [1.2.0] - 2026-09-14 (2026091402)
### Added
- "Account already exists" errors are reported under the e-mail field, since the username field is hidden.
- Generic `LOCAL_ALBORHANSIGNUP_ORDER` list controlling the order of the signup fields.

## [1.1.0] - 2026-09-14 (2026091401)
### Changed
- The username field is hidden; the username is the lower-cased e-mail address (same rule as the former auth_flex).

## [1.0.0] - 2026-09-14 (2026091400)
### Added
- Removes the collapsible profile-category headers from the email signup form.
- Moves the custom profile fields `certname` and `gender` right after the name fields.
