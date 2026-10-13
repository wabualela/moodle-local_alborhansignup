# Architecture decision records

These are the main design decisions behind local_alborhansignup, written as short architecture decision records
(ADRs). Each one gives the context, the decision, its consequences and the alternatives that were rejected.

- [history.md](history.md) tells the story in time order.
- [CHANGES.md](../CHANGES.md) lists what each release changed.

Core files are cited by their path inside Moodle (`public/...`). Everything listed below is accepted and implemented as
of 1.3.0.

| # | Decision | Since |
|---|---|---|
| [ADR-01](#adr-01-store-the-phone-in-core-phone1) | Store the phone in core `phone1` | 1.3.0 |
| [ADR-02](#adr-02-required-on-signup-only) | Required on signup only | 1.3.0 |
| [ADR-03](#adr-03-mobile-numbers-only-stored-in-e164) | Mobile numbers only, stored in E.164 | 1.3.0 |
| [ADR-04](#adr-04-the-server-is-the-source-of-truth) | The server is the source of truth | 1.3.0 |
| [ADR-05](#adr-05-vendor-intl-tel-input-and-load-it-at-runtime) | Vendor intl-tel-input and load it at runtime | 1.3.0 |
| [ADR-06](#adr-06-keep-the-dial-code-in-the-field) | Keep the dial code in the field | 1.3.0 |
| [ADR-07](#adr-07-submit-untouched-values-exactly-as-stored) | Submit untouched values exactly as stored | 1.3.0 |
| [ADR-08](#adr-08-validate-once-per-edit-when-leaving-the-field) | Validate once per edit, when leaving the field | 1.3.0 |
| [ADR-09](#adr-09-theme_remui-inline-editor-redirect-the-pencil-block-the-save-service) | theme_remui inline editor: redirect the pencil, block the save service | 1.3.0 |
| [ADR-10](#adr-10-hide-contact-details-on-remui-profiles-through-control_view_profile) | Hide contact details on RemUI profiles through `control_view_profile` | 1.3.0 |
| [ADR-11](#adr-11-block-the-auth_email_signup_user-web-service) | Block the `auth_email_signup_user` web service | 1.2.3 |
| [ADR-12](#adr-12-own-public-repository-tests-never-on-production) | Own public repository; tests never on production | 1.2.2 |
| [ADR-13](#adr-13-no-bulk-migration-of-legacy-phone-numbers) | No bulk migration of legacy phone numbers | 1.3.0 |
| [ADR-14](#adr-14-no-picker-on-the-upload-users-default-values-step) | No picker on the upload users "default values" step | 1.3.0 |

---

## ADR-01: Store the phone in core `phone1`

**Context.** Management asked for a mobile number on signup. Core has two phone columns in the `user` table:

- `phone1`, labelled "Phone" in English;
- `phone2`, labelled "Mobile phone".

On this site, `phone1` is already the mobile number: the site's language customisation labels it "الجوال" (mobile), and
existing accounts keep their number there.

**Decision.** Store the number in core `phone1`. Do not create a custom profile field, and do not use `phone2`. On the
signup form the field has the plugin's own label, "Mobile phone".

**Consequences.**

- **Saved without extra code.** A form element named `phone1` is written by `user_create_user()` with no extra code.
- **Already wired into core.** `phone1` is:
  - exported by the bulk user download (`public/admin/user/user_bulk_download.php`);
  - selectable as an identity field;
  - covered by core's privacy provider (`public/user/classes/privacy/provider.php`).

  So the plugin stays a `null_provider`.
- **Shown on core profile forms.** `phone1` appears there with core's label (`public/user/editlib.php`), so the
  picker has to work on those forms too (see ADR-05).
- **Shown by themes.** Themes that display `phone1` display this number (see ADR-10).

**Alternatives rejected.**

- **Custom profile field.** It would duplicate data that already lives in `phone1`. Signup, enrol_apply, CSV upload,
  exports and reports would all need rework.
- **`phone2`.** Core's English label suits it, but the site's data and wording already use `phone1`.

## ADR-02: Required on signup only

**Context.** The signup form has a plugin validation callback (`validate_extend_signup_form`). The core profile forms
have none:

- `public/user/edit_form.php` and `public/user/editadvanced_form.php` share their fields through
  `useredit_shared_definition()`.
- The `before_user_updated` hook, dispatched in `user_update_user()` (`public/user/lib.php`), can change the record but
  cannot reject it with a form error.
- Core has no hook before a user is created.

**Decision.** The phone is required and validated only on the signup form. Elsewhere it is optional:

- The browser validates it (the picker).
- The server normalises it: the `before_user_updated` hook handles updates, and a `user_created` observer handles
  accounts created by an admin, a CSV upload or a web service.
- Invalid values entered outside signup are stored as typed.

**Consequences.**

- **New accounts.** Every self-registered account has a valid mobile number.
- **Other paths.** Accounts created or edited through other paths may still have no number or an invalid one.
- **The hook never throws.** It runs on every user update, so it must not break unrelated saves such as CSV uploads,
  admin edits or authentication syncs.

**Alternatives rejected.**

- **Throwing from `before_user_updated` to reject invalid numbers.** It would turn a bad phone number into an
  exception page on every path that updates users.
- **Overriding the core profile forms.** A local plugin cannot do that without modifying core.

## ADR-03: Mobile numbers only, stored in E.164

**Context.** The number is used to contact students, so landlines are not useful. Stored values must be comparable and
exportable. The `phone1` column is `char(20)` (`public/lib/db/install.xml`). Students type numbers in many forms:
national or international, with spaces, and with Arabic-Indic, Persian or full-width digits.

**Decision.**

- **Mobile only.** Accept only numbers libphonenumber classifies as `MOBILE`, or as `FIXED_LINE_OR_MOBILE` for regions
  where the two cannot be told apart, such as North America.
- **E.164 storage.** Store the number as `+<country code><number>`, at most 16 characters, so it fits the column.
- **Digit conversion.** Convert Arabic-Indic, Persian and full-width digits, and the full-width plus sign, to ASCII
  before parsing.
- **National numbers.** Parse a number without an international prefix in the region of the selected country. Reject it
  as ambiguous when that region is not Saudi Arabia and the same digits are also a valid Saudi number. The user is then
  asked for the country code.
- **Extensions.** Never normalise a number with an extension, because E.164 would drop it. Signup rejects such numbers;
  other paths keep them as typed.

**Consequences.**

- **One clean format.** New values have a single canonical form, for example `+966501234567`.
- **Clear errors.** Ambiguous input fails with a clear message instead of being stored under the wrong country.
- **Legacy formats remain** until each number is next changed (see ADR-13).

**Alternatives rejected.**

- **Accepting any valid number.** Landlines are not useful for contacting students.
- **Storing the number as typed, or in international format with spaces.** That gives inconsistent data, and some
  formats exceed 20 characters.
- **Picking a country silently for ambiguous numbers.** That stores wrong numbers without telling anyone.

## ADR-04: The server is the source of truth

**Context.** Client-side checks can be bypassed, and JavaScript may fail to load. Core already bundles libphonenumber
(`giggsey/libphonenumber-for-php-lite`, listed in `public/lib/thirdpartylibs.xml`) and exposes it through dependency
injection, as `core_sms` does.

**Decision.** All validation and normalisation happen in PHP in `classes/local/phone.php`:

- It uses `\core\di::get(\libphonenumber\PhoneNumberUtil::class)`.
- Its methods never throw: they return `null` or `false`.

The JavaScript picker is a UX layer only. It submits E.164 when it can, and the server validates and normalises again.
The form works without JavaScript.

**Consequences.**

- **No extra dependency.** Nothing is added on the PHP side.
- **Safe to call from hooks and observers**, because the parser never throws.
- **Two metadata copies.** The browser and the server use different copies of libphonenumber's metadata (the one
  bundled with intl-tel-input and core's). Updating the vendored library keeps them close.

**Alternatives rejected.**

- **Trusting the picker's output or a hidden input.** A crafted request could bypass it.
- **A regular expression, or core's format-only helpers.** They cannot tell mobile numbers from landlines or check
  them against each country's numbering plan.

## ADR-05: Vendor intl-tel-input and load it at runtime

**Context.**

- **No CDN or geo-IP.** Loading the library or its utilities from a CDN, or using its geo-IP country lookup, would send
  visitors' IP addresses to a third party. The plugin would then need a privacy declaration for an external location.
- **No AMD build.** intl-tel-input 29 ships no AMD build, and its utilities script is ESM only. Moodle's grunt build
  turns `import()` into RequireJS calls, so the documented `loadUtils: () => import(...)` pattern cannot load it.
- **CSS through `styles.css` breaks.** The library's CSS would go through the theme pipeline:
  - On an RTL site it is flipped, and the library's own `[dir=rtl]` rules are flipped a second time.
  - Moodle's CSS parser corrupts `image-set()`, so the flags disappear.
  - The relative `../img/` URLs no longer resolve.

**Decision.**

- **Pin and vendor.** Vendor intl-tel-input 29.5.4 in `thirdparty/intl-tel-input/`, declared in `thirdpartylibs.xml`,
  with no CDN and no geo-IP lookup. The initial country is Saudi Arabia.
- **JavaScript.** The AMD module `amd/src/phoneinput.js` injects the classic `intlTelInputWithUtils` build, which
  defines `window.intlTelInput` and already contains the utilities, only when the page has a `phone1` input.
- **CSS.** The library's CSS is linked unchanged with `$PAGE->requires->css()` from the `before_http_headers` output
  hook, on the page types that edit `phone1`: signup, own profile, admin user edit and enrolment. The hook runs before
  the page head is printed, which `css()` requires.
- **Cache busting.** File URLs carry the plugin version.

**Consequences.**

- **No third-party requests at runtime**, and the privacy provider stays `null_provider`.
- **Download cost.** The bundle is large, about 75 KB gzipped, but it is downloaded only on pages with a phone field.
- **Maintenance.** The vendored file has a local patch, the Arabic search normaliser (see [history.md](history.md)).
  Every upgrade must re-apply it and check for renamed options; `readme_moodle.txt` gives the steps.

**Alternatives rejected.**

- **CDN or geo-IP lookup.** Privacy, plus a runtime dependency on a third party.
- **Compiling the ESM sources into AMD with grunt.** It would need custom wrappers around the library and its lazy
  utilities, to be maintained at every upgrade.
- **Moodle 5.2's new ESM build pipeline.** Rejected: it is not documented for plugins, and it is absent from Moodle
  5.1, which the plugin also supports.
- **Adding the CSS to `styles.css`.** It breaks on RTL sites, as described above.

## ADR-06: Keep the dial code in the field

**Context.** intl-tel-input 28 and later default to `separateDialCode: true` and `strictMode: true`:

- With `separateDialCode`, a typed `+` opens the country search instead of entering the field.
- `strictMode` rejects characters while typing.

Yet the error message tells users to type the country code, and many paste numbers as `+971…` or `00971…`. Also, the
library selects a country only from numbers that start with `+`.

**Decision.**

- Set `separateDialCode: false` and `strictMode: false`, so the dial code stays visible in the field and can be typed
  or pasted.
- Replace a leading `00` (also in Arabic-Indic or Persian digits) with `+` while typing, so the flag follows the
  number.

**Consequences.**

- **Any input style works.** Users can type national numbers, `+` numbers or `00` numbers.
- **Errors move to validation.** Letters and over-long input are no longer blocked while typing. They are reported by
  validation instead.

**Alternatives rejected.**

- **Library defaults.** Users could not follow the instruction in the error message, and pasted international numbers
  were mangled.

## ADR-07: Submit untouched values exactly as stored

**Context.** When the picker starts, it reformats the stored value for display. Some legacy values (for example short,
invalid or old-format numbers) came out in a form that lost the country code. Submitting the displayed text would have
silently changed a number the user never touched, merely because they saved the profile for another reason.

**Decision.**

- **Remember the start state.** The module records three things when the picker starts: the original value, the
  displayed value and the selected country.
- **On submit:**
  - If neither the text nor the country changed, the original value is submitted exactly as stored.
  - If the user edited the number, its E.164 form is submitted.
- **On the server.** The `before_user_updated` hook also ignores values equal to the current one.

**Consequences.**

- Legacy numbers are only rewritten when their owner (or an admin) actually changes them.
- A Behat scenario covers a legacy value that the picker displays differently.

**Alternatives rejected.**

- **Always submitting the picker's output (`getNumber()`).** It corrupts legacy values.
- **Re-parsing the display form on the server.** It cannot recover a country code that has already been dropped.

## ADR-08: Validate once per edit, when leaving the field

**Context.**

- **The theme refocuses errors.** When a field error is shown through `core_form/events`
  (`notifyFieldValidationFailure`), `theme_boost/form-display-errors` moves the focus back to the field.
- **The first version trapped users.** It validated on every `blur`, so an invalid field pulled the focus back each
  time the user tried to leave. Keyboard and mouse users were stuck.
- **Core clears errors.** Core's own client-side `required` rule listens for `change` and clears the field's error
  message.

**Decision.**

- **On leaving the field.** Validate once, and only if the value or the country changed since the last validation.
  Moving focus to the field's own country button does not count as leaving.
- **On submit.** Validate again in a `submit` listener on `document`. It runs after the form's own listeners, so core
  cannot clear the message. An invalid number blocks the submission with a field error.
- **Clearing.** When the field is emptied, clear only the plugin's own "invalid number" message. Core's "required"
  message stays.

**Consequences.**

- Errors still show as soon as the user leaves an edited field, but they never trap the focus.
- The behaviour matches core's validate-on-change model.

**Alternatives rejected.**

- **Validating on every `blur`.** This was the first implementation, and it caused the focus trap.

## ADR-09: theme_remui inline editor: redirect the pencil, block the save service

**Context.** theme_remui's profile page has an inline editor, opened by a pencil icon:

- It rejects international numbers (it accepts only 10 digits), so a valid `+966…` number cannot be saved.
- It writes the profile through the web service `theme_remui_save_user_profile_settings` with a raw database update,
  so neither the plugin's validation nor its E.164 normalisation can run.
- The theme's files must not be modified.

**Decision (option A, chosen by the platform owner).**

- On RemUI profile pages, `amd/src/profileedit.js` makes the pencil open the core profile edit page:
  - `user/editadvanced.php` for users who may update any user;
  - `user/edit.php` otherwise.
- `theme_remui_save_user_profile_settings` is refused by the same guard as ADR-11 (`webservice_guard`).

**Consequences.**

- Profile edits go through `user_update_user()`, so the picker, the hook and core events all apply.
- Users edit their profile on the core form, not inline.
- The redirect depends on RemUI's markup. It must be checked after RemUI upgrades.

**Alternatives rejected.**

- **Intercepting the inline editor's save to validate and normalise in the browser (option B).** The server write
  would still be a raw database update that the plugin cannot check, and the theme's 10-digit check would still need
  overriding.
- **Patching RemUI's JavaScript or templates.** That modifies theme files, which is not allowed. A local plugin cannot
  override another theme's templates.

## ADR-10: Hide contact details on RemUI profiles through `control_view_profile`

**Context.**

- **RemUI shows contact details to other users.** Its profile pages show contact details (phone, e-mail, address,
  ...) to other users, ignoring core's identity field and e-mail display settings.
- **The theme must not be modified.** Its files are not changed, and a local plugin cannot override another theme's
  templates.
- **One usable check.** RemUI's layout makes a single access check: a call to `user_can_view_profile()`. That function
  (`public/user/lib.php`) consults plugins' `control_view_profile` callbacks, but only for non-owners and only when
  `forceloginforprofiles` is on.

**Decision (option 1, approved by the platform owner).**

- **The callback.** `local_alborhansignup_control_view_profile()` returns `VIEWPROFILE_PREVENT` only when all of these
  hold:
  - the page is printing its header;
  - the layout is `mypublic`;
  - the stack trace shows that the call comes from RemUI's profile layout file.

  Every other caller, including RemUI's page-header rendering, is unaffected, and owners never reach plugin callbacks.
- **What other users see.** RemUI then skips its own details block. Other users see core's profile details, which
  respect core's privacy settings.
- **Security check.** A core security check (`classes/check/profileprivacy.php`) reports an error if
  `forceloginforprofiles` is off, or if RemUI's layout no longer calls `user_can_view_profile()`.

**Consequences.**

- **Privacy restored.** Other users see only what core's identity and e-mail-display settings allow.
- **Less RemUI design for others.** Non-owners lose RemUI's "About" design on other people's profiles. Owners keep
  it.
- **Upgrade dependency.** The protection depends on RemUI's layout and on `forceloginforprofiles`. The security check
  and a note to re-check after every RemUI upgrade cover this.
- **Narrow heuristic.** The stack-trace match is a heuristic, but it is kept narrow by the page-state and layout
  conditions, and it is unit-tested through a pure helper (`profile_privacy::should_hide()`).

**Alternatives rejected.**

- **Output-buffer HTML stripping.** Fragile: it depends on RemUI's markup and clashes with core's own output
  buffering.
- **A RemUI child theme.** Heavy: the whole site would switch theme, and RemUI's settings would have to be copied and
  kept in sync after every customiser change.
- **Removing `moodle/user:viewdetails` from students.** Too blunt: it breaks profile links everywhere and does not
  cover teachers' profiles.
- **Redacting the header HTML in a later output hook.** The rendered header cannot be modified there.

## ADR-11: Block the `auth_email_signup_user` web service

**Context.** `auth_email_external::signup_user()` (`public/auth/email/classes/external.php`) creates self-registered
accounts but calls none of the signup-form callbacks. It is declared `ajax` with `loginrequired = false`, so anyone can
call it anonymously through `public/lib/ajax/service-nologin.php`. A caller could:

- choose any username, or register someone else's e-mail address as a username;
- skip the phone requirement added later.

The Moodle app is not used on the site.

**Decision.** Refuse the function through the core `override_webservice_execution` callback.
`\local_alborhansignup\local\webservice_guard::check()` throws for a short list of blocked functions, and the callback
otherwise returns `false` so core runs the real function. Self-registration stays available on `login/signup.php`.

**Consequences.**

- The form is the only self-registration path, so every rule the plugin enforces applies to every self-registered
  account.
- Self-registration from the Moodle app is not possible. That is acceptable while the app is not used. If the site
  adopts the app, this decision must be revisited.
- The same guard also blocks RemUI's inline profile save (ADR-09).

**Alternatives rejected.**

- **Re-implementing the username and phone rules inside the web service path.** Core offers no callback there.
- **Disabling only the mobile app service.** The anonymous AJAX endpoint would remain open.
- **Modifying core.** Not allowed.

## ADR-12: Own public repository; tests never on production

**Context.**

- **No history.** Until October 2026 the plugin existed only as a deployed directory, with no version control and no
  tests.
- **Shared tree.** The site's Moodle core tree has its own repository, which must not be modified.
- **Destructive test init.** PHPUnit and Behat initialisation drop and reinstall database tables and modify `vendor/`.

**Decision.**

- **Repository.** The plugin has its own public repository, starting from a byte-identical import of the deployed
  1.2.1 (tag `v1.2.1`).
- **Deployment.** A tagged release is copied explicitly into `<moodle>/public/local/alborhansignup`. Nothing is ever
  committed in the core repository.
- **Where tests run.**
  - GitHub Actions with moodle-plugin-ci: Moodle 5.2 with PHP 8.4 and MariaDB, Moodle 5.2 with PHP 8.3 and PostgreSQL,
    and Moodle 5.1 with PHP 8.4 and PostgreSQL.
  - A separate development checkout (`<dev-checkout>`), used for builds and checks.
- **Never on production.** PHPUnit and Behat are never run against the production site.

**Consequences.**

- **History and review.** Every change is reviewed, tested on two databases and two Moodle versions, and tagged.
- **Separate build checkout.** JavaScript is built with grunt in `<dev-checkout>`, where the plugin must be a real
  directory, not a symlink, and `amd/build/` is committed.
- **No rollback by file copy.** Moodle refuses version downgrades, so problems are fixed forward with a new version.

**Alternatives rejected.**

- **Committing the plugin in the site's core repository.** It mixes site and plugin history, and risks committing
  unrelated local changes.
- **Running tests on the production server.** It is destructive.

## ADR-13: No bulk migration of legacy phone numbers

**Context.** Existing accounts hold phone numbers in many formats from before 1.3.0. Normalising them in bulk would mean
guessing the country of national numbers, which is exactly the ambiguity ADR-03 refuses to resolve silently.
Management currently works with the existing export.

**Decision.** No bulk migration for now. A legacy number is normalised the next time it is changed, through the
profile forms (`before_user_updated`) or when an account is created (`user_created`). Unchanged values are never
rewritten (ADR-07).

**Consequences.**

- Old and new formats coexist in `phone1` for a while.
- Reports and exports must accept both formats.
- No number is changed without someone editing it.

**Alternatives rejected (for now).**

- **An upgrade step or CLI script that rewrites every `phone1`.** It risks storing wrong country codes for ambiguous
  numbers, and a bulk rewrite is hard to undo. It can be reconsidered as a separate, reviewed task with a dry-run
  report.

## ADR-14: No picker on the upload users "default values" step

**Context.** The upload users tool (`public/admin/tool/uploaduser/user_form.php`) shows a `phone1` field on its
"default values" step. That value is a template applied to many CSV rows, not one person's number.

**Decision.** The picker is not added there: `page_assets::PHONE_PAGETYPES` leaves that page out on purpose. Uploaded
numbers are still normalised on the server: the `user_created` observer handles new accounts, and the
`before_user_updated` hook handles updated ones.

**Consequences.**

- Admins can enter any default value, or none.
- Values from the CSV are normalised when they are valid and kept as typed otherwise.

**Alternatives rejected.**

- **Adding the picker there.** It would push a single person's E.164 number into a field meant as a bulk template, and
  block legitimate template values.
