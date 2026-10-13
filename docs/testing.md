# Testing and quality checks

How changes to local_alborhansignup are checked before they reach a site. There are four layers:

1. **GitHub Actions CI** on every push: the reference result (code style, PHPDoc, plugin validation, JavaScript
   build, PHPUnit and Behat on three Moodle/PHP/database combinations).
2. **Local checks** before pushing: `dev/check.sh` (PHP syntax and coding style) and `dev/build.sh` (JavaScript
   build and linters).
3. **The jsdom harness** (`dev/jsdom/`): fast, browser-like checks of the phone country picker.
4. **Manual end-to-end acceptance** by a human right after deployment, up to the creation of a real account (see
   [deployment.md](deployment.md)).

## The rule: never run PHPUnit or Behat on a production site

PHPUnit and Behat need their own database tables, data root and (for Behat) web root. Their init scripts are
destructive by design:

- `public/admin/tool/phpunit/cli/init.php` calls `testing_update_composer_dependencies()`
  (`public/lib/testing/lib.php`), which downloads composer and installs the development dependencies into the
  code tree's `vendor/`.
- It then drops and reinstalls a whole site (`phpunit_util::drop_site()` and `install_site()`, called from
  `public/admin/tool/phpunit/cli/util.php`; `drop_site()` is in `public/lib/classes/test/phpunit/phpunit_util.php`).
  The tables of the configured test prefix are dropped and the test data root is emptied. The safety checks only
  reject a prefix or data root identical to the site's own; a typo or a shared database user is enough to lose
  production data.
- Behat requires `$CFG->behat_wwwroot` to differ from `$CFG->wwwroot` (`public/lib/behat/lib.php`), which means
  a second virtual host, a second data root and a Selenium browser.

Tests therefore run only in GitHub Actions or in a separate development checkout with its own database (for
example [moodle-docker](https://github.com/moodlehq/moodle-docker)). The same spirit applies to manual tests: agents
never send write requests to production, not even as a "probe" expected to fail. The only write on production is
the human's acceptance signup right after deployment (see [Manual end-to-end testing](#manual-end-to-end-testing)).

## Continuous integration

Workflow: [`.github/workflows/moodle-plugin-ci.yml`](../.github/workflows/moodle-plugin-ci.yml), triggered on
`push`, `pull_request` and `workflow_dispatch`. It is based on the template of
[moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) 4.x (4.5.11 when this was written), which checks
out the requested Moodle branch, installs the plugin into `public/local/alborhansignup` and installs a test site.

### Matrix

`fail-fast: false`, so every leg reports even when another fails.

| PHP | Moodle | Database | Why |
|---|---|---|---|
| 8.4 | `MOODLE_502_STABLE` | MariaDB 11 | the production combination |
| 8.3 | `MOODLE_502_STABLE` | PostgreSQL 17 | lowest PHP of Moodle 5.2, the other database family |
| 8.4 | `MOODLE_501_STABLE` | PostgreSQL 17 | lowest supported Moodle (`version.php` requires 5.1) |

### Steps

Every check step has `if: ${{ !cancelled() }}`, so one push shows all problems, not only the first.

| Step | Command | What it checks | Blocking |
|---|---|---|---|
| PHP Lint | `moodle-plugin-ci phplint` | PHP syntax | yes |
| PHP Mess Detector | `moodle-plugin-ci phpmd` | code smells | no (`continue-on-error`) |
| Moodle Code Checker | `moodle-plugin-ci phpcs --max-warnings 0` | moodle-cs, standard `moodle`, inside the Moodle checkout; warnings fail | yes |
| Moodle PHPDoc Checker | `moodle-plugin-ci phpdoc --max-warnings 0` | PHPDoc completeness | yes |
| Validating | `moodle-plugin-ci validate` | plugin structure: `version.php`, language strings, naming | yes |
| Check upgrade savepoints | `moodle-plugin-ci savepoints` | `db/upgrade.php` savepoints (none yet) | yes |
| Mustache Lint | `moodle-plugin-ci mustache` | templates (none yet) | yes |
| Grunt | `moodle-plugin-ci grunt --max-lint-warnings 0` | grunt `amd`, `yui`, `gherkinlint`, `stylelint`; fails with "File is stale and needs to be rebuilt" when the committed `amd/build` differs from a fresh build | yes |
| PHPUnit tests | `moodle-plugin-ci phpunit --fail-on-warning` | all PHPUnit tests of the plugin; PHPUnit warnings fail too | yes |
| Behat features | `moodle-plugin-ci behat --profile chrome --scss-deprecations` | all `@local_alborhansignup` features, in Chrome (Selenium container) | yes |
| Annotate PHPUnit / Behat failures | shell, only after a failure | publishes the failure output as annotations (see below) | - |
| Upload Behat Faildump | `actions/upload-artifact` | screenshots and HTML of failed Behat steps, kept 7 days | - |

### Reading failures without logging in: annotations

Job logs and artifacts can only be downloaded by a signed-in GitHub user, even for a public repository. Check-run
annotations, however, are readable anonymously through the REST API
(`GET /repos/{owner}/{repo}/check-runs/{job_id}/annotations`). The workflow uses that:

1. The PHPUnit and Behat steps run with `set -o pipefail` and `tee` their output into `$RUNNER_TEMP`.
2. When one of them fails, an "Annotate ... failures" step removes blank lines and docker pull progress lines
   (`^[0-9a-f]{12}: `), keeps the last 150 lines and splits them into chunks of at most 3500 bytes (annotation
   messages are truncated at about 4 KB).
3. Each chunk is escaped for a workflow command (`%` to `%25`, carriage returns dropped, newlines to `%0A`) and
   printed as `::error title=PHPUnit output (...)::<chunk>` (or `Behat output`).

`dev/ci-status.sh` reads them back:

```bash
dev/ci-status.sh                # result for HEAD
dev/ci-status.sh --wait         # poll every 60 s until the run has finished (or appears, right after a push)
dev/ci-status.sh --steps 1a2b3c # every step of every job, for another commit
```

It finds the run for the commit through the public API (repository from `git remote get-url origin`), prints
each job with its conclusion, the steps of the jobs that did not succeed, and, for failed jobs, the PHPUnit/Behat
output annotations in order. Exit status: 0 success, 1 failure, 2 no run yet or still running, 3 usage or API
error. It needs only `curl` and `python3`, sends only GET requests, and accepts an optional `GITHUB_TOKEN` to raise
the anonymous rate limit of 60 requests per hour.

Things to know about the annotations:

- The API does not return them in the order they were written; their `start_line` (line in the log) restores it.
- Commas separate workflow command properties and would cut the title short, so the matrix values are joined with
  spaces: `PHPUnit output (8.4 MOODLE_502_STABLE mariadb) phpunit.log.part.00`. The script groups the parts by the
  title without the `....part.NN` suffix and numbers them itself (titles of older runs, which were cut at the first
  comma, are handled too).
- Only the last 150 lines are published. For the full log or the Behat faildump, sign in on GitHub.

## Local checks: `dev/check.sh`

```bash
dev/check.sh --install          # once: moodle-cs ^3.7 with composer into dev/tools (git-ignored)
dev/check.sh                    # php -l on every plugin PHP file, then phpcs
PHP=php8.4 dev/check.sh         # lint with a specific PHP version
PHPCS=/path/to/phpcs dev/check.sh   # use an existing moodle-cs installation
```

It checks the plugin's own PHP files (not `thirdparty/`, `amd/build/`, `dev/tools/` or any `node_modules/` or
`vendor/`) and exits non-zero on any error **or warning**, like `--max-warnings 0` in CI. Other variables:
`PHPCS_STANDARD` (default `moodle-extra`), `PHPCS_MOODLE_BRANCH` (default `502`), `COMPOSER`.

Differences from CI:

| Aspect | `dev/check.sh` | CI |
|---|---|---|
| Standard | `moodle-extra` (stricter superset) | `moodle` |
| Moodle branch | given explicitly (`moodleBranch`) | detected from `public/version.php` of the checkout |
| Component of each file | known (temporary minimal Moodle root, see below) | not known (see below) |
| Other checks | none | PHPDoc checker, phpmd, validate, savepoints, mustache |

**Component context.** moodle-cs works out the Moodle branch and the component of each file from a Moodle code root
above it. When the plugin is checked on its own, outside a Moodle checkout, the sniffs that depend on that context
silently do nothing:

- language string ordering (`moodle.Files.LangFilesOrdering`) needs the branch;
- the `@package` check (`moodle.Commenting.Package`) and PHPUnit test case naming
  (`moodle.PHPUnit.TestCaseNames`) need the component.

So by default the script copies the plugin into a temporary directory laid out as a minimal Moodle root (empty
`version.php` and `config-dist.php`, the plugin under `local/alborhansignup`) and passes
`--runtime-set moodleRoot`, `moodleComponentsListPath` (a one-line component list) and `moodleBranch`.
`dev/check.sh --no-context` checks the tree in place: the ordering check still runs (the branch is always passed),
the component checks do not.

Inside a Moodle 5.1+ checkout, as in CI, moodle-cs 3.7.0 finds the code root and the branch (it knows the
`public/version.php` layout) but looks for `lib/classes/component.php` directly under the root, while 5.1 moved it
to `public/lib/`. It then cannot list the components, and the `@package` and test case name checks are skipped
there too (verified with a wrong `@package` tag in a 5.2 checkout: not reported). For these two checks the local run
is stricter than CI; for everything else CI is the reference.

## JavaScript build: `dev/build.sh`

`amd/src/*.js` is built into `amd/build/*.min.js` (plus source maps) with Moodle's grunt. Both directories are
committed, and CI fails when `amd/build` is stale. Grunt only works from inside a Moodle checkout, so the build uses a
separate development checkout, never a production code tree:

```bash
git clone --depth 1 --branch MOODLE_502_STABLE https://github.com/moodle/moodle.git <dev-checkout>
cd <dev-checkout> && nvm install && nvm use && npm ci     # Node 22 (.nvmrc: lts/jod)

cd <plugin-repo>
export MOODLE_DEV=<dev-checkout>
./dev/build.sh
```

The script:

1. checks the checkout (Moodle 5.1+ layout, no `config.php`, `node_modules` present), loads nvm if installed and
   runs `nvm use` with the checkout's `.nvmrc`, and compares Node with `engines.node` in its `package.json`
   (`>=22.11.0 <23` for Moodle 5.2);
2. copies the plugin into `<dev-checkout>/public/local/alborhansignup` with `rsync --delete`, leaving out `.git`,
   `dev/tools` and `node_modules`;
3. runs `npx grunt amd --max-lint-warnings=0` in its `amd/` directory (eslint, then rollup; any warning fails);
4. runs `npx grunt gherkinlint stylelint:css` in the plugin directory (Behat feature files, `styles.css`);
5. copies `amd/build` back and shows whether it changed. On failure nothing is copied back.

Why it works this way:

- **No `config.php`.** A build checkout is never installed, so it has no `config.php`. The script refuses a checkout
  that has one (an installed site, possibly a production tree, where `rsync --delete` would replace the deployed
  plugin) unless `MOODLE_DEV_ALLOW_CONFIG=1` is set for a development site.
- **A real directory, not a symlink.** The Gruntfile resolves the current directory with `fs.realpathSync()` and
  derives the component from the real path. A symlink to this repository resolves outside the checkout, and the
  component is then wrong or not found. If `public/local/alborhansignup` in the checkout *is* a clone of this
  repository, run the script from that clone and it builds in place.
- **`--max-lint-warnings=0` with `=`.** Grunt's option parser treats an unknown option without `=` as a boolean
  flag: `--max-lint-warnings 0` sets the option to `true` and runs `0` as a task name.
  (`moodle-plugin-ci grunt --max-lint-warnings 0` uses a different parser and is fine.)
- **Node 22.** Moodle 5.2 declares `>=22.11.0 <23` in `package.json`; the script refuses other major versions
  instead of letting grunt fail later.
- **`thirdpartylibs.xml`.** intl-tel-input is declared there; grunt's `ignorefiles` task turns that file into the
  `.eslintignore` and `.stylelintignore` entries that keep the vendored library out of the linters.

## PHPUnit tests

All test classes extend `advanced_testcase`, are `final`, and declare what they cover with PHPUnit attributes
(`CoversClass`, `CoversFunction`).

| File | Covers |
|---|---|
| `tests/local/form_customiser_test.php` | Username derived from the e-mail (lower case, trimmed, `extendedusernamechars`); field reordering keeps validation rules; only profile-category headers are removed; the username field becomes hidden and a form constant (a posted value cannot choose it) and stays visible without an e-mail field; the phone field (required, `PARAM_NOTAGS`, `autocomplete=tel`, forced LTR, added once); phone and "account already exists" validation; `post_signup()` sets the username and E.164 phone and leaves records without an e-mail alone. |
| `tests/signup_test.php` | Integration through the real core form (`public/login/signup_form.php`, submitted with `mock_submit()`) and auth_email: field order with the `certname` and `gender` profile fields, no category headers, hidden username; a full signup up to account creation (`signup_setup_new_user()`, `core_login_post_signup_requests()`, `user_signup()`) with a tampered username, checking the stored username and E.164 phone and the confirmation e-mail; existing account, missing phone and landline numbers rejected. |
| `tests/local/phone_test.php` | `phone::to_e164()` and `is_valid_mobile()` on a large data set: international, formatted, national and `00` forms; Arabic-Indic, Persian and full-width digits and plus sign (inputs that make libphonenumber throw unless normalised); landlines; country hints; ambiguous national numbers; extensions; too short or long; garbage. `phone::region()` fallbacks. |
| `tests/hook_callbacks_test.php` | The `before_user_updated` hook: a changed valid number is stored in E.164, the user's country is the hint, unchanged legacy, invalid and empty values are kept, updates without `phone1` are untouched. |
| `tests/observer_test.php` | The `user_created` observer: new accounts (including generator users) get E.164 numbers; invalid and empty values are kept. |
| `tests/local/webservice_guard_test.php` | `auth_email_signup_user` and `theme_remui_save_user_profile_settings` are blocked, other functions are not; an anonymous AJAX-style call (`external_api::call_external_function(..., true)`) to `auth_email_signup_user` is refused and creates no account, while `auth_email_get_signup_settings` still works. |
| `tests/local/page_assets_test.php` | Which page types and layouts get the picker (signup, own profile, admin user edit, enrolment page; not bulk upload, profile page or front page); the picker configuration (vendored script URL, Saudi Arabia first, excluded countries, locale, country names, under the 1024-character limit of `js_call_amd()` arguments); Moodle language codes mapped to `Intl` locales; the profile edit URL for users and administrators; CSS, strings and AMD module added only where needed; no RemUI pencil redirect under other themes. |
| `tests/local/profile_privacy_test.php` | `profile_privacy::should_hide()` from page state, layout and call stack (only RemUI's own check in its profile layout while the header is printed, Windows paths included); the callback never prevents access elsewhere; classmates can still view each other's profiles through core. |
| `tests/check/profileprivacy_test.php` | The security check's status for each situation (other theme, protected, `forceloginforprofiles` off, RemUI layout changed or missing) and its registration. |

A human runs them, in a development environment only (paths relative to the Moodle code root). The project settings
deny these init commands to agents, even in a dev checkout:

```bash
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_alborhansignup_testsuite
```

## Behat features

Both features are tagged `@local @local_alborhansignup`. Scenarios tagged `@javascript` run in Chrome with the
country picker; the others check the server side without JavaScript.

| Feature | Scenarios |
|---|---|
| `tests/behat/signup_phone.feature` | Without JavaScript: a full signup stores `0501234567`, `+971 50 123 4567` and `٠٥٠١٢٣٤٥٦٧` as E.164 (checked in the user list); a landline is rejected by the server. With JavaScript: the country chosen in the picker is stored with the number; an invalid number is reported in the browser and blocks submission; a number typed as `00971...` selects the UAE flag. |
| `tests/behat/profile_phone.feature` | Both with JavaScript: the admin user edit form shows the stored number in the picker (flag and formatting) and saves a new number in E.164; saving the profile without touching an invalid legacy value keeps it exactly as stored. |

Custom steps (`tests/behat/behat_local_alborhansignup.php`):

| Step | Purpose |
|---|---|
| `I select the :iso2 country in the :field phone field` | Opens the picker of the field and picks a country by ISO code, independent of the interface language. Looks for the list in the whole page, because in fullscreen mode (narrow viewports) the library moves it out of the field's wrapper. |
| `I set the :field phone field to :number` | Sets the number through the library (`setNumber()`), which fires the same `input` event as typing. WebDriver's clearing keys are unreliable on values that start with `+`, because the picker re-adds the dial code. |
| `the :field phone field should contain the number :number` | Asserts the E.164 number the picker would submit (`getNumber()`). |

Notes for writing more:

- With JavaScript, Behat only sees visible text. On Moodle 5.1, "Confirm your account" appears only in the page
  title after signup, so the JavaScript scenario checks the body text instead.
- The core data generator's `create_user()` fires `user_created`, so the plugin's observer normalises `phone1` of
  generated users. Write legacy fixture values directly with `$DB->set_field()`.

A human runs them, in a development environment with a separate `behat_wwwroot` and a browser (agents are denied
the init commands):

```bash
php public/admin/tool/behat/cli/init.php
php public/admin/tool/behat/cli/run.php --tags=@local_alborhansignup
```

## jsdom harness

`dev/jsdom/` loads the built `amd/build/phoneinput.min.js` and the vendored intl-tel-input into
[jsdom](https://github.com/jsdom/jsdom) with small Moodle stubs and drives a signup-like form: picker attachment,
E.164 submission, `00` turned into `+`, Arabic-Indic digits, one error per edit without a focus trap, untouched
legacy values submitted as stored, core's "required" message. It runs in seconds, needs no Moodle site, and was used to
verify the fixes of each review round. Build first (it tests `amd/build`), then see
[`dev/jsdom/README.md`](../dev/jsdom/README.md). In short, from the repository root:

```bash
(cd dev/jsdom && npm ci)          # once
node dev/jsdom/run.js [filter]
```

It complements Behat; it does not replace it (no real browser layout, no server).

## Manual end-to-end testing

Before deploying, CI (PHPUnit and Behat, including a full signup up to account creation)
is the gate. Right after a release is deployed, a human tests the signup **end to end on the live site, up to the
creation of the account**, then deletes the test account (manual checks in [deployment.md](deployment.md)). Agents
never do this. Version 1.2.1 exists because earlier tests stopped too early: a command-line test applied a QuickForm
filter after the form was built, and a manual test stopped at validation, so neither noticed that real submissions
lost the username. Also check, with and without JavaScript and in Arabic (RTL): the picker on the signup form, own
profile, admin user editing and the enrolment form; the RemUI profile pencil; the security check report.

## Checklist before pushing a change

1. `dev/check.sh` passes.
2. If `amd/src` changed: `export MOODLE_DEV=<dev-checkout>`, then `./dev/build.sh`; commit `amd/src` and `amd/build`
   together.
3. If the picker changed: the jsdom harness passes (`node dev/jsdom/run.js`).
4. New behaviour has PHPUnit tests (and Behat scenarios for anything in the browser).
5. After pushing: `dev/ci-status.sh --wait` shows all three legs green before tagging a release.
