/*
 * Unit tests for the pure helpers of assets/js/first-inspection.js
 * (spec.md §7.1, Phase 21.2). Maintainer and CI only: `composer test:js`.
 * Node is never needed to run or install Logbook.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const first = require('../../assets/js/first-inspection.js');

test('adds whole months, clamped to the end of a shorter month', () => {
    assert.equal(first.addMonths('2023-06-14', 36), '2026-06-14');
    assert.equal(first.addMonths('2024-02-29', 36), '2027-02-28', '29 Feb clamps, as the server does');
    assert.equal(first.addMonths('2024-02-29', 48), '2028-02-29', 'a leap year keeps it');
    assert.equal(first.addMonths('2023-01-31', 1), '2023-02-28');
    assert.equal(first.addMonths('2023-11-30', 3), '2024-02-29');
    assert.equal(first.addMonths('2023-12-15', 12), '2024-12-15');
});

test('anything that is not a date gives nothing', () => {
    assert.equal(first.addMonths('', 36), '');
    assert.equal(first.addMonths('14/06/2023', 36), '');
    assert.equal(first.addMonths(undefined, 36), '');
});

test('suggests first registration plus the months', () => {
    assert.equal(first.suggest('2024-06-14', 36, '2026-09-30'), '2027-06-14');
    assert.equal(first.suggest('2024-06-14', 48, '2026-09-30'), '2028-06-14');
});

test('a suggestion before today is never offered', () => {
    assert.equal(first.suggest('2020-06-14', 36, '2026-09-30'), '', 'that vehicle has had its first MOT');
    assert.equal(first.suggest('2023-09-30', 36, '2026-09-30'), '2026-09-30', 'today itself is still to come');
});

test('no rule or no first registration gives nothing', () => {
    assert.equal(first.suggest('2024-06-14', 0, '2026-09-30'), '');
    assert.equal(first.suggest('', 36, '2026-09-30'), '');
});
