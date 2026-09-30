/*
 * Unit tests for the pure helpers of assets/js/file-drop.js (spec.md §7.12,
 * Phase 21.1). Maintainer and CI only: `composer test:js`. Node is never
 * needed to run or install Logbook.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const drop = require('../../assets/js/file-drop.js');

const ATTACHMENTS = 'image/jpeg,image/png,image/webp,application/pdf';
const MB = 1024 * 1024;

function file(name, type, size = 1000, lastModified = 1) {
    return { name, type, size, lastModified };
}

test('accepts MIME types, wildcards and extensions', () => {
    assert.equal(drop.accepts(file('a.pdf', 'application/pdf'), ATTACHMENTS), true);
    assert.equal(drop.accepts(file('a.heic', 'image/heic'), ATTACHMENTS), false);
    assert.equal(drop.accepts(file('a.heic', 'image/heic'), 'image/*'), true);
    assert.equal(drop.accepts(file('fuel.CSV', ''), '.csv,.txt,text/csv,text/plain'), true, 'extension, any case, no type');
    assert.equal(drop.accepts(file('csv', ''), '.csv'), false, 'a bare name is not an extension');
    assert.equal(drop.accepts(file('backup.zip', 'application/x-zip-compressed'), '.zip,application/zip'), true);
    assert.equal(drop.accepts(file('anything', ''), ''), true, 'no accept list takes anything');
    assert.equal(drop.accepts(file('invoice.pdf', ''), ATTACHMENTS), true, 'no browser type: the server decides');
    assert.equal(drop.accepts(file('notes.doc', ''), '.csv,.txt,text/csv,text/plain'), false, 'no type and no matching extension');
});

test('dropped files are added to the current selection', () => {
    const chosen = [file('receipt.pdf', 'application/pdf')];
    const result = drop.merge(chosen, [file('photo.jpg', 'image/jpeg'), file('scan.png', 'image/png')], { accept: ATTACHMENTS, maxBytes: 10 * MB });

    assert.deepEqual(result.files.map((f) => f.name), ['receipt.pdf', 'photo.jpg', 'scan.png']);
    assert.equal(result.added.length, 2);
    assert.deepEqual(result.rejected, []);
    assert.equal(chosen.length, 1, 'the current list is not changed in place');
});

test('the same file twice is added once', () => {
    const receipt = file('receipt.pdf', 'application/pdf', 5000, 42);
    const result = drop.merge([receipt], [file('receipt.pdf', 'application/pdf', 5000, 42)], { accept: ATTACHMENTS, maxBytes: 0 });
    assert.equal(result.files.length, 1);
    assert.equal(result.added.length, 0);
});

test('wrong types and files over the limit are refused with a reason', () => {
    const result = drop.merge([], [
        file('receipt.heic', 'image/heic'),
        file('huge.pdf', 'application/pdf', 11 * MB),
        file('ok.pdf', 'application/pdf', 10 * MB),
    ], { accept: ATTACHMENTS, maxBytes: 10 * MB });

    assert.deepEqual(result.files.map((f) => f.name), ['ok.pdf'], 'exactly the limit is allowed');
    assert.deepEqual(result.rejected.map((r) => [r.file.name, r.reason]), [['receipt.heic', 'type'], ['huge.pdf', 'size']]);
});

test('a single-file input keeps only the last acceptable file', () => {
    const options = { accept: 'image/jpeg,image/png,image/webp', maxBytes: 10 * MB, multiple: false };
    const old = file('old.jpg', 'image/jpeg');

    const replaced = drop.merge([old], [file('new.png', 'image/png'), file('newer.webp', 'image/webp')], options);
    assert.deepEqual(replaced.files.map((f) => f.name), ['newer.webp']);
    assert.deepEqual(replaced.added.map((f) => f.name), ['newer.webp']);

    const refused = drop.merge([old], [file('notes.pdf', 'application/pdf')], options);
    assert.deepEqual(refused.files.map((f) => f.name), ['old.jpg'], 'a refused drop keeps what was chosen');
    assert.equal(refused.rejected.length, 1);
});

test('messages are filled in and pluralised', () => {
    const strings = { added_one: '{count} file added', added_other: '{count} files added', b: '{value} B', kb: '{value} KB', mb: '{value} MB' };
    assert.equal(drop.plural(1, strings, 'en'), '1 file added');
    assert.equal(drop.plural(3, strings, 'en'), '3 files added');
    assert.equal(drop.fill('{name}: not a PDF, JPEG, PNG or WebP file', { name: 'receipt.heic' }), 'receipt.heic: not a PDF, JPEG, PNG or WebP file');
    assert.equal(drop.fill('{name} and {other}', { name: 'a' }), 'a and {other}', 'unknown placeholders stay');
    assert.equal(drop.sizeText(512, strings, 'en'), '512 B');
    assert.equal(drop.sizeText(20 * 1024, strings, 'en'), '20 KB');
    assert.equal(drop.sizeText(1.5 * MB, strings, 'en'), '1.5 MB');
    assert.equal(drop.sizeText(1.5 * MB, strings, 'de'), '1,5 MB');
});
