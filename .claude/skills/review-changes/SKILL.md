---
name: review-changes
description: Adversarially review a commit range of the plugin with the saved /moodle-review workflow (Moodle standards, behaviour/security, test critic, JS/a11y/RTL), confirm each finding yourself before fixing it, then re-check the fixes with /moodle-verify-fixes. Use after finishing a phase of work and before a release.
argument-hint: "<base>[..<head>]"
disable-model-invocation: true
---

# Review a commit range

Range: $ARGUMENTS (if empty, ask; a typical base is the last release tag, `git describe --tags --abbrev=0`).

The review is multi-agent and costs many tokens. Run it only when the user asks for it. It is read-only: the
reviewers never modify the repository, a Moodle checkout or any site.

## 1. Prepare

- Make sure the range is committed (`git log --oneline <base>..<head>`). Uncommitted changes are not reviewed.
- Get the path of a **read-only Moodle checkout** of the target version (`<dev-checkout>` or another Moodle checkout of that version, the directory
  that contains `public/`) from the user. Without one, the reviewers read core from GitHub, which is slower and less
  exact.
- Write a short site context: Moodle version, theme, language/RTL, what kind of data and users the change touches.
  Keep it in the workflow arguments only, never in the repository.

## 2. Run the review workflow

Run the saved workflow `/moodle-review` (`.claude/workflows/moodle-review.js`) with:

```json
{ "base": "<base>", "head": "HEAD", "moodleroot": "<moodle checkout>", "context": "<site context>" }
```

Optional: `lenses` (subset of `standards`, `behaviour`, `tests`, `frontend`; frontend is skipped automatically when no
JS/CSS/template changed), `votes` (verifiers per finding, high and critical findings always get at least 2),
`maxVerify`, `standardsAgent` (default `moodle-dev:moodle-reviewer`; the workflow falls back to the default agent when
that agent type is not installed).

It returns `confirmed`, `uncertain`, `unverified` and `rejected` findings, each with severity, `file:line`, evidence,
failure scenario and a proposed fix.

## 3. Verify before fixing

Workflow verifiers reduce noise, but they can be wrong. For every confirmed or uncertain finding:

1. Re-read the cited code yourself, in the plugin and in core for **each supported Moodle version**.
2. Reproduce it where possible: a failing PHPUnit test (run in CI; in a dev checkout a human runs it, never on
   production, and agents are denied the init commands), a jsdom scenario (`check` skill), or a precise trace through
   the code.
3. Classify it as real (fix it), not real (drop it, with the reason), or needs a human decision (UX, privacy, data).
   Ask the user about the last group with a concrete example and a recommendation.

## 4. Fix

- Tests first for behaviour bugs. Keep each fix small, in its own commit, with the finding id in the message.
- Run the `check` skill, plus `build-amd` if JavaScript changed. Push, then use the `ci-status` skill.

## 5. Verify the fixes

Run `/moodle-verify-fixes` (`.claude/workflows/moodle-verify-fixes.js`) with the findings you fixed:

```json
{ "findings": [ ...the fixed findings... ], "base": "<commit that was reviewed>", "commit": "HEAD", "moodleroot": "<moodle checkout>" }
```

It re-checks each finding (`fixed`, `partially_fixed`, `not_fixed`, `regression`, `cannot_verify`, or `disputed` when
a challenger breaks a claimed fix) and reviews the fix diff for regressions. Repeat steps 3 to 5 until nothing real
is left. Then report to the user: what was found, fixed, dropped and why.
