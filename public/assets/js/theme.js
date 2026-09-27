/*
 * Logbook — light/dark theme.
 *
 * Loaded synchronously in <head> so the chosen theme applies before first
 * paint. Without JS the stylesheet follows the OS (prefers-color-scheme) and
 * the toggle stays hidden.
 *
 * Signed in, the server renders the user's preference as data-theme-pref
 * (system | light | dark; light/dark also set data-theme directly) and the
 * toggle is a small form that saves the new preference. Signed out, the
 * choice is remembered per browser in localStorage.
 */
(function () {
    'use strict';

    var KEY = 'logbook.theme';
    var root = document.documentElement;
    var pref = root.getAttribute('data-theme-pref');
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        if (pref) {
            return pref === 'light' || pref === 'dark' ? pref : null;
        }
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
                var form = button.closest('[data-theme-form]');
                root.setAttribute('data-theme', next);

                if (form) {
                    // Signed in: the form submits and saves the preference.
                    form.querySelector('[data-theme-value]').value = next;
                    return;
                }
                try {
                    window.localStorage.setItem(KEY, next);
                } catch (e) {
                    // Storage blocked: still switch for this page view.
                }
            });
        });
    });
})();
