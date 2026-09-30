/*
 * The trip form (spec.md §7.22, Phase 22).
 *
 * Progressive enhancement, delegated from the document so it also works in
 * the desktop modal:
 * - `[data-trip-journey]`, the *Saved journey* select in its own GET form
 *   (`data-trip-journeys` names the trip form's id): picking one fills
 *   from, to, distance, return, business and purpose in place, and the
 *   form's *Use* button (only needed without JS) is hidden.
 * - `[data-trip-return]`: the distance label reads "one way" while ticked.
 *
 * The pure helper at the top has no DOM and is unit tested with
 * `composer test:js` (node --test, tests/js/trip-form.test.js).
 */
(function (root) {
    'use strict';

    /*
     * The field values a saved journey's option carries, as the trip form
     * names them; null for the "none" option.
     */
    function journeyFields(dataset) {
        if (!dataset || !dataset.from) {
            return null;
        }

        return {
            from_place: dataset.from,
            to_place: dataset.to || '',
            distance: dataset.distance || '',
            is_return: dataset.return === '1',
            is_business: dataset.business === '1',
            purpose: dataset.purpose || '',
        };
    }

    var core = {journeyFields: journeyFields};

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
        return;
    }

    /* ---- DOM enhancement (browser only) ---- */

    function fill(form, fields) {
        Object.keys(fields).forEach(function (name) {
            var inputs = form.querySelectorAll('[name="' + name + '"]');
            inputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = fields[name];
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                } else if (input.type !== 'hidden') {
                    input.value = fields[name];
                }
            });
        });
    }

    function syncLabel(form) {
        var box = form.querySelector('[data-trip-return]');
        var label = form.querySelector('[data-distance-label]');
        if (box && label) {
            label.textContent = box.checked ? label.dataset.labelOneWay : label.dataset.label;
        }
    }

    function enhance(scope) {
        (scope || document).querySelectorAll('[data-trip-journey-submit]').forEach(function (button) {
            button.hidden = true;
        });
    }

    root.LogbookTripForm = {enhance: enhance};

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (target.matches('[data-trip-journey]')) {
            var picker = target.closest('[data-trip-journeys]');
            var form = picker ? document.getElementById(picker.dataset.tripJourneys) : null;
            var option = target.options[target.selectedIndex];
            var fields = option ? journeyFields(option.dataset) : null;
            if (form && fields) {
                fill(form, fields);
                syncLabel(form);
            }
        } else if (target.matches('[data-trip-return]')) {
            var tripForm = target.closest('form');
            if (tripForm) {
                syncLabel(tripForm);
            }
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
}(typeof window !== 'undefined' ? window : this));
