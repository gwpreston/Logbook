/*
 * Logbook — progressive enhancement entry point.
 *
 * Loaded (deferred) before Alpine.js so components registered on
 * `alpine:init` exist when Alpine starts. Pages must work without this file.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    document.addEventListener('alpine:init', function () {
        // Alpine components are registered here from Phase 1 onwards.
    });
})();
