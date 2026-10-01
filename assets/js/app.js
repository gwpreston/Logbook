/*
 * Logbook — progressive enhancement entry point.
 *
 * Loaded (deferred) before Alpine.js so components registered on
 * `alpine:init` exist when Alpine starts. Pages must work without this file.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    document.addEventListener('alpine:init', function () {
        // Alpine components are registered here.
    });

    /*
     * Scan (spec.md §7.27): reading a file takes a while, so the page says
     * so while the ordinary form posts. Nothing else changes: without JS
     * the form posts all the same.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-scan-form')) {
            return;
        }
        var button = form.querySelector('[data-scan-submit]');
        var status = form.querySelector('[data-scan-status]');
        if (status && button) {
            status.textContent = button.getAttribute('data-scan-reading') || '';
            status.hidden = false;
        }
        if (button) {
            // After this tick, so the button's own name still submits.
            window.setTimeout(function () { button.disabled = true; }, 0);
        }
    });

    /*
     * Charts: <canvas data-chart="{...}"> holds a Support\View\LineChart —
     * time series whose values are already in the user's units. Colours come
     * from the CSS tokens, so charts follow the light/dark theme.
     */
    var charts = [];

    function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue('--' + name).trim();
    }

    // A chart that is printed (the sale pack) keeps the print palette whatever
    // the theme: a canvas keeps the colours it was drawn with.
    var PRINT_PALETTE = { muted: '#333', border: '#bbb' };

    /*
     * Printing (spec.md §8 *Printing reports*): while the page prints, every
     * chart is drawn again in the print palette (the --print-* tokens), its
     * series told apart by dashes, point shapes and fill patterns as well as
     * by tone, so no chart depends on colour.
     */
    var printing = false;
    var PRINT_DASHES = [[], [6, 4], [2, 3], [10, 3, 2, 3]];
    var PRINT_POINTS = ['circle', 'rect', 'triangle', 'rectRot'];
    var PRINT_FILLS = ['solid', 'stripes', 'dots', 'hatch', 'light'];
    // Every chart is drawn at the printable width (A4 or Letter less the
    // margins) and scaled to fit by print CSS: beforeprint fires before the
    // print layout, so a chart sized to its box would keep its screen size.
    var PRINT_SIZE = { width: 680, height: 260 };

    function chartToken(spec, name) {
        if (spec.print || printing) {
            return PRINT_PALETTE[name] || '#000';
        }
        return token(name);
    }

    function printColour(index) {
        return token('print-' + (index % 4 + 1)) || '#000';
    }

    // A bar's fill on paper: grey, or white under the stripes, dots or
    // cross-hatching the printTexture plugin draws.
    function printFill(kind) {
        if (kind === 'solid') {
            return token('print-2') || '#555';
        }
        if (kind === 'light') {
            return token('print-4') || '#bbb';
        }
        return '#fff';
    }

    // Stripes, dots or hatching inside a rectangle, as plain strokes: Chrome
    // leaves a CanvasPattern out of the printed page.
    function drawTexture(context, kind, left, top, width, height) {
        if (kind !== 'stripes' && kind !== 'dots' && kind !== 'hatch') {
            return;
        }
        var step = 6;
        context.save();
        context.beginPath();
        context.rect(left, top, width, height);
        context.clip();
        context.fillStyle = printColour(0);
        context.strokeStyle = printColour(0);
        context.lineWidth = 1;
        if (kind === 'dots') {
            for (var y = top + step / 2; y < top + height; y += step) {
                for (var x = left + step / 2; x < left + width; x += step) {
                    context.fillRect(x - 1, y - 1, 2, 2);
                }
            }
        } else {
            context.beginPath();
            for (var d = -height; d < width; d += step) {
                context.moveTo(left + d, top + height);
                context.lineTo(left + d + height, top);
                if (kind === 'hatch') {
                    context.moveTo(left + d, top);
                    context.lineTo(left + d + height, top + height);
                }
            }
            context.stroke();
        }
        context.restore();
    }

    var printTexture = {
        id: 'printTexture',
        afterDatasetsDraw: function (chart) {
            chart.data.datasets.forEach(function (dataset, index) {
                var meta = chart.getDatasetMeta(index);
                if (!dataset.printTexture || meta.hidden) {
                    return;
                }
                meta.data.forEach(function (bar) {
                    var props = bar.getProps(['x', 'y', 'base', 'width'], true);
                    var top = Math.min(props.y, props.base);
                    var height = Math.abs(props.base - props.y);
                    if (height >= 1) {
                        drawTexture(chart.ctx, dataset.printTexture, props.x - props.width / 2, top, props.width, height);
                    }
                });
            });
        },
        afterDraw: function (chart) {
            var legend = chart.legend;
            if (!legend || !legend.options.display || !legend.legendHitBoxes) {
                return;
            }
            var labels = legend.options.labels;
            legend.legendItems.forEach(function (item, index) {
                var dataset = chart.data.datasets[item.datasetIndex];
                var hit = legend.legendHitBoxes[index];
                if (!dataset || !dataset.printTexture || !hit) {
                    return;
                }
                var boxHeight = Math.min(labels.boxHeight || 12, hit.height);
                drawTexture(chart.ctx, dataset.printTexture, hit.left, hit.top + (hit.height - boxHeight) / 2, labels.boxWidth, boxHeight);
            });
        },
    };

    function chartOptions(spec) {
        var dateFormat = new Intl.DateTimeFormat(spec.locale, { day: 'numeric', month: 'short', year: '2-digit', timeZone: spec.timeZone });
        var longDate = new Intl.DateTimeFormat(spec.locale, { dateStyle: 'medium', timeZone: spec.timeZone });
        var numberFormat = spec.currency
            ? new Intl.NumberFormat(spec.locale, { style: 'currency', currency: spec.currency, maximumFractionDigits: spec.decimals })
            : new Intl.NumberFormat(spec.locale, { maximumFractionDigits: spec.decimals });
        var muted = chartToken(spec, 'muted');
        var grid = chartToken(spec, 'border');
        var times = [];
        spec.series.forEach(function (series) {
            series.points.forEach(function (point) { times.push(point[0]); });
        });

        return {
            // Printing: a fixed size (beforePrint), not one measured mid-layout.
            responsive: !printing,
            maintainAspectRatio: false,
            animation: false,
            devicePixelRatio: printing ? 2 : undefined,
            interaction: { mode: 'nearest', intersect: false },
            scales: {
                x: {
                    type: 'linear',
                    min: times.length ? Math.min.apply(null, times) : undefined,
                    max: times.length ? Math.max.apply(null, times) : undefined,
                    ticks: { color: muted, maxTicksLimit: 6, callback: function (value) { return dateFormat.format(new Date(value)); } },
                    grid: { display: false },
                },
                y: {
                    title: { display: true, text: spec.unit, color: muted },
                    ticks: { color: muted, callback: function (value) { return numberFormat.format(value); } },
                    grid: { color: grid },
                },
            },
            plugins: {
                legend: { display: spec.series.length > 1, labels: { color: muted, boxWidth: 12 } },
                tooltip: {
                    callbacks: {
                        title: function (items) { return items.length ? longDate.format(new Date(items[0].parsed.x)) : ''; },
                        label: function (item) {
                            var value = numberFormat.format(item.parsed.y) + (spec.currency ? '' : ' ' + spec.unit);
                            return item.dataset.label + ': ' + value;
                        },
                    },
                },
            },
        };
    }

    function chartData(spec) {
        return {
            datasets: spec.series.map(function (series, index) {
                if (printing) {
                    // Series 0 is solid black with filled points (as the sale pack draws it);
                    // the rest are grey, dashed or dotted, with hollow points of their own shape.
                    return {
                        label: series.label,
                        data: series.points.map(function (point) { return { x: point[0], y: point[1] }; }),
                        borderColor: printColour(index),
                        backgroundColor: index === 0 ? printColour(0) : '#fff',
                        borderDash: series.dashed && index === 0 ? PRINT_DASHES[1] : PRINT_DASHES[index % PRINT_DASHES.length],
                        borderWidth: 2,
                        pointStyle: PRINT_POINTS[index % PRINT_POINTS.length],
                        pointRadius: series.dashed ? 0 : 3,
                        tension: 0.25,
                    };
                }
                var colour = chartToken(spec, series.color) || chartToken(spec, 'accent');
                return {
                    label: series.label,
                    data: series.points.map(function (point) { return { x: point[0], y: point[1] }; }),
                    borderColor: colour,
                    backgroundColor: colour,
                    borderDash: series.dashed ? [6, 4] : [],
                    borderWidth: 2,
                    pointRadius: series.dashed ? 0 : 3,
                    tension: 0.25,
                };
            }),
        };
    }

    /*
     * Bar charts (Support\View\BarChart): labelled bars, stacked series,
     * already formatted labels.
     */
    function barConfig(spec) {
        var numberFormat = spec.currency
            ? new Intl.NumberFormat(spec.locale, { style: 'currency', currency: spec.currency, maximumFractionDigits: spec.decimals })
            : new Intl.NumberFormat(spec.locale, { maximumFractionDigits: spec.decimals });
        var muted = chartToken(spec, 'muted');
        var bars = 0;
        var lines = 0;

        return {
            type: 'bar',
            data: {
                labels: spec.labels,
                datasets: spec.series.map(function (series) {
                    var colour = token(series.color) || token('accent');
                    if (printing && series.type === 'line') {
                        var line = lines++;
                        return {
                            type: 'line',
                            label: series.label,
                            data: series.values,
                            borderColor: printColour(line === 0 ? 0 : 1),
                            backgroundColor: '#fff',
                            borderDash: PRINT_DASHES[(line + 1) % PRINT_DASHES.length],
                            borderWidth: 2,
                            pointStyle: PRINT_POINTS[(line + 1) % PRINT_POINTS.length],
                            pointRadius: 3,
                            tension: 0.25,
                            stack: series.label,
                            order: 0,
                        };
                    }
                    if (printing) {
                        var kind = PRINT_FILLS[bars++ % PRINT_FILLS.length];
                        return {
                            label: series.label,
                            data: series.values,
                            backgroundColor: printFill(kind),
                            printTexture: kind,
                            borderColor: printColour(0),
                            borderWidth: 1,
                            maxBarThickness: 40,
                            order: 1,
                        };
                    }
                    if (series.type === 'line') {
                        // A line over the bars (e.g. one year against the average); null leaves a gap.
                        return {
                            type: 'line',
                            label: series.label,
                            data: series.values,
                            borderColor: colour,
                            backgroundColor: colour,
                            borderWidth: 2,
                            pointRadius: 3,
                            tension: 0.25,
                            stack: series.label,
                            order: 0,
                        };
                    }
                    return {
                        label: series.label,
                        data: series.values,
                        backgroundColor: colour,
                        borderRadius: 4,
                        maxBarThickness: 40,
                        order: 1,
                    };
                }),
            },
            options: {
                responsive: !printing,
                maintainAspectRatio: false,
                animation: false,
                devicePixelRatio: printing ? 2 : undefined,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { stacked: spec.stacked, ticks: { color: muted, maxRotation: 0, autoSkip: true }, grid: { display: false } },
                    y: {
                        stacked: spec.stacked,
                        beginAtZero: true,
                        title: { display: !!spec.unit, text: spec.unit || '', color: muted },
                        ticks: { color: muted, callback: function (value) { return numberFormat.format(value); } },
                        grid: { color: chartToken(spec, 'border') },
                    },
                },
                plugins: {
                    legend: { display: spec.series.length > 1, labels: { color: muted, boxWidth: 12 } },
                    tooltip: {
                        filter: function (item) { return item.parsed.y !== null; },
                        callbacks: {
                            label: function (item) {
                                return item.dataset.label + ': ' + numberFormat.format(item.parsed.y) + (spec.unit ? ' ' + spec.unit : '');
                            },
                        },
                    },
                },
            },
        };
    }

    function drawCharts() {
        charts.forEach(function (chart) { chart.destroy(); });
        charts = [];
        if (typeof window.Chart !== 'function') {
            return;
        }
        document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
            var spec;
            try {
                spec = JSON.parse(canvas.getAttribute('data-chart'));
            } catch (e) {
                return;
            }
            var config = spec.type === 'bar'
                ? barConfig(spec)
                : { type: 'line', data: chartData(spec), options: chartOptions(spec) };
            if (printing && spec.type === 'bar') {
                config.plugins = [printTexture];
            }
            var chart = new window.Chart(canvas, config);
            if (printing) {
                // However it is redrawn while printing (the theme can flip for print too).
                chart.resize(PRINT_SIZE.width, PRINT_SIZE.height);
            }
            charts.push(chart);
        });
    }

    var shownForPrint = [];

    function beforePrint() {
        if (printing) {
            return;
        }
        printing = true;
        // Both Economy | Cost panels print (a chart drawn while hidden has no size).
        shownForPrint = Array.prototype.slice.call(document.querySelectorAll('.print-report [data-trend-panel][hidden]'));
        shownForPrint.forEach(function (panel) { panel.hidden = false; });
        drawCharts();
    }

    function afterPrint() {
        if (!printing) {
            return;
        }
        printing = false;
        shownForPrint.forEach(function (panel) { panel.hidden = true; });
        shownForPrint = [];
        drawCharts();
    }

    /*
     * Fuel tab, Economy | Cost per distance switch: the links work without
     * JS (?trend=cost); with it, both charts are already on the page, so
     * the switch swaps them and keeps the address (and the pagination
     * links) in step, without a reload.
     */
    function enhanceTrendLink(link) {
        link.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            var mode = link.getAttribute('data-trend-link');
            document.querySelectorAll('[data-trend-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-trend-panel') !== mode;
            });
            document.querySelectorAll('a[data-trend-link]').forEach(function (other) {
                if (other.getAttribute('data-trend-link') === mode) {
                    other.setAttribute('aria-current', 'true');
                } else {
                    other.removeAttribute('aria-current');
                }
            });
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', link.href);
            }
            var trend = new URL(link.href, window.location.href).searchParams.get('trend');
            document.querySelectorAll('.pagination a[href]').forEach(function (pageLink) {
                var url = new URL(pageLink.href, window.location.href);
                if (trend) {
                    url.searchParams.set('trend', trend);
                } else {
                    url.searchParams.delete('trend');
                }
                pageLink.href = url.pathname + url.search + url.hash;
            });
            // A chart drawn while hidden has no size: draw them again now they show.
            drawCharts();
        });
    }

    /*
     * Dashboard, customise mode: drag widgets by their handle (SortableJS);
     * the new order is saved straight away with the page's CSRF token. The
     * move buttons (plain forms) keep working without this.
     */
    function enhanceDashboard(grid) {
        var form = document.querySelector('form[data-dashboard-order]');
        if (!form || typeof window.Sortable !== 'function') {
            return;
        }
        grid.querySelectorAll('[data-drag-handle]').forEach(function (handle) {
            handle.hidden = false;
        });
        window.Sortable.create(grid, {
            handle: '[data-drag-handle]',
            draggable: '[data-widget]',
            ghostClass: 'widget--ghost',
            animation: 150,
            onEnd: function () {
                var widgets = grid.querySelectorAll('[data-widget]');
                var order = Array.prototype.map.call(widgets, function (widget, index) {
                    var up = widget.querySelector('button[name="move"][value="up"]');
                    var down = widget.querySelector('button[name="move"][value="down"]');
                    if (up) { up.disabled = index === 0; }
                    if (down) { down.disabled = index === widgets.length - 1; }
                    return widget.getAttribute('data-widget');
                });
                form.querySelector('input[name="order"]').value = order.join(',');
                fetch(form.action, {
                    method: 'POST',
                    body: new URLSearchParams(new FormData(form)),
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                }).then(function (response) {
                    if (!response.ok) {
                        window.location.reload();
                    }
                });
            },
        });
    }

    /*
     * Filter forms (reports): apply a choice as soon as it is made. Typing a
     * date selects "custom" instead, and waits for the button or Enter.
     */
    function enhanceAutoSubmit(form) {
        form.addEventListener('change', function (event) {
            var target = event.target;
            if (target.hasAttribute('data-custom-date')) {
                var custom = form.querySelector('[data-custom-range]');
                if (custom) {
                    custom.checked = true;
                }
                return;
            }
            if (target.matches('select, input[type="radio"], input[type="checkbox"]')
                && !(target.hasAttribute('data-custom-range'))) {
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        });
    }

    /*
     * Fill-up form: with exactly two of volume / price / total filled in,
     * show the third as a placeholder (the server derives it the same way,
     * exactly, on save).
     */
    function enhanceFuelAmounts(group) {
        var inputs = {};
        group.querySelectorAll('[data-amount]').forEach(function (input) {
            inputs[input.getAttribute('data-amount')] = input;
        });
        if (!inputs.volume || !inputs.price || !inputs.total) {
            return;
        }
        var moneyDigits = parseInt(group.getAttribute('data-money-digits') || '2', 10);
        var note = group.querySelector('[data-derived-note]');

        function value(name) {
            var number = parseFloat(inputs[name].value);
            return inputs[name].value.trim() === '' || isNaN(number) ? null : number;
        }

        function update() {
            var volume = value('volume');
            var price = value('price');
            var total = value('total');
            var derived = null;
            var target = null;

            if (volume !== null && price !== null && total === null) {
                target = 'total';
                derived = (volume * price).toFixed(moneyDigits);
            } else if (volume !== null && total !== null && price === null && volume > 0) {
                target = 'price';
                derived = String(Math.round(total / volume * 1000) / 1000);
            } else if (price !== null && total !== null && volume === null && price > 0) {
                target = 'volume';
                derived = String(Math.round(total / price * 1000) / 1000);
            }

            Object.keys(inputs).forEach(function (name) {
                var isTarget = name === target;
                inputs[name].placeholder = isTarget ? derived : '';
                inputs[name].classList.toggle('is-derived', isTarget);
            });
            if (note) {
                var label = target ? group.querySelector('label[for="' + inputs[target].id + '"]') : null;
                note.hidden = !target;
                note.textContent = target && label ? '= ' + label.textContent.trim() + ': ' + derived : '';
            }
        }

        Object.keys(inputs).forEach(function (name) {
            inputs[name].addEventListener('input', update);
        });
        update();
    }

    /*
     * Installable app and offline fill-ups (spec.md §7.15). The service
     * worker (<base>/sw.js) keeps the fill-up forms for offline use; a
     * fill-up submitted without a connection is queued here, in IndexedDB,
     * and sent once the device is online again.
     */
    var outbox = (function () {
        function open() {
            return new Promise(function (resolve, reject) {
                var request = window.indexedDB.open('logbook', 1);
                request.onupgradeneeded = function () {
                    request.result.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
                };
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error); };
            });
        }

        function run(mode, work) {
            return open().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var transaction = db.transaction('outbox', mode);
                    var request = work(transaction.objectStore('outbox'));
                    transaction.oncomplete = function () { resolve(request.result); };
                    transaction.onerror = function () { reject(transaction.error); };
                });
            });
        }

        return {
            available: 'indexedDB' in window,
            add: function (item) { return run('readwrite', function (store) { return store.add(item); }); },
            put: function (item) { return run('readwrite', function (store) { return store.put(item); }); },
            get: function (id) { return run('readonly', function (store) { return store.get(id); }); },
            remove: function (id) { return run('readwrite', function (store) { return store.delete(id); }); },
            all: function () { return run('readonly', function (store) { return store.getAll(); }); },
        };
    })();

    var outboxBox = null;
    var outboxNote = '';

    function text(name) {
        return outboxBox ? outboxBox.getAttribute('data-text-' + name) || '' : '';
    }

    function renderOutbox() {
        if (!outboxBox || !outbox.available) {
            return;
        }
        outbox.all().then(function (items) {
            var list = outboxBox.querySelector('[data-outbox-list]');
            var status = outboxBox.querySelector('[data-outbox-status]');
            list.textContent = '';
            outboxBox.hidden = items.length === 0 && outboxNote === '';
            status.textContent = outboxNote || (items.length ? text('waiting') : '');
            var when = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
            items.forEach(function (item) {
                var li = document.createElement('li');
                li.className = 'outbox__item';
                var label = document.createElement('span');
                label.textContent = item.label + ' · ' + when.format(new Date(item.savedAt))
                    + ' — ' + (item.rejected ? text('rejected') : text('pending'));
                li.appendChild(label);
                if (item.rejected) {
                    var review = document.createElement('a');
                    review.className = 'btn btn--ghost';
                    review.href = item.url + '#offline-' + item.id;
                    review.textContent = text('review');
                    li.appendChild(review);
                }
                var discard = document.createElement('button');
                discard.type = 'button';
                discard.className = 'btn btn--ghost';
                discard.textContent = text('discard');
                discard.addEventListener('click', function () {
                    outbox.remove(item.id).then(renderOutbox);
                });
                li.appendChild(discard);
                list.appendChild(li);
            });
        }).catch(function () {});
    }

    // Send one queued fill-up with a fresh CSRF token from its form.
    function sendQueued(item) {
        var path = new URL(item.url, window.location.href).pathname;
        return fetch(item.url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok || new URL(response.url).pathname !== path) {
                    throw new Error('signed-out');
                }
                return response.text();
            })
            .then(function (html) {
                var form = new DOMParser().parseFromString(html, 'text/html');
                var body = new FormData();
                form.querySelectorAll('input[name^="csrf_"]').forEach(function (input) {
                    body.append(input.name, input.value);
                });
                item.fields.forEach(function (field) { body.append(field[0], field[1]); });
                return fetch(item.url, { method: 'POST', body: body, credentials: 'same-origin' });
            })
            .then(function (response) {
                if (response.ok && response.redirected) {
                    return outbox.remove(item.id).then(function () { return true; });
                }
                if (response.status === 422) {
                    item.rejected = true;
                    return outbox.put(item).then(function () { return false; });
                }
                return false;
            });
    }

    var flushing = false;
    function flushOutbox() {
        if (flushing || navigator.onLine === false || !outbox.available) {
            return;
        }
        flushing = true;
        var sent = 0;
        outbox.all()
            .then(function (items) {
                return items.filter(function (item) { return !item.rejected; }).reduce(function (chain, item) {
                    return chain.then(function () {
                        return sendQueued(item).then(function (ok) { sent += ok ? 1 : 0; });
                    });
                }, Promise.resolve());
            })
            .then(function () { outboxNote = sent ? text('sent') : ''; })
            .catch(function (error) { outboxNote = error && error.message === 'signed-out' ? text('signed-out') : ''; })
            .then(function () {
                flushing = false;
                renderOutbox();
            });
    }

    function enhanceOfflineForm(form) {
        // A form kept by the service worker shows the time it was stored:
        // move an untouched "now" to the actual now (in the owner's zone).
        var field = form.getAttribute('data-now-field');
        var zone = form.getAttribute('data-zone');
        var input = field ? form.querySelector('#f-' + field) : null;
        if (input && zone && input.value) {
            try {
                var parts = {};
                new Intl.DateTimeFormat('en-CA', {
                    timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
                }).formatToParts(new Date()).forEach(function (part) { parts[part.type] = part.value; });
                var today = parts.year + '-' + parts.month + '-' + parts.day;
                var now = today + 'T' + parts.hour + ':' + parts.minute;
                if (input.type === 'date') {
                    // A day-only field (a trip's date, Phase 22): today.
                    input.value = today;
                } else if (Math.abs(Date.parse(now) - Date.parse(input.value)) > 10 * 60 * 1000) {
                    input.value = now;
                }
            } catch (e) {
                // Unknown zone: keep the server's value.
            }
        }

        // "Review" on a rejected queued fill-up: put it back into the form.
        var match = /^#offline-(\d+)$/.exec(window.location.hash);
        if (match && outbox.available) {
            outbox.get(parseInt(match[1], 10)).then(function (item) {
                if (!item) {
                    return;
                }
                item.fields.forEach(function (pair) {
                    var element = form.elements.namedItem(pair[0]);
                    if (element && element.type === 'checkbox') {
                        element.checked = pair[1] !== '';
                    } else if (element) {
                        element.value = pair[1];
                    }
                });
                return outbox.remove(item.id).then(renderOutbox);
            }).catch(function () {});
        }

        form.addEventListener('submit', function (event) {
            if (navigator.onLine !== false || !outbox.available) {
                return;
            }
            event.preventDefault();
            var fields = [];
            new FormData(form).forEach(function (value, name) {
                if (typeof value === 'string' && name.indexOf('csrf_') !== 0) {
                    fields.push([name, value]);
                }
            });
            outbox.add({
                url: form.action,
                label: form.getAttribute('data-offline-label') || '',
                fields: fields,
                savedAt: Date.now(),
                rejected: false,
            }).then(function () {
                outboxNote = text('queued');
                form.reset();
                renderOutbox();
                if (outboxBox) {
                    outboxBox.scrollIntoView({ block: 'nearest' });
                }
            });
        });
    }

    // The vehicle picker: keep each vehicle's fill-up form for offline use.
    function cacheOfflineForms(links) {
        if (!links.length || !('caches' in window) || navigator.onLine === false) {
            return;
        }
        caches.open('logbook-pages').then(function (cache) {
            links.forEach(function (link) {
                fetch(link.href, { credentials: 'same-origin' }).then(function (response) {
                    if (response.ok && !response.redirected) {
                        cache.put(new URL(link.href).pathname, response);
                    }
                }).catch(function () {});
            });
        });
    }

    /*
     * Desktop modal forms (spec.md §5). A link marked data-modal opens its
     * page in the <dialog> when the viewport is wide (the sidebar
     * breakpoint): the page is fetched with X-Logbook-Modal, and the server
     * answers with the form alone. A submit goes by fetch (FormData, so files
     * upload too): a validation error comes back as the form again; a
     * redirect comes back as 204 + X-Logbook-Location and is followed as a
     * normal page load, so its flash message shows. Anything unexpected falls
     * back to the plain page or a normal submit: the modal is never the only
     * way in.
     */
    var modal = (function () {
        var dialog = null;
        var body = null;
        var title = null;
        var trigger = null;
        var wide = window.matchMedia ? window.matchMedia('(min-width: 60rem)') : null;

        function available() {
            return dialog !== null && typeof dialog.showModal === 'function' && wide !== null && wide.matches
                && typeof window.fetch === 'function' && typeof window.DOMParser === 'function';
        }

        function send(url, init) {
            init = init || {};
            init.credentials = 'same-origin';
            init.headers = { 'X-Logbook-Modal': '1' };
            return fetch(url, init);
        }

        function busy(on) {
            if (on) {
                dialog.setAttribute('aria-busy', 'true');
            } else {
                dialog.removeAttribute('aria-busy');
            }
        }

        // Put a server-rendered form into the dialog; a page without one opens as a page.
        function show(html, url) {
            var fragment = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-modal-fragment]');
            if (!fragment) {
                window.location.assign(url);
                return;
            }
            title.textContent = fragment.getAttribute('data-modal-title') || '';
            body.innerHTML = fragment.innerHTML;
            body.querySelectorAll('[data-fuel-amounts]').forEach(enhanceFuelAmounts);
            if (window.LogbookFileDrop) {
                window.LogbookFileDrop.enhance(body);
            }
            if (window.LogbookTripForm) {
                window.LogbookTripForm.enhance(body);
            }
            if (window.LogbookFirstInspection) {
                window.LogbookFirstInspection.enhance(body);
            }
            if (!dialog.open) {
                dialog.showModal();
            }
            body.scrollTop = 0;
            var first = body.querySelector('[aria-invalid="true"]')
                || body.querySelector('input:not([type="hidden"]), select, textarea, a[href], button');
            if (first) {
                first.focus();
            }
        }

        function load(url) {
            busy(true);
            return send(url)
                .then(function (response) {
                    var location = response.headers.get('X-Logbook-Location');
                    if (response.status === 204 && location) {
                        // e.g. the vehicle picker with one vehicle: straight to its form.
                        return load(location);
                    }
                    if (!response.ok) {
                        throw new Error('status ' + response.status);
                    }
                    return response.text().then(function (html) { show(html, url); });
                })
                .catch(function () { window.location.assign(url); })
                .then(function () { busy(false); });
        }

        function submit(form) {
            busy(true);
            send(form.action, { method: 'POST', body: new FormData(form) })
                .then(function (response) {
                    var location = response.headers.get('X-Logbook-Location');
                    if (response.status === 204 && location) {
                        dialog.close();
                        window.location.assign(location);
                        return;
                    }
                    if (response.status !== 422 && !response.ok) {
                        throw new Error('status ' + response.status);
                    }
                    return response.text().then(function (html) { show(html, form.action); });
                })
                .catch(function () {
                    // Let the browser post it the ordinary way (and show whatever the server says).
                    HTMLFormElement.prototype.submit.call(form);
                })
                .then(function () { busy(false); });
        }

        function init() {
            dialog = document.querySelector('[data-modal-dialog]');
            if (!dialog) {
                return;
            }
            body = dialog.querySelector('[data-modal-body]');
            title = dialog.querySelector('[data-modal-title]');

            document.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                var link = event.target.closest('a[data-modal]');
                if (link && available()) {
                    event.preventDefault();
                    if (!dialog.open) {
                        trigger = link;
                    }
                    load(link.href);
                    return;
                }
                if (dialog.open && event.target.closest('[data-modal-cancel], [data-modal-close]')) {
                    event.preventDefault();
                    dialog.close();
                }
            });

            // A click on the backdrop (the dialog itself, outside its content) closes it.
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });

            dialog.addEventListener('submit', function (event) {
                var form = event.target;
                if (form.method.toLowerCase() !== 'post') {
                    return;
                }
                event.preventDefault();
                if (navigator.onLine === false) {
                    // Offline: the form's own page (kept by the service worker for
                    // fill-ups) can queue it; the dialog cannot.
                    window.location.assign(form.action);
                    return;
                }
                submit(form);
            });

            dialog.addEventListener('close', function () {
                body.textContent = '';
                title.textContent = '';
                if (trigger && document.body.contains(trigger)) {
                    trigger.focus();
                }
                trigger = null;
            });
        }

        return { init: init };
    })();

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) {
            return;
        }
        var base = document.documentElement.getAttribute('data-base') || '';
        navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () {});
    }

    /*
     * Attachment inputs (spec.md §7.12) take up to data-max-files files per
     * save. Choosing more marks the input invalid, so the browser refuses
     * the submit (page or modal) before anything is sent. Inputs of one form
     * with the same data-max-files-group (the vehicle form's purchase and
     * sale paperwork) share the limit, as PHP counts every file in the
     * request: their files are counted together, and every input of the
     * group is marked, so removing files from one clears the other.
     * Delegated, so it also covers forms loaded into the modal.
     */
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.hasAttribute('data-max-files')) {
            return;
        }
        var group = input.getAttribute('data-max-files-group');
        var inputs = [input];
        if (group !== null && input.form !== null) {
            inputs = Array.prototype.filter.call(input.form.elements, function (el) {
                return el instanceof HTMLInputElement && el.type === 'file' && el.getAttribute('data-max-files-group') === group;
            });
        }
        var chosen = inputs.reduce(function (sum, el) {
            return sum + (el.files === null ? 0 : el.files.length);
        }, 0);
        var max = parseInt(input.getAttribute('data-max-files'), 10);
        var tooMany = !isNaN(max) && chosen > max;
        inputs.forEach(function (el) {
            el.setCustomValidity(tooMany ? (el.getAttribute('data-max-files-message') || '') : '');
        });
        if (tooMany) {
            input.reportValidity();
        }
    });

    /*
     * Sale pack, *Choose files* (spec.md §7.19): without JS the boxes are a
     * form sent back to the page, which then links the ZIP with the choice.
     * With JS the ZIP link follows the boxes as they change.
     */
    function enhancePaperwork(panel) {
        var link = panel.querySelector('a[data-sale-pack-zip]');
        if (!link) {
            return;
        }
        var update = function () {
            var url = new URL(link.getAttribute('data-sale-pack-zip'), window.location.href);
            panel.querySelectorAll('input[data-sale-pack-file]').forEach(function (box) {
                if (!box.checked) {
                    url.searchParams.append('exclude[]', box.value);
                }
            });
            link.href = url.pathname + url.search;
        };
        panel.querySelectorAll('input[data-sale-pack-file]').forEach(function (box) {
            box.addEventListener('change', update);
        });
    }

    /*
     * Print buttons (History print view, sale pack): shown only with JS,
     * since without it the browser's own Print does the same.
     */
    function enhancePrint(button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            window.print();
        });
    }

    /*
     * Copy buttons (the API key shown once, spec.md §7.20): hidden without
     * JS or a clipboard, where the read-only field is selected by hand.
     */
    function enhanceCopy(button) {
        var field = document.querySelector(button.getAttribute('data-copy'));
        if (!field || !navigator.clipboard) {
            return;
        }
        var label = button.querySelector('span');
        var original = label ? label.textContent : '';
        button.hidden = false;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(field.value).then(function () {
                if (label) {
                    label.textContent = button.getAttribute('data-copied-label') || original;
                    window.setTimeout(function () {
                        label.textContent = original;
                    }, 2000);
                }
            }, function () {
                field.select();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('button[data-copy]').forEach(enhanceCopy);
        registerServiceWorker();
        modal.init();
        outboxBox = document.querySelector('[data-offline-outbox]');
        document.querySelectorAll('form[data-offline-queue]').forEach(enhanceOfflineForm);
        cacheOfflineForms(Array.prototype.slice.call(document.querySelectorAll('a[data-offline-cache]')));
        renderOutbox();
        flushOutbox();
        window.addEventListener('online', flushOutbox);

        // Settings: "Quick setup" buttons fill in the four unit preferences.
        document.querySelectorAll('[data-unit-presets]').forEach(function (group) {
            group.hidden = false;
            group.querySelectorAll('[data-unit-preset]').forEach(function (button) {
                button.addEventListener('click', function () {
                    ['distance_unit', 'volume_unit', 'consumption_unit', 'depth_unit'].forEach(function (name) {
                        var value = button.getAttribute('data-' + name.replace('_', '-'));
                        var radio = button.form && button.form.querySelector(
                            'input[type="radio"][name="' + name + '"][value="' + value + '"]'
                        );
                        if (radio) {
                            radio.checked = true;
                        }
                    });
                });
            });
        });

        // First-run setup: pre-select the browser's time zone.
        document.querySelectorAll('select[data-detect-timezone]').forEach(function (select) {
            var zone;
            try {
                zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            } catch (e) {
                return;
            }
            if (zone && select.querySelector('option[value="' + zone + '"]')) {
                select.value = zone;
            }
        });

        document.querySelectorAll('[data-fuel-amounts]').forEach(enhanceFuelAmounts);
        document.querySelectorAll('[data-dashboard-sortable]').forEach(enhanceDashboard);
        document.querySelectorAll('form[data-auto-submit]').forEach(enhanceAutoSubmit);
        document.querySelectorAll('[data-print]').forEach(enhancePrint);
        document.querySelectorAll('[data-sale-pack-paperwork]').forEach(enhancePaperwork);
        document.querySelectorAll('a[data-trend-link]').forEach(enhanceTrendLink);

        drawCharts();
        window.addEventListener('beforeprint', beforePrint);
        window.addEventListener('afterprint', afterPrint);
        if (window.matchMedia) {
            var print = window.matchMedia('print');
            if (print.addEventListener) {
                print.addEventListener('change', function (event) {
                    if (event.matches) {
                        beforePrint();
                    } else {
                        afterPrint();
                    }
                });
            }
        }
        // Redraw with the other theme's colours when the OS theme flips.
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) {
                scheme.addEventListener('change', drawCharts);
            }
        }
    });
})();
