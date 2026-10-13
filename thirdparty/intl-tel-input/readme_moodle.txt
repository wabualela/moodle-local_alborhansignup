intl-tel-input in local_alborhansignup
======================================

Files copied from the npm package intl-tel-input (https://www.npmjs.com/package/intl-tel-input):

  LICENSE
  dist/js/intlTelInputWithUtils.min.js  -> js/
  dist/css/intlTelInput.min.css         -> css/
  dist/img/flags.webp, flags@2x.webp    -> img/

Local customisation (re-apply after every upgrade):
  js/intlTelInputWithUtils.min.js: the country search normaliser strips only Latin combining accents
  (.replace(/[\u0300-\u036f]/g,"")), so Arabic names typed without hamza (e.g. "الامارات", "الاردن") are not found.
  It is changed to strip all combining marks: .replace(/\p{M}/gu,""). Search for 'normalize("NFD")' in the file.

The "WithUtils" build is a classic script that defines window.intlTelInput and already contains the utils
(libphonenumber), so no dynamic import() is needed. amd/src/phoneinput.js injects it at runtime only on pages with a
phone1 field. The CSS is linked directly (not through styles.css) so that Moodle's RTL flipping does not touch it and
its relative ../img/ URLs keep working.

To upgrade:
1. npm pack intl-tel-input@<version> && tar xzf intl-tel-input-<version>.tgz
2. Copy the files listed above over the ones in this folder, then re-apply the local customisation.
3. Update the version in ../../thirdpartylibs.xml.
4. Check the release notes for renamed options used in amd/src/phoneinput.js (v29 names: initialCountry,
   countryOrder, excludeCountries, allowedNumberTypes, countryNameLocale, uiTranslations, isValidNumberPrecise,
   getNumber) and for new keys in dist/js/locale/ar.js (strings iti_* in lang/en and lang/ar).
5. Run the Behat features in tests/behat, which drive the picker.
