'use strict';

const test = require('node:test');
const assert = require('node:assert');
const {outcome} = require('../../assets/js/ask.js');

test('a reply with a url goes to the answer', () => {
    assert.deepStrictEqual(outcome(200, {url: '/ask/threads/3#answer-7'}, 'x'), {go: '/ask/threads/3#answer-7'});
});

test('a reply with an error shows it', () => {
    assert.deepStrictEqual(outcome(422, {error: 'Still working on your last question.'}, 'x'), {error: 'Still working on your last question.'});
});

test('anything else shows the fallback', () => {
    assert.deepStrictEqual(outcome(403, null, 'Something went wrong.'), {error: 'Something went wrong.', status: 403});
});

test('no reply from Logbook keeps waiting for the polls', () => {
    assert.deepStrictEqual(outcome(504, null, 'x'), {wait: true, status: 504});
    assert.deepStrictEqual(outcome(0, null, 'x'), {wait: true, status: 0});
});

const {progressStep} = require('../../assets/js/ask.js');

test('a done poll with a url goes to the answer', () => {
    assert.deepStrictEqual(progressStep({done: true, url: '/ask/threads/4', line: 'x'}, 'f'), {go: '/ask/threads/4'});
});

test('a running poll shows its line, a done one without a url fails', () => {
    assert.deepStrictEqual(progressStep({done: false, line: 'Looking up your costs…'}, 'f'), {line: 'Looking up your costs…'});
    assert.deepStrictEqual(progressStep({done: true, url: null}, 'f'), {error: 'f'});
    assert.deepStrictEqual(progressStep(null, 'f'), {});
});
