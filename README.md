# local_alborhansignup

Rearranges the core email self-registration form (`login/signup.php`):

- removes the collapsible profile-category headers so custom profile fields render inline with core fields;
- moves the custom profile fields `certname` and `gender` to sit directly after the name fields (before `city`).

Implemented through the supported `local_<plugin>_extend_signup_form()` callback; no core or theme files are modified.
Edit the two constants at the top of `lib.php` to change the field list or anchor.

Since 1.1.0 the username field is hidden: the username is set server-side to the lower-cased e-mail
address (same rule the former `auth_flex` used) in the `post_signup_requests` hook (1.2.1 fix: a
QuickForm filter is discarded by moodleform::_process_submission()), and "account already exists"
errors are reported under the e-mail field so the user can see them.
