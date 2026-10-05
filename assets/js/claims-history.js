/*
 * The claims history's *Copy for insurance quote* (spec.md §7.29, Phase
 * 33.3).
 *
 * Progressive enhancement: the `[data-claims-copy]` button is `hidden` in
 * the page and shown only when the clipboard is there. It copies the rows
 * in view, each a `[data-claims-line]` whose value is the row's parts as a
 * JSON list, already worded and formatted by the server ("12 Mar 2024",
 * "Collision", "Not at fault", …), one line per row; then says "Copied"
 * through the `[data-claims-copied]` status region.
 *
 * The pure helper at the top has no DOM and is unit tested with
 * `composer test:js` (node --test, tests/js/claims-history.test.js).
 */
(function (root) {
    'use strict';

    var SEPARATOR = ' – ';

    /*
     * The plain text for a list of rows: each row's non-empty parts joined
     * with an en dash, one row per line.
     */
    function copyText(rows) {
        return (rows || []).map(function (parts) {
            return (parts || []).filter(function (part) {
                return typeof part === 'string' && part.trim() !== '';
            }).map(function (part) {
                return part.trim();
            }).join(SEPARATOR);
        }).filter(function (line) {
            return line !== '';
        }).join('\n');
    }

    /* A row's parts from its data attribute; an unreadable one is skipped. */
    function parts(json) {
        try {
            var value = JSON.parse(json);

            return Array.isArray(value) ? value : [];
        } catch (e) {
            return [];
        }
    }

    var core = {copyText: copyText, parts: parts};

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
        return;
    }

    /* ---- DOM enhancement (browser only) ---- */

    function enhance(button) {
        if (!navigator.clipboard) {
            return;
        }
        var status = document.querySelector('[data-claims-copied]');
        button.hidden = false;
        button.addEventListener('click', function () {
            var rows = Array.prototype.map.call(document.querySelectorAll('[data-claims-line]'), function (row) {
                return parts(row.getAttribute('data-claims-line'));
            });
            navigator.clipboard.writeText(copyText(rows)).then(function () {
                if (status) {
                    // Cleared first so saying it again is announced again.
                    status.textContent = '';
                    window.setTimeout(function () {
                        status.textContent = status.getAttribute('data-claims-copied');
                    }, 50);
                }
            });
        });
    }

    root.LogbookClaimsHistory = core;

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-claims-copy]'), enhance);
    });
}(typeof window !== 'undefined' ? window : this));
