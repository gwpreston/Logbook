/*
 * Unit tests for assets/js/vehicle-lookup.js (spec.md §7.38, K194).
 * Maintainer and CI only: `composer test:js`. Node is never needed to run or
 * install Logbook.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const lookup = require('../../assets/js/vehicle-lookup.js');

// A look-up box with just enough DOM for enhance(): its form, button and message.
function fakeRoot() {
    const button = {
        listeners: 0,
        addEventListener(type) {
            if (type === 'click') {
                this.listeners += 1;
            }
        },
    };
    const box = {
        dataset: {},
        closest: () => ({action: '/vehicles/new'}),
        querySelector: (selector) => (selector === '[data-lookup-button]' ? button : {textContent: ''}),
    };

    return {button, root: {querySelectorAll: () => [box]}};
}

test('the script exposes enhance, for the modal to call', () => {
    assert.equal(typeof lookup.enhance, 'function');
});

test('enhancing a form twice binds Look up once', () => {
    const {button, root} = fakeRoot();

    lookup.enhance(root);
    lookup.enhance(root);

    assert.equal(button.listeners, 1);
});
