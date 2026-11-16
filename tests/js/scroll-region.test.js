/*
 * Scrolling table boxes (WCAG 2.1.1) and chart tables (spec.md §8
 * *Printing reports*, K202, #623): enhanceScrollRegion in assets/js/app.js
 * gives a box that scrolls a tab stop and a name, but never a chart's
 * screen-reader table, which is clipped to 1 px and only looks as if it
 * scrolls. Stub elements stand in for the DOM. Maintainer and CI only:
 * `composer test:js`.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {enhanceScrollRegion} = require('../../assets/js/app.js');

/*
 * Just enough of an element for enhanceScrollRegion: classes, attributes,
 * a parent for closest(), and a box wider inside than out. closest() is
 * honest: it matches only a class the element or a parent really has, so
 * a test can't pass on a stub that matches everything.
 */
function element(classes, parent = null) {
    const attributes = new Map();
    return {
        classes: new Set(classes),
        parent,
        scrollWidth: 800,
        clientWidth: 300,
        hasAttribute: (name) => attributes.has(name),
        getAttribute: (name) => (attributes.has(name) ? attributes.get(name) : null),
        setAttribute: (name, value) => attributes.set(name, String(value)),
        removeAttribute: (name) => attributes.delete(name),
        querySelector: () => null,
        // Class selectors only ('.chart-table', 'section, .card'); others never match.
        closest(selector) {
            const wanted = selector.split(',').map((one) => one.trim())
                .filter((one) => one.startsWith('.')).map((one) => one.slice(1));
            for (let node = this; node; node = node.parent) {
                if (wanted.some((name) => node.classes.has(name))) {
                    return node;
                }
            }
            return null;
        },
        attributes,
    };
}

test('an ordinary table box that scrolls gets a tab stop and a name', () => {
    const card = element(['card']);
    card.querySelector = () => ({textContent: 'Fill-ups'});
    const wrap = element(['table-wrap'], card);

    enhanceScrollRegion(wrap);

    assert.equal(wrap.getAttribute('tabindex'), '0');
    assert.equal(wrap.getAttribute('role'), 'group');
    assert.equal(wrap.getAttribute('aria-label'), 'Fill-ups');
});

test("a chart's screen-reader table wrapper is never a tab stop (K202)", () => {
    const card = element(['card']);
    card.querySelector = () => ({textContent: 'Economy trend'});
    const wrap = element(['table-wrap', 'chart-table'], card);

    enhanceScrollRegion(wrap);

    assert.deepEqual([...wrap.attributes.keys()], [], 'no tabindex, role or name');
});

test('a table box inside a chart table is left alone too (K202)', () => {
    const chart = element(['chart-table']);
    const wrap = element(['table-wrap'], chart);

    enhanceScrollRegion(wrap);

    assert.deepEqual([...wrap.attributes.keys()], []);
});
