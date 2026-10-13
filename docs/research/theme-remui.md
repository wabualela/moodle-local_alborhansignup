# theme_remui: what this plugin works around

theme_remui (Edwiser, commercial) changes several pages that this plugin touches. This note describes the RemUI
5.2.x behaviour that mattered, the mitigation implemented in the plugin, and what to re-check after every RemUI
upgrade. RemUI is not available in CI, so these parts are covered by PHPUnit tests of the decision logic and by
manual checks. The RemUI-specific PHP and JavaScript only act when theme_remui is active.

Theme files are cited relative to the theme directory (`public/theme/remui/`); core files and pages by their path
inside Moodle (`public/...`). Page names such as `user/profile.php` are the URLs users open.

## 1. Things that just work

- **Signup form.** RemUI's `render_login_signup_form()` only wraps the core form HTML
  (`templates/core/signup_form_layout.mustache`, `{{{formhtml}}}`), so added elements keep their ids
  (`id_phone1`) and core's error markup (`id_error_<name>`). RemUI overrides `core_form/element-template` but not
  `element-text`, and still enhances every element with `theme_boost/form-display-errors`.
- **Output hooks.** RemUI does not override `header()` or `footer()`, so `before_http_headers` and
  `before_footer_html_generation` fire normally on every RemUI layout.
- **Head output.** RemUI overrides `standard_head_html()`. It dispatches `before_standard_head_html_generation`,
  but it also calls the legacy `before_standard_html_head` callbacks itself and then replaces the collected output
  with the hook's output. A plugin must therefore not implement the legacy head callback: it would run twice and
  part of its output would be discarded. This plugin uses `before_http_headers`.

## 2. The inline profile editor

RemUI's profile pages use the `mypublic` layout (`user/profile.php`, `user/view.php`, `user/profilesys.php`). For the
profile owner (with `moodle/user:editownprofile`) the "About me" block has a pencil that reveals an inline editor.

| Behaviour | Consequence |
|---|---|
| The phone input is `#phone_number`, not `phone1` | the picker's selector does not match it |
| The inline editor rejects international numbers (it accepts only 10 digits) | once `phone1` holds E.164 (`+966...`), every inline save fails, even when the phone was not touched |
| Saving calls the AJAX web service `theme_remui_save_user_profile_settings`, which writes the profile with a raw database update | the plugin's validation and `before_user_updated` normalisation never run on that path |

**Mitigation ("option A"):**

1. `amd/src/profileedit.js` listens for `click` and `keydown` in the **capture phase** on `document`. A click on
   `.edit-profile-icon-wrapper .edw-icon-Edit` is stopped (`preventDefault()` + `stopImmediatePropagation()`) and
   the browser goes to the core editing page instead. RemUI binds its jQuery handler directly on the span, so a
   capture listener runs first whatever the module load order. Replacing the element would not work if RemUI's
   module ran after ours.
2. The target URL is built on the server (`page_assets::profile_edit_url()`) with the same rule as core's "Edit
   profile" link in `public/lib/myprofilelib.php`: `user/editadvanced.php` for users with `moodle/user:update`,
   otherwise `user/edit.php` (`returnto=profile`, plus the course on `user/view.php`). There the picker and the
   `before_user_updated` normalisation apply.
3. RemUI renders the pencil as a bare span, so the module also gives it `role="link"`, `tabindex="0"` and an
   accessible name.
4. `theme_remui_save_user_profile_settings` is added to `webservice_guard::BLOCKED_FUNCTIONS`, enforced through the
   core `override_webservice_execution` callback, so the raw write cannot be reached directly either.

Rejected alternatives: rewriting the inline value to E.164 (the 10-digit check rejects it); hiding the phone input
with CSS (the value is still checked on every save); intercepting the save and calling the web service ourselves
(still a raw database update that the plugin cannot validate or normalise).

## 3. Contact details on profile pages

**Behaviour.** RemUI's profile pages (layout `layout/mypublic.php`) show contact details (phone, e-mail, address,
...) to other users, ignoring core's identity field and e-mail display settings. A local plugin cannot override a
theme's templates: the Mustache template finder searches the active theme and its parents before the component.

Core's own profile tree (`public/lib/myprofilelib.php`) already applies the right rules: `phone1` only when it is an
identity field the viewer may see, and e-mail according to `maildisplay`.

**Mitigation ("option 1"):** `local_alborhansignup_control_view_profile()` →
`\local_alborhansignup\local\profile_privacy`.

- RemUI's only access check in its profile layout is a call to `user_can_view_profile()`. The callback returns
  `\core\user::VIEWPROFILE_PREVENT` only when all of these hold:
  - the page is in `STATE_PRINTING_HEADER` (RemUI's layout file runs while the header is printed);
  - the page layout is `mypublic`;
  - the stack trace contains a `user_can_view_profile` frame called from `theme/remui/layout/mypublic.php`.
- Owners never reach plugin callbacks (`user_can_view_profile()` returns early for them), so they keep the full
  RemUI page.
- For other viewers RemUI skips its user section (general details, tabs, "About me"). The page still renders core's
  profile tree, which follows core's privacy settings.
- Every other call is unaffected, including RemUI's own `context_header()` call made during the same header. The
  profile heading and message buttons remain.
- `styles.css` shows `#page-header` again when RemUI's profile block is absent
  (`body#page-user-profile:not(:has(.profile-general-info-wrapper))`), because RemUI hides the core header on
  profile pages.
- The decision is a pure function (`profile_privacy::should_hide()`), so PHPUnit can test it without RemUI.

**Limits.**

- Plugin callbacks only run when `forceloginforprofiles` is on.
- The mitigation depends on RemUI keeping the `user_can_view_profile()` call in that layout file at that path.
- Non-owners see core's profile layout instead of RemUI's design.
- `$PAGE->add_body_class()` cannot be called from the callback (the page is already past the header state).

**Security check.** `local_alborhansignup_security_checks()` adds `\local_alborhansignup\check\profileprivacy`
(Site administration > Reports > Security checks):

| Situation | Result |
|---|---|
| Site theme is not `remui` | N/A |
| `forceloginforprofiles` is off | Error (callbacks are skipped, the protection is off) |
| RemUI's `layout/mypublic.php` is missing or no longer calls `user_can_view_profile(` | Error (RemUI layout changed) |
| Otherwise | OK |

Rejected alternatives: stripping the HTML with an output buffer (fragile, depends on RemUI markup, nests with core's
own buffering); a RemUI child theme (switches the whole site's theme and duplicates RemUI settings);
`after_http_headers` (it can only append to the header HTML); switching the layout in `before_http_headers` (kept as
a fallback if RemUI ever drops the access check).

## 4. After every RemUI upgrade

Re-check these after every RemUI upgrade: in a test environment with RemUI if one is available, otherwise a human
checks right after the upgrade (agents: anonymous GET checks only, never write requests):

1. **Security check** for this plugin is OK.
2. `layout/mypublic.php` still calls `user_can_view_profile()` before building the user context, and the file path
   is unchanged (it is hard-coded in `profile_privacy::REMUI_LAYOUT`).
3. As another user, open a profile through `user/profile.php` and `user/view.php`. The page source must not contain
   the owner's phone, e-mail or address. The heading and message buttons must still appear.
4. As the owner, the full RemUI "About me" block is still shown, and the pencil still opens the core edit page
   (selector `.edit-profile-icon-wrapper .edw-icon-Edit`).
5. The inline save web service still has the same name (`theme_remui_save_user_profile_settings`) and is refused.
6. The profile header class used in `styles.css` (`.profile-general-info-wrapper`) still exists.
7. The signup form still renders through `{{{formhtml}}}` and shows the picker and field errors.
8. RemUI still does not override `header()` (the plugin adds its assets from `before_http_headers`) or
   `footer()` (AMD calls are printed by core's end code).
