// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Country picker (intl-tel-input) for the core phone1 field of Moodle forms.
 *
 * The vendored library is loaded only when the page has a phone1 input. On submit, a valid number is written back
 * to the input in E.164 (+966501234567), so the server receives the country the user picked; invalid numbers that
 * the user typed block the submission with a field error. A value the user did not touch is submitted exactly as it
 * was stored. The server validates and normalises again.
 *
 * @module     local_alborhansignup/phoneinput
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Pending from 'core/pending';
import {getStrings} from 'core/str';
import {notifyFieldValidationFailure} from 'core_form/events';

const COMPONENT = 'local_alborhansignup';
const SELECTOR = 'form.mform input[name="phone1"]:not([type="hidden"])';
const COUNT_PLACEHOLDER = '__COUNT__';

/**
 * @typedef {Object} Instance
 * @property {Object} iti intl-tel-input instance
 * @property {string} original value of the input when the page loaded (as stored)
 * @property {string} displayed value shown by the picker right after it started
 * @property {string} country country selected by the picker right after it started
 * @property {boolean} edited whether the value changed since the last validation
 * @property {boolean} showing whether the field shows our "invalid number" error
 */

/** @type {Map<HTMLInputElement, Instance>} */
const instances = new Map();

/** @type {Promise<Function>|null} */
let libraryPromise = null;

/** @type {Object|null} Strings shared by every instance. */
let strings = null;

let listening = false;

/**
 * Load the vendored intl-tel-input bundle (a classic script defining window.intlTelInput) once.
 *
 * @param {string} scripturl
 * @returns {Promise<Function>}
 */
const loadLibrary = (scripturl) => {
    if (window.intlTelInput) {
        return Promise.resolve(window.intlTelInput);
    }
    if (!libraryPromise) {
        libraryPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = scripturl;
            script.async = true;
            script.addEventListener('load', () => {
                if (window.intlTelInput) {
                    resolve(window.intlTelInput);
                } else {
                    reject(new Error('intl-tel-input did not define window.intlTelInput'));
                }
            });
            script.addEventListener('error', () => reject(new Error('Could not load ' + scripturl)));
            document.head.append(script);
        });
    }
    return libraryPromise;
};

/**
 * Fetch the interface strings (preloaded with strings_for_js, so no AJAX request is needed).
 *
 * @returns {Promise<Object>}
 */
const loadStrings = async() => {
    const keys = [
        'iti_selectedcountry', 'iti_nocountry', 'iti_countrylist', 'iti_search', 'iti_clearsearch', 'iti_close',
        'iti_noresults', 'iti_resultsone', 'iti_resultstwo', 'iti_resultsfew', 'iti_resultsmany', 'phone1invalid',
    ];
    const values = await getStrings(keys.map((key) => ({key, component: COMPONENT, param: COUNT_PLACEHOLDER})));
    const s = Object.fromEntries(keys.map((key, index) => [key, values[index]]));
    const count = (template, number) => template.replace(COUNT_PLACEHOLDER, number);
    return {
        invalid: s.phone1invalid,
        ui: {
            selectedCountryAriaLabel: s.iti_selectedcountry,
            noCountrySelected: s.iti_nocountry,
            countryListAriaLabel: s.iti_countrylist,
            searchPlaceholder: s.iti_search,
            clearSearchAriaLabel: s.iti_clearsearch,
            closeCountrySelectorAriaLabel: s.iti_close,
            searchEmptyState: s.iti_noresults,
            searchSummaryAria: (number) => {
                if (number === 0) {
                    return s.iti_noresults;
                }
                if (number === 1) {
                    return s.iti_resultsone;
                }
                if (number === 2) {
                    return s.iti_resultstwo;
                }
                if (number % 100 >= 3 && number % 100 <= 10) {
                    return count(s.iti_resultsfew, number);
                }
                return count(s.iti_resultsmany, number);
            },
        },
    };
};

/**
 * Whether the number in the picker is a valid mobile number (same rule as the server).
 *
 * @param {Object} iti intl-tel-input instance
 * @returns {boolean}
 */
const isValid = (iti) => iti.isValidNumberPrecise() === true;

/**
 * Show (or clear, with an empty message) a client-side error under the field, the way core forms do.
 *
 * @param {HTMLInputElement} input
 * @param {string} message
 */
const showError = (input, message) => {
    const event = notifyFieldValidationFailure(input, message);
    if (event.defaultPrevented) {
        return;
    }
    // The theme did not handle the event: update the standard feedback element ourselves.
    const feedback = input.closest('.fitem')?.querySelector('.form-control-feedback');
    if (feedback) {
        feedback.textContent = message;
        feedback.style.display = message ? 'block' : 'none';
    }
    input.classList.toggle('is-invalid', message !== '');
    input.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
};

/**
 * Whether the user changed the number or its country since the page loaded.
 *
 * @param {HTMLInputElement} input
 * @param {Instance} instance
 * @returns {boolean}
 */
const isChanged = (input, instance) => input.value.trim() !== instance.displayed
    || (instance.iti.getSelectedCountry()?.iso2 ?? '') !== instance.country;

/**
 * Validate one field when the user leaves it after editing it (like core's validation on "change"). Validating on
 * every blur would make the theme put the focus back on the field each time (form-display-errors), trapping it.
 *
 * @param {HTMLInputElement} input
 * @param {FocusEvent} event
 */
const validateOnLeave = (input, event) => {
    const instance = instances.get(input);
    // Moving to the country button of the same field is not leaving it.
    if (!instance || !instance.edited || input.closest('.iti')?.contains(event.relatedTarget)) {
        return;
    }
    instance.edited = false;
    if (input.value.trim() === '') {
        // Core's "required" message may be showing (it replaces ours on "change"): only remove our own error.
        if (instance.showing && feedbackText(input) === strings.invalid) {
            setError(input, instance, '');
        }
    } else if (isValid(instance.iti)) {
        setError(input, instance, '');
    } else if (isChanged(input, instance)) {
        setError(input, instance, strings.invalid);
    }
};

/**
 * Text of the error feedback element of the field.
 *
 * @param {HTMLInputElement} input
 * @returns {string|null} null when the theme has no feedback element
 */
const feedbackText = (input) => {
    const feedback = input.closest('.fitem')?.querySelector('.form-control-feedback');
    return feedback ? feedback.textContent.trim() : null;
};

/**
 * Show or clear our "invalid number" error and remember whether it is showing.
 *
 * @param {HTMLInputElement} input
 * @param {Instance} instance
 * @param {string} message empty to clear
 */
const setError = (input, instance, message) => {
    instance.showing = message !== '';
    showError(input, message);
};

/**
 * Document-level submit listener. It runs after the form's own listeners (core client-side validation), so core
 * cannot clear the error shown here.
 *
 * @param {SubmitEvent} event
 */
const onSubmit = (event) => {
    const form = event.target;
    const submitter = event.submitter;
    if (window.skipClientValidation || submitter?.dataset?.skipValidation || submitter?.dataset?.cancel) {
        return;
    }
    instances.forEach((instance, input) => {
        if (input.form !== form) {
            return;
        }
        if (!isChanged(input, instance)) {
            // Submit what was stored, not the picker's display form of it.
            if (!event.defaultPrevented) {
                input.value = instance.original;
            }
            return;
        }
        if (input.value.trim() === '') {
            return;
        }
        if (isValid(instance.iti)) {
            if (!event.defaultPrevented) {
                // E.164; digits stay in the numeral set the user typed, the server converts them to ASCII.
                input.value = instance.iti.getNumber();
            }
        } else {
            event.preventDefault();
            setError(input, instance, strings.invalid);
        }
    });
};

/**
 * Turn a leading "00" (also in Arabic-Indic or Persian digits) into "+": the library only recognises the country of a
 * number typed with "+", so "00971..." would otherwise keep the previously selected flag.
 *
 * @param {HTMLInputElement} input
 */
const normaliseInternationalPrefix = (input) => {
    const match = input.value.match(/^\s*(00|\u0660\u0660|\u06F0\u06F0)/);
    if (match) {
        input.value = '+' + input.value.slice(match[0].length);
        // Let the library read the new value (and select the country).
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }
};

/**
 * Attach the picker to one input.
 *
 * @param {Function} intlTelInput
 * @param {HTMLInputElement} input
 * @param {Object} config
 */
const attach = (intlTelInput, input, config) => {
    const original = input.value;
    // The library moves the input into its wrapper, which drops the focus.
    const hadfocus = document.activeElement === input;
    const iti = intlTelInput(input, {
        initialCountry: config.initialcountry,
        countryOrder: config.countryorder,
        excludeCountries: config.excludecountries,
        countryNameLocale: config.locale,
        countryNameOverrides: config.countrynames,
        uiTranslations: strings.ui,
        allowedNumberTypes: ['MOBILE'],
        // Keep the dial code in the input, so "+971..." and "00971..." can be typed or pasted, as the error says.
        separateDialCode: false,
        strictMode: false,
    });
    const instance = {
        iti,
        original,
        displayed: input.value.trim(),
        country: iti.getSelectedCountry()?.iso2 ?? '',
        edited: false,
        showing: false,
    };
    instances.set(input, instance);
    if (hadfocus) {
        input.focus({preventScroll: true});
    }
    const markEdited = () => {
        instance.edited = true;
    };
    input.addEventListener('input', markEdited);
    input.addEventListener('input', () => normaliseInternationalPrefix(input));
    input.addEventListener('countrychange', markEdited);
    input.addEventListener('blur', (event) => validateOnLeave(input, event));
};

/**
 * Enhance every phone1 input of the page.
 *
 * @param {Object} config
 * @param {string} config.scripturl URL of the vendored intl-tel-input bundle
 * @param {string} config.initialcountry ISO 3166-1 alpha-2 code, lower case
 * @param {string[]} config.countryorder countries listed first
 * @param {string[]} config.excludecountries countries not offered
 * @param {string} config.locale locale for the country names
 * @param {Object} config.countrynames country names that must match the site's country list, by ISO code
 */
export const init = async(config) => {
    const inputs = [...document.querySelectorAll(SELECTOR)].filter((input) => !instances.has(input));
    if (!inputs.length) {
        return;
    }
    const pending = new Pending('local_alborhansignup/phoneinput:init');
    try {
        const [intlTelInput, loaded] = await Promise.all([loadLibrary(config.scripturl), loadStrings()]);
        strings = loaded;
        inputs.forEach((input) => attach(intlTelInput, input, config));
        if (!listening) {
            document.addEventListener('submit', onSubmit);
            listening = true;
        }
    } catch (error) {
        // The plain input keeps working; the server validates and normalises the number.
        Log.warn(error);
    } finally {
        pending.resolve();
    }
};
