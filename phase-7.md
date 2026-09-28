# Phase 7 — Design alignment + dashboard enhancements

**Goal:** close the gaps between the built app and the Claude Design handoff,
and add the dashboard, garage, sidebar and settings enhancements identified in
the post-Phase 6 review. No new data modules; this phase is about presentation,
navigation and a few derived figures built on existing services.

Read `spec.md` (§5, §6 User, §7.1, §7.2, §7.3, §7.6, §7.7, §7.8, §8) and
`CLAUDE.md` (§7, §8, §11) before starting.

**Visual source of truth:** the `design-import/` folder in the repo, plus the
review screenshots (`add-vechicle-model.png`, `log-entry-model.png`,
`garage-vechicle.png`, `dashboard-toggle.png`,
`dashboard-toggle-selected-vechicle.png`, `dashboard-your-vechicles.png`,
`sidebar-vechicles.png`, `reminder-nav-link.png`). Translate the design into
Twig + the existing CSS tokens; do not paste its markup.

**Prerequisites:** Phase 6 complete and green.

---

## Scope

**In:** desktop modals for entry forms, sidebar changes (reminders badge,
"Log entry", vehicles list with status dots), dashboard vehicle filter with a
pinned selected-vehicle card, new *Mileage* and *Recent activity* widgets,
restyled *Your vehicles* tiles, garage card badges and stats, consistent vehicle
tab chrome, 50/50 layouts, accent colour setting, and the app version display.

**Out:** new data modules, multi-user, anything in `spec.md` §12.

---

## Tasks

### 7.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] §5 — the progressive-enhancement modal pattern (7.1).
- [x] §6 User — `accent` preference (7.9).
- [x] §7.1 — garage card due badge and stats footer (7.5).
- [x] §7.2 — shared vehicle header actions and list toolbar (7.6).
- [x] §7.8 — vehicle filter, pinned vehicle card, *Mileage* and *Recent
      activity* widgets, restyled *Your vehicles* (7.3, 7.4).
- [x] §8 — accent colours, sidebar badge and vehicles list, version display.
- [ ] `ROADMAP.md` gains a Phase 7 row; `CHANGELOG.md` gets the release entry.

### 7.1 Desktop modals for entry forms (progressive enhancement)
- [x] Forms that open in a modal on desktop: add / edit vehicle, add / edit
      expense, add / edit fill-up, add / edit document, the *Log entry* chooser,
      and the forms reached from it (odometer reading, service record, service
      interval). See `add-vechicle-model.png`, `log-entry-model.png`.
- [x] Every form keeps its own URL and full page. With JS **and** a desktop
      viewport (≥ 960px, the same breakpoint as the sidebar), links to those
      URLs are intercepted and the server-rendered form is loaded into a native
      `<dialog>`; below 960px or without JS, the normal page opens.
- [x] The server renders the form body alone for modal requests (e.g. a
      `partial` flag or request header) using the same template and parser as
      the full page — one form, two wrappers.
- [x] Validation errors re-render inside the modal; success closes it and
      follows the normal redirect (with its flash message).
- [x] File inputs (vehicle photo, attachments) work in the modal (multipart
      submit via `FormData`, falling back to a full-page submit on failure).
- [x] Accessible: labelled dialog title, focus moves in and returns to the
      trigger on close, Esc and the ✕ button close, background inert.
- [x] Modal fetch URLs come from `url_for()` so subpath installs work; CSRF uses
      the page's existing token.

### 7.2 Sidebar
- [x] **Reminders badge:** the *Reminders* link shows the count of open
      reminders that are *overdue* + *due*, in red (`reminder-nav-link.png`);
      hidden when zero. Archived vehicles and disabled `reminders` module
      contribute nothing.
- [x] **"+ Log fill-up" → "+ Log entry":** opens the *Log something* chooser
      (Fill-up, Odometer reading, Service record, Expense, Document, Service
      interval) as a page (`/log/new`) or a modal on desktop. Choices for a
      disabled module are hidden. With several active vehicles, the chosen form
      asks for the vehicle (as `/fuel/new` does today); with one, it is
      preselected. The mobile tab bar "+" opens the same chooser.
- [x] **Vehicles section** below *Settings* (`sidebar-vechicles.png`): every
      active (non-archived) vehicle with a car / motorbike icon, its name, and a
      status dot — **red** any overdue reminder, **orange** any due soon,
      **green** none. Each links to the vehicle overview.
- [x] The dot is not colour-only: give it a text alternative ("2 overdue",
      "1 due soon", "All up to date") for screen readers and a tooltip.

### 7.3 Dashboard — vehicle filter and pinned vehicle card
- [x] A row of toggle chips under the greeting (`dashboard-toggle.png`):
      *All vehicles* plus one chip per active vehicle with its type icon,
      styled like the type chips on the *Add vehicle* form. Shown when there
      are two or more active vehicles.
- [x] The selection is a GET parameter (`/?vehicle={id}`): the chips are links,
      so it works without JS, survives refresh and is bookmarkable. The active
      chip has `aria-current`. An unknown or archived id falls back to *All*.
- [x] **All vehicles:** every widget uses the fleet (active vehicles), as now.
- [x] **One vehicle selected:**
  - every widget's data is filtered to that vehicle;
  - *Your vehicles* is hidden;
  - a **pinned vehicle card** appears directly below the chips
    (`dashboard-toggle-selected-vechicle.png`) and is not part of the saved
    layout: it cannot be dragged, moved or hidden in customise mode.
- [x] Pinned card contents: photo (or placeholder), plate, fuel chip, name,
      "year make model · current odometer", and four tiles —
      *Economy* (12-month average, in the user's consumption unit),
      *Running cost* (all costs ÷ distance, last 12 months, per the user's
      distance unit), *Spent* (last 12 months), *Next due* (the most urgent open
      reminder: "in 4 days" / "3 days overdue" plus its title, coloured by
      status). Actions: *Log fill-up*, *Add reading*, *Open vehicle →*.
      Tiles stack 2×2 on mobile.
- [x] Reuse the existing report / fuel / reminder services for every figure;
      no new calculations in templates or Actions.

### 7.4 Dashboard — new and restyled widgets
- [x] **Mileage widget** (new, id `mileage`): three figures under the title —
      *This month*, *This year*, *Monthly avg* — in the user's distance unit.
  - This month / this year: calendar month / year to date in the owner's time
    zone, computed as the reports' *distance driven* (§7.7).
  - Monthly avg: the same figure the vehicle's Mileage tab shows (§7.2).
  - Fleet figures are computed per vehicle and summed.
  - A figure with no history shows "—", not 0.
- [x] **Recent activity widget** (new, id `recent_activity`): the latest eight
      items across fill-ups, manual odometer readings, service records,
      documents and expenses — newest first, each with an icon, what it was,
      the vehicle, the date and its amount or value, linking to its source.
      Derived odometer readings are left out (the fill-up or service already
      appears). Respects the vehicle filter, feature toggles and archived
      exclusion.
- [x] Both new widgets join the layout registry, so saved layouts gain them at
      the end (existing behaviour for new widgets).
- [x] **Your vehicles** restyled as photo tiles (`dashboard-your-vechicles.png`):
      photo or placeholder with the plate over its lower-left corner, name,
      current odometer, and "N due" (red when any are overdue, amber when only
      due soon; hidden when zero). The card title links to the garage.

### 7.5 Garage cards
- [x] Due badge on the photo's top-right corner (`garage-vechicle.png`):
      "N due" = open reminders that are overdue + due; red when any are overdue,
      amber otherwise; hidden when zero.
- [x] A footer below a hairline divider with small icons: current odometer
      (user's distance unit) and average economy (user's consumption unit — mpg
      for UK/US users, L/100 km etc. for others; kWh efficiency for EVs; "—"
      when there is no full-to-full segment yet).

### 7.6 Vehicle pages — consistent chrome
- [x] *Edit*, *Archive* and *Delete* live in one shared vehicle-header partial
      and sit in the same place on Overview, Mileage, Fuel, Maintenance,
      Documents and Expenses.
- [x] One shared list-toolbar partial for the tab lists: *Export CSV* and
      *Import CSV* right-aligned on every tab, matching the Fuel tab today
      (fixes the Mileage tab).

### 7.7 Two-column layouts
- [x] A shared two-column grid utility (50/50 on wide screens, stacked on
      narrow ones), used for:
  - Fuel tab: *Economy trend* | *Price trend*;
  - Reports: *By category* | *By vehicle*.
- [x] Reports *By vehicle*: remove the link on the vehicle name and show the
      car / motorbike icon before it.

### 7.8 App version
- [x] One source of truth for the version (e.g. a `VERSION` file updated with
      each release, stamped into the Docker image at build time).
- [x] Shown in the sidebar footer and on the Settings page ("Logbook v0.7.0"),
      and included in the `/health` JSON.

### 7.9 Accent colour setting
- [x] Settings → Appearance: *Accent colour* chips — **Blue** (default),
      **Teal**, **Indigo**, **Purple** — next to the existing theme setting.
- [x] Stored per user (`users.accent`, string, default `blue`) via a reversible
      migration on every engine; signed-out pages use the default.
- [x] Rendered server-side as `data-accent` on `<html>` (no flash), switching a
      small set of accent tokens (primary, hover, pressed, subtle background,
      focus ring, chart series) with light and dark variants of each.
- [x] Status colours (red overdue, amber due soon, green OK) and the yellow
      plate stay fixed in every accent, so status never depends on the accent.
- [x] Every accent passes WCAG AA for button text and focus rings in both
      themes. Charts read the accent tokens when they render.

### 7.10 i18n
- [x] All new strings translatable (English plus the second locale from
      Phase 6); relative due text ("in 4 days", "3 days overdue") uses ICU
      plurals.

### 7.11 Tests
- [x] Unit: mileage figures (month and year boundaries in the owner's time
      zone, a DST change, units, fleet summing, no history → "—"); due counts
      and dot colour; recent activity ordering and exclusion of derived
      readings; accent resolution.
- [x] Integration: dashboard with and without `?vehicle=` (pinned card shown,
      *Your vehicles* hidden, other widgets filtered, invalid id falls back);
      pinned card absent from saved layout and customise mode; sidebar badge and
      vehicles list (archived excluded); modal partial vs full-page render of
      each form, including a validation error and an upload; *Log entry*
      chooser honours feature toggles; accent migration and persistence;
      version in sidebar, settings and `/health`.
- [x] Pass on **both** MySQL and Postgres.

### 7.12 Review follow-ups (after the first review of the branch)
- [x] **Mileage chart:** the *Mileage* widget shows a bar chart below its three
      figures: distance driven per calendar month over the last 12 months
      (this month and the 11 before), in the owner's distance unit, summed per
      vehicle for the fleet. Without JS the same figures are a table.
- [x] **Default widget order:** *Upcoming reminders*, *Spend this month* and
      *Recent fuel* come before *Your vehicles* (then efficiency, documents,
      mileage, recent activity). A layout the owner has saved is unchanged.
- [x] **Dev port:** `bin/dev`, `docker-compose.dev.yml` and `composer start`
      default to port **8090** instead of 8080 (production defaults stay
      8080; `APP_PORT` still overrides).
- [x] **Expenses tab — Last 12 months:** a bar chart card of spend per month
      (stacked by group) beside *By category*, 50/50 on wide screens. It always
      covers the last 12 months: the *This month* / *3 months* / *12 months* /
      *All time* chips do not change it.
- [x] **CSS tweak** from the review (`assets/css/app.css`): the pinned card's
      photo is clipped to its column and fills it side by side.
- [x] **Desktop modals working:** the forms render their `modal_body` for
      modal requests and `assets/js/app.js` opens, submits and closes the
      dialog (the first push had the markup and server side only).
- [ ] Check every modal, chart and layout in a browser, light and dark, in
      each accent, at desktop and phone widths.

---

## Deliverables
An app that matches the Claude Design handoff: desktop modals for entry forms,
a sidebar with reminder counts and a vehicles list, a filterable dashboard with
a pinned vehicle card and new Mileage and Recent activity widgets, richer
garage cards, consistent vehicle pages, an accent colour setting, and a visible
app version.

## Acceptance criteria
- [ ] On desktop with JS, the listed forms open in a modal; on mobile or without
      JS, they open as pages; both save identically and show validation errors.
- [ ] The Reminders link shows overdue + due soon in red, hidden at zero.
- [ ] The sidebar button reads "+ Log entry" and opens the chooser.
- [ ] The sidebar lists active vehicles with red / orange / green status dots
      that have a text alternative.
- [ ] Dashboard chips filter every widget; selecting a vehicle hides *Your
      vehicles* and shows a pinned, immovable vehicle card; *All vehicles*
      restores the fleet view.
- [ ] The Mileage widget shows this month, this year and monthly average in the
      user's distance unit; Recent activity lists the latest items.
- [ ] *Your vehicles* and garage cards match the design, with due badges and the
      odometer + economy footer in the user's units.
- [ ] Edit / Archive / Delete sit in the same place on every vehicle tab; CSV
      actions are right-aligned on every tab.
- [ ] Fuel trends and Reports *By category* / *By vehicle* are 50/50 on desktop
      and stacked on mobile; *By vehicle* names are unlinked with an icon.
- [ ] Accent colour changes buttons, icons, hovers, bars and charts, persists
      per user, and never changes status colours.
- [ ] The app version shows in the sidebar, settings and `/health`.
- [ ] Suite green on both DBs; translatable; works behind a subpath with
      deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Sidebar counts render on every page.** Don't run the full reminder sync per
  request: derive counts with one indexed query on stored `due_on` / status
  against the owner's *today*, cached for the request. Keep it fast on a Pi.
- **Fleet mileage:** sum each vehicle's distance; never subtract readings from
  different vehicles.
- **Recent activity mixes instants and dates:** fill-ups are UTC instants,
  others are calendar dates. Sort by the owner's local date, then by
  `created_at`.
- **Modals must not become the only path.** Every form stays a real page with
  its own URL; the modal is an enhancement layered on top.
- **The pinned card is outside the layout model.** Keep it out of
  `dashboard.layout` so saved layouts and customise mode are unaffected.
- **Units, not labels:** the design shows "mpg" because the demo user is on UK
  units; always use the user's consumption and distance preferences.
