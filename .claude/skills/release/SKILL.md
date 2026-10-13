---
name: release
description: Prepare a release of local_alborhansignup - bump version.php, date the CHANGES.md entry, check that CI is green on every leg, tag vX.Y.Z, then write the deployment runbook from docs/deployment.md for a human to run. The agent never deploys.
argument-hint: "[patch|minor|major|X.Y.Z]"
disable-model-invocation: true
allowed-tools: Bash(./dev/check.sh *) Bash(./dev/ci-status.sh *) Bash(git status *) Bash(git diff *) Bash(git log *) Bash(git show *) Bash(git describe *) Bash(git tag --list *)
---

# Release

Requested bump: $ARGUMENTS (if empty, propose one from the changes and ask).

## 0. Preconditions

- The working tree is clean and on the release branch (`git status`). Every change for the release is committed.
- `CHANGES.md` has entries under `## [Unreleased]` describing the user-visible changes.
- The local checks pass (`check` skill). If `amd/src` changed, `amd/build` was rebuilt and committed (`build-amd`).

## 1. Choose the numbers

Read the current values in `version.php` and the latest tag (`git describe --tags --abbrev=0`).

| Field | Rule |
|---|---|
| `$plugin->release` | Semantic version `X.Y.Z`: **patch** for fixes and security fixes with no new behaviour, **minor** for new user-visible features, **major** for breaking changes. |
| `$plugin->version` | `YYYYMMDDXX`: today's date plus a two-digit counter (`00` first, then `01`, ...). It must be **strictly greater** than the current value. On the same day, increment the counter. Moodle refuses downgrades, so the number never goes back. |
| `$plugin->requires`, `$plugin->supported` | Change only when the supported Moodle versions change, and say so in CHANGES.md. Keep the CI matrix consistent with them. |
| `$plugin->maturity` | Stays `MATURITY_STABLE` for releases. |

A version bump is **mandatory** when any of these change: `db/*.php` (hooks, events, services, access), `lib.php`
callbacks, new or renamed autoloaded classes, lang strings, JavaScript, templates or CSS. Moodle caches the hook,
callback and class lists and the strings per version, so without a bump the site keeps running the old lists.

## 2. Edit the files

1. `version.php`: set `version` and `release`.
2. `CHANGES.md`: rename the `[Unreleased]` entries to `## [X.Y.Z] - YYYY-MM-DD (VERSION)`, grouped under
   `### Added` / `### Changed` / `### Fixed` / `### Security`, and leave an empty `## [Unreleased]` above it.
   Describe what changed and why. Keep server details out (public repository).
3. Update `README.md` if the behaviour it describes changed.
4. Run `./dev/check.sh`.
5. Show the user the diff and the proposed commit message (`Release X.Y.Z`). Commit and push only when the user
   agrees.

## 3. CI must be green

```bash
./dev/ci-status.sh --wait <release-commit>   # as a background command; see the ci-status skill
```

Every leg must succeed on **the exact commit** you will tag. If anything fails, fix forward with new commits and
check again. Never tag a red or unfinished run.

## 4. Tag

With the user's agreement:

```bash
git tag -a vX.Y.Z -m "local_alborhansignup X.Y.Z (VERSION): <summary>" <release-commit>
git push origin vX.Y.Z
```

`VERSION` is the `$plugin->version` value and `<summary>` a few words, like the existing tags (e.g.
`local_alborhansignup 1.2.3 (2026101301): security release`). Never move or delete a published tag, and never
force-push.

## 5. Write the deployment runbook (do not run it)

Read `docs/deployment.md` and produce a runbook for **this** release for a human to run. Agents do not deploy:
the project settings deny sudo and deployment commands.

- Ask the user for the values to fill in: the Moodle code root (`<moodle>`), the plugin clone (`<plugin-repo>`)
  checked out at `vX.Y.Z`, a backup directory, the PHP CLI binary the site uses, and the web server user. Fill them in
  only in your reply. Never write them into the repository.
- Keep the steps and their order: backup copy (for reference only), maintenance mode on, `rsync` with the documented
  excludes and ownership, `admin/cli/upgrade.php --non-interactive` **immediately** after the copy (new classes are
  found only after the upgrade purges caches), maintenance mode off.
- Add what is specific to the release: which pages change for users, the CHANGES.md entry, and who to tell
  (e.g. the platform manager).
- Include the verification list from `docs/deployment.md`: the read-only checks an agent may do afterwards
  (anonymous GET requests only) and the manual checks for the human (a diff of the deployed files against
  `git archive vX.Y.Z`, then the logged-in checks), including one signup **all the way to account creation**. The optional blocked-web-service
  check is a manual check for the human, never an agent request.
- Repeat the rollback rule: Moodle refuses version downgrades, so there is no rollback by copying old files. Fix
  forward with a higher version.

## 6. After the human has deployed

Only if asked: run the read-only verification from the runbook and report the results. Agents never send POST/write
requests to the live site or log in to it, not even as a probe that should fail.
