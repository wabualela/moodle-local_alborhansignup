@AGENTS.md

## Claude Code specifics

- Project skills live in `.claude/skills/` (build-amd, check, ci-status, release, review-changes). Use them instead of
  retyping commands.
- Saved multi-agent workflows live in `.claude/workflows/` (moodle-research, moodle-review, moodle-verify-fixes). Run
  them only when the user asks for a workflow / multi-agent pass. Pass the Moodle checkout with `args.moodleroot`.
- `.claude/settings.json` allows the read-only and dev commands above (installs such as `dev/check.sh --install` and
  `npm ci` in dev/jsdom still prompt) and denies production-changing ones (sudo,
  Moodle CLI upgrade/maintenance, PHPUnit/Behat init, force pushes). Don't work around a denial.
- Moodle-specific skills from the `moodle-dev` plugin were used heavily: plugin development, PHPUnit, Behat, AMD,
  codestyle, security, privacy, strings, accessibility, version bump and hooks API (`moodle-hooks-api`), plus the
  `moodle-dev:moodle-reviewer` agent. Enable that plugin if it is available to you.
- Talk to the user in their language (Arabic for this project). Code, comments, commits and docs stay in English.
