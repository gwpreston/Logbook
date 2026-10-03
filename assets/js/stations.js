/*
 * Fuel stations (spec.md §7.33, Phase 30.1).
 *
 * Progressive enhancement; every flow works without it:
 * - `[data-station-field]` on the fill-up form: the select of favourites
 *   and recent stations plus *Other* (and its name field) becomes a combo
 *   box. Typing asks `data-search-url` (JSON: favourites first, then
 *   recent, then the rest, each with its "Last time here" hint) and
 *   offers *Add "…"* when no station has that name. Choosing a station
 *   sets the hidden `station_id`; typing a name clears it, and the name is
 *   linked or created when the fill-up is saved. Offline, the typed name
 *   is simply queued with the fill-up.
 * - `[data-locate]`: *Use my current location* fills a latitude and
 *   longitude pair from the browser's geolocation, asked only on the
 *   press. Nothing is stored unless the form is saved.
 * - `a[data-geo-href]` (*Open in maps*): a `geo:` link on touch devices,
 *   OpenStreetMap elsewhere.
 *
 * The pure helpers at the top have no DOM and are unit tested with
 * `composer test:js` (node --test, tests/js/stations.test.js).
 */
(function (root) {
    'use strict';

    /*
     * The combo box's options for a search answer: each station, then
     * *Add "…"* when something was typed that no station is called.
     */
    function comboOptions(answer, typed) {
        var options = [];
        var results = (answer && Array.isArray(answer.results)) ? answer.results : [];
        results.forEach(function (station) {
            options.push({
                kind: 'station',
                id: String(station.id),
                name: station.name,
                meta: [station.brand, station.postcode].filter(Boolean).join(' · '),
                favourite: !!station.favourite,
                hint: station.hint || '',
                listed: (station.listed && typeof station.listed === 'object') ? station.listed : {},
            });
        });
        var name = tidy(typed);
        if (name !== '' && !(answer && answer.exact)) {
            options.push({kind: 'add', id: '', name: name, meta: '', favourite: false, hint: ''});
        }

        return options;
    }

    /* Trimmed, runs of whitespace collapsed (as Domain\Station\StationName::tidy). */
    function tidy(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    /*
     * A position from the browser, to the 6 places stored (about 0.1 m).
     */
    function coordinates(position) {
        if (!position || !position.coords) {
            return null;
        }
        var lat = Number(position.coords.latitude);
        var lon = Number(position.coords.longitude);
        if (!isFinite(lat) || !isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
            return null;
        }

        return {latitude: lat.toFixed(6), longitude: lon.toFixed(6)};
    }

    /*
     * The listed price for the fuel picker's choice ("petrol:e10_95"), from
     * a station's listed prices by grade (spec.md §7.34), or null.
     */
    function listedFor(listed, choice) {
        var grade = String(choice || '').split(':').pop();
        var entry = listed && grade ? listed[grade] : null;
        if (!entry || typeof entry.text !== 'string' || !/^[0-9]+(\.[0-9]+)?$/.test(String(entry.price))) {
            return null;
        }

        return {text: entry.text, price: String(entry.price)};
    }

    var core = {comboOptions: comboOptions, tidy: tidy, coordinates: coordinates, listedFor: listedFor};

    if (typeof module === 'object' && module.exports) {
        module.exports = core;
        return;
    }

    /* ---- DOM enhancement (browser only) ---- */

    var counter = 0;

    function enhanceField(field) {
        if (field.dataset.enhanced === '1') {
            return;
        }
        var select = field.querySelector('[data-station-select]');
        var other = field.querySelector('[data-station-other]');
        var input = other ? other.querySelector('input[name="station"]') : null;
        var hint = field.querySelector('[data-station-hint]');
        var url = field.dataset.searchUrl;
        if (!select || !input || !url || !window.fetch) {
            return;
        }
        field.dataset.enhanced = '1';
        counter += 1;
        var listId = 'station-list-' + counter;

        // The select stays for its value only; the name field becomes the combo box.
        var chosen = select.options[select.selectedIndex];
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'station_id';
        hidden.value = chosen && /^[0-9]+$/.test(chosen.value) ? chosen.value : '';
        if (hidden.value !== '') {
            input.value = chosen.dataset.name || chosen.textContent;
        }
        select.disabled = true;
        select.closest('[data-station-choose]').hidden = true;
        field.appendChild(hidden);

        var label = other.querySelector('label');
        if (label && field.dataset.label) {
            label.textContent = field.dataset.label;
        }
        var otherHint = other.querySelector('[data-station-other-hint]');
        if (otherHint) {
            otherHint.hidden = true;
        }
        other.hidden = false;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);
        input.setAttribute('autocomplete', 'off');

        var list = document.createElement('ul');
        list.className = 'combo__list';
        list.id = listId;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        input.insertAdjacentElement('afterend', list);

        var options = [];
        var active = -1;
        var pending = null;
        var timer = null;
        // Phase 30.2: the chosen station's listed prices and *Use listed price*.
        var form = field.closest('form');
        var fuel = form ? form.querySelector('#f-fuel') : null;
        var price = form ? form.querySelector('#f-price') : null;
        var useListed = field.querySelector('[data-use-listed]');
        var plainHint = hint ? (hint.dataset.plain !== undefined ? hint.dataset.plain : hint.textContent) : '';
        var listed = {};
        try {
            listed = hint && hint.dataset.listed ? JSON.parse(hint.dataset.listed) : {};
        } catch (error) {
            listed = {};
        }
        if (Array.isArray(listed)) {
            listed = {};
        }

        function showListed() {
            var found = listedFor(listed, fuel ? fuel.value : '');
            if (hint) {
                var text = found ? found.text : plainHint;
                hint.textContent = text;
                hint.hidden = text === '';
            }
            if (useListed) {
                useListed.hidden = !found || !price;
                useListed.dataset.price = found ? found.price : '';
            }
        }

        function setHint(text, prices) {
            plainHint = text;
            listed = prices || {};
            showListed();
        }

        if (fuel) {
            fuel.addEventListener('change', showListed);
        }
        if (useListed && price) {
            useListed.addEventListener('click', function () {
                if (!useListed.dataset.price) {
                    return;
                }
                price.value = useListed.dataset.price;
                price.dispatchEvent(new Event('input', {bubbles: true}));
                price.focus();
            });
        }
        showListed();

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        }

        function render() {
            list.innerHTML = '';
            options.forEach(function (option, index) {
                var item = document.createElement('li');
                item.id = listId + '-' + index;
                item.className = 'combo__option' + (index === active ? ' combo__option--active' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', index === active ? 'true' : 'false');
                var title = document.createElement('span');
                title.className = 'combo__name';
                title.textContent = option.kind === 'add'
                    ? (field.dataset.addLabel || 'Add "{name}"').replace('{name}', option.name)
                    : (option.favourite ? '★ ' : '') + option.name;
                item.appendChild(title);
                if (option.meta) {
                    var meta = document.createElement('span');
                    meta.className = 'combo__meta';
                    meta.textContent = option.meta;
                    item.appendChild(meta);
                }
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    choose(index);
                });
                list.appendChild(item);
            });
            list.hidden = options.length === 0;
            input.setAttribute('aria-expanded', options.length === 0 ? 'false' : 'true');
            if (active >= 0) {
                input.setAttribute('aria-activedescendant', listId + '-' + active);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function choose(index) {
            var option = options[index];
            if (!option) {
                return;
            }
            input.value = option.name;
            hidden.value = option.id;
            setHint(option.kind === 'add'
                ? (field.dataset.newLabel || '').replace('{name}', option.name)
                : option.hint, option.kind === 'add' ? {} : option.listed);
            close();
        }

        function search() {
            var typed = input.value;
            if (pending) {
                pending.abort();
            }
            pending = window.AbortController ? new AbortController() : null;
            var target = url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(tidy(typed));
            fetch(target, {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
                signal: pending ? pending.signal : undefined,
            })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (answer) {
                    if (input.value !== typed) {
                        return;
                    }
                    options = comboOptions(answer, typed);
                    active = -1;
                    render();
                })
                .catch(function () { /* offline: the typed name is saved as it is */ });
        }

        input.addEventListener('input', function () {
            hidden.value = '';
            setHint('');
            clearTimeout(timer);
            timer = setTimeout(search, 150);
        });
        input.addEventListener('focus', function () {
            if (input.value === '') {
                search();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(close, 100);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (list.hidden) {
                    search();
                    return;
                }
                event.preventDefault();
                var step = event.key === 'ArrowDown' ? 1 : -1;
                active = (active + step + options.length) % options.length;
                render();
            } else if (event.key === 'Enter' && !list.hidden && active >= 0) {
                event.preventDefault();
                choose(active);
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                close();
            }
        });
    }

    function enhanceLocate(box) {
        if (!navigator.geolocation || box.dataset.enhanced === '1') {
            return;
        }
        box.dataset.enhanced = '1';
        box.hidden = false;
        var button = box.querySelector('[data-locate-button]');
        var status = box.querySelector('[data-locate-status]');
        var lat = document.getElementById(box.dataset.lat);
        var lon = document.getElementById(box.dataset.lon);
        if (!button || !lat || !lon) {
            return;
        }
        button.addEventListener('click', function () {
            if (status) {
                status.textContent = box.dataset.finding || '';
            }
            navigator.geolocation.getCurrentPosition(function (position) {
                var found = coordinates(position);
                if (!found) {
                    return;
                }
                lat.value = found.latitude;
                lon.value = found.longitude;
                if (status) {
                    status.textContent = box.dataset.found || '';
                }
            }, function () {
                if (status) {
                    status.textContent = box.dataset.failed || '';
                }
            }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 60000});
        });
    }

    /*
     * *Cheapest near me* (spec.md §7.34): *My current location* is offered
     * only where the browser can tell, and asked for when the form is sent;
     * the position goes with this search only and is never stored.
     */
    function enhanceNearForm(form) {
        if (form.dataset.enhanced === '1') {
            return;
        }
        form.dataset.enhanced = '1';
        var from = form.querySelector('select[name="from"]');
        var here = form.querySelector('option[data-here]');
        var lat = form.querySelector('[data-near-lat]');
        var lng = form.querySelector('[data-near-lng]');
        var status = form.querySelector('[data-near-status]');
        if (!from || !here || !lat || !lng || !navigator.geolocation) {
            return;
        }
        here.hidden = false;
        var located = false;
        form.addEventListener('submit', function (event) {
            if (from.value !== 'here' || located) {
                return;
            }
            event.preventDefault();
            if (status) {
                status.textContent = form.dataset.finding || '';
            }
            navigator.geolocation.getCurrentPosition(function (position) {
                var found = coordinates(position);
                if (!found) {
                    return;
                }
                lat.value = found.latitude;
                lng.value = found.longitude;
                located = true;
                form.submit();
            }, function () {
                if (status) {
                    status.textContent = form.dataset.failed || '';
                }
            }, {enableHighAccuracy: false, timeout: 15000, maximumAge: 120000});
        });
    }

    function enhanceGeoLinks(scope) {
        var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        if (!touch) {
            return;
        }
        scope.querySelectorAll('a[data-geo-href]').forEach(function (link) {
            link.href = link.dataset.geoHref;
            link.removeAttribute('target');
        });
    }

    function enhance(scope) {
        var within = scope || document;
        within.querySelectorAll('[data-station-field]').forEach(enhanceField);
        within.querySelectorAll('[data-locate]').forEach(enhanceLocate);
        within.querySelectorAll('[data-near-form]').forEach(enhanceNearForm);
        enhanceGeoLinks(within);
    }

    root.LogbookStations = {enhance: enhance};

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
}(typeof window !== 'undefined' ? window : this));
