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
const {ownedBy, untagged, sentAs} = require('../../assets/js/app.js');

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
    const network = {online: true, user: null};
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
    const fetch = async (request) => {
        if (!network.online) {
            throw new TypeError('Failed to fetch');
        }
        const headers = network.user === null ? {} : {'X-Logbook-User': network.user};
        const body = network.user === null ? 'SIGN IN' : 'FORM FOR ' + network.user;
        const response = new Response(body, {status: 200, headers});
        Object.defineProperty(response, 'url', {value: request.url});
        return response;
    };
    const script = fs.readFileSync(path.join(__dirname, '../../assets/js/service-worker.js'), 'utf8');
    vm.runInNewContext(script, {self: scope, caches, fetch, Response, URL, Promise, RegExp});

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
