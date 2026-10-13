#!/usr/bin/env node
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
 * Browser-like checks of the phone1 country picker (amd/build/phoneinput.min.js + vendored intl-tel-input) in jsdom.
 *
 * Usage, from the repository root: node dev/jsdom/run.js [filter]
 * (filter: run only the scenarios whose name contains the text)
 * Exit code: 0 when every check passes, 1 otherwise.
 */
const {createPage, tick} = require('./harness.js');

/**
 * Convert Arabic-Indic (U+0660..U+0669) and Persian (U+06F0..U+06F9) digits to ASCII.
 *
 * @param {string} value
 * @returns {string}
 */
const toAscii = (value) => [...String(value)].map((c) => {
    const code = c.codePointAt(0);
    if (code >= 0x0660 && code <= 0x0669) {
        return String(code - 0x0660);
    }
    if (code >= 0x06F0 && code <= 0x06F9) {
        return String(code - 0x06F0);
    }
    return c;
}).join('');

/**
 * Leave the phone field for the e-mail field, then let the theme's setTimeout refocus run.
 *
 * @param {Page} page
 */
const leavePhone = async(page) => {
    page.tab(page.email);
    await tick();
};

const scenarios = [];
const scenario = (name, fn, options = {}) => scenarios.push({name, fn, options});

scenario('picker attaches to phone1 without warnings', async(page, check) => {
    check('intl-tel-input wrapper around phone1', page.phone.parentElement.classList.contains('iti'));
    check('initial country is Saudi Arabia', page.selectedCountry === 'sa', page.selectedCountry);
    check('Pending resolved', page.window.harness.pending === 0, page.window.harness.pending);
    check('excluded countries are not offered', !page.$('li.iti__country[data-iso2="il"]'));
});

scenario('typing 0501234567 submits +966501234567', async(page, check) => {
    page.email.value = 'student@example.com';
    page.type(page.phone, '0501234567');
    await leavePhone(page);
    check('no error after leaving the field', page.phoneError === '', page.phoneError);
    check('focus stayed on the next field', page.active === page.email, page.active.id);
    const post = await page.submit();
    check('submission not blocked', post.prevented === false);
    check('phone1 posted in E.164', post.phone1 === '+966501234567', post.phone1);
});

scenario('typing 00971501234567 selects the AE flag and submits +971501234567', async(page, check) => {
    page.email.value = 'student@example.com';
    page.type(page.phone, '00971501234567');
    check('leading 00 became +', page.phone.value.startsWith('+971'), page.phone.value);
    check('UAE selected', page.selectedCountry === 'ae', page.selectedCountry);
    await leavePhone(page);
    check('no error after leaving the field', page.phoneError === '', page.phoneError);
    const post = await page.submit();
    check('submission not blocked', post.prevented === false);
    check('phone1 posted in E.164', post.phone1 === '+971501234567', post.phone1);
});

scenario('Arabic-Indic digits are accepted', async(page, check) => {
    page.email.value = 'student@example.com';
    page.type(page.phone, '٠٥٠١٢٣٤٥٦٧');
    await leavePhone(page);
    check('no error after leaving the field', page.phoneError === '', page.phoneError);
    const post = await page.submit();
    check('submission not blocked', post.prevented === false);
    // The module leaves the digits in the numeral set the user typed; the server converts them to ASCII.
    check('phone1 posted as +966501234567 (digits may stay Arabic-Indic)', toAscii(post.phone1) === '+966501234567',
        post.phone1);
});

scenario('invalid edit: error shown once, Tab leaves the field (no focus trap)', async(page, check) => {
    page.email.value = 'student@example.com';
    page.type(page.phone, '12345');
    await leavePhone(page);
    check('error shown after the first Tab', page.phoneError === page.strings.phone1invalid, page.phoneError);
    // Sanity check of the theme replica: a new error moves the focus back to the field once (core behaviour).
    check('theme moved the focus back to the field once', page.active === page.phone, page.active.id);
    await leavePhone(page);
    check('second Tab leaves the field', page.active === page.email, page.active.id);
    page.tab(page.phone);
    await leavePhone(page);
    check('Tab in and out again without editing leaves the field', page.active === page.email, page.active.id);
    check('error still shown', page.phoneError === page.strings.phone1invalid, page.phoneError);
    check('error notified exactly once', page.phoneErrorsNotified.length === 1, page.phoneErrorsNotified.length);
    const post = await page.submit();
    check('submit with the invalid number is blocked', post.prevented === true);
});

scenario('moving to the country button is not leaving the field', async(page, check) => {
    page.type(page.phone, '12345');
    page.tab(page.$('.iti__selected-country'));
    await tick();
    check('no error while on the country button', page.phoneError === '', page.phoneError);
    check('focus on the country button', page.active.classList.contains('iti__selected-country'), page.active.className);
});

scenario('untouched legacy value +9665012345 is submitted as stored', async(page, check) => {
    page.email.value = 'student@example.com';
    page.tab(page.phone);
    await leavePhone(page);
    check('no error when focusing and leaving without editing', page.phoneError === '', page.phoneError);
    const post = await page.submit();
    check('submission not blocked', post.prevented === false);
    check('phone1 posted exactly as stored', post.phone1 === '+9665012345', JSON.stringify(post.phone1));
}, {phone1: '+9665012345'});

scenario('stored valid number is submitted as stored', async(page, check) => {
    page.email.value = 'student@example.com';
    const post = await page.submit();
    check('submission not blocked', post.prevented === false);
    check('phone1 posted exactly as stored', post.phone1 === '+971501234567', JSON.stringify(post.phone1));
}, {phone1: '+971501234567'});

scenario('clearing the field after an error keeps core\'s "required" message', async(page, check) => {
    page.email.value = 'student@example.com';
    page.type(page.phone, '12345');
    await leavePhone(page);
    check('invalid-number error shown', page.phoneError === page.strings.phone1invalid, page.phoneError);
    check('focus back on the field', page.active === page.phone, page.active.id);
    page.clear(page.phone);
    check('field is empty', page.phone.value === '', JSON.stringify(page.phone.value));
    await leavePhone(page);
    check('core\'s required message is shown', page.phoneError === page.strings.phone1required, page.phoneError);
    check('field still marked invalid', page.phone.classList.contains('is-invalid'));
    const post = await page.submit();
    check('submit with an empty field is blocked by core', post.prevented === true);
    check('required message still shown after submit', page.phoneError === page.strings.phone1required,
        page.phoneError);
});

scenario('fixing the number clears the error', async(page, check) => {
    page.email.value = 'student@example.com';
    page.type(page.phone, '050123');
    await leavePhone(page);
    check('error shown', page.phoneError === page.strings.phone1invalid, page.phoneError);
    page.type(page.phone, '4567');
    await leavePhone(page);
    check('error cleared', page.phoneError === '', page.phoneError);
    check('field not marked invalid', !page.phone.classList.contains('is-invalid'));
    const post = await page.submit();
    check('phone1 posted in E.164', post.prevented === false && post.phone1 === '+966501234567', post.phone1);
});

scenario('cancel skips validation', async(page, check) => {
    page.type(page.phone, '12345');
    // Safari: the button does not take the focus, so the field is never left.
    const post = await page.submit('id_cancel', {focus: false});
    check('cancel not blocked', post.prevented === false);
    check('submitter is cancel', post.submitter === 'cancel', post.submitter);
    check('no error shown', page.phoneError === '', page.phoneError);
    check('typed value left alone', post.phone1 === '12345', post.phone1);
});

scenario('cancel skips validation after the field showed an error', async(page, check) => {
    page.type(page.phone, '12345');
    // Chrome/Firefox: the button takes the focus, the field is left and shows the error.
    const post = await page.submit('id_cancel');
    check('cancel not blocked', post.prevented === false);
    check('submitter is cancel', post.submitter === 'cancel', post.submitter);
});

scenario('Arabic country search: "الامارات" finds ae (patched library)', async(page, check) => {
    page.click(page.$('.iti__selected-country'));
    const search = page.$('.iti__search-input');
    check('country search opened', Boolean(search));
    const visible = () => [...page.document.querySelectorAll('.iti__country-list li.iti__country')]
        .filter((li) => li.isConnected && !li.classList.contains('iti__hide'))
        .map((li) => li.dataset.iso2);
    const query = async(text) => {
        search.value = text;
        search.dispatchEvent(new page.window.InputEvent('input', {bubbles: true, inputType: 'insertText', data: text}));
        await tick(400);
        return visible();
    };
    const ae = await query('الامارات');
    check('"الامارات" (no hamza) lists ae', ae.includes('ae'), ae.join(','));
    const jo = await query('الاردن');
    check('"الاردن" (no hamza) lists jo', jo.includes('jo'), jo.join(','));
    const ps = await query('فلسطين');
    check('"فلسطين" lists ps (site name override)', ps.includes('ps'), ps.join(','));
});

async function main() {
    const filter = process.argv[2] || '';
    let failed = 0;
    let passed = 0;
    for (const {name, fn, options} of scenarios.filter((s) => s.name.includes(filter))) {
        console.log(`\n# ${name}`);
        let page = null;
        let scenarioFailed = false;
        const check = (label, ok, detail) => {
            const suffix = ok || detail === undefined ? '' : `  (got ${detail})`;
            console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}${suffix}`);
            if (!ok) {
                scenarioFailed = true;
            }
        };
        try {
            page = await createPage(options);
            await fn(page, check);
            const problems = page.problems;
            check('no module warnings or script errors', problems.length === 0, problems.join(' | '));
        } catch (error) {
            check('scenario ran', false, error && error.stack || error);
        } finally {
            if (page) {
                page.close();
            }
        }
        if (scenarioFailed) {
            failed++;
        } else {
            passed++;
        }
    }
    console.log(`\n${passed} scenario(s) passed, ${failed} failed.`);
    process.exitCode = failed || !passed ? 1 : 0;
}

main();
