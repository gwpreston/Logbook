/*
 * Logbook — service worker (spec.md §7.15).
 *
 * Served by the app at <base>/sw.js with its settings prepended as
 * self.LOGBOOK = { base, version, assets, offline }, so every URL here
 * respects APP_BASE_PATH.
 *
 *   - Built assets: cache first (the cache is named after their versions,
 *     so a new release replaces it).
 *   - Pages: network first. The Log entry chooser and the fill-up forms
 *     are kept for offline use; any other page offline gets the offline page.
 *   - Everything else (POSTs, downloads, other sites) is left alone. An
 *     offline fill-up is queued by the page itself (js/app.js).
 */
'use strict';

var CONFIG = self.LOGBOOK;
var STATIC = 'logbook-static-' + CONFIG.version;
var PAGES = 'logbook-pages';
var FORM = new RegExp('^' + CONFIG.base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/(log/new|fuel/new|vehicles/[0-9]+/fuel/new|log/new/trip|vehicles/[0-9]+/trips/new)$');

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC)
            .then(function (cache) { return cache.addAll(CONFIG.assets.concat([CONFIG.offline])); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys
                    .filter(function (key) { return key.indexOf('logbook-static-') === 0 && key !== STATIC; })
                    .map(function (key) { return caches.delete(key); }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

// A response the page may use for a navigation: redirected responses must
// be copied first, or browsers refuse them.
function detached(response) {
    if (!response.redirected) {
        return Promise.resolve(response);
    }
    return response.blob().then(function (body) {
        return new Response(body, { status: response.status, statusText: response.statusText, headers: response.headers });
    });
}

function page(request, url) {
    return fetch(request)
        .then(function (response) {
            var finalPath = response.url ? new URL(response.url).pathname : url.pathname;
            if (response.ok && FORM.test(finalPath)) {
                var copy = response.clone();
                detached(copy).then(function (stored) {
                    caches.open(PAGES).then(function (cache) {
                        cache.put(finalPath, stored.clone());
                        // /fuel/new redirects straight to the form with one vehicle.
                        if (finalPath !== url.pathname && FORM.test(url.pathname)) {
                            cache.put(url.pathname, stored);
                        }
                    });
                });
            }
            return response;
        })
        .catch(function () {
            return caches.open(PAGES)
                .then(function (cache) { return cache.match(url.pathname); })
                .then(function (cached) { return cached || caches.match(CONFIG.offline); });
        });
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    var url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.indexOf(CONFIG.base + '/') !== 0) {
        return;
    }

    if (url.pathname.indexOf(CONFIG.base + '/assets/') === 0) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                return cached || fetch(request);
            })
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(page(request, url));
    }
});
