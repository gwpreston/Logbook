/*
 * Drop zones on file inputs (spec.md §7.12, Phase 21.1).
 *
 * Progressive enhancement of `[data-file-drop]`, a wrapper the server puts
 * around a plain <input type="file"> (templates/macros/ui.twig
 * file_drop()). The native input stays in the page, focusable and
 * clickable; this adds the drop target, the list of chosen files with
 * *Remove*, and an aria-live status. Dropped files are added to the input's
 * own selection (a DataTransfer assigned to `input.files`), so the form, the
 * modal's FormData submit and the server's one parser are unchanged. A
 * single-file input's drop replaces its file. The server stays the
 * authority on type, size and count.
 *
 * The pure helpers at the top have no DOM and are unit tested with
 * `composer test:js` (node --test, tests/js/file-drop.test.js).
 */
(function (root) {
    'use strict';

    /*
     * Whether a file matches an input's `accept` list: MIME types
     * ("image/png"), wildcards ("image/*") or extensions (".csv"). An empty
     * list accepts anything, as the browser does.
     */
    function accepts(file, accept) {
        var entries = String(accept || '').split(',').map(function (entry) {
            return entry.trim().toLowerCase();
        }).filter(function (entry) { return entry !== ''; });
        if (entries.length === 0) {
            return true;
        }
        var name = String(file.name || '').toLowerCase();
        var type = String(file.type || '').toLowerCase();

        return entries.some(function (entry) {
            if (entry.charAt(0) === '.') {
                return name.length > entry.length && name.slice(-entry.length) === entry;
            }
            if (entry.slice(-2) === '/*') {
                return type.indexOf(entry.slice(0, -1)) === 0;
            }
            return type === entry;
        });
    }

    function sameFile(a, b) {
        return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
    }

    /*
     * The selection after adding `incoming` to `current`: files of the wrong
     * type or over `maxBytes` are rejected with a reason ("type", "size");
     * a file already chosen is not added twice. A single-file input keeps
     * only the last acceptable file. Returns {files, added, rejected}.
     */
    function merge(current, incoming, options) {
        var multiple = options.multiple !== false;
        var files = multiple ? current.slice() : [];
        var added = [];
        var rejected = [];
        Array.prototype.forEach.call(incoming, function (file) {
            if (!accepts(file, options.accept)) {
                rejected.push({ file: file, reason: 'type' });
                return;
            }
            if (options.maxBytes > 0 && file.size > options.maxBytes) {
                rejected.push({ file: file, reason: 'size' });
                return;
            }
            if (files.some(function (chosen) { return sameFile(chosen, file); })) {
                return;
            }
            if (!multiple) {
                files = [];
                added = [];
            }
            files.push(file);
            added.push(file);
        });
        if (!multiple && added.length === 0 && current.length > 0 && rejected.length === incoming.length) {
            files = current.slice(0, 1);
        }

        return { files: files, added: added, rejected: rejected };
    }

    /* A template's {name} placeholders replaced by values. */
    function fill(template, values) {
        return String(template).replace(/\{(\w+)\}/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match;
        });
    }

    /* "12 KB", "1.4 MB", as the server's file_size filter writes them. */
    function sizeText(bytes, strings, locale) {
        var unit = bytes < 1024 ? 'b' : (bytes < 1024 * 1024 ? 'kb' : 'mb');
        var value = unit === 'b' ? bytes : (unit === 'kb' ? bytes / 1024 : bytes / (1024 * 1024));
        var number = new Intl.NumberFormat(locale || undefined, {
            maximumFractionDigits: unit === 'mb' ? 1 : 0,
            minimumFractionDigits: unit === 'mb' ? 1 : 0,
        }).format(unit === 'mb' ? value : Math.round(value));

        return fill(strings[unit] || '{value}', { value: number });
    }

    function plural(count, strings, locale) {
        var rule = 'other';
        try {
            rule = new Intl.PluralRules(locale || undefined).select(count);
        } catch (e) {
            rule = count === 1 ? 'one' : 'other';
        }
        var template = rule === 'one' ? strings.added_one : strings.added_other;

        return fill(template, { count: count });
    }

    var core = { accepts: accepts, merge: merge, fill: fill, sizeText: sizeText, plural: plural };

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
        return;
    }

    /* ---- DOM enhancement (browser only) ---- */

    function canBuildFileLists() {
        try {
            return typeof DataTransfer === 'function' && new DataTransfer().items !== undefined;
        } catch (e) {
            return false;
        }
    }

    var supported = canBuildFileLists();
    var canDrag = !window.matchMedia || window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var guarded = false;

    function assign(input, files) {
        var transfer = new DataTransfer();
        files.forEach(function (file) { transfer.items.add(file); });
        input.files = transfer.files;
    }

    /* Tell app.js's upload-limit check (and anything else listening) that the selection changed. */
    function changed(input) {
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function enhanceZone(zone) {
        var input = zone.querySelector('input[type="file"]');
        if (!input || zone.classList.contains('file-drop--enhanced')) {
            return;
        }
        var strings = {};
        try {
            strings = JSON.parse(zone.getAttribute('data-file-drop-strings') || '{}');
        } catch (e) {
            return;
        }
        var locale = document.documentElement.lang || undefined;
        var multiple = input.multiple;
        var maxMb = parseFloat(zone.getAttribute('data-file-drop-max-mb') || '0');
        var options = { accept: input.getAttribute('accept'), maxBytes: maxMb > 0 ? maxMb * 1024 * 1024 : 0, multiple: multiple };
        var current = Array.prototype.slice.call(input.files || []);
        var internal = false;

        zone.classList.add('file-drop--enhanced');

        var prompt = document.createElement('p');
        prompt.className = 'file-drop__prompt';
        prompt.setAttribute('aria-hidden', 'true');
        var idle = canDrag ? strings.prompt : strings.choose;
        prompt.textContent = idle;
        zone.insertBefore(prompt, zone.firstChild);

        var list = document.createElement('ul');
        list.className = 'file-drop__list';
        list.hidden = true;
        zone.appendChild(list);

        var status = document.createElement('p');
        status.className = 'visually-hidden';
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('role', 'status');
        zone.appendChild(status);

        function announce(messages) {
            // Cleared first so the same message twice is still read out.
            status.textContent = '';
            window.setTimeout(function () { status.textContent = messages.join(' '); }, 50);
        }

        function rejectedMessages(rejected) {
            return rejected.map(function (item) {
                return fill(item.reason === 'size' ? strings.size : strings.type, { name: item.file.name });
            });
        }

        function render() {
            list.textContent = '';
            current.forEach(function (file, index) {
                var item = document.createElement('li');
                item.className = 'file-drop__item';
                var name = document.createElement('span');
                name.className = 'file-drop__name';
                name.textContent = file.name;
                var size = document.createElement('span');
                size.className = 'file-drop__size';
                size.textContent = sizeText(file.size, strings, locale);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn--ghost file-drop__remove';
                remove.textContent = strings.remove_label || 'Remove';
                remove.setAttribute('aria-label', fill(strings.remove, { name: file.name }));
                remove.addEventListener('click', function () {
                    var gone = current[index];
                    current = current.filter(function (other, at) { return at !== index; });
                    update();
                    announce([fill(strings.removed, { name: gone.name })]);
                    input.focus();
                });
                item.appendChild(name);
                item.appendChild(size);
                item.appendChild(remove);
                list.appendChild(item);
            });
            list.hidden = current.length === 0;
        }

        function update() {
            assign(input, current);
            internal = true;
            changed(input);
            internal = false;
            render();
        }

        function add(files) {
            var result = merge(current, files, options);
            current = result.files;
            update();
            var messages = rejectedMessages(result.rejected);
            if (result.added.length > 0) {
                messages.unshift(multiple ? plural(result.added.length, strings, locale) : fill(strings.chosen, { name: result.added[0].name }));
            }
            if (messages.length > 0) {
                announce(messages);
            }
        }

        // Picking with the file browser adds to what was already chosen, as a drop does.
        input.addEventListener('change', function () {
            if (internal) {
                return;
            }
            var picked = Array.prototype.slice.call(input.files || []);
            var before = current;
            current = multiple ? before : [];
            if (picked.length === 0 && !multiple) {
                current = [];
                render();
                return;
            }
            add(picked);
        });

        if (!canDrag) {
            render();
            return;
        }

        var depth = 0;
        function over(on) {
            zone.classList.toggle('file-drop--over', on);
            prompt.textContent = on ? strings.over : idle;
        }
        function hasFiles(event) {
            var types = event.dataTransfer ? event.dataTransfer.types : null;
            return types !== null && Array.prototype.indexOf.call(types, 'Files') !== -1;
        }
        zone.addEventListener('dragenter', function (event) {
            if (!hasFiles(event)) {
                return;
            }
            event.preventDefault();
            depth += 1;
            over(true);
        });
        zone.addEventListener('dragover', function (event) {
            if (!hasFiles(event)) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });
        zone.addEventListener('dragleave', function () {
            depth = Math.max(0, depth - 1);
            if (depth === 0) {
                over(false);
            }
        });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            depth = 0;
            over(false);
            if (input.disabled || !event.dataTransfer || event.dataTransfer.files.length === 0) {
                return;
            }
            add(Array.prototype.slice.call(event.dataTransfer.files));
            input.focus();
        });
        render();
    }

    /*
     * A file dropped next to a zone would make the browser open it and lose
     * the form. On a page (or dialog) with a zone, drops outside one are
     * ignored; pages without a zone are untouched.
     */
    function guardPage() {
        if (guarded) {
            return;
        }
        guarded = true;
        ['dragover', 'drop'].forEach(function (name) {
            document.addEventListener(name, function (event) {
                if (event.defaultPrevented || !document.querySelector('.file-drop--enhanced')) {
                    return;
                }
                if (event.target instanceof Element && event.target.closest('.file-drop--enhanced')) {
                    return;
                }
                event.preventDefault();
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'none';
                }
            });
        });
    }

    function enhance(scope) {
        if (!supported) {
            return;
        }
        var zones = (scope || document).querySelectorAll('[data-file-drop]');
        if (zones.length === 0) {
            return;
        }
        Array.prototype.forEach.call(zones, enhanceZone);
        guardPage();
    }

    root.LogbookFileDrop = { enhance: enhance };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
})(typeof window !== 'undefined' ? window : this);
