# How AI agents were used on this plugin

Versions 1.2.2 to 1.3.0 were developed with Claude Code (desktop app), using the Claude Opus model. The agent only read
the live site, wrote only to this repository, and never deployed. This page records the tools, skills and working
method, so the next person (or agent) can reuse them.

## Tools

| Tool | Used for |
|---|---|
| Claude Code (model: Claude Opus) + Workflow tool | Multi-agent runs: parallel research, adversarial reviews, verification passes |
| Subagents | General researchers; `moodle-dev:moodle-reviewer` for Moodle-specific reviews |
| Skills (`moodle-dev` plugin) | `moodle-plugin-development`, `moodle-phpunit-testing`, `moodle-behat-testing`, `moodle-amd-javascript`, `moodle-codestyle`, `moodle-security-review`, `moodle-privacy-audit`, `moodle-string-check`, `moodle-accessibility`, `moodle-bump-version`, `moodle-hooks-api` |
| Project skills (`.claude/skills/`) | `build-amd`, `check`, `ci-status`, `release`, `review-changes` |
| Saved workflows (`.claude/workflows/`) | `moodle-research`, `moodle-review`, `moodle-verify-fixes` |
| Moodle core source (exact version, read-only) | Ground truth for APIs, since no Moodle documentation MCP was available; moodledev.io was the secondary source |
| GitHub public REST API | Reading CI runs and failure annotations; the GitHub connector was not connected |
| Notion connector | Task tracking (private, not part of this repository) |
| moodle-cs 3.7.0, grunt (Node 22), jsdom | Local standards checks, AMD build and lint, browser-like probes of the picker |
| moodle-plugin-ci 4.5.11 (GitHub Actions) | phplint, phpcs, phpdoc, validate, savepoints, mustache, grunt, PHPUnit, Behat |

## Method

1. **Scout inline, then fan out.** Read the plugin and the environment first, then launch parallel read-only
   researchers. Each phase used three or four of them, one per question: core mechanics, the library's real package,
   tooling, code review. A skeptic re-read the cited core code to refute the claims. Several plausible but wrong
   ideas were caught this way, for example "intl-tel-input has an AMD build" and "hide RemUI's phone field with CSS".
2. **Ask the human** for anything that changes behaviour for users. The choices were explained with a concrete
   example: the RemUI editor would reject `+966…`, so the human chose between redirecting and intercepting. Recommend
   an option, don't just list them.
3. **Tests first**, small commits, CI on every push. CI failure output was made readable through annotations, because
   the logs need authentication.
4. **Adversarial review after each phase**, with distinct lenses: Moodle standards, behaviour/security in
   production, test critic, JS/accessibility/RTL. Reviewers ran jsdom probes against the real build. Every
   finding was then **re-verified** with the same probes after fixing. A verification pass caught a regression the
   fix itself had introduced (erasing core's "required" message).
5. **Deploy by runbook.** The human runs the commands, the agent verifies read-only, the human does the logged-in
   checks.

## Lessons for agents

- Read the exact version's source. APIs changed between releases, and the library renamed many options in v29.
- Separate "the number submitted is right" from "the UI shows the right thing". The `00971…` case submitted the
  right number while showing the wrong flag.
- A test that passes for the wrong reason is a bug. One Behat scenario passed only because the expected value
  happened to match what WebDriver produced.
- Never probe production with writes, and never publish server specifics in this public repository (see
  [AGENTS.md](../AGENTS.md)).
- Keep the human informed between long background runs. Report failures honestly, including your own mistakes.
