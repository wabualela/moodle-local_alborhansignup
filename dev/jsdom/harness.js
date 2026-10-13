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

'use strict';
/**
 * jsdom page factory and browser-like input helpers for run.js.
 *
 * A page is fixture.html + moodle-stubs.js + the plugin's built AMD module (amd/build/phoneinput.min.js). The module
 * itself loads the vendored intl-tel-input bundle through a <script src> element, as it does in Moodle.
 */
const fs = require('fs');
const path = require('path');
const {pathToFileURL} = require('url');
const {JSDOM, VirtualConsole} = require('jsdom');

const PLUGIN = path.resolve(__dirname, '../..');
const COMPONENT = 'local_alborhansignup';

const FILES = {
    fixture: path.join(__dirname, 'fixture.html'),
    stubs: path.join(__dirname, 'moodle-stubs.js'),
    module: path.join(PLUGIN, 'amd', 'build', 'phoneinput.min.js'),
    library: path.join(PLUGIN, 'thirdparty', 'intl-tel-input', 'js', 'intlTelInputWithUtils.min.js'),
    pageAssets: path.join(PLUGIN, 'classes', 'local', 'page_assets.php'),
    lang: (lang) => path.join(PLUGIN, 'lang', lang, COMPONENT + '.php'),
};

/**
 * Parse the $string['key'] = '...'; lines of a Moodle language file.
 *
 * @param {string} file
 * @returns {Object<string, string>}
 */
function parseLangFile(file) {
    const source = fs.readFileSync(file, 'utf8');
    const strings = {};
    const re = /\$string\['([^']+)'\]\s*=\s*'((?:[^'\\]|\\.)*)';/g;
    let match;
    while ((match = re.exec(source))) {
        strings[match[1]] = match[2].replace(/\\(['\\])/g, '$1');
    }
    const declared = (source.match(/^\$string\[/gm) || []).length;
    if (declared !== Object.keys(strings).length) {
        throw new Error(`Parsed ${Object.keys(strings).length} of ${declared} strings in ${file}`);
    }
    return strings;
}

/**
 * Read a constant of page_assets.php, so the picker configuration follows the plugin.
 *
 * @param {string} source PHP source
 * @param {string} name constant name
 * @returns {string|string[]}
 */
function phpConstant(source, name) {
    const match = source.match(new RegExp(`const ${name} = (\\[[^\\]]*\\]|'[^']*');`));
    if (!match) {
        throw new Error(`Constant ${name} not found in page_assets.php`);
    }
    const values = [...match[1].matchAll(/'([^']*)'/g)].map((m) => m[1]);
    return match[1].startsWith('[') ? values : values[0];
}

/**
 * Configuration passed to init(), mirroring page_assets::phone_config().
 *
 * @param {Object} overrides
 * @returns {Object}
 */
function phoneConfig(overrides = {}) {
    const source = fs.readFileSync(FILES.pageAssets, 'utf8');
    const scripturl = pathToFileURL(FILES.library);
    scripturl.searchParams.set('v', '1');
    return {
        scripturl: scripturl.href,
        initialcountry: phpConstant(source, 'INITIAL_COUNTRY'),
        countryorder: phpConstant(source, 'COUNTRY_ORDER'),
        excludecountries: phpConstant(source, 'EXCLUDED_COUNTRIES'),
        locale: 'ar',
        // Names from the site's country list for COUNTRY_NAMES_FROM_SITE (Arabic site).
        countrynames: {ps: 'فلسطين', sy: 'سوريا'},
        ...overrides,
    };
}

const tick = (ms = 20) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Build a page and wait until the picker is attached (or failed to attach).
 *
 * @param {Object} options
 * @param {string} [options.phone1] stored value of phone1 (the value attribute the server prints)
 * @param {Object} [options.config] overrides of the init() configuration
 * @returns {Promise<Page>}
 */
async function createPage({phone1 = '', config = {}} = {}) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', (error) => errors.push(String(error && error.message || error)));
    virtualConsole.on('error', (...args) => errors.push('console.error: ' + args.join(' ')));

    const dom = new JSDOM(fs.readFileSync(FILES.fixture, 'utf8'), {
        url: pathToFileURL(FILES.fixture).href,
        runScripts: 'dangerously',
        resources: 'usable',
        pretendToBeVisual: true,
        virtualConsole,
    });
    const w = dom.window;
    const d = w.document;
    // Not implemented by jsdom; the theme calls it before refocusing a field.
    w.Element.prototype.scrollIntoView = function() {
        // No layout.
    };
    d.getElementById('id_phone1').setAttribute('value', phone1);

    const addScript = (code) => {
        const script = d.createElement('script');
        script.textContent = code;
        d.body.append(script);
    };
    const strings = parseLangFile(FILES.lang('en'));
    addScript(`window.M = {str: {${COMPONENT}: ${JSON.stringify(strings)}}};`);
    addScript(fs.readFileSync(FILES.stubs, 'utf8'));
    addScript(fs.readFileSync(FILES.module, 'utf8'));

    // Record every submission after all other listeners (window is the last stop of the bubbling submit event),
    // then cancel the navigation jsdom does not implement.
    const submissions = [];
    w.addEventListener('submit', (event) => {
        submissions.push({
            prevented: event.defaultPrevented,
            submitter: event.submitter ? event.submitter.name : null,
            phone1: new w.FormData(event.target).get('phone1'),
        });
        event.preventDefault();
    });

    // End-of-page JavaScript, in the order Moodle prints it: core's validation script, the theme's error display for
    // each field, then the plugin (page_assets::add_to_page()).
    const finalConfig = phoneConfig(config);
    addScript(`
        coreFormValidation('mform1', [
            {name: 'email', message: 'Missing email address'},
            {name: 'phone1', message: ${JSON.stringify(strings.phone1required)}},
        ]);
        ['id_email', 'id_phone1', 'id_submitbutton', 'id_cancel'].forEach((id) => {
            require(['theme_boost/form-display-errors'], (module) => module.enhance(id));
        });
        require(['${COMPONENT}/phoneinput'], (module) => {
            window.harness.init = module.init(${JSON.stringify(finalConfig)});
        });
    `);

    for (let i = 0; i < 500 && !w.harness.init; i++) {
        await tick(10);
    }
    if (!w.harness.init) {
        throw new Error('init() was never called: ' + JSON.stringify(w.harness.log));
    }
    let timer = null;
    const timeout = new Promise((resolve, reject) => {
        timer = setTimeout(() => reject(new Error('init() did not finish within 15 s')), 15000);
    });
    try {
        await Promise.race([w.harness.init, timeout]);
    } finally {
        clearTimeout(timer);
    }
    await tick();

    return new Page(dom, {errors, submissions, strings, config: finalConfig});
}

/**
 * A loaded page with browser-like helpers. jsdom has no keyboard navigation, so focus moves are explicit:
 * tab(to) fires "change" on the element being left when its value changed since it got the focus (as browsers do),
 * then moves the focus (blur/focus events carry relatedTarget).
 */
class Page {
    constructor(dom, {errors, submissions, strings, config}) {
        this.dom = dom;
        this.window = dom.window;
        this.document = dom.window.document;
        this.errors = errors;
        this.submissions = submissions;
        this.strings = strings;
        this.config = config;
        this.valueAtFocus = new WeakMap();
        this.document.addEventListener('focusin', (event) => {
            this.valueAtFocus.set(event.target, event.target.value);
        }, true);
    }

    $(selector) {
        return this.document.querySelector(selector);
    }

    get phone() {
        return this.document.getElementById('id_phone1');
    }

    get email() {
        return this.document.getElementById('id_email');
    }

    get active() {
        return this.document.activeElement;
    }

    /** @returns {Object} intl-tel-input instance of phone1 */
    get iti() {
        return this.window.intlTelInput.getInstance(this.phone);
    }

    get selectedCountry() {
        const country = this.iti.getSelectedCountry();
        return country ? country.iso2 : '';
    }

    /** @returns {string} visible error text under phone1 ('' when hidden) */
    get phoneError() {
        const feedback = this.document.getElementById('id_error_phone1');
        return feedback.style.display === 'none' ? '' : feedback.textContent.trim();
    }

    /** @returns {string[]} non-empty error messages notified for phone1 */
    get phoneErrorsNotified() {
        return this.window.harness.failures.filter((f) => f.field === 'phone1' && f.message !== '').map((f) => f.message);
    }

    /** @returns {string[]} warnings logged by the module and jsdom errors other than missing browser features */
    get problems() {
        return [
            ...this.window.harness.log.map((entry) => entry.join(': ')),
            ...this.errors.filter((message) => !/^Not implemented/.test(message)),
        ];
    }

    /**
     * Move the focus like Tab / Shift+Tab / a click would.
     *
     * @param {HTMLElement|null} to null to blur
     */
    tab(to) {
        const from = this.active;
        if (from && from !== this.document.body && 'value' in from && this.valueAtFocus.has(from)
                && this.valueAtFocus.get(from) !== from.value) {
            this.valueAtFocus.set(from, from.value);
            from.dispatchEvent(new this.window.Event('change', {bubbles: true}));
        }
        if (to) {
            to.focus();
        } else if (from) {
            from.blur();
        }
    }

    /**
     * Type keys into the focused element (focusing el first, caret at the end): keydown, edit, input, keyup per key.
     * '\b' is Backspace.
     *
     * @param {HTMLInputElement} el
     * @param {string} keys
     */
    type(el, keys) {
        const w = this.window;
        if (this.active !== el) {
            this.tab(el);
            el.setSelectionRange(el.value.length, el.value.length);
        }
        for (const key of keys) {
            const target = this.active && this.active !== this.document.body ? this.active : el;
            const name = key === '\b' ? 'Backspace' : key;
            const allowed = target.dispatchEvent(new w.KeyboardEvent('keydown', {key: name, bubbles: true, cancelable: true}));
            if (allowed && 'value' in target) {
                const start = target.selectionStart ?? target.value.length;
                const end = target.selectionEnd ?? start;
                let value = target.value;
                let caret = start;
                let inputType = null;
                if (name === 'Backspace') {
                    if (start !== end || start > 0) {
                        caret = start !== end ? start : start - 1;
                        value = value.slice(0, caret) + value.slice(end);
                        inputType = 'deleteContentBackward';
                    }
                } else {
                    value = value.slice(0, start) + key + value.slice(end);
                    caret = start + 1;
                    inputType = 'insertText';
                }
                if (inputType) {
                    target.value = value;
                    target.setSelectionRange(caret, caret);
                    target.dispatchEvent(new w.InputEvent('input', {
                        bubbles: true, inputType, data: inputType === 'insertText' ? key : null,
                    }));
                }
            }
            target.dispatchEvent(new w.KeyboardEvent('keyup', {key: name, bubbles: true}));
        }
    }

    /**
     * Select all the text of the focused field and delete it (Ctrl+A, Backspace).
     *
     * @param {HTMLInputElement} el
     */
    clear(el) {
        if (this.active !== el) {
            this.tab(el);
        }
        el.setSelectionRange(0, el.value.length);
        this.type(el, '\b');
    }

    /**
     * Click a button. With focus (Chrome, Firefox) the button takes the focus first, which blurs the field;
     * without (Safari) the field keeps it.
     *
     * @param {HTMLElement} button
     * @param {Object} [options]
     * @param {boolean} [options.focus=true]
     */
    click(button, {focus = true} = {}) {
        if (focus) {
            this.tab(button);
        }
        button.click();
    }

    /**
     * Submit with the submit button and return what was posted.
     *
     * @param {string} [buttonid]
     * @returns {Promise<{prevented: boolean, submitter: string|null, phone1: string|null}>}
     */
    async submit(buttonid = 'id_submitbutton', options = {}) {
        const before = this.submissions.length;
        this.click(this.document.getElementById(buttonid), options);
        await tick();
        if (this.submissions.length !== before + 1) {
            throw new Error('The form did not fire exactly one submit event');
        }
        return this.submissions[before];
    }

    close() {
        this.window.close();
    }
}

module.exports = {createPage, parseLangFile, tick, FILES, PLUGIN};
