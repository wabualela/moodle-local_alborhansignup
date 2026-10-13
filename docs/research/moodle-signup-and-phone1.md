# Moodle signup flow, phone1 and related extension points (Moodle 5.1/5.2)

This note collects what we learned from reading the Moodle core source (5.1.0 and 5.2.x) while building this
plugin. Paths are relative to the Moodle code root, inside `public/` (for example `public/login/signup.php`).
Line numbers shift between point releases, so this note gives function names rather than line numbers.

## 1. Email self-registration: order of events

`public/login/signup.php` with `$CFG->registerauth = 'email'`:

| Step | Code | Plugin callback |
|---|---|---|
| 1 | `core_login_pre_signup_requests()` (`public/login/lib.php`) | `<plugin>_pre_signup_requests()` |
| 2 | `$authplugin->signup_form()` builds `login_signup_form` (`public/lib/authlib.php`) | |
| 2a | `moodleform::__construct()` calls `definition()`, then `after_definition()`, then `_process_submission()` | |
| 2b | in `definition()` (`public/login/signup_form.php`): core fields, `profile_signup_fields()`, then `core_login_extend_signup_form()` | `<plugin>_extend_signup_form(MoodleQuickForm $mform)` |
| 3 | `get_data()` runs `validation()`: plugin errors first, then captcha, then `signup_validate_data()` | `<plugin>_validate_extend_signup_form(array $data): array` |
| 4 | `signup_setup_new_user()` (`public/lib/authlib.php`): confirmed, secret, auth, timestamps | |
| 5 | `core_login_post_signup_requests($user)` | `<plugin>_post_signup_requests($user)` |
| 6 | `$authplugin->user_signup($user, true)` → `user_signup_with_confirmation()` (`public/auth/email/auth.php`): password hash, `user_create_user($user, false, false)`, `profile_save_data()`, `user_created` event, confirmation e-mail | |

What matters for a plugin:

- **No Hooks API replacement.** Moodle 5.1 and 5.2 have no hook for the signup form. The dispatchers in
  `public/login/lib.php` call `get_plugins_with_function()` without `migratedtohook`, so the legacy `lib.php`
  callbacks are the supported mechanism and are not deprecated.
- **The form is built before `$OUTPUT->header()`.** `extend_signup_form` therefore runs before the head is printed,
  so `$PAGE->requires->css()` and `js_call_amd()` are both still allowed there.
- **Error precedence.** `validation()` merges the plugin errors first and adds core's errors with `+=`, so a
  plugin error wins when both report the same element.
- **`post_signup_requests` receives the object that is saved.** Changes made to `$user` there (username, phone1)
  reach `user_create_user()`. It runs after validation, so it is the right place to derive or normalise values.
- **Only declared elements are exported.** `exportValues()` walks the form elements, so a value must be a real form
  element to reach `$user`. Extra POST keys are ignored.

### QuickForm pitfalls we hit

- `applyFilter()` set in `definition()` is lost: `_process_submission()` calls `updateSubmission()`, which replaces
  the submitted values with a cleaned copy of `$_POST`. Derive values in `post_signup_requests` instead (the 1.2.1
  bug: real signups failed with `invalidusernameblank`, while tests that stopped at validation passed).
- `setConstant()` overrides any posted value, which is how a hidden field is made tamper-proof.
- `removeElement($name, $removeRules = true)` drops the element and its rules but leaves the name in `_required`.
- `insertElementBefore()` stores a reference to the variable it is given: `unset($element)` inside a loop that
  moves several elements, or the next assignment overwrites the element inserted in the previous iteration.
- Always test signup end to end, up to account creation.

### The signup web service

`auth_email_signup_user` (`public/auth/email/classes/external.php`) is declared `ajax` and `loginrequired => false`,
so it can be called anonymously through `public/lib/ajax/service-nologin.php`. It calls `signup_validate_data()`,
`signup_setup_new_user()` and `user_signup_with_confirmation()`, but none of the three form callbacks, and it has no
`phone1` parameter. Any rule enforced by those callbacks is therefore not enforced there. This plugin blocks it
(section 5).

## 2. How phone1 is stored

| Property | Value |
|---|---|
| Column | `user.phone1`, `char(20)`, NOT NULL (`public/lib/db/install.xml`) |
| PARAM type | `PARAM_NOTAGS` (`\core\user::get_property_type('phone1')`): `fix_utf8` + `strip_tags`, no format check |
| Default on create | none set by `user_create_user()` |
| Truncation | only `truncate_userinfo()` during external auth sync; elsewhere more than 20 characters fails the DB write |
| Core strings | `phone1` = "Phone", `phone2` = "Mobile phone" (`public/lang/en/moodle.php`) |

- A signup form element named `phone1` is saved by core with no extra code: `user_create_user()` inserts the object
  and the DB layer drops keys that are not columns.
- E.164 is at most `+` and 15 digits (16 characters), so it fits the column.
- `phone1` is already exported by bulk user download, selectable in `showuseridentity`, and covered by the
  `core_user` privacy provider, which is why this plugin stores the number there and can remain a null provider.
- The core profile shows `phone1` only when it is in `showuseridentity` and the viewer has
  `moodle/site:viewuseridentity` (`public/lib/myprofilelib.php`).

## 3. Where phone1 is written

| Place | Form / path | Saved through | Notes |
|---|---|---|---|
| Own or other profile | `public/user/edit.php`, `user_edit_form` | `user_update_user()` | element in `useredit_shared_definition()` (`public/user/editlib.php`): text, maxlength 20, forced LTR, in the collapsed "Optional" section, no validation |
| Admin edit / add user | `public/user/editadvanced.php` | `user_update_user()` / `user_create_user()` | auth field locks are not applied here |
| Signup | `public/login/signup.php` | `user_create_user()` | core has no phone1 here; this plugin adds it |
| enrol_apply (third party) | `public/enrol/index.php`, reuses `useredit_shared_definition()` when standard profile fields are shown | not saved to `user` | value only goes into the notification e-mail |
| Upload users, "default values" step | `public/admin/tool/uploaduser/user_form.php` | template for many rows | the picker is deliberately not added here |
| CSV upload, web services, OAuth2 mapping | `core_user_create_users`, `core_user_update_users`, OIDC `phone` claim | create / update functions | no form at all |

Auth field locks (`field_lock_phone1`) freeze the field on `user/edit.php` only: a frozen field is printed as static
text plus a hidden input. QuickForm forces `type="text"`; use `inputmode="tel"` and `autocomplete="tel"` instead.

## 4. User hooks and events

**`\core_user\hook\before_user_updated`** is dispatched only inside `user_update_user()`, before
`core_user::validate()`, the changed-fields comparison and `update_record()`.

- `$hook->user` is a `readonly` property, but the object is mutable: `$hook->user->phone1 = ...` is saved.
- It runs for every `user_update_user()` call (profile forms, upload, web services). The callback must be cheap,
  idempotent and must never throw (the hook manager does not catch exceptions).
- Compare with `$hook->currentuserdata` and only touch a changed value, so unrelated updates do not rewrite legacy
  data.
- It cannot send an error back to the form. Core profile forms have no plugin validation callback, so on those forms
  validation can only happen in the browser; the server can only normalise.

**No hook before user creation.** `user_create_user()` dispatches none. The fallback is an observer on
`\core\event\user_created` that fixes the row with `$DB->set_field()` after the insert. Never call
`user_update_user()` from it (hooks and events would fire again). The event fires on every creation path, signup
included (auth_email triggers it itself after `profile_save_data()`).

## 5. Other callbacks used by this plugin

| Callback | Called from | Contract |
|---|---|---|
| `<plugin>_override_webservice_execution($function, $params)` | `core_external\external_api::call_external_function()` (AJAX) and `webservice_base_server::execute()` (token protocols) | runs after parameter validation; return `false` to let core run the real function, anything else replaces the result; throwing aborts the call with an error response |
| `<plugin>_control_view_profile($user, $course, $usercontext)` | `user_can_view_profile()` via `user_process_profile_callbacks()` (`public/user/lib.php`) | only when `forceloginforprofiles` is on, after the deleted-user and owner checks; returns `\core\user::VIEWPROFILE_PREVENT`, `FORCE_ALLOW` or `DO_NOT_PREVENT`; PREVENT wins |
| `<plugin>_security_checks()` | `\core\check\manager::get_security_checks()` | returns `\core\check\check[]`, shown in Site administration > Reports > Security checks |

All legacy callback lists, hook callbacks and observers are cached: bump the plugin version and run the upgrade
when adding one.

## 6. Output hooks and `$PAGE->requires`

`css()` throws a `coding_exception` once the head is printed (`headdone`). `js_call_amd()` has no state check: it is
printed by the end code in `footer()`.

| Hook (`\core\hook\output\...`) | When | `css()` | `js_call_amd()` |
|---|---|---|---|
| `before_http_headers` | first thing in `core_renderer::header()`, before the layout is rendered | yes | yes |
| `before_standard_head_html_generation` | in `standard_head_html()`, before `get_head_code()` | yes | yes |
| `before_standard_top_of_body_html_generation`, `after_http_headers` | after the head | no | yes |
| `before_footer_html_generation` | in `footer()` | no | yes |

- `pagetype` (derived from the script path in `set_url()`) and `pagelayout` are already set when
  `before_http_headers` fires.
- The `maintenance` layout prints no end-of-body code, so AMD calls are dropped there.
- `js_call_amd()` arguments over 1024 characters trigger a developer debugging warning; preload strings with
  `strings_for_js()` instead of passing them.

## 7. Core libphonenumber

Moodle bundles `giggsey/libphonenumber-for-php-lite` (9.0.12 in 5.2), namespace `\libphonenumber`, available
through dependency injection and already used by `core_sms`:

```php
$util = \core\di::get(\libphonenumber\PhoneNumberUtil::class);
$number = $util->parse($raw, 'SA');               // throws \libphonenumber\NumberParseException
$util->isValidNumber($number);
$util->getNumberType($number);                    // PhoneNumberType::MOBILE, FIXED_LINE_OR_MOBILE, ...
$util->format($number, \libphonenumber\PhoneNumberFormat::E164);
```

- Core has no `PARAM_PHONE`. No vendored PHP library is needed.
- Some unusual Unicode input raises a `RuntimeException`, so catch it too.
- The plugin converts Arabic-Indic, Persian and full-width digits (and the full-width plus) to ASCII before parsing.

## 8. Testing notes

- **PHPUnit signup:** `set_config('registerauth', 'email')`, require `$CFG->dirroot . '/login/signup_form.php'`, call
  `login_signup_form::mock_submit($data)`, then `new login_signup_form()` and `get_data()`. End to end: then
  `signup_setup_new_user()`, `core_login_post_signup_requests()` and `get_auth_plugin('email')->user_signup($user,
  false)` inside `redirectEmails()`, and assert the database row.
- **The data generator triggers `user_created`**, so observers run on generated users. Write legacy fixtures with
  `$DB->set_field()` after creation.
- **Behat:** signup needs `registerauth=email` (add `passwordpolicy=0` for simple passwords). On core profile forms
  `phone1` is labelled "Phone" and sits in the collapsed "Optional" section (`I expand all fieldsets`). With
  JavaScript only visible text counts; on 5.1 "Confirm your account" is only in the page title.
- Never run PHPUnit or Behat against a production site: initialisation drops and reinstalls tables.
