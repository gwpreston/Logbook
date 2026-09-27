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
     * Charts: <canvas data-chart="{...}"> holds a Support\View\LineChart —
     * time series whose values are already in the user's units. Colours come
     * from the CSS tokens, so charts follow the light/dark theme.
     */
    var charts = [];

    function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue('--' + name).trim();
    }

    function chartOptions(spec) {
        var dateFormat = new Intl.DateTimeFormat(spec.locale, { day: 'numeric', month: 'short', year: '2-digit', timeZone: spec.timeZone });
        var longDate = new Intl.DateTimeFormat(spec.locale, { dateStyle: 'medium', timeZone: spec.timeZone });
        var numberFormat = spec.currency
            ? new Intl.NumberFormat(spec.locale, { style: 'currency', currency: spec.currency, maximumFractionDigits: spec.decimals })
            : new Intl.NumberFormat(spec.locale, { maximumFractionDigits: spec.decimals });
        var muted = token('muted');
        var grid = token('border');
        var times = [];
        spec.series.forEach(function (series) {
            series.points.forEach(function (point) { times.push(point[0]); });
        });

        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
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
            datasets: spec.series.map(function (series) {
                var colour = token(series.color) || token('accent');
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
            charts.push(new window.Chart(canvas, { type: 'line', data: chartData(spec), options: chartOptions(spec) }));
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

    document.addEventListener('DOMContentLoaded', function () {
        // Settings: "Quick setup" buttons fill in the three unit preferences.
        document.querySelectorAll('[data-unit-presets]').forEach(function (group) {
            group.hidden = false;
            group.querySelectorAll('[data-unit-preset]').forEach(function (button) {
                button.addEventListener('click', function () {
                    ['distance_unit', 'volume_unit', 'consumption_unit'].forEach(function (name) {
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

        drawCharts();
        // Redraw with the other theme's colours when the OS theme flips.
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) {
                scheme.addEventListener('change', drawCharts);
            }
        }
    });
})();
