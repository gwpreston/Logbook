/*
 * Logbook — light/dark theme.
 *
 * Loaded synchronously in <head> so the chosen theme applies before first
 * paint. Without JS the stylesheet follows the OS (prefers-color-scheme) and
 * the toggle stays hidden. The choice is remembered per browser; it moves to
 * the user's settings once accounts exist.
 */
(function () {
    'use strict';

    var KEY = 'logbook.theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try {
            var value = window.localStorage.getItem(KEY);
            return value === 'light' || value === 'dark' ? value : null;
        } catch (e) {
            return null;
        }
    }

    function apply() {
        root.setAttribute('data-theme', stored() || (media && media.matches ? 'dark' : 'light'));
    }

    apply();
    if (media && media.addEventListener) {
        media.addEventListener('change', apply);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () {
                var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                try {
                    window.localStorage.setItem(KEY, next);
                } catch (e) {
                    // Storage blocked: still switch for this page view.
                }
                root.setAttribute('data-theme', next);
            });
        });
    });
})();
