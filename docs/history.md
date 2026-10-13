# Development history

This page tells how local_alborhansignup reached its current state: what changed in each version, why, which bugs
were found along the way and how they were fixed. [CHANGES.md](../CHANGES.md) is the authoritative changelog;
[decisions.md](decisions.md) records the reasoning behind the main design choices.

## Background

Alborhan Academy runs Moodle 5.2 in Arabic (right-to-left) with the commercial theme_remui (Edwiser). Until
September 2026, self-registration used a custom three-step authentication plugin (`auth_flex`). It was retired and
self-registration moved to the core `auth_email` plugin.

The core signup form looks and behaves differently from the old one. local_alborhansignup was created to keep the
familiar experience on the core form, using only the callbacks core supports, and without changing any core or theme
file.

## Timeline

All dates are in 2026. Tags and short commit hashes refer to this repository, which starts at 1.2.1.

| Version | Date | Tag / commit | What changed | Why |
|---|---|---|---|---|
| 1.0.0 (2026091400) | 14 Sep | before the repository | Removed the collapsible profile-category headers. Moved the custom profile fields `certname` and `gender` right after the name fields. | Custom profile fields should appear inline with the core fields, in the order users knew. |
| 1.1.0 (2026091401) | 14 Sep | before the repository | Hid the username field. The username became the lower-cased e-mail address. The first version used a QuickForm filter. | Same rule as the former `auth_flex`: users sign in with their e-mail address. |
| 1.2.0 (2026091402) | 14 Sep | before the repository | "Account already exists" shown under the e-mail field. Added a generic list that sets the field order. | With the username hidden, core's "username exists" error would have been invisible. |
| 1.2.1 (2026092000) | 20 Sep | `v1.2.1`, `9b3709a` | The username is derived in the `post_signup_requests` callback instead of by a filter. | Real signups failed with `invalidusernameblank` (see below). |
| no release | 12–13 Oct | `04f050a`, `48bb79b` | Own git repository with the deployed 1.2.1 as its baseline. Changelog rebuilt. GitHub Actions CI added. | A code review found no version control and no tests. |
| 1.2.2 (2026101300) | 13 Oct | `093e884` (not tagged) | Logic moved to `classes/local/form_customiser.php`. The hidden username became a form constant. PHPUnit tests added. Moodle 5.1 required. | Security: a crafted POST could choose the username. Testability. |
| 1.2.3 (2026101301) | 13 Oct | `v1.2.3`, `4ac6454` | The `auth_email_signup_user` web service is blocked. | Security: anyone could call it anonymously, and it skips every signup-form callback. Deployed the same day as a security release. |
| 1.3.0 (2026101302) | 13 Oct | `v1.3.0`, `d9ba5bc` | Required mobile phone (core `phone1`) stored in E.164, with an intl-tel-input country picker. `phone1` is normalised wherever it is saved. The RemUI profile pencil now opens the core edit page. Contact details on RemUI profile pages are hidden from other users. Behat features added. | Management asked for a validated mobile number, and RemUI's profile pages had to be made consistent with it. Released after three adversarial review rounds, one verification round and manual acceptance tests. |

## September: the first versions (1.0.0 to 1.2.0)

The first three versions came out on the same day. They rearranged the core signup form through
`local_alborhansignup_extend_signup_form()`, called from `public/login/signup_form.php`:

- They removed the `category_<id>` header elements that `profile_signup_fields()` adds for each profile-field category.
- They moved elements into a fixed order.
- They hid the username.

The duplicate-account check moved under the e-mail field in `local_alborhansignup_validate_extend_signup_form()`,
because the username field is no longer visible.

## 1.2.1: the QuickForm filter bug

**Symptom.** Real signups failed with `invalidusernameblank`, which is thrown by `user_create_user()` in
`public/user/lib.php`.

**Cause.** 1.1.0 rewrote the hidden username with `applyFilter()` inside the form definition. That cannot work:

1. `moodleform::__construct()` (`public/lib/formslib.php`) first runs `definition()`, where the plugin callback runs.
2. Then it calls `_process_submission()`.
3. That calls `updateSubmission()`, which replaces the whole submitted-values array with a cleaned copy of `$_POST`.

The filter was registered against values that were then thrown away. Core's `signup_validate_data()` does not reject
an empty username, so the error only appeared at account creation.

**Why testing missed it.** Two tests both passed, for the wrong reasons:

- A CLI test applied the filter *after* the form had been constructed.
- The manual web test stopped once validation passed and never created an account.

**Fix.** The username is derived in `local_alborhansignup_post_signup_requests()`. Core calls it in
`public/login/signup.php` after validation and just before `auth_email::user_signup()`, and it passes the same user
object on, so the change sticks.

## 12–13 October: repository, review and CI

A code review of the deployed 1.2.1 found three problems:

- the plugin had no tests;
- it was not under version control;
- one hidden field could be tampered with (fixed in 1.2.2).

So the plugin got its own repository:

- **Baseline.** Commit `9b3709a` (tag `v1.2.1`) is byte-identical to the deployed copy.
- **Changelog.** `04f050a` rebuilt the changelog for 1.0.0 to 1.2.1 from the version history.
- **CI.** `48bb79b` added GitHub Actions with moodle-plugin-ci. The matrix is:
  - Moodle 5.2 with PHP 8.4 and MariaDB (the production stack);
  - Moodle 5.2 with PHP 8.3 and PostgreSQL;
  - Moodle 5.1 with PHP 8.4 and PostgreSQL.

From then on, the site's Moodle core repository was never modified. The plugin directory on the site is only a deploy
target, filled by an explicit copy of a tagged release (see [deployment.md](deployment.md)).

## 1.2.2: refactor and the username tamper fix

**Tamper bug.** In 1.2.1, the hidden `username` element took its value from the POST, and `post_signup_requests` only
derived the username when that value was empty. So a crafted request with `username=anything` passed core's username
checks and created an account under that name. That broke the rule that the username always equals the e-mail address.

**Fix.** The hidden element now gets `setConstant('username', '')`. QuickForm constants override submitted values, and
`post_signup()` always derives the username from the e-mail address.

**Other changes in the same release:**

- **Refactor.** The logic moved into the autoloaded class `\local_alborhansignup\local\form_customiser`. The
  `lib.php` callbacks became one-line wrappers, because core still calls them by name: Moodle 5.2 has no Hooks API
  replacement for the signup callbacks.
- **Header lookup.** Category headers are now recognised by their `category_<id>` name, without a database query.
- **Field order.** The global `define()` became `form_customiser::FIELD_ORDER`.
- **Code style.** moodle-cs (`moodle-extra`) now reports no errors and no warnings.
- **Tests.**
  - Unit tests for the customiser.
  - Integration tests that drive the real `login_signup_form` (`mock_submit()`) and `auth_email` all the way to
    account creation. This closes the gap that let the 1.2.1 bug through.

## 1.2.3: the web-service bypass

The bypass was found while researching the signup flow for the phone feature.

**The problem.**

- `auth_email_external::signup_user()` (`public/auth/email/classes/external.php`) calls `signup_validate_data()`,
  `signup_setup_new_user()` and `user_signup_with_confirmation()`.
- It calls none of the signup-form callbacks.
- The function is declared `ajax` with `loginrequired = false`, so anyone can call it anonymously through
  `public/lib/ajax/service-nologin.php`.
- A caller could therefore choose any username, or register someone else's e-mail address as a username. Later it would
  also have skipped the phone requirement.

**The fix.** The site does not use the Moodle app, so the function is refused through the core
`override_webservice_execution` callback (`classes/local/webservice_guard.php`). Self-registration still works on
`login/signup.php`. The release was tagged `v1.2.3` and deployed the same day.

## 1.3.0: mobile phone and country picker

The work's commits, grouped by topic (not in strict time order):

| Commit | Step |
|---|---|
| `200191e` | Server side: required `phone1` on signup, validated with the libphonenumber library bundled with core (mobile numbers only) and stored in E.164. A `before_user_updated` hook and a `user_created` observer normalise `phone1` saved anywhere else. |
| `c1a74ee` | Tests: legacy fixtures are written with `set_field()`, because the data generator triggers `user_created`. |
| `173e7d9` | Hardening after the adversarial review of the parsing code. |
| `fcb43f9` | intl-tel-input 29.5.4 vendored. Picker added on signup, profile edit, admin user edit and enrol_apply. RemUI pencil redirected and RemUI's inline save service blocked. |
| `a95640d`, `5278e6d`, `ed3ca65` | CI publishes PHPUnit and Behat failure output as annotations, because job logs need authentication. |
| `0555bf6` | RemUI profile privacy (`control_view_profile`) and its security check. |
| `72ce169`, `dc8db28` | Fixes from the review of the picker. |
| `a8dd9dc` | Fixes from the final verification pass. |
| `d9ba5bc` | Release: deployed, then accepted after manual tests. |

### Notable review findings and their fixes

| # | Finding | Effect | Fix | Commit |
|---|---|---|---|---|
| 1 | libphonenumber threw an unhandled `RuntimeException` on unusual Unicode, such as a full-width plus followed by Arabic-Indic digits, which split into malformed UTF-8. | Exception page on signup, and in the `before_user_updated` hook, which runs on every user update. | `RuntimeException` is caught next to `NumberParseException`. The full-width plus and full-width, Arabic-Indic and Persian digits are mapped to ASCII before parsing. | `173e7d9` |
| 2 | Ambiguous national numbers: `0501234567` with the United Arab Emirates selected is valid both there and in Saudi Arabia. | The number could be stored under the wrong country. | A number without `+`/`00` that is valid both in the selected country and in Saudi Arabia is rejected. The user is asked for the country code. | `173e7d9` |
| 3 | Extensions were dropped, because E.164 has no extension. | Silent data loss. | Numbers with an extension are never normalised. Signup rejects them, and elsewhere they are kept as typed. | `173e7d9` |
| 4 | Keyboard focus trap. The picker validated on every `blur`, and `theme_boost/form-display-errors` moves focus back to a field whenever it shows an error. | Keyboard and mouse users could not leave an invalid field. | Validation runs once per edit, when leaving the field. Moving to the field's own country button does not count as leaving. | `72ce169` |
| 5 | Untouched legacy values were posted without their country code. The picker reformats the stored value on load, and the reformatted text was submitted. | Saving a profile for an unrelated reason could corrupt a legacy number the server could not re-parse. | A value the user did not change (same text, same country) is submitted exactly as stored. The verification pass added a Behat case with a value the picker displays differently. | `72ce169`, `a8dd9dc` |
| 6 | `+` and `00` could not be typed with `separateDialCode`. In intl-tel-input 28+ a typed `+` opens the country search, yet the error message tells users to type the country code. | Users could not follow the instruction. Pasted international numbers were mangled. | `separateDialCode: false` and `strictMode: false`. A leading `00` (also in Arabic-Indic or Persian digits) is turned into `+`, so the flag follows the number. | `72ce169`, `dc8db28` |
| 7 | Arabic country search without hamza. The library's search normaliser only stripped Latin combining accents (U+0300 to U+036F). | Typing "الامارات" or "الاردن" found nothing. | Local patch of the vendored file to strip every combining mark (`/\p{M}/gu`). It is recorded in `thirdparty/intl-tel-input/readme_moodle.txt` and marked `<customised/>` in `thirdpartylibs.xml`. | `72ce169` |
| 8 | Core's "required" message was erased. When the field was emptied, the blur handler cleared the error, including core's message. | Required field with no visible error. | Only the plugin's own "invalid number" message is cleared. | `a8dd9dc` |
| 9 | Behat on Moodle 5.1. In JavaScript scenarios only visible text counts, and on 5.1 "Confirm your account" appears only in the page title. | False failure on the 5.1 CI leg. | The scenario checks a sentence from the visible page body. | `0555bf6` |
| 10 | WebDriver could not reliably clear fields whose value starts with `+`, because the picker adds the dial code back. | Flaky profile-editing scenario. | A custom step sets the number through the library (`setNumber()`), which fires the same `input` event as typing. | `a8dd9dc` |

Smaller fixes from the same rounds:

- Focus is restored after the library wraps the input.
- Country names for Palestine and Syria are taken from the site's country list.
- The `Intl.DisplayNames` locale keeps a two-letter region (`zh_tw` becomes `zh-TW`).
- On RTL pages, the "invalid" icon is no longer drawn under the country button.
- The page-assets hook can never throw.

## Lessons learned

**Testing**

- **Test signup end to end, up to account creation.** A test that stops at validation, or builds the form in an
  unusual order, can pass while real signups fail (1.2.1).
- **The test data generator fires events.** `create_user()` triggers `user_created`, so observers run on generated
  users. Write legacy fixtures directly with `set_field()`.
- **Behat with JavaScript only sees visible text.** WebDriver's field clearing is unreliable on intl-tel-input values
  that start with `+`. Set such numbers through the library in a custom step.

**QuickForm**

- `insertElementBefore()` stores a reference. Call `unset($element)` in every loop iteration that reuses the
  variable.
- `applyFilter()` called in `definition()` is discarded by `_process_submission()`.
- `removeElement()` leaves the name in `_required`.
- Constants override posted values, which makes them the right tool for server-owned hidden fields.

**Moodle caches and deployment**

- Hook callbacks, the list of plugin functions (`get_plugins_with_function()`) and the class map are all cached. Bump
  the version and run the upgrade whenever a callback or class is added or renamed.
- Deploy new autoloaded classes with maintenance mode and an immediate upgrade. Otherwise pages fail with "class not
  found" until caches are purged.
- Moodle refuses plugin version downgrades, so copying old files back is not a rollback. Fix forward with a new
  version.
- **Never send write requests to production as a probe**, even ones you expect to fail. One such probe created a real
  account. Agents never send them. A human may check with a request that fails before any write (for example one
  missing a required parameter), or use a test environment.

**intl-tel-input**

- Only numbers typed with `+` select the country.
- The search normaliser ignored Arabic combining marks.
- v29 renamed many options, for example `hiddenInput` to `hiddenInputs`, `i18n` to `uiTranslations` and
  `geoIpLookup` to `initialCountryLookup`; `utilsScript` had already been replaced by `loadUtils` in v25. Check the
  type definitions of the exact version, not older tutorials.

**Tooling**

- GitHub Actions job logs need authentication, but annotations can be read through the public API. Publishing failure
  output as chunked annotations made CI failures readable without a token.
- No Moodle documentation server was available to the AI agents. The read-only Moodle core source of the exact version
  in use was the ground truth, with moodledev.io as a secondary source. Many claims made in early research were
  corrected by reading the core code.
