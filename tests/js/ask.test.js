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
    assert.deepStrictEqual(outcome(502, null, 'Something went wrong.'), {error: 'Something went wrong.', status: 502});
});
