/*
 * Offline fill-ups per user (spec.md §7.15, K40, #449, #574, #579, #580):
 * the queue's pure helpers in assets/js/app.js, and the real service worker
 * run in a stand-in worker scope with two people on one browser.
 * Maintainer and CI only: `composer test:js`.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {ownedBy, untagged, sentAs, forgetsUser, formVerdict, sendAll} = require('../../assets/js/app.js');

const alice = {id: 1, userId: '1', fields: [['notes', 'ALICE-QUEUED-1']]};
const bob = {id: 2, userId: '2', fields: [['notes', 'BOB']]};
const old = {id: 3, fields: [['notes', 'before 54.2']]};

test('a user sees and sends only their own queued fill-ups', () => {
    assert.deepEqual(ownedBy([alice, bob, old], '1'), [alice]);
    assert.deepEqual(ownedBy([alice, bob, old], '2'), [bob]);
    assert.deepEqual(ownedBy([alice, bob, old], ''), [], 'nobody signed in: nothing');
});

test('fill-ups queued before Phase 54.2 are the untagged ones (#579)', () => {
    assert.deepEqual(untagged([alice, bob, old]), [old]);
});

test('a queued fill-up is sent only when the server names its user', () => {
    assert.equal(sentAs(alice, '1'), true);
    assert.equal(sentAs(alice, '2'), false, 'Bob signed in since: not sent as Bob');
    assert.equal(sentAs(alice, null), false, 'signed out');
    assert.equal(sentAs(old, null), false);
});

test('only the sign-in or signed-out page forgets the user, not an error or the offline page (#580)', () => {
    assert.equal(forgetsUser('', true), true);
    assert.equal(forgetsUser('', false), false, 'an error page or the offline page names nobody too');
    assert.equal(forgetsUser('1', true), false);
});

/*
 * The service worker, with a Cache API kept in memory and a network the
 * test controls. Requests are navigations to <base>/…; `online` false
 * makes the network fail.
 */
function worker(base) {
    const stores = new Map();
    const cache = (name) => {
        if (!stores.has(name)) {
            stores.set(name, new Map());
        }
        const entries = stores.get(name);
        const key = (request) => (typeof request === 'string' ? request : new URL(request.url).pathname);
        return {
            put: async (request, response) => { entries.set(key(request), response.clone()); },
            match: async (request) => entries.get(key(request))?.clone(),
            addAll: async () => {},
        };
    };
    const network = {online: true, user: null, redirects: {}};
    const listeners = {};
    const scope = {
        LOGBOOK: {base, version: 'test', assets: [], offline: base + '/offline'},
        location: {origin: 'https://cars.example'},
        addEventListener: (type, listener) => { listeners[type] = listener; },
        skipWaiting: async () => {},
        clients: {claim: async () => {}},
    };
    const caches = {
        open: async (name) => cache(name),
        keys: async () => [...stores.keys()],
        delete: async (name) => stores.delete(name),
        match: async (request) => {
            for (const name of stores.keys()) {
                const found = await cache(name).match(request);
                if (found) {
                    return found;
                }
            }
            return undefined;
        },
    };
    stores.set('logbook-static-test', new Map([[base + '/offline', new Response('OFFLINE')]]));
    const fetch = async (request, init = {}) => {
        if (!network.online) {
            throw new TypeError('Failed to fetch');
        }
        const requested = typeof request === 'string' ? 'https://cars.example' + request : request.url;
        const follow = typeof request === 'string' ? init.redirect === 'follow' : false;
        let where = new URL(requested).pathname;
        const target = network.redirects[where];
        // A navigation never follows a redirect: the worker gets an opaque one.
        if (target && !follow) {
            const opaque = new Response(null, {status: 200});
            Object.defineProperty(opaque, 'type', {value: 'opaqueredirect'});
            Object.defineProperty(opaque, 'ok', {value: false});
            return opaque;
        }
        const redirected = !!target;
        if (target) {
            where = target;
        }
        const download = where.endsWith('.csv');
        const headers = {'Content-Type': download ? 'text/csv' : 'text/html; charset=utf-8'};
        // The offline page names nobody, like the files shared caches keep.
        const offlinePage = where === base + '/offline';
        if (network.user !== null && !download && !offlinePage) {
            headers['X-Logbook-User'] = network.user;
        }
        const body = network.user === null ? 'SIGN IN' : 'FORM FOR ' + network.user + (network.chooser && where === base + '/fuel/new' ? ' CHOOSER' : '');
        const response = new Response(body, {status: 200, headers});
        Object.defineProperty(response, 'url', {value: 'https://cars.example' + where});
        Object.defineProperty(response, 'redirected', {value: redirected});
        return response;
    };
    const script = fs.readFileSync(path.join(__dirname, '../../assets/js/service-worker.js'), 'utf8');
    vm.runInNewContext(script, {self: scope, caches, fetch, Response, URL, Promise, RegExp, Headers});

    const visit = async (where) => {
        let answer;
        listeners.fetch({
            request: {method: 'GET', mode: 'navigate', url: 'https://cars.example' + base + where},
            respondWith: (promise) => { answer = promise; },
        });
        const response = await answer;
        // Let the worker finish keeping the page.
        await new Promise((resolve) => setTimeout(resolve, 0));
        return response.text();
    };

    return {network, visit, stores};
}

for (const base of ['', '/logbook']) {
    test('a kept fill-up page is shown offline only to its own user' + (base ? ' (at a subpath)' : ''), async () => {
        const {network, visit} = worker(base);

        network.user = '1';
        assert.equal(await visit('/vehicles/7/fuel/new'), 'FORM FOR 1');
        network.online = false;
        assert.equal(await visit('/vehicles/7/fuel/new'), 'FORM FOR 1', 'Alice offline: her kept page');

        // A download names nobody but isn't a signed-out page.
        network.online = true;
        await visit('/vehicles/7/export/fuel.csv');
        network.online = false;
        assert.equal(await visit('/vehicles/7/fuel/new'), 'FORM FOR 1', 'still Alice after a download');

        // Opening the offline page online names nobody but signs nobody out (K166).
        network.online = true;
        await visit('/offline');
        network.online = false;
        assert.equal(await visit('/vehicles/7/fuel/new'), 'FORM FOR 1', 'still Alice after the offline page');

        // Alice signs out: the signed-out page makes the worker forget her (#580).
        network.online = true;
        network.user = null;
        assert.equal(await visit('/login'), 'SIGN IN');
        network.online = false;
        assert.equal(await visit('/vehicles/7/fuel/new'), 'OFFLINE', 'nobody signed in: not Alice’s page');

        // Bob signs in on the same browser, then goes offline before opening the form.
        network.online = true;
        network.user = '2';
        assert.equal(await visit('/'), 'FORM FOR 2');
        network.online = false;
        assert.equal(await visit('/vehicles/7/fuel/new'), 'OFFLINE', 'Alice’s page is never Bob’s');

        // Once Bob has opened it, his own copy replaces hers.
        network.online = true;
        await visit('/vehicles/7/fuel/new');
        network.online = false;
        assert.equal(await visit('/vehicles/7/fuel/new'), 'FORM FOR 2');
    });
}

test('a page kept before Phase 54.2 names no user and is not served', async () => {
    const {network, visit, stores} = worker('');
    network.user = '1';
    await visit('/fuel/new');
    stores.get('logbook-pages').set('/log/new', new Response('KEPT BY AN OLDER WORKER'));
    network.online = false;
    assert.equal(await visit('/log/new'), 'OFFLINE');
});

for (const base of ['', '/logbook']) {
    test('the fill-up shortcut that redirects to the one vehicle is kept offline (K163, #591, #592)' + (base ? ' (at a subpath)' : ''), async () => {
        const {network, visit} = worker(base);
        network.user = '1';
        network.redirects = {[base + '/fuel/new']: base + '/vehicles/7/fuel/new'};

        // Online, the browser is sent on by the redirect and the worker keeps the form twice.
        await visit('/fuel/new');
        network.online = false;
        assert.equal(await visit('/fuel/new'), 'FORM FOR 1', 'the shortcut opens offline');
        assert.equal(await visit('/vehicles/7/fuel/new'), 'FORM FOR 1', 'and so does the vehicle’s own path');

        // A second vehicle: the next online visit shows the chooser, which replaces the copy.
        network.online = true;
        network.redirects = {};
        network.chooser = true;
        await visit('/fuel/new');
        network.online = false;
        assert.equal(await visit('/fuel/new'), 'FORM FOR 1 CHOOSER', 'never the old one-vehicle form');
    });
}

test('the shortcut’s copy belongs to its user, like any kept page', async () => {
    const {network, visit} = worker('');
    network.user = '1';
    network.redirects = {'/fuel/new': '/vehicles/7/fuel/new'};
    await visit('/fuel/new');
    network.user = null;
    network.redirects = {};
    await visit('/login');
    network.online = false;
    assert.equal(await visit('/fuel/new'), 'OFFLINE');
});

const queued = (id) => ({id, userId: '1', url: '/vehicles/' + id + '/fuel/new', fields: []});

test('the form’s answer decides what happens to a queued fill-up (K164)', () => {
    const item = queued(1);
    const path = '/vehicles/1/fuel/new';
    assert.equal(formVerdict(item, 200, path, path, '1'), 'ok');
    assert.equal(formVerdict(item, 404, path, path, null), 'gone', 'vehicle deleted');
    assert.equal(formVerdict(item, 403, path, path, '1'), 'gone', 'share revoked');
    assert.equal(formVerdict(item, 200, '/login', path, null), 'signed-out', 'the sign-in page answered');
    assert.equal(formVerdict(item, 200, path, path, '2'), 'signed-out', 'someone else is signed in');
    assert.equal(formVerdict(item, 500, path, path, null), 'failed', 'an error is not a sign-out');
    assert.equal(formVerdict(item, 403, '/login', path, null), 'failed', 'a 403 elsewhere is not the form');
});

test('a refused queued fill-up is passed over and the ones after it are still sent (K164, #589)', async () => {
    const seen = [];
    const result = await sendAll([queued(1), queued(2), queued(3)], async (item) => {
        seen.push(item.id);
        return item.id === 1 ? 'refused' : 'sent';
    });
    assert.deepEqual(seen, [1, 2, 3]);
    assert.deepEqual(result, {sent: 2, refused: 1});
});

test('a signed-out answer stops the queue and keeps the count so far', async () => {
    const seen = [];
    await assert.rejects(
        sendAll([queued(1), queued(2), queued(3)], async (item) => {
            seen.push(item.id);
            if (item.id === 1) {
                return 'refused';
            }
            throw new Error('signed-out');
        }),
        (error) => error.message === 'signed-out' && error.result.refused === 1 && error.result.sent === 0,
    );
    assert.deepEqual(seen, [1, 2]);
});
