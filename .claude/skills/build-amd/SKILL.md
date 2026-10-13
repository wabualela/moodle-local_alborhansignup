---
name: build-amd
description: Build amd/build from amd/src and run the grunt linters (eslint, gherkinlint, stylelint) with dev/build.sh in a separate Moodle dev checkout. Use after any change to amd/src/*.js, styles.css or tests/behat/*.feature, and before committing JavaScript.
allowed-tools: Bash(./dev/build.sh *) Bash(git status *) Bash(git diff *)
---

# Build the AMD modules

CI (`moodle-plugin-ci grunt --max-lint-warnings 0`) fails when `amd/build/` does not match what grunt produces from
`amd/src/`, or when eslint, stylelint or gherkinlint report anything. Build locally and commit `amd/src` and
`amd/build` together.

## Requirements

- `MOODLE_DEV` points to a **separate Moodle 5.1+ checkout used only for development** (`<dev-checkout>`), never a
  production site. It needs its JavaScript dependencies: `cd <dev-checkout> && nvm install && nvm use && npm ci`.
- Prefer `MOODLE_DEV` already exported in the shell that launched Claude Code, or set through `env` in your own
  `.claude/settings.local.json` (git-ignored). An `export` inside one agent Bash call does not carry over to the next
  call. The allow rule covers `./dev/build.sh`, not
  `MOODLE_DEV=... ./dev/build.sh`, so the inline form asks for permission.
- The checkout has **no `config.php`**: a build needs none. The script refuses to run when
  `<dev-checkout>/config.php` exists (an installed site, possibly production) unless `MOODLE_DEV_ALLOW_CONFIG=1` is
  set. Never set it for a production tree; ask the user before setting it at all.

## Steps

1. Run `./dev/build.sh`. It:
   - copies the plugin into `<dev-checkout>/public/local/alborhansignup` as a real directory (grunt resolves real paths
     to find the component, so a symlink fails);
   - selects the checkout's Node version through nvm (`.nvmrc`; Moodle 5.2 needs Node 22, `lts/jod`);
   - runs `npx grunt amd --max-lint-warnings=0` in `amd/` (use the `=` form when calling grunt directly: the
     space-separated form is misparsed; moodle-plugin-ci handles the flag itself);
   - runs `npx grunt gherkinlint stylelint:css` in the plugin directory;
   - copies `amd/build` back and reports whether it changed.
2. If grunt fails, fix the **source** (`amd/src`, `styles.css`, the `.feature` file) and run it again. Never edit
   `amd/build` by hand.
3. Check `git status -- amd/build` and commit the sources and the build in the same commit.
4. If the picker code changed, run the `check` skill afterwards: the jsdom harness tests the built module.

## Exit codes

`0` success; `1` grunt failed (nothing copied back); `2` setup problem (`MOODLE_DEV` unset or wrong, a `config.php`
in the checkout, no `node_modules`, wrong Node version, symlinked plugin directory, another clone at the target). The
message says which.

## Do not

- Run `npm install` or grunt inside a production Moodle tree.
- Leave another git clone of this repository at `<dev-checkout>/public/local/alborhansignup`. The script refuses to
  copy over it (it would overwrite that clone); run `dev/build.sh` from that clone instead, or remove it so the script
  can manage the copy.
