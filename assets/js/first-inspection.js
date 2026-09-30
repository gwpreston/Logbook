/*
 * *First MOT due* on the vehicle form (spec.md §7.1, Phase 21.2).
 *
 * Progressive enhancement of `[data-first-inspection]`, a wrapper the server
 * puts around the date input (templates/vehicles/form.twig). While the owner
 * hasn't typed in it, entering or changing *First registered* fills it with
 * first registration + `data-months` (end-of-month clamped, as the server's
 * LocalTime::addMonths()). A suggestion before `data-today` (the owner's
 * today, never the browser's clock) is not filled in. Once the owner edits
 * the field, or it had a value when the page loaded, it is theirs.
 *
 * The script also adds a hidden `data-marker` field, so a blank date the
 * owner cleared is not filled in again by the server's no-JS fallback.
 *
 * The pure helpers at the top have no DOM and are unit tested with
 * `composer test:js` (node --test, tests/js/first-inspection.test.js).
 */
(function (root) {
    'use strict';

    var ISO = /^(\d{4})-(\d{2})-(\d{2})$/;

    function pad(number, width) {
        var text = String(number);
        while (text.length < width) {
            text = '0' + text;
        }

        return text;
    }

    /*
     * A calendar date ("2024-02-29") plus whole months, clamped to the end
     * of a shorter month ("2027-02-28"); '' for anything that isn't a date.
     */
    function addMonths(date, months) {
        var match = ISO.exec(String(date || ''));
        if (match === null) {
            return '';
        }
        var total = Number(match[1]) * 12 + Number(match[2]) - 1 + months;
        var year = Math.floor(total / 12);
        var month = total % 12 + 1;
        // Day 0 of the next month is the last day of this one (UTC: no DST).
        var last = new Date(Date.UTC(year, month, 0)).getUTCDate();
        var day = Math.min(Number(match[3]), last);

        return pad(year, 4) + '-' + pad(month, 2) + '-' + pad(day, 2);
    }

    /*
     * The suggested due date, or '' without a rule, without a first
     * registration, or when it is before today (that vehicle has had its
     * first test). ISO dates compare correctly as strings.
     */
    function suggest(registered, months, today) {
        if (!months) {
            return '';
        }
        var due = addMonths(registered, months);

        return due === '' || (ISO.test(String(today || '')) && due < today) ? '' : due;
    }

    var core = { addMonths: addMonths, suggest: suggest };

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
        return;
    }

    /* ---- DOM enhancement (browser only) ---- */

    function enhanceField(wrapper) {
        if (wrapper.dataset.firstInspectionReady === '1') {
            return;
        }
        var input = wrapper.querySelector('input[type="date"]');
        var form = wrapper.closest('form');
        if (!input || !form) {
            return;
        }
        wrapper.dataset.firstInspectionReady = '1';

        var marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = wrapper.dataset.marker || 'first_inspection_js';
        marker.value = '1';
        form.appendChild(marker);

        var months = Number(wrapper.dataset.months || 0);
        var registered = document.getElementById(wrapper.dataset.registered || '');
        if (!months || !registered) {
            return;
        }

        var owned = input.value !== '';
        input.addEventListener('input', function () { owned = true; });

        function fill() {
            if (!owned) {
                input.value = suggest(registered.value, months, wrapper.dataset.today);
            }
        }
        registered.addEventListener('input', fill);
        registered.addEventListener('change', fill);
    }

    function enhance(scope) {
        var fields = (scope || document).querySelectorAll('[data-first-inspection]');
        Array.prototype.forEach.call(fields, enhanceField);
    }

    root.LogbookFirstInspection = { enhance: enhance };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
})(typeof window !== 'undefined' ? window : this);
