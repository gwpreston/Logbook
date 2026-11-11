/*
 * Look up on the add-vehicle form (spec.md §7.38, #326): asks the server,
 * which asks DVSA, and fills only the fields that are still blank (the fuel
 * type while it is the form's default). Without JS the button submits and
 * the form comes back filled. Nothing is saved either way.
 *
 * enhance(root) is exposed as window.LogbookVehicleLookup, so the modal in
 * js/app.js can enhance each form it shows, as it does for the other
 * enhancers (K194: the second time the Add vehicle modal opened, *Look up*
 * submitted the form instead). Each box is enhanced once. It is also
 * module.exports for the Node tests (`composer test:js`).
 */
(function (root) {
    'use strict';

    function fill(form, fields) {
        Object.keys(fields).forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) {
                return;
            }
            var blank = input.value === '' || (name === 'fuel_type' && input.value === 'petrol');
            if (!blank) {
                return;
            }
            input.value = fields[name];
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
        });
    }

    function enhanceBox(box) {
        // The modal re-enhances a form it shows; one click handler is enough.
        if (box.dataset.lookupReady) {
            return;
        }
        var form = box.closest('form');
        var button = box.querySelector('[data-lookup-button]');
        var message = box.querySelector('[data-lookup-message]');
        if (!form || !button || typeof fetch !== 'function' || typeof FormData !== 'function') {
            return;
        }
        box.dataset.lookupReady = '1';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            var data = new FormData(form);
            data.set('lookup', '1');
            data.delete('photo');
            button.disabled = true;
            message.textContent = button.getAttribute('data-working') || '';
            fetch(form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'X-Lookup': '1', Accept: 'application/json'},
            }).then(function (response) {
                return response.json();
            }).then(function (body) {
                fill(form, body && body.fields ? body.fields : {});
                message.textContent = body && body.message ? body.message : '';
            }, function () {
                message.textContent = '';
            }).then(function () {
                button.disabled = false;
            });
        });
    }

    // Each look-up box inside scope (the whole page by default).
    function enhance(scope) {
        (scope || document).querySelectorAll('[data-vehicle-lookup]').forEach(enhanceBox);
    }

    var api = {enhance: enhance};
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }
    root.LogbookVehicleLookup = api;

    // js/app.js may add this script after the page has loaded, so start now
    // if DOMContentLoaded has already fired.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
}(typeof window !== 'undefined' ? window : this));
