/*
 * Settings → Jobs (spec.md §7.30, Phase 28.1).
 *
 * Progressive enhancement:
 * - `[data-run-now]` forms post in the background; the button reads
 *   "Running…" and the page polls `data-started-url?after=…` until the
 *   new run's row exists, then opens its page while the job goes on
 *   (the server keeps running it if the browser leaves). Without JS the
 *   form posts, the job runs, and the server redirects to the run.
 * - `[data-run-poll]` (a run still going) fetches the run's status every
 *   2 seconds, updating the output, and reloads once it has finished.
 * - `[data-copy-output]` copies the output (hidden without a clipboard).
 *
 * The pure helper at the top has no DOM and is unit tested with
 * `composer test:js` (tests/js/jobs.test.js).
 */
(function () {
    'use strict';

    /*
     * What the run page does with a status poll: show the output so far,
     * and reload once the run is over (so the summary and times show).
     */
    function pollStep(body) {
        if (!body || typeof body !== 'object') {
            return {wait: true};
        }
        var step = {output: typeof body.output === 'string' ? body.output : null};
        if (body.finished === true) {
            step.reload = true;
        }

        return step;
    }

    /*
     * The URL to poll for the run a Run now started: the page's newest
     * run id at render time, so an older run is never mistaken for it.
     */
    function startedUrl(base, after) {
        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'after=' + encodeURIComponent(String(after || 0));
    }

    var core = {pollStep: pollStep, startedUrl: startedUrl};

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    }
    if (typeof document === 'undefined') {
        return;
    }

    function getJson(url) {
        return fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : null; })
            .catch(function () { return null; });
    }

    function enhanceRunNow(form) {
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-busy') === '1') {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            form.setAttribute('data-busy', '1');
            var button = form.querySelector('button[type="submit"]');
            var label = form.querySelector('[data-run-label]');
            if (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            }
            if (label) {
                label.textContent = form.getAttribute('data-text-running') || label.textContent;
            }

            var gone = false;
            function go(url) {
                if (!gone && url) {
                    gone = true;
                    window.location.assign(url);
                }
            }

            // The run itself: if it finishes before a poll sees it, the redirect lands on its page.
            fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'})
                .then(function (response) {
                    if (response.redirected) {
                        go(response.url);
                    }
                })
                .catch(function () { /* a proxy timeout: the polls still find the run */ });

            var poll = function () {
                if (gone) {
                    return;
                }
                getJson(startedUrl(form.getAttribute('data-started-url'), form.getAttribute('data-after'))).then(function (body) {
                    if (body && typeof body.url === 'string' && body.url !== '') {
                        go(body.url);
                        return;
                    }
                    window.setTimeout(poll, 700);
                });
            };
            window.setTimeout(poll, 400);
        });
    }

    function enhancePoll(page) {
        var url = page.getAttribute('data-run-poll');
        var output = document.querySelector('[data-run-output]');
        var tick = function () {
            getJson(url).then(function (body) {
                var step = pollStep(body);
                if (output && step.output !== null && step.output !== undefined && step.output !== '') {
                    output.textContent = step.output;
                }
                if (step.reload) {
                    window.location.reload();
                    return;
                }
                window.setTimeout(tick, 2000);
            });
        };
        window.setTimeout(tick, 2000);
    }

    function enhanceCopy(button) {
        var output = document.querySelector('[data-run-output]');
        if (!output || !navigator.clipboard) {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(output.textContent).then(function () {
                var label = button.getAttribute('data-text-copied');
                var span = button.querySelector('span');
                if (label && span) {
                    span.textContent = label;
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-run-now]'), enhanceRunNow);
        Array.prototype.forEach.call(document.querySelectorAll('[data-run-poll]'), enhancePoll);
        Array.prototype.forEach.call(document.querySelectorAll('[data-copy-output]'), enhanceCopy);
    });
}());
