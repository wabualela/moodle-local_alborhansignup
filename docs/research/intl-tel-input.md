# intl-tel-input in a Moodle plugin (v29.5.4)

How the country picker ([intl-tel-input](https://github.com/jackocnr/intl-tel-input)) is packaged, why this plugin
loads it the way it does, and what to watch for when upgrading. The integration lives in `amd/src/phoneinput.js`,
`classes/local/page_assets.php` and `thirdparty/intl-tel-input/` (see its `readme_moodle.txt`).

## 1. Packaging in v29

| File in the npm package `dist/` | Format | Notes |
|---|---|---|
| `js/intlTelInput(.min).js` | classic script (IIFE) | no utils; needs `loadUtils` |
| `js/intlTelInputWithUtils.min.js` | classic script (IIFE) | utils (libphonenumber) bundled; about 316 KB raw / 75 KB gzipped |
| `js/*.mjs` | ESM | default export |
| `js/utils.js` | ESM only (since v23) | ends with `export default` |
| `js/locale/*.js` | ESM only | UI strings; no Arabic country-name file (names come from `Intl.DisplayNames`) |
| `css/intlTelInput.min.css` | CSS | flag sprites via relative `url(../img/...)` and `image-set()` |
| `img/flags.webp`, `flags@2x.webp` | images | PNG variants exist but the CSS does not reference them |

- **No version has an AMD `define()`.** The v17–v26 "UMD" wrapper only had CommonJS plus a `window` global; v27
  dropped CommonJS. The classic build ends with `var intlTelInput = _factory.default;` and defines
  `window.intlTelInput` (it also leaks `window._factory`, so do not load `data.min.js` next to it).
- **Licences:** the library is MIT; the bundled utils contain libphonenumber and Closure Library code under
  Apache-2.0 (the notice is stripped from the minified bundle). `thirdpartylibs.xml` declares one `<library>` with
  `<license>MIT</license>`; the Apache-2.0 code is noted in its description and copyright lines. The file also makes
  grunt's eslint/stylelint skip the folder.

## 2. Why it is injected at runtime

Options that were considered:

| Option | Verdict |
|---|---|
| Put the ESM files in `amd/src/` and let grunt compile them | Rejected. Grunt's babel step turns `import()` into a RequireJS call, so `loadUtils: () => import(...)` would load an ESM file through RequireJS. Every file in `amd/src` is also built and checked by CI's stale-build comparison. |
| Wrap the classic build in `define()` under `amd/src/` | Rejected. It modifies the vendor file, grunt rebuilds everything under `amd/src` (babel + terser), and in production non-lazy modules are joined into the AMD bundle loaded on every page. |
| Moodle 5.2's ESM/import-map pipeline | Rejected. Not documented for plugins and absent from 5.1. |
| **Inject the classic WithUtils script from our AMD module** | **Chosen.** Same pattern as core's TinyMCE loader (`public/lib/editor/tiny/amd/src/loader.js`). |

How it works:

1. The `before_http_headers` hook runs on every page. `page_assets::add_to_page()` acts only on page types that can
   contain an editable `phone1` (`login-signup`, `user-edit`, `user-editadvanced`, `enrol-index`, never the
   `maintenance` layout). There it links the CSS with `$PAGE->requires->css()`, preloads strings with `strings_for_js()` and calls
   `js_call_amd('local_alborhansignup/phoneinput', 'init', [config])`.
2. `init()` looks for `form.mform input[name="phone1"]:not([type="hidden"])`. Frozen (locked) fields render as a
   hidden input and are skipped. If none is found, the library script is not downloaded.
3. Otherwise it appends a `<script>` for the vendored bundle (URL with `?v=<plugin version>` for cache busting),
   waits for `window.intlTelInput` and attaches the picker. The work is wrapped in `core/pending` for Behat.
4. If loading fails, the plain input still works: the server validates and normalises the number.

The library makes no network requests of its own. `initialCountryLookup` (geo-IP) is not set and nothing comes from
a CDN, so the plugin needs no external-location privacy metadata.

## 3. Options used, and v29 renames

```js
intlTelInput(input, {
    initialCountry: 'sa',                 // country for empty values and numbers without "+"
    countryOrder: ['sa', 'ae', 'kw', 'qa', 'bh', 'om'],
    excludeCountries: ['il', 'eh'],       // match the countries hidden from the site's country list
    countryNameLocale: config.locale,     // BCP 47 from the Moodle language, e.g. zh_tw -> zh-TW
    countryNameOverrides: config.countrynames, // names that must match the site's country list
    uiTranslations: strings.ui,           // from lang/*/local_alborhansignup.php (iti_* strings)
    allowedNumberTypes: ['MOBILE'],
    separateDialCode: false,              // default true since v28
    strictMode: false,                    // default true since v28
});
```

- `countryNameLocale` feeds `Intl.DisplayNames`. Extra parts of Moodle language codes (`en_us_k12`) are dropped
  because `Intl.DisplayNames` rejects them.
- `uiTranslations.searchSummaryAria` must be a function; ours chooses between the Arabic plural forms (0, 1, 2,
  3–10, 11+). `selectedCountryAriaLabel` keeps the literal `${countryName}` and `${dialCode}` tokens.
- Not used: `hiddenInputs` (for a field named `phone1` it would find the visible input itself, and Moodle only
  exports declared elements), `loadUtils` (utils are bundled), `initialCountryLookup`.

Most tutorials still use old names. The renames that matter:

| Version | Old name → new name |
|---|---|
| v22 | `preferredCountries` → `countryOrder`; `showSelectedDialCode` → `separateDialCode`; globals → statics on `intlTelInput` |
| v25 | `utilsScript` (URL) → `loadUtils` (function returning a Promise) |
| v26 | country names from `Intl.DisplayNames` (`countryNameLocale`); `allowedNumberTypes` added |
| v27 | `build/` → `dist/`; utils methods throw before utils are loaded |
| v28 | country names moved out of i18n (`countryNameOverrides`); `data-iso2` on list items; string enums for errors |
| v29 | `hiddenInput` → `hiddenInputs`; `i18n` → `uiTranslations`; `geoIpLookup` → `initialCountryLookup`; `nationalMode`/`formatOnDisplay` → `numberDisplayFormat`; `autoPlaceholder` → `placeholderNumberPolicy`; `allowDropdown`/`useFullscreenPopup` → `countrySelectorMode`; `dropdownContainer` → `dropdownParent`; utils `isPossibleNumber` → `isValidNumber`, `isValidNumber` → `isValidNumberPrecise` |

## 4. RTL and CSS

- When the input is inside `[dir=rtl]`, the library sets `dir="ltr"` on its `.iti` wrapper (flag and dial code on
  the left, number left to right) and `dir="rtl"` on the country list. Moodle puts `dir="rtl"` on `<html>` for
  Arabic, so nothing needs configuring. Core's `setForceLtr()` also keeps the input itself LTR.
- **The vendor CSS must not go through the theme.** A plugin's `styles.css` is part of the theme CSS. On RTL pages
  Moodle's RTLCSS flips physical `left`/`right`, double-flips the library's own `[dir=rtl]` rules and breaks the
  LTR wrapper. The CSS parser also rewrites `image-set(... 1x, ... 2x)` as `1 x`/`2 x`, which is invalid CSS, so
  no flags show. Relative `../img/` URLs would also resolve against `public/theme/styles.php`.
- Linking the unmodified file with `$PAGE->requires->css()` avoids all three: it is not parsed or flipped, and the
  relative image paths resolve because `public/` serves static files.
- Own tweaks stay in `styles.css`. A rule that must not be flipped gets a `/*rtl:ignore*/` comment (RTLCSS also
  swaps `direction: ltr`). Ours makes the wrapper full width on the signup form and moves Bootstrap's "invalid" icon
  away from the country button.

## 5. Existing values, "+" and "00"

- The library detects the country only from a value starting with `+`. Otherwise it uses `initialCountry`
  (without it, a national number shows a globe and `getNumber()` returns the raw value).
- `+966501234567` selects SA, `+971...` selects AE. Arabic-Indic digits are accepted, displayed as typed, and
  `getNumber()` returns them in the same numeral set, so the server converts digits to ASCII before parsing.
- **Untouched values are submitted exactly as stored.** The picker reformats the value on init, and the display form
  of some legacy values loses information (for example the country code of a number stored without `+`). The
  module records the displayed value and country right after init and treats the field as changed only if either
  differs.
- **`+` versus `00`.** With `separateDialCode: true` the `+` key opens the country search instead of typing, and
  "00971..." keeps the previous flag. So the dial code stays in the input (`separateDialCode: false`) and a leading
  `00` (also `٠٠` or `۰۰`) is replaced by `+` on input, which lets the flag follow the number. Strict mode is off
  for the same reason: it filters and caps what can be typed for the currently selected country. The server
  validates anyway.

## 6. Validation in the browser

- `isValidNumber()` checks length only. `isValidNumberPrecise()` applies per-country rules and respects
  `allowedNumberTypes`. With `['MOBILE']`, utils also accept `FIXED_LINE_OR_MOBILE` (North America), which matches
  the server rule (`phone::is_valid_mobile()`). Listing `FIXED_LINE_OR_MOBILE` explicitly would also accept
  landlines.
- `getNumber()` returns E.164 for a valid number and the raw string otherwise. An empty input reports `TOO_SHORT`,
  so empty values are left to the form's `required` rule.
- **When to validate:** once per edit, when the field is left (moving to its own country button does not count).
  theme_boost's `form-display-errors` focuses the field on every error, so validating on every blur trapped
  keyboard users.
- **On submit:** a non-capture `submit` listener on `document` runs after the form's own listeners. Core's
  client-side validation clears client errors on `change`/`submit`, so ours has to run last. The listener skips Cancel
  (`skipClientValidation`, `data-skip-validation`, `data-cancel`), blocks a changed invalid number, writes
  `getNumber()` (E.164) into the input for a changed valid number, and restores the stored value for an untouched
  one.
- Errors are shown with `core_form/events` `notifyFieldValidationFailure()`, with a manual fallback when the theme
  does not handle the event.

## 7. Behat

- Generated DOM: `div.iti` wrapper → `button.iti__selected-country` (flag `div.iti__flag.iti__<iso2>`), country list
  `li.iti__country[data-iso2="ae"][data-dial-code="971"]`. The input keeps its id, name and label.
- Ids contain a per-page instance number (`iti-0__...`): never select by id. In fullscreen mode (viewport ≤ 500px)
  or with `dropdownParent` the list is moved out of the wrapper, so look for `li.iti__country` in the whole page.
  Behat's default 1366x768 window gives dropdown mode.
- Click the list item directly. The search box is debounced (100 ms), and `core/pending` does not track that timer.
- WebDriver's field clearing is unreliable on values starting with `+` (the picker re-adds the dial code). The
  custom step `I set the :field phone field to :number` uses `setNumber()` through the library.
- `the field ... matches value` sees the displayed value. The custom step `the :field phone field should contain the
  number :number` checks `getNumber()` instead. Language-independent check: the flag's class contains `iti__ae`.

## 8. Upgrading

1. `npm pack intl-tel-input@<version>` and unpack it in an empty directory.
2. Copy `LICENSE`, `dist/js/intlTelInputWithUtils.min.js`, `dist/css/intlTelInput.min.css` and
   `dist/img/flags*.webp` over `thirdparty/intl-tel-input/`, keeping `css/` and `img/` side by side.
3. Re-apply the local patch. The search normaliser strips only Latin combining accents
   (`.replace(/[\u0300-\u036f]/g,"")`). In NFD, Arabic letters with hamza decompose to a base letter plus a
   combining mark outside that range, so "الامارات" did not find "الإمارات". Change it to `.replace(/\p{M}/gu,"")`
   (search for `normalize("NFD")`).
4. Update the version in `thirdpartylibs.xml`, read the release notes for renamed options used in
   `amd/src/phoneinput.js`, and compare `dist/js/locale/ar.js` with the `iti_*` strings for new keys.
5. Bump the plugin version (it is also the asset cache-buster), then run the jsdom checks and the Behat features.
