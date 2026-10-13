# AGENTS.md: working on local_alborhansignup

Read this first if you are an AI coding agent (or a new developer). It covers what the plugin is, the rules you must
not break, and how to build, test, release and deploy it.

## What this is

`local_alborhansignup` is a Moodle local plugin (Moodle 5.1–5.2, PHP 8.3/8.4) for Alborhan Academy (an Arabic, RTL site
using theme_remui). It customises core email self-registration and the `phone1` field:

- signup form layout, hidden username derived from the e-mail, "account exists" under the e-mail field;
- required mobile phone stored in core `phone1` in E.164, with an intl-tel-input country picker;
- the theme_remui profile pencil opens the core edit page, and other users don't see contact details on RemUI profiles;
- the web services that would bypass the form are blocked.

It does all of this through supported callbacks and hooks, with no core or theme modifications. See [README.md](README.md),
[CHANGES.md](CHANGES.md) and [docs/](docs/).

## Rules (non-negotiable)

1. **Never touch the Moodle core repository** of a site. The plugin lives in its own repository and is deployed by an
   explicit copy (see [docs/deployment.md](docs/deployment.md)).
2. **Never run PHPUnit or Behat against a production site.** Their init drops and reinstalls database tables and
   modifies `vendor/`. Tests run in GitHub Actions, or in a separate dev checkout where a human runs them (the project
   settings deny the init commands to agents).
3. **Agents never send write requests to production**, not even as a "probe" you expect to fail. One such probe
   once created a real account. Agents check the live site only with read-only (GET) requests; any check that sends
   data is a manual check for a human, with a request that fails before any write (see
   [docs/deployment.md](docs/deployment.md)).
4. **Agents do not deploy.** Produce the runbook; a human runs the commands. Only a human with deployment rights runs
   production commands.
5. **The repository is public.** Never commit server hostnames, server paths, IPs, user counts, account names, e-mails,
   tracker links, credentials, or unfixed weaknesses of a live site. Use placeholders: `<moodle>` (Moodle code root
   containing `public/`), `<dev-checkout>`, `<plugin-repo>`.
6. **The server is the source of truth.** Phone validation and normalisation happen in PHP (`classes/local/phone.php`,
   core's libphonenumber). The JS picker is UX only, and the form must work without JavaScript.
7. **Moodle refuses version downgrades.** There is no rollback by copying old files. Fix forward with a higher version.
8. **Verify against the exact Moodle version's source.** Read the core code of the target version (read-only) rather
   than recalling APIs. Check both 5.1 and 5.2 when an API matters.

## Layout

| Path | Purpose |
|---|---|
| `lib.php` | Thin legacy callbacks: `extend_signup_form`, `validate_extend_signup_form`, `post_signup_requests`, `override_webservice_execution`, `control_view_profile`, `security_checks` |
| `classes/local/form_customiser.php` | Signup form changes, validation, post-signup (username, E.164) |
| `classes/local/phone.php` | Parse/validate/normalise numbers (never throws) |
| `classes/local/page_assets.php` | Which pages get the picker / RemUI pencil redirect |
| `classes/local/webservice_guard.php` | Blocked web service functions |
| `classes/local/profile_privacy.php` | Hides contact details on RemUI profiles from other users |
| `classes/check/profileprivacy.php` | Security check for that protection |
| `classes/hook_callbacks.php`, `db/hooks.php` | `before_user_updated` (normalise phone1), `before_http_headers` (page assets) |
| `classes/observer.php`, `db/events.php` | `user_created` (normalise phone1) |
| `amd/src/phoneinput.js` | Picker integration (built into `amd/build/`) |
| `amd/src/profileedit.js` | RemUI pencil → core edit page |
| `thirdparty/intl-tel-input/` | Vendored library (pinned, locally patched; see its `readme_moodle.txt`) |
| `tests/` | PHPUnit (`tests/*_test.php`, `tests/local/`, `tests/check/`) and Behat (`tests/behat/`) |
| `dev/` | Local tools: `check.sh`, `build.sh`, `ci-status.sh`, `jsdom/` harness (not deployed) |
| `docs/` | History, decisions, plans, research notes, testing, deployment, AI workflow (not deployed) |
| `.claude/` | Claude Code project settings, skills and saved workflows (not deployed) |

## Everyday commands

```bash
./dev/check.sh                      # php -l + phpcs (moodle-extra); ./dev/check.sh --install installs moodle-cs
export MOODLE_DEV=<dev-checkout>    # separate build checkout (no config.php); agents: set it in the launching shell or settings.local.json
./dev/build.sh                      # grunt amd + gherkinlint + stylelint, copies amd/build back
(cd dev/jsdom && npm ci)            # once: install the jsdom harness from its lockfile
node dev/jsdom/run.js [filter]      # browser-like checks of the picker
./dev/ci-status.sh --wait           # CI result for HEAD, with failure output from annotations
```

- `amd/build/` must be committed and must match what grunt produces. CI fails on a stale build.
- The plugin must be a **real directory** inside the dev checkout (`public/local/alborhansignup`). Symlinks break
  grunt's component detection. Moodle 5.2 needs Node 22 (`nvm use` in the checkout, `.nvmrc` = `lts/jod`).
- Use `--max-lint-warnings=0` with `=` (when calling grunt directly; moodle-plugin-ci handles the flag itself). The
  space-separated form is misparsed.

## Working method that worked here

1. **Research first** (read-only): core source of the target version, the library's real package, the theme's code.
   Several independent researchers in parallel, then a skeptic who tries to refute them.
2. **Plan**, and get the human's decision on anything that changes what users see (UX, privacy, data).
3. **Tests first** for behaviour changes. Keep `lib.php` thin and put logic in autoloaded classes.
4. **Adversarial review** after each phase (standards, behaviour/security, test critic, JS/a11y/RTL), then
   **verify the fixes** with the same probes (jsdom for the picker).
5. **CI must be green** on all legs before tagging. Bump `version.php` and update `CHANGES.md` for every release
   (hook/callback caches are keyed on the version).

See [docs/ai-workflow.md](docs/ai-workflow.md) for the tools, skills and saved workflows (`.claude/workflows/`).

## Gotchas (each cost real time)

- QuickForm: `applyFilter()` in `definition()` is discarded. Derive values in `post_signup_requests`.
  `insertElementBefore()` keeps a reference, so `unset()` the variable per loop. Constants override posted values.
- The core test generator's `create_user()` fires `user_created`, so observers run. Set legacy fixtures with
  `$DB->set_field()`.
- New autoloaded classes cause "class not found" until caches are purged. Deploy with maintenance mode and run the
  upgrade immediately.
- intl-tel-input v29: no AMD build. Load the classic WithUtils script at runtime and link its CSS with
  `$PAGE->requires->css()` (never `styles.css`, which gets RTL-flipped and parsed).
- Only `+` numbers select a country. Validate once per edit, not on every blur: boost's form-display-errors
  refocuses the field and trapped keyboard users.
- theme_remui: the inline profile editor accepts only 10-digit phones and writes with a raw DB update. The
  profile-privacy protection depends on RemUI's layout, so re-check the security check after RemUI upgrades.
- GitHub Actions logs need authentication. CI publishes PHPUnit/Behat failure output as annotations, which
  `dev/ci-status.sh` reads.
