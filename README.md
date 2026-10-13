# local_alborhansignup

Customises the core email self-registration form (`login/signup.php`) of Alborhan Academy:

- removes the collapsible profile-category headers so custom profile fields render inline with core fields;
- arranges the fields in the order given by `form_customiser::FIELD_ORDER`
  (`classes/local/form_customiser.php`): names, certificate name, e-mail, password, gender, city, country;
- hides the username field: the username is always the lower-cased e-mail address (same rule as the former
  `auth_flex`), set server-side in the `post_signup_requests` callback; a posted username is ignored;
- reports "account already exists" under the e-mail field, since the username field is hidden;
- refuses the anonymous `auth_email_signup_user` web service, which would bypass all of the above
  (self-registration happens on the website only).

Implemented through the supported `local_<plugin>_extend_signup_form()`, `local_<plugin>_validate_extend_signup_form()`,
`local_<plugin>_post_signup_requests()` and `local_<plugin>_override_webservice_execution()` callbacks (`lib.php`);
no core or theme files are modified.

## Requirements

Moodle 5.1 or later.

## Development

- Source: <https://github.com/wabualela/moodle-local_alborhansignup>. The plugin directory of the site is a deploy target
  only; commit here, never in the Moodle core repository.
- CI: GitHub Actions with [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) (code style, PHPDoc,
  PHPUnit, Behat) on every push.
- Never run PHPUnit or Behat against a production site: they need their own database and dataroot.

## License

GNU GPL v3 or later.
