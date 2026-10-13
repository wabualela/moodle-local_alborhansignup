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
 * the user typed block the submission with a field error. The server validates and normalises again.
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

/** @type {Map<HTMLInputElement, {iti: Object, initialvalue: string}>} */
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
 * Whether the user changed the value since the page loaded (unchanged legacy values are never blocked).
 *
 * @param {HTMLInputElement} input
 * @param {string} initialvalue
 * @returns {boolean}
 */
const isChanged = (input, initialvalue) => input.value.trim() !== initialvalue;

/**
 * Validate one field on blur.
 *
 * @param {HTMLInputElement} input
 */
const validateOnBlur = (input) => {
    const instance = instances.get(input);
    if (!instance || input.value.trim() === '') {
        return;
    }
    if (isValid(instance.iti)) {
        showError(input, '');
    } else if (isChanged(input, instance.initialvalue)) {
        showError(input, strings.invalid);
    }
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
    instances.forEach(({iti, initialvalue}, input) => {
        if (input.form !== form || input.value.trim() === '') {
            return;
        }
        if (isValid(iti)) {
            if (!event.defaultPrevented) {
                // E.164 with ASCII digits; the server normalises digits again anyway.
                input.value = iti.getNumber();
            }
        } else if (isChanged(input, initialvalue)) {
            event.preventDefault();
            showError(input, strings.invalid);
        }
    });
};

/**
 * Attach the picker to one input.
 *
 * @param {Function} intlTelInput
 * @param {HTMLInputElement} input
 * @param {Object} config
 */
const attach = (intlTelInput, input, config) => {
    const iti = intlTelInput(input, {
        initialCountry: config.initialcountry,
        countryOrder: config.countryorder,
        excludeCountries: config.excludecountries,
        allowedNumberTypes: ['MOBILE'],
        countryNameLocale: config.locale,
        uiTranslations: strings.ui,
    });
    instances.set(input, {iti, initialvalue: input.value.trim()});
    input.addEventListener('blur', () => validateOnBlur(input));
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
