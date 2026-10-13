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
 * Browser-side stand-ins for the Moodle pieces that amd/build/phoneinput.min.js talks to.
 *
 * Injected into the jsdom window by harness.js before the module. Expects window.M.str to hold the plugin strings.
 * Everything the tests observe is recorded on window.harness.
 *
 * - define()/require(): a tiny synchronous AMD registry (named modules only, like Moodle's built files).
 * - core/log, core/pending, core/str: minimal versions of the core modules.
 * - core_form/events: notifyFieldValidationFailure() dispatches the same cancelable event as core.
 * - theme_boost/form-display-errors: replica of the behaviour of public/theme/boost/amd/src/form-display-errors.js
 *   (message in .form-control-feedback, is-invalid, aria-*, and the setTimeout refocus that once caused a focus trap).
 * - coreFormValidation(): replica of the client-side "required" rule script printed by public/lib/formslib.php.
 *
 * Behaviour replicated from Moodle core (public/theme/boost/amd/src/form-display-errors.js, public/lib/formslib.php),
 * GPL v3 or later.
 */
(function() {
    'use strict';

    const harness = window.harness = {
        log: [],
        failures: [],
        pending: 0,
        init: null,
    };

    // AMD shim.
    const registry = new Map();

    window.define = function(name, deps, factory) {
        if (typeof name !== 'string') {
            throw new Error('Only named modules are supported');
        }
        if (typeof deps === 'function') {
            factory = deps;
            deps = [];
        }
        registry.set(name, {deps, factory, done: false, value: undefined});
    };
    window.define.amd = {};

    const resolve = (name) => {
        const entry = registry.get(name);
        if (!entry) {
            throw new Error('Unknown AMD module ' + name);
        }
        if (!entry.done) {
            const exports = {};
            const args = entry.deps.map((dep) => (dep === 'exports' ? exports : resolve(dep)));
            const value = entry.factory(...args);
            entry.value = entry.deps.includes('exports') ? exports : value;
            entry.done = true;
        }
        return entry.value;
    };

    // Asynchronous like RequireJS, so callbacks run in the order they were queued.
    window.require = function(deps, callback) {
        Promise.resolve().then(() => {
            const modules = deps.map(resolve);
            if (callback) {
                callback(...modules);
            }
            return null;
        }).catch((error) => {
            harness.log.push(['require', String(error && error.stack || error)]);
        });
    };

    // Core modules.
    define('core/log', [], () => ({
        debug() {
            // Not recorded.
        },
        info() {
            // Not recorded.
        },
        warn(...args) {
            harness.log.push(['warn', args.map(String).join(' ')]);
        },
        error(...args) {
            harness.log.push(['error', args.map(String).join(' ')]);
        },
    }));

    define('core/pending', [], () => class Pending {
        constructor() {
            harness.pending++;
        }
        resolve() {
            harness.pending--;
        }
    });

    const getString = (key, component, param) => {
        const strings = (window.M && window.M.str && window.M.str[component]) || {};
        if (!Object.prototype.hasOwnProperty.call(strings, key)) {
            return Promise.reject(new Error('Missing string ' + key + ', ' + component));
        }
        let value = strings[key];
        if (typeof param === 'string' || typeof param === 'number') {
            value = value.replace(/\{\$a\}/g, String(param));
        } else if (param && typeof param === 'object') {
            Object.keys(param).forEach((name) => {
                value = value.split('{$a->' + name + '}').join(String(param[name]));
            });
        }
        return Promise.resolve(value);
    };

    define('core/str', [], () => ({
        getString,
        getStrings: (requests) => Promise.all(requests.map((r) => getString(r.key, r.component, r.param))),
    }));

    define('core_form/events', [], () => {
        const eventTypes = {
            formError: 'core_form/error',
            formFieldValidationFailed: 'core_form/fieldValidationFailed',
        };
        const dispatch = (name, detail, target, cancelable) => {
            const event = new CustomEvent(name, {bubbles: true, cancelable, detail});
            target.dispatchEvent(event);
            return event;
        };
        return {
            eventTypes,
            notifyFormError: (field) => dispatch(eventTypes.formError, {}, field, false),
            notifyFieldValidationFailure: (field, message) => {
                harness.failures.push({field: field.name || field.id, message});
                return dispatch(eventTypes.formFieldValidationFailed, {message}, field, true);
            },
        };
    });

    // Replica of theme_boost/form-display-errors (jQuery removed, same observable behaviour).
    define('theme_boost/form-display-errors', ['core_form/events'], (FormEvents) => {
        let focusedAlready = false;
        const clientError = new WeakMap();
        return {
            enhance(elementid) {
                const element = document.getElementById(elementid);
                if (!element) {
                    return;
                }
                element.addEventListener(FormEvents.eventTypes.formFieldValidationFailed, (e) => {
                    const msg = e.detail.message;
                    e.preventDefault();
                    const parent = element.closest('.fitem');
                    const feedback = parent ? parent.querySelector('.form-control-feedback') : null;
                    const feedbackId = feedback ? feedback.id : '';
                    const describedBy = (element.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
                    if (msg !== '') {
                        parent.classList.add('has-danger');
                        clientError.set(parent, true);
                        element.classList.add('is-invalid');
                        if (feedbackId && !describedBy.includes(feedbackId)) {
                            describedBy.push(feedbackId);
                            element.setAttribute('aria-describedby', describedBy.join(' '));
                        }
                        element.setAttribute('aria-invalid', 'true');
                        feedback.innerHTML = msg;
                        feedback.style.display = 'block';
                        if (!focusedAlready) {
                            element.scrollIntoView({behavior: 'smooth', block: 'center'});
                            focusedAlready = true;
                            // The theme puts the focus back on the field with an error.
                            setTimeout(() => {
                                element.focus({preventScroll: true});
                                focusedAlready = false;
                            }, 0);
                        }
                    } else if (clientError.get(parent) === true) {
                        parent.classList.remove('has-danger');
                        clientError.set(parent, false);
                        element.classList.remove('is-invalid');
                        const remaining = describedBy.filter((id) => id !== feedbackId);
                        if (remaining.length) {
                            element.setAttribute('aria-describedby', remaining.join(' '));
                        } else {
                            element.removeAttribute('aria-describedby');
                        }
                        element.setAttribute('aria-invalid', 'false');
                        feedback.style.display = 'none';
                    }
                });
            },
        };
    });

    /**
     * Replica of the client-side validation script core prints for "required" rules with validation 'client':
     * validate a field on "change", validate all of them on submit unless skipClientValidation is set.
     *
     * @param {string} formid
     * @param {Array<{name: string, message: string}>} rules
     */
    window.coreFormValidation = (formid, rules) => {
        require(['core_form/events'], (FormEvents) => {
            const form = document.getElementById(formid);
            const qfErrorHandler = (element, message) => {
                const event = FormEvents.notifyFieldValidationFailure(element, message);
                return event.defaultPrevented ? message === '' : true;
            };
            const validateField = (rule) => {
                const element = form.elements[rule.name];
                return qfErrorHandler(element, element.value === '' ? rule.message : '');
            };
            rules.forEach((rule) => {
                form.elements[rule.name].addEventListener('change', () => validateField(rule));
            });
            form.addEventListener('submit', (ev) => {
                if (window.skipClientValidation) {
                    return;
                }
                let ret = true;
                let firstFocus = false;
                rules.forEach((rule) => {
                    ret = validateField(rule) && ret;
                    if (!ret && !firstFocus) {
                        firstFocus = true;
                        const error = document.getElementById('id_error_' + rule.name);
                        if (error) {
                            FormEvents.notifyFormError(error);
                            error.focus();
                        }
                    }
                });
                if (!ret) {
                    ev.preventDefault();
                }
            });
        });
    };
})();
