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
        // Alpine components are registered here.
    });

    document.addEventListener('DOMContentLoaded', function () {
        // Settings: "Quick setup" buttons fill in the three unit preferences.
        document.querySelectorAll('[data-unit-presets]').forEach(function (group) {
            group.hidden = false;
            group.querySelectorAll('[data-unit-preset]').forEach(function (button) {
                button.addEventListener('click', function () {
                    ['distance_unit', 'volume_unit', 'consumption_unit'].forEach(function (name) {
                        var value = button.getAttribute('data-' + name.replace('_', '-'));
                        var radio = button.form && button.form.querySelector(
                            'input[type="radio"][name="' + name + '"][value="' + value + '"]'
                        );
                        if (radio) {
                            radio.checked = true;
                        }
                    });
                });
            });
        });

        // First-run setup: pre-select the browser's time zone.
        document.querySelectorAll('select[data-detect-timezone]').forEach(function (select) {
            var zone;
            try {
                zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            } catch (e) {
                return;
            }
            if (zone && select.querySelector('option[value="' + zone + '"]')) {
                select.value = zone;
            }
        });
    });
})();
