/*
 * Unit tests for the pure helpers of assets/js/stations.js (spec.md §7.33,
 * Phase 30.1). Maintainer and CI only: `composer test:js`.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const stations = require('../../assets/js/stations.js');

test('search results become options, then Add for a new name', () => {
    const options = stations.comboOptions({
        exact: null,
        results: [
            {id: 4, name: 'Maxol Antrim', brand: 'Maxol', postcode: 'BT41 2BB', favourite: true, hint: ''},
            {id: 7, name: 'Tesco Antrim', brand: null, postcode: null, favourite: false, hint: 'Last time here: £1.389/L'},
        ],
    }, '  antrim ');
    assert.deepEqual(options.map((o) => [o.kind, o.id, o.name]), [
        ['station', '4', 'Maxol Antrim'],
        ['station', '7', 'Tesco Antrim'],
        ['add', '', 'antrim'],
    ]);
    assert.equal(options[0].meta, 'Maxol · BT41 2BB');
    assert.equal(options[1].hint, 'Last time here: £1.389/L');
});

test('no Add when the typed name is a station already, or nothing was typed', () => {
    assert.equal(stations.comboOptions({exact: 7, results: []}, 'Tesco Antrim').length, 0);
    assert.equal(stations.comboOptions({exact: null, results: []}, '   ').length, 0);
    assert.equal(stations.comboOptions(null, '').length, 0);
});

test('names are tidied as the server tidies them', () => {
    assert.equal(stations.tidy('  Tesco \t Antrim\n'), 'Tesco Antrim');
});

test('a position is kept to six places, and nonsense is refused', () => {
    assert.deepEqual(stations.coordinates({coords: {latitude: 54.7154321, longitude: -6.2164}}), {
        latitude: '54.715432',
        longitude: '-6.216400',
    });
    assert.equal(stations.coordinates({coords: {latitude: 95, longitude: 0}}), null);
    assert.equal(stations.coordinates(null), null);
});

test('options carry the listed prices of a linked station (Phase 30.2)', () => {
    const options = stations.comboOptions({
        exact: null,
        results: [{id: 4, name: 'Tesco', listed: {e10_95: {text: 'Listed £1.379/L E10 95 at 14:20', price: '1.379'}}}],
    }, '');
    assert.equal(options[0].listed.e10_95.price, '1.379');
});

test('the listed price follows the chosen grade, and odd answers are ignored', () => {
    const listed = {e10_95: {text: 'Listed £1.379/L E10 95 at 14:20', price: '1.379'}, b7: {text: 'x', price: '1,5'}};
    assert.deepEqual(stations.listedFor(listed, 'petrol:e10_95'), {text: 'Listed £1.379/L E10 95 at 14:20', price: '1.379'});
    assert.equal(stations.listedFor(listed, 'petrol:e5_97'), null);
    assert.equal(stations.listedFor(listed, 'diesel:b7'), null, 'not a canonical price');
    assert.equal(stations.listedFor({}, 'petrol:e10_95'), null);
    assert.equal(stations.listedFor(null, ''), null);
});
