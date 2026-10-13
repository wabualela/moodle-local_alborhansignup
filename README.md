# local_alborhansignup

Customises the core email self-registration form (`login/signup.php`) of Alborhan Academy:

- removes the collapsible profile-category headers so custom profile fields render inline with core fields;
- arranges the fields in the order given by `form_customiser::FIELD_ORDER`
  (`classes/local/form_customiser.php`): names, certificate name, e-mail, mobile phone, password, gender, city, country;
- collects a required mobile phone number in the core field `phone1`, validated with core's libphonenumber and stored in
  E.164 (`+966501234567`); `phone1` is also normalised whenever a user is created or updated elsewhere;
- hides the username field: the username is always the lower-cased e-mail address (same rule as the former
  `auth_flex`), set server-side in the `post_signup_requests` callback; a posted username is ignored;
- reports "account already exists" under the e-mail field, since the username field is hidden;
- adds a country picker ([intl-tel-input](https://github.com/jackocnr/intl-tel-input), `thirdparty/`) to every
  editable `phone1` field (signup, profile editing, admin user editing, enrol_apply);
- on theme_remui profile pages, the "edit" pencil opens the core profile editing page (RemUI's inline editor writes
  the profile with a raw database update and rejects international numbers);
- on theme_remui profile pages, other users no longer see contact details (RemUI ignores core's identity and e-mail
  display settings); they see core's profile details instead. A security check reports whether this still works;
- refuses the anonymous `auth_email_signup_user` web service, which would bypass all of the above
  (self-registration happens on the website only), and RemUI's inline profile save service.

Implemented through supported extension points only; no core or theme files are modified:

- `lib.php` callbacks: `extend_signup_form`, `validate_extend_signup_form`, `post_signup_requests`,
  `override_webservice_execution`, `control_view_profile` and `security_checks` (thin wrappers around the classes in
  `classes/`);
- hooks (`db/hooks.php`): `core_user\hook\before_user_updated` (normalise `phone1`) and
  `core\hook\output\before_http_headers` (picker and RemUI pencil assets);
- event observer (`db/events.php`): `\core\event\user_created` (normalise `phone1`).

## Requirements

Moodle 5.1 or later.

## Documentation

- [AGENTS.md](AGENTS.md): rules, layout and commands for developers and AI agents ([CLAUDE.md](CLAUDE.md) for Claude Code).
- [docs/history.md](docs/history.md): how the plugin evolved, bugs found and lessons learned.
- [docs/decisions.md](docs/decisions.md): design decisions and the alternatives rejected.
- [docs/plans/](docs/plans/): implementation plans.
- [docs/research/](docs/research/): technical notes on Moodle signup/`phone1`, intl-tel-input and theme_remui.
- [docs/testing.md](docs/testing.md), [docs/deployment.md](docs/deployment.md), [docs/ai-workflow.md](docs/ai-workflow.md).

## Development

- Source: <https://github.com/wabualela/moodle-local_alborhansignup>. The plugin directory of the site is a deploy target
  only; commit here, never in the Moodle core repository.
- JavaScript: `amd/src` is built into `amd/build` with `dev/build.sh`, which runs grunt in a separate Moodle 5.2
  checkout (Node 22, no `config.php`): `export MOODLE_DEV=<dev-checkout>`, then `./dev/build.sh`. Commit both
  directories. See [docs/testing.md](docs/testing.md) for `dev/check.sh` and the jsdom harness.
- CI: GitHub Actions with [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) (code style, PHPDoc,
  PHPUnit, Behat) on every push.
- Never run PHPUnit or Behat against a production site: they need their own database and dataroot.

## License

GNU GPL v3 or later.
