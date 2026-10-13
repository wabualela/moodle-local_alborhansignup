---
name: ci-status
description: Show the GitHub Actions (moodle-plugin-ci) result for a commit with dev/ci-status.sh, including the PHPUnit/Behat failure output published as annotations, and explain how to read it. Use after every push, before tagging a release, and whenever CI is red.
argument-hint: "[commit] [--wait]"
allowed-tools: Bash(./dev/ci-status.sh *) Bash(git log *) Bash(git show *) Bash(git rev-parse *)
---

# CI status

The repository is public, but GitHub Actions **job logs need authentication**. The workflow
(`.github/workflows/moodle-plugin-ci.yml`) therefore publishes the tail of the PHPUnit and Behat output as
**annotations**, which the public REST API returns without a token. `dev/ci-status.sh` reads them with read-only GET
requests (curl + python3).

## Run

```bash
./dev/ci-status.sh                 # HEAD
./dev/ci-status.sh <commit>        # any commit git rev-parse accepts, or a full SHA
./dev/ci-status.sh --steps <commit>   # also list the steps of successful jobs
```

- A full run takes 15 to 30 minutes. `--wait` polls until it finishes (default every 60 s, `--timeout` 2700 s). That
  is longer than a foreground shell command may run, so start it as a background command, or run without
  `--wait` and check again later.
- Unauthenticated clients get 60 API requests per hour. Each check uses a few requests per run, so poll sparingly.
  `GITHUB_TOKEN` in the environment raises the limit. Never write a token into a file or a command line.
- Exit codes: `0` all runs succeeded; `1` a run failed or was cancelled; `2` no run yet (not pushed, or still
  queued) or still running; `3` usage or API error (including the rate limit).

## Read the result

1. **Legs**: one job per matrix entry (Moodle branch, PHP, database). Read the current matrix in the workflow
   file. A failure on one leg only usually means an API difference between Moodle branches, or between databases
   (SQL, ordering, case sensitivity).
2. **Steps**: PHP Lint, PHP Mess Detector (`continue-on-error`, so ignore its result), Moodle Code Checker
   (phpcs, 0 warnings), Moodle PHPDoc Checker (0 warnings), Validating, Check upgrade savepoints, Mustache Lint,
   Grunt (0 lint warnings, stale `amd/build`), PHPUnit tests, Behat features. The script lists the steps of every
   job that did not succeed.
3. **Failure output**: annotations titled `PHPUnit output (...) <part>` or `Behat output (...) <part>`. The output
   is split into chunks of about 3.5 KB (annotation messages are truncated near 4 KB), so read **all parts in
   order**. The script sorts and labels them `[part i of n]` and decodes `%0A` line breaks. The first failing test
   or step is usually the cause; later errors often follow from it.
4. **Other annotations** (phpcs, phpdoc, eslint, validate) carry `path:line` directly.
5. **No output annotations?** Then the failure is in a step without them (install, lint, validate), or the commit
   predates the annotation steps. Open the run URL in a browser while signed in to GitHub. The Behat fail dump
   (screenshots, HTML) is an artifact, and downloading it needs authentication too.

## Then

- Reproduce what you can locally: phpcs and php -l with the `check` skill, grunt with `build-amd`. PHPUnit and Behat
  run only in CI or, run by a human, in a separate dev checkout, **never against a production site**.
- Fix forward with a new commit and push. Never force-push to re-trigger CI or to hide a red run.
- A release needs every leg green on the exact commit that gets tagged (see the `release` skill).
