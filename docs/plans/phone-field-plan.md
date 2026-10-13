# Plan: mobile phone with country picker (delivered in 1.2.2 – 1.3.0)

**Request** (October 2026): add the mobile phone to the signup form using the existing core field `phone1` (no new
profile field), with the intl-tel-input country picker. Later extended: the picker must appear wherever `phone1` is
editable. Constraints: Moodle coding standards, PHPUnit and Behat tests, the core repository untouched, and the
plugin in its own repository.

**Status:** all phases are done. 1.2.3 was deployed as an early security release, and 1.3.0 was deployed and accepted
on 13 October 2026.

## Decisions taken with the platform owner

| Question | Decision |
|---|---|
| Field | Core `phone1` |
| Required? | On signup yes; on profile forms optional but validated (core has no validation hook there) |
| Number types | Mobile only |
| Default / order | Saudi Arabia first, then the Gulf states; IL and EH excluded, as in the site's country list |
| Storage | E.164 (`+9665…`) |
| Test environment | GitHub Actions (moodle-plugin-ci); a local dev checkout for builds; never production |
| theme_remui inline editor | Option A: the pencil opens the core edit page; the inline save service is blocked |
| Upload users "defaults" | Excluded |
| enrol_apply form | Included |
| RemUI profile privacy | Option 1: other users see core's profile page; security check added |
| Legacy numbers | No bulk migration now; normalised on their next change |

## Phases

| # | Phase | Result |
|---|---|---|
| 0 | Repository: `git init`, baseline tag `v1.2.1`, `.gitignore`, `CHANGES.md`, public GitHub repo | Done |
| 1 | CI: moodle-plugin-ci on Moodle 5.2 (PHP 8.4/MariaDB, PHP 8.3/PostgreSQL) and 5.1 (PHP 8.4/PostgreSQL) | Done; failure output published as annotations |
| 2 | Tests first, then refactor into `form_customiser`; username tamper fix; phpcs 0/0 (1.2.2) | Done |
| — | Security release: block `auth_email_signup_user` (1.2.3) | Deployed early |
| 3 | Server side: `phone1` on signup, `phone` helper (libphonenumber, E.164), `before_user_updated` hook, `user_created` observer | Done |
| 4 | Client side: vendored intl-tel-input, AMD module, output hook, RemUI pencil redirect, RemUI profile privacy, Behat | Done |
| 5 | Quality: adversarial reviews and a verification pass (see [history](../history.md)), CI green on all legs | Done |
| 6 | Deployment ([runbook](../deployment.md)), live verification, manual acceptance, tracker update | Done |

## Scope notes

- **Places where `phone1` is editable:** signup (plugin), `user/edit.php`, `user/editadvanced.php`, the enrol_apply
  form, theme_remui's inline profile editor (redirected), and upload users defaults (excluded). See
  [research/moodle-signup-and-phone1.md](../research/moodle-signup-and-phone1.md).
- **Not covered on the server:** RemUI's raw-update path. The pencil is redirected and the service is blocked, so
  this path is closed. enrol_apply never stores the number.
- **Follow-ups:** site-configuration decisions are tracked privately.
