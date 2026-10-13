# jsdom harness for the phone picker

Browser-like checks of the `phone1` country picker: the plugin's **built** AMD module
(`amd/build/phoneinput.min.js`) together with the vendored, locally patched intl-tel-input bundle
(`thirdparty/intl-tel-input/js/intlTelInputWithUtils.min.js`), run in [jsdom](https://github.com/jsdom/jsdom).

It needs no Moodle site, no database and no production HTML. The page is a small hand-written form
(`fixture.html`), and the Moodle modules the picker talks to are replaced by small stand-ins
(`moodle-stubs.js`). Development only: `dev/` is not deployed.

## Run it

Node 22 (the version Moodle 5.2 uses for grunt). From the repository root:

```bash
(cd dev/jsdom && npm ci)           # once: installs jsdom from package-lock.json (don't use npm install)
node dev/jsdom/run.js              # every scenario
node dev/jsdom/run.js cancel       # only the scenarios whose name contains "cancel"
```

Each check prints `PASS` or `FAIL`. The process exits with code 1 if any check fails. A full run takes a few seconds.

The harness tests the build, not `amd/src`. After changing `amd/src/phoneinput.js`, rebuild `amd/build` with
`dev/build.sh` (see [docs/testing.md](../../docs/testing.md#javascript-build-devbuildsh)) before running it.

## When to run it

- after any change to `amd/src/phoneinput.js` (once rebuilt);
- after upgrading intl-tel-input, to check that the options still work and the local search patch was re-applied
  (see `thirdparty/intl-tel-input/readme_moodle.txt`);
- before a release, next to PHPUnit and Behat in CI.

## What is loaded

| Piece | Source |
|---|---|
| Form markup | `fixture.html`: `form.mform` with `.fitem` > `input[name=phone1]` + `.form-control-feedback`, an e-mail field, submit and cancel buttons; `<html dir="rtl" lang="ar">` |
| AMD loader | `moodle-stubs.js`: minimal `define()`/`require()` for named modules |
| `core/log` | records warnings; any warning fails the scenario |
| `core/pending` | counts open pending promises |
| `core/str` | `getStrings()` resolves from `lang/en/local_alborhansignup.php` (parsed by `harness.js`), with `{$a}` replacement |
| `core_form/events` | `notifyFieldValidationFailure()` dispatches the same cancelable `core_form/fieldValidationFailed` event as core |
| `theme_boost/form-display-errors` | replica of `public/theme/boost/amd/src/form-display-errors.js`: message in `.form-control-feedback`, `is-invalid`, `aria-invalid`, `aria-describedby`, and the `setTimeout` refocus of the field |
| Core client validation | replica of the script printed by `public/lib/formslib.php` for a `required` rule with `'client'` validation: checks the field on `change` and every rule on submit, skipped when `skipClientValidation` is set (as by the cancel button, `public/lib/form/cancel.php`) |
| Picker module | `amd/build/phoneinput.min.js`, unchanged |
| Library | `thirdparty/intl-tel-input/js/intlTelInputWithUtils.min.js`, loaded by the module itself through a `<script src>` element, as on a real page |
| Configuration | same shape as `page_assets::phone_config()`; initial country, country order and excluded countries are read from `classes/local/page_assets.php`; country names in Arabic (`Intl.DisplayNames`, locale `ar`) |

The theme's refocus matters most here. When a field gets an error, `form-display-errors` moves the focus back to it.
An earlier version of the picker validated on every blur, so every Tab brought the focus back: a keyboard trap. The
replica keeps that refocus so the harness can catch the trap if it comes back.

## Scenarios

| Scenario | What it protects |
|---|---|
| Picker attaches without warnings | the library loads, Saudi Arabia is the initial country, excluded countries are not offered, `Pending` resolves |
| Typing `0501234567` | posts `+966501234567` (E.164) |
| Typing `00971501234567` | a leading `00` becomes `+`, the UAE flag is selected, posts `+971501234567` |
| Arabic-Indic digits `٠٥٠١٢٣٤٥٦٧` | accepted; the posted E.164 value keeps the digits the user typed (`+٩٦٦٥٠١٢٣٤٥٦٧`), and the server converts them to ASCII |
| Invalid edit, then Tab | the error is notified once; the theme refocuses the field once; the next Tab leaves the field (no focus trap); submit is blocked |
| Moving to the country button | not treated as leaving the field |
| Untouched legacy value `+9665012345` | posted exactly as stored, not the picker's display form (`+966 5012345`) |
| Untouched valid value | posted exactly as stored |
| Clearing the field after an error | core's "required" message (set by the simulated core validator on `change`) is not erased by the picker; submit is blocked by core |
| Fixing the number | the error is cleared and the number posted in E.164 |
| Cancel (button does not take focus) | no validation, no error, the typed value is posted untouched |
| Cancel (button takes focus) | the submission is not blocked even though the field was left with an invalid number |
| Arabic country search | `الامارات` finds `ae` and `الاردن` finds `jo` (the local `/\p{M}/gu` patch); `فلسطين` finds `ps` (site name override) |

Every scenario also fails on a module warning (`core/log`) or a script error reported by jsdom.

Each scenario was checked against a deliberately broken copy of the build (focus trap restored, required message
erased, stored value replaced by the display form, `00` not converted, cancel validated, no E.164 conversion) and
of the library (unpatched search normaliser). Each of these copies made the scenario that covers it fail.

## How input is simulated

jsdom has no keyboard navigation and no layout, so `harness.js` acts like a browser explicitly:

- `page.tab(element)` fires `change` on the field being left if its value changed since it got the focus, then moves
  the focus. jsdom then fires `blur`/`focusout` with `relatedTarget`, in the same order as a browser.
- `page.type(element, keys)` sends `keydown`, edits the value at the caret, fires `input` (`insertText` or
  `deleteContentBackward`, with `\b` for Backspace), then `keyup`. The library's as-you-type formatting runs on
  those events.
- `page.clear(element)` selects all the text and presses Backspace.
- `page.submit(buttonid, {focus})` clicks a submit button. With `focus: true` (Chrome, Firefox) the button takes the
  focus first, which blurs the field. With `focus: false` (Safari) the field keeps it. It returns what the form would
  post: the `phone1` value read with `FormData`, whether the submission was blocked, and the submitter. The
  navigation itself is cancelled.

## What it does not cover

- **No CSS or layout.** The library's stylesheet is not loaded. Visibility is judged from `style.display` and the
  library's classes. Flags, the dropdown position, RTL rendering and the fullscreen popup on small screens are not
  tested.
- **No real browser focus behaviour.** Tab order, focus on click, mobile keyboards, IME composition, autofill and
  paste menus are simulated or absent.
- **No real Moodle modules.** The stand-ins and replicas were written from the Moodle 5.2 sources listed above. If
  core changes `form-display-errors`, `core_form/events` or the validation script, the replicas must be updated
  too. The theme's submit-time focus on the first visible error (a jQuery `:visible` check, which never matches in
  jsdom) is left out, and so is RequireJS.
- **No server.** PHP validation and normalisation (`classes/local/phone.php`) are covered by PHPUnit. The full signup
  and profile flows in a real browser (Chrome, with Moodle's default theme in CI) are covered by the Behat features in
  `tests/behat/`. Neither covers theme_remui; check it by hand after RemUI upgrades.
- **Only the signup-like form.** The profile, admin edit and enrol_apply pages use the same module but are not
  modelled separately.

## Files

| File | Purpose |
|---|---|
| `run.js` | scenarios and the PASS/FAIL runner |
| `harness.js` | builds a page (fixture + stubs + module), waits for `init()`, browser-like input helpers |
| `moodle-stubs.js` | browser-side AMD shim, core module stand-ins, theme and core-validation replicas |
| `fixture.html` | the hand-written form |
| `package.json`, `package-lock.json` | jsdom as a development dependency |

To add a scenario, call `scenario(name, async(page, check) => { ... }, {phone1: 'stored value'})` in `run.js`.
`check(label, condition, detail)` prints PASS or FAIL. `detail` is shown on failure.
