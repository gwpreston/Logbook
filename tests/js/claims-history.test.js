/*
 * Unit tests for the pure helpers of assets/js/claims-history.js (spec.md
 * §7.29 *Copy for insurance quote*, Phase 33.3). Maintainer and CI only:
 * `composer test:js`.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const claims = require('../../assets/js/claims-history.js');

test('each row is one line, its parts joined with an en dash', () => {
    assert.equal(claims.copyText([
        ['12 Mar 2024', 'Collision', 'Not at fault', 'Claim settled', '£1,240.00', '2019 BMW 320d'],
        ['2 May 2023', 'Glass', 'Not shared with you', '2015 Ford Fiesta'],
    ]), '12 Mar 2024 – Collision – Not at fault – Claim settled – £1,240.00 – 2019 BMW 320d\n'
        + '2 May 2023 – Glass – Not shared with you – 2015 Ford Fiesta');
});

test('an empty part (no payout shown) leaves no gap', () => {
    assert.equal(
        claims.copyText([['12 Mar 2024', 'Theft', 'Fault not known', 'Claim open', '', ' 2019 BMW 320d ']]),
        '12 Mar 2024 – Theft – Fault not known – Claim open – 2019 BMW 320d',
    );
});

test('no rows copies nothing', () => {
    assert.equal(claims.copyText([]), '');
    assert.equal(claims.copyText([[]]), '');
    assert.equal(claims.copyText(null), '');
});

test('a row attribute is read as a JSON list, anything else as no parts', () => {
    assert.deepEqual(claims.parts('["12 Mar 2024","Fire"]'), ['12 Mar 2024', 'Fire']);
    assert.deepEqual(claims.parts('{"a":1}'), []);
    assert.deepEqual(claims.parts('not json'), []);
});
