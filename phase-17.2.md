# Phase 17.2 — Printable reports + v1.9 release

*A clean paper or PDF copy of any report, without a PDF library.*

Status: ✅ complete · released as **v1.9.0** together with Phase 17.1

The roadmap lists "PDF reports" as a later idea. Vehicle and service
history already print (§7.16), and Phase 17.1 adds the sale pack. What
remains is making the report pages themselves print well: Reports, the
Ownership report, *Coming up*, and the Fuel and Mileage tabs. Today they
print with the app shell, dark colours when the dark theme is on, and
charts that may be cut in half. This phase gives each one a print layout
and a *Print* button. The browser's *Save as PDF* then produces a proper
document.

Server-side PDF stays out. It is only worth its dependency for sending
reports automatically (for example attached to the monthly digest), which
is a separate decision (ROADMAP *After 1.0*).

---

## Goals

1. A *Print* button on Reports (vehicle and fleet), the Ownership report,
   *Coming up*, the Fuel tab and the Mileage tab.
2. One shared print header naming what the page is and what it covers:
   vehicle or fleet, the period or horizon, the units, and the date
   printed.
3. Charts that print legibly in a print palette, with their table beside
   them.
4. Filters shown as a one-line summary on paper, not as a form.

## Not in scope

- Server-side PDF, emailed reports, scheduled reports.
- New figures or new report types. Each page prints what it already shows.
- Hiding costs. These are the owner's own reports. Printing for a buyer is
  the sale pack's job (Phase 17.1).
- Print layouts for list tabs (Maintenance, Documents, Expenses, Tyres).
  Their CSV export and History's print view cover those.

---

## Spec addition (§8, after *Two-column layouts*)

> - **Printing reports** (Phase 17.2): Reports (`/reports`, per vehicle and
>   fleet), the Ownership report, *Coming up* (`/upcoming` and the vehicle's
>   card page), the Fuel tab and the Mileage tab have a *Print* button in
>   their toolbar. It calls `window.print()` with JS and is hidden without
>   it, as History's is. The browser's own print gives the same result.
>   - **Print header** (one partial, `templates/print/_header.twig`): the
>     page title; the vehicle (name and registration) or "All vehicles"
>     plus the vehicle chip's choice; the period ("1 Jan – 31 Dec 2025", a
>     month range, or *Coming up*'s "Oct 2026 – Sep 2027"); the owner's units
>     ("Miles, UK gallons, mpg (UK)"); and "Printed 29 Sep 2026". It is
>     print-only and hidden on screen.
>   - **Filters:** the filter form is hidden on paper. Its current values
>     are already in the header.
>   - **Charts:** on `beforeprint`, each chart re-renders with the print
>     palette (black, dark grey and patterned or dashed series, so no chart
>     depends on colour). It is sized to the printable width and restored on
>     `afterprint`. Every chart's table prints with it, even where the
>     screen folds the table away. Without JS, only the tables exist, as
>     now.
>   - **Layout:** black on white whatever the theme or accent; the app
>     shell, navigation, toolbars, chips and buttons are hidden; `.split`
>     cards stack; cards and table rows never split across pages
>     (`break-inside: avoid`); table headers repeat on each page (`thead {
>     display: table-header-group }`); wide tables shrink their font before
>     they overflow. Pages are A4 or Letter portrait by the browser's
>     default. Reports with many months may ask for landscape with a named
>     `@page` rule.
>   - Economy check flags (§7.3) print as their text ("More than usual"),
>     never as an icon alone.

---

## Tasks

### Styles and partials
- [x] `assets/css/print.css` (or the print section of `app.css`): shared
      rules for hiding the shell, black on white, breaks, repeating table
      headers, and a `.print-only` / `.screen-only` utility.
- [x] `templates/print/_header.twig`, fed by each Action's existing
      filter state. There is no new query.
- [x] `ui.print_button()` macro, reused by History, the sale pack and the
      five pages here.
- [x] Move History's print-specific rules onto the shared ones where they
      overlap, with no visual change to the History print view.

### Charts
- [x] `assets/js/print-charts.js`: registers every Chart.js instance on the
      page. On `beforeprint` it swaps datasets to the print palette
      (colours plus `borderDash` or pattern fills) and resizes; on
      `afterprint` it restores them. The chart tokens gain a print set.
- [x] Make sure each chart's fallback table exists in the markup (some are
      JS-only today) and is `.print-only` where the screen hides it.

### Pages
- [x] Reports (vehicle and fleet), including the per-currency sections.
- [x] Ownership report.
- [x] *Coming up* (fleet page and vehicle card page).
- [x] Fuel tab: the summary, *By grade*, both trend charts and Phase 16's
      *Economy by month*; fill-up list, current page only.
- [x] Mileage tab: summary, chart, readings on the current page.

### Translations
- [x] Header strings, units summary and period formats in English and
      German (ICU date ranges).

### Tests
- [x] Each page renders the print header with the right vehicle, period and
      units. Test this against the rendered HTML: the header exists and is
      `print-only`.
- [x] The *Print* button is present with JS markup and absent from the
      no-JS render path, as History's.
- [x] Every chart on these pages has a table in the markup.
- [x] Module toggles: a switched-off module's page answers 404 as before,
      and no print partial leaks its data elsewhere.
- [ ] Manual check list in the PR: Chrome, Firefox and Safari print preview
      of each page in light and dark themes, portrait. Save as PDF on
      Android and iOS. *(Chrome done, headless print to PDF in both themes;
      Firefox, Safari, Android and iOS still to check.)*

### Release (with Phase 17.1)
- [x] `CHANGELOG.md` **1.9.0**: the sale pack and printable reports. Upgrade
      notes: no migrations, no configuration, backup format unchanged.
- [x] Bump `VERSION`, rebuild assets, update the README status paragraph.

---

## Acceptance criteria

1. Printing any of the five pages gives a document with a header that says
   what it is, for which vehicle and period, in which units, and when.
2. Charts print in black and grey with distinguishable series, and their
   tables print with them.
3. Nothing from the app shell prints, and no card or row is cut across a
   page.
4. The dark theme prints black on white.
5. History's print view is unchanged. Definition of done (CLAUDE.md §11)
   holds.

---

## Changed while building it

spec.md §8 *Printing reports* is the current text.

- **No `print-charts.js`.** The print palette lives in `assets/js/app.js`
  beside the chart code, whose chart list is private; a second file would
  have meant another script tag, service-worker entry and manifest line for
  a few functions.
- **Bar textures are drawn, not patterned.** Chrome leaves a
  `CanvasPattern` out of the printed page (with or without background
  graphics), so a small Chart.js plugin strokes the stripes, dots and
  hatching into each bar and legend box while printing.
- **Scoped, not merged.** The new rules sit under `.print-report` instead
  of being folded into History's; History's print view renders identically
  to v1.8.0 (compared in print media). The utility is `.print-only`, and the
  existing `.no-print` stays as the screen-only one.
- **Periods** use the existing `{from} – {to}` wording with medium dates
  ("1 Jan 2025 – 31 Dec 2025"), not an ICU interval format. *Coming up*'s
  months start with this month ("Sep 2026 – Aug 2027" on 29 Sep 2026).
  Fuel and Mileage show the first to the latest record, or "Nothing recorded
  yet"; the Ownership report says each vehicle is from purchase to sale or
  today.
- **Chart tables:** the economy, price and mileage charts get a print-only
  table of their own points (`ui.chart_table()`, from `LineChart::rows()`);
  the others already had one. Totals (`tfoot`) print once at the end rather
  than at the foot of every page, where they read as page totals.
- **No landscape page.** Wide tables shrink their font instead.
- The printed date comes from a `today()` Twig function (the app clock in
  the owner's time zone), so no Action changed. Sale pack charts print at
  twice the pixel density, otherwise as before.
