/*
 * Look up on the add-vehicle form (spec.md §7.38, #326): asks the server,
 * which asks DVSA, and fills only the fields that are still blank (the fuel
 * type while it is the form's default). Without JS the button submits and
 * the form comes back filled. Nothing is saved either way.
 */
(function () {
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

    function enhance(box) {
        var form = box.closest('form');
        var button = box.querySelector('[data-lookup-button]');
        var message = box.querySelector('[data-lookup-message]');
        if (!form || !button || !window.fetch || !window.FormData) {
            return;
        }
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

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-vehicle-lookup]').forEach(enhance);
    });
}());
