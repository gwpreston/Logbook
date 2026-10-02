'use strict';

const test = require('node:test');
const assert = require('node:assert');
const {pollStep, startedUrl} = require('../../assets/js/jobs.js');

test('a running run shows its output so far and keeps polling', () => {
    assert.deepStrictEqual(pollStep({status: 'running', finished: false, output: '[10:00:00] Starting'}), {output: '[10:00:00] Starting'});
});

test('a finished run reloads the page', () => {
    assert.deepStrictEqual(pollStep({status: 'ok', finished: true, output: 'done'}), {output: 'done', reload: true});
});

test('no reply keeps waiting', () => {
    assert.deepStrictEqual(pollStep(null), {wait: true});
});

test('the started poll asks for runs after the newest one on the page', () => {
    assert.strictEqual(startedUrl('/logbook/settings/jobs/reminders/started', 41), '/logbook/settings/jobs/reminders/started?after=41');
    assert.strictEqual(startedUrl('/x?y=1', null), '/x?y=1&after=0');
});
