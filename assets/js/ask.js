/*
 * Ask Logbook (spec.md §7.26, Phase 26.2).
 *
 * Progressive enhancement of the plain form on /ask:
 * - `[data-ask-form]` posts in the background (header `X-Ask: 1`) and polls
 *   its `data-progress-url` about once a second for the progress line
 *   ("Looking up your fuel costs…") until the answer is ready; then the
 *   page goes to the answer. Without JS the form posts and the server
 *   redirects to the answer itself.
 * - `[data-ask-copy]` buttons (hidden without JS) copy their answer's text.
 *
 * The pure helper at the top has no DOM and is unit tested with
 * `composer test:js` (tests/js/ask.test.js).
 */
(function (root) {
    'use strict';

    /*
     * What the page does with the POST's JSON reply: go to the answer, or
     * show an error (the server's message, else the fallback).
     */
    function outcome(status, body, fallback) {
        if (body && typeof body.url === 'string' && body.url !== '') {
            return {go: body.url};
        }
        if (body && typeof body.error === 'string' && body.error !== '') {
            return {error: body.error};
        }

        return {error: fallback, status: status};
    }

    var core = {outcome: outcome};

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    }
    if (typeof document === 'undefined') {
        return;
    }

    function setProgress(node, text) {
        node.textContent = text;
        node.hidden = text === '';
    }

    function enhance(form) {
        var progress = form.querySelector('[data-ask-progress]');
        var submit = form.querySelector('[data-ask-submit]');
        var question = form.querySelector('textarea[name="question"]');
        var working = form.getAttribute('data-working') || '';
        var failed = form.getAttribute('data-failed') || '';
        var busy = false;

        form.addEventListener('submit', function (event) {
            if (busy || !window.fetch || !window.FormData) {
                return;
            }
            event.preventDefault();
            busy = true;
            submit.disabled = true;
            form.setAttribute('aria-busy', 'true');
            setProgress(progress, working);

            var polling = true;
            var poll = function () {
                if (!polling) {
                    return;
                }
                fetch(form.getAttribute('data-progress-url'), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (body) {
                        if (polling && body && body.line) {
                            setProgress(progress, body.line);
                        }
                    })
                    .catch(function () { /* the next poll tries again */ })
                    .then(function () {
                        if (polling) {
                            window.setTimeout(poll, 1000);
                        }
                    });
            };
            window.setTimeout(poll, 600);

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'X-Ask': '1', Accept: 'application/json'}
            })
                .then(function (response) {
                    return response.json().then(
                        function (body) { return outcome(response.status, body, failed); },
                        function () { return outcome(response.status, null, failed); }
                    );
                })
                .catch(function () { return outcome(0, null, failed); })
                .then(function (result) {
                    polling = false;
                    if (result.go) {
                        window.location.assign(result.go);
                        return;
                    }
                    busy = false;
                    submit.disabled = false;
                    form.removeAttribute('aria-busy');
                    setProgress(progress, result.error);
                    progress.classList.add('field__error');
                    question.focus();
                });
        });
    }

    function enhanceCopy(button) {
        var target = document.getElementById(button.getAttribute('data-ask-copy'));
        var text = target ? target.querySelector('[data-ask-answer]') : null;
        if (!text || !navigator.clipboard) {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(text.innerText.trim()).then(function () {
                var label = button.getAttribute('data-copied');
                if (label) {
                    button.lastChild.textContent = label;
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ask-form]'), enhance);
        Array.prototype.forEach.call(document.querySelectorAll('[data-ask-copy]'), enhanceCopy);
    });
}(this));
