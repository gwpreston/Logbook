/*
 * Unit tests for the pure helper of assets/js/trip-form.js (spec.md §7.22,
 * Phase 22). Maintainer and CI only: `composer test:js`.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const trip = require('../../assets/js/trip-form.js');

test('a saved journey option fills the trip form fields', () => {
    assert.deepEqual(trip.journeyFields({
        from: 'Office',
        to: 'Client site',
        distance: '27',
        return: '1',
        business: '1',
        purpose: 'Site visit',
    }), {
        from_place: 'Office',
        to_place: 'Client site',
        distance: '27',
        is_return: true,
        is_business: true,
        purpose: 'Site visit',
    });
});

test('a private journey without a purpose clears them', () => {
    const fields = trip.journeyFields({from: 'Home', to: 'Gym', distance: '4.5', return: '', business: '', purpose: ''});
    assert.equal(fields.is_business, false);
    assert.equal(fields.is_return, false);
    assert.equal(fields.purpose, '');
});

test('the "none" option fills nothing', () => {
    assert.equal(trip.journeyFields({}), null);
    assert.equal(trip.journeyFields(undefined), null);
});
