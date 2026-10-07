/*
 * Ask Logbook (spec.md §7.26, Phase 26.2).
 *
 * Progressive enhancement of the plain form on the Insights page and a
 * thread's page (Phase 38):
 * - `[data-ask-form]` posts in the background (header `X-Ask: 1`) and polls
 *   its `data-progress-url` about once a second for the progress line
 *   ("Looking up your fuel costs…") until the answer is ready; then the
 *   page goes to the answer. If the POST gets no reply from Logbook (a
 *   proxy's timeout), the polls still find the answer once it is saved.
 *   Without JS the form posts and the server redirects to the answer.
 * - `[data-ask-copy]` buttons (hidden without JS) copy their answer's text.
 * - Arriving at `#ask` (the top-bar button, the dashboard link, the phone's
 *   quick action) or `#ask-question` focuses the box; without JS the
 *   browser scrolls to it.
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
        // No reply Logbook wrote (a proxy's timeout, the network): the
        // question may still be running, so keep polling for it.
        if (status === 0 || status >= 500) {
            return {wait: true, status: status};
        }

        return {error: fallback, status: status};
    }

    /*
     * What a progress poll means: go to the answer, a line to show, or
     * (done without an answer) the fallback error.
     */
    function progressStep(body, fallback) {
        if (!body) {
            return {};
        }
        if (body.done && typeof body.url === 'string' && body.url !== '') {
            return {go: body.url};
        }
        if (body.done) {
            return {error: fallback};
        }

        return body.line ? {line: body.line} : {};
    }

    /*
     * Whether the page's address asks for the question box to be focused.
     */
    function focusesBox(hash) {
        return hash === '#ask' || hash === '#ask-question';
    }

    var core = {outcome: outcome, progressStep: progressStep, focusesBox: focusesBox};

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
        var label = form.querySelector('[data-ask-submit-label]');
        var idle = label ? label.textContent : '';
        var busy = false;
        var setBusy = function (on) {
            submit.disabled = on;
            if (label) {
                label.textContent = on ? (submit.getAttribute('data-busy-label') || idle) : idle;
            }
        };

        // A suggestion asks at once, as the prototype's chips; without JS it opens Ask with the box filled.
        form.querySelectorAll('.ask-suggestions a').forEach(function (chip) {
            chip.addEventListener('click', function (event) {
                if (busy || !question) {
                    return;
                }
                event.preventDefault();
                question.value = chip.textContent.trim();
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submit);
                } else {
                    submit.click();
                }
            });
        });

        // Enter sends, Shift+Enter starts a new line (spec.md §7.26).
        if (question && question.hasAttribute('data-ask-enter')) {
            question.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submit);
                    } else {
                        submit.click();
                    }
                }
            });
        }

        form.addEventListener('submit', function (event) {
            if (busy || !window.fetch || !window.FormData) {
                return;
            }
            event.preventDefault();
            busy = true;
            setBusy(true);
            form.setAttribute('aria-busy', 'true');
            setProgress(progress, working);

            var polling = true;
            var waiting = false;
            var started = Date.now();
            var finish = function (result) {
                if (!polling) {
                    return;
                }
                if (result.go) {
                    polling = false;
                    window.location.assign(result.go);
                    return;
                }
                if (result.wait) {
                    // The POST went quiet; the polls will find the answer.
                    waiting = true;
                    return;
                }
                polling = false;
                busy = false;
                setBusy(false);
                form.removeAttribute('aria-busy');
                setProgress(progress, result.error);
                progress.classList.add('field__error');
                question.focus();
            };
            var poll = function () {
                if (!polling) {
                    return;
                }
                fetch(form.getAttribute('data-progress-url'), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (body) {
                        var step = progressStep(body, failed);
                        if (step.line) {
                            setProgress(progress, step.line);
                        } else if (step.go) {
                            finish(step);
                        } else if (step.error && waiting) {
                            finish(step);
                        }
                    })
                    .catch(function () { /* the next poll tries again */ })
                    .then(function () {
                        if (polling && Date.now() - started > 15 * 60 * 1000) {
                            finish({error: failed});
                        }
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
                .then(finish);
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

    // A draft card's Undo (Phase 26.3) is offered for a few seconds after Add;
    // the server refuses it after that, so the button goes when its time is up.
    function enhanceUndo(form) {
        var seconds = parseInt(form.getAttribute('data-draft-undo'), 10);
        if (!(seconds >= 0)) {
            return;
        }
        window.setTimeout(function () { form.hidden = true; }, seconds * 1000);
    }

    function focusBox() {
        var box = document.getElementById('ask-question');
        if (box && focusesBox(window.location.hash)) {
            box.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ask-form]'), enhance);
        focusBox();
        window.addEventListener('hashchange', focusBox);
        Array.prototype.forEach.call(document.querySelectorAll('[data-ask-copy]'), enhanceCopy);
        Array.prototype.forEach.call(document.querySelectorAll('[data-draft-undo]'), enhanceUndo);
    });
}(this));
