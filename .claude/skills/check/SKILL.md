---
name: check
description: Run the local quality checks of local_alborhansignup - php -l and phpcs (moodle-extra) through dev/check.sh, and the jsdom harness for the phone picker (dev/jsdom). Use before every commit that touches PHP or the picker, and when CI reports phpcs or picker problems.
allowed-tools: Bash(./dev/check.sh *) Bash(node dev/jsdom/run.js *) Bash(git status *) Bash(git diff *)
---

# Local checks

These checks are fast and read-only for the repository. CI stays authoritative: it also runs phpdoc, validate,
savepoints, mustache, grunt, PHPUnit and Behat on every leg (see the `ci-status` skill).

## 1. PHP syntax and coding style

```bash
./dev/check.sh
```

- First time only: `./dev/check.sh --install` installs moodle-cs (`^3.7`) with composer into `dev/tools/`, which is
  git-ignored. This writes files and downloads packages, so ask the user first (the project settings make it
  prompt).
- Environment: `PHP` (default `php`; use the site's version, e.g. `PHP=php8.4`), `PHPCS` (an existing phpcs with
  moodle-cs), `PHPCS_MOODLE_BRANCH` (default `502`; use `501` to check against 5.1), `PHPCS_STANDARD` (default
  `moodle-extra`).
- The script copies the plugin into a temporary minimal Moodle root so that the sniffs that need the component and
  the branch (lang string order, `@package`, test case names) actually run. `--no-context` checks the tree in place,
  without those sniffs.
- Exit codes: `0` clean, `1` a check reported problems, `2` setup error (no PHP, no phpcs).

Fix every error and warning: CI runs phpcs with `--max-warnings 0`. phpdoc rules (`moodle-plugin-ci phpdoc`) are not
covered locally. Document every class, method, function and constant, with `@param`/`@return` matching the
signature.

## 2. Phone picker in jsdom

The harness loads the **built** `amd/build/phoneinput.min.js` with the vendored intl-tel-input into a hand-written
Moodle-like form (RTL) with stubbed core modules. If `amd/src` changed, run the `build-amd` skill first.

```bash
(cd dev/jsdom && npm ci)          # once; downloads jsdom from the lockfile, so ask the user first
node dev/jsdom/run.js             # all scenarios
node dev/jsdom/run.js 00971       # only scenarios whose name contains the text
```

Each scenario prints PASS/FAIL, and the exit code is non-zero on any failure. When a scenario fails, first decide
whether the harness or the plugin is wrong. Report real plugin bugs instead of weakening the scenario. Read
`dev/jsdom/README.md` for the list of scenarios.

The harness has no real CSS layout, no real browser focus behaviour and no Moodle server. Behat in CI covers the real
browser, and PHPUnit covers server-side validation (the server is the source of truth).

## Never

- Run PHPUnit or Behat init against a production site. Tests run in CI, or in a separate dev checkout where a human
  runs them (agents are denied the init commands).
- Install tools inside a Moodle site's code tree.
