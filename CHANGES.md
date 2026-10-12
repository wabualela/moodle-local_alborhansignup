# Changelog

All notable changes to local_alborhansignup.

## [Unreleased]

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
