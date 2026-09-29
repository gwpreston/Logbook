# Roadmap

This is the planned build order for the vehicle tracker — a self-hosted app for
keeping everything about your cars and bikes in one place (vehicles, mileage,
fuel, maintenance, insurance/certificate expiry, reminders, expenses and
reports, on a rearrangeable dashboard).

The work is split into **independently runnable phases**: each phase leaves the
app working, tested on both MySQL and PostgreSQL, and deployable via Docker or a
bare PHP 8.4 server. Detailed task breakdowns live in the `phase-*.md` files;
the *what* and *why* live in [`spec.md`](spec.md); repo conventions live in
[`CLAUDE.md`](CLAUDE.md).

**Stack:** PHP 8.4 · Slim 4 · PHP-DI · Doctrine DBAL · Phinx · Twig ·
symfony/translation · Monolog · Alpine.js / Chart.js / SortableJS. Server-
rendered, no SPA, no runtime Node. See [`spec.md`](spec.md) §4 for rationale.

## Status

Legend: ✅ complete · 🚧 in progress · 📋 planned

| Phase | Theme | Status |
|------:|-------|:------:|
| [0](phase-0.md) | Foundations | ✅ |
| [1](phase-1.md) | Authentication + Garage | ✅ |
| [2](phase-2.md) | Odometer + Fuel | ✅ |
| [3](phase-3.md) | Maintenance + Compliance | ✅ |
| [4](phase-4.md) | Reminders + Notifications | ✅ |
| [5](phase-5.md) | Expenses + Reports + Dashboard | ✅ |
| [6](phase-6.md) | Feature toggles + Import/backup + polish | ✅ |
| [7](phase-7.md) | Design alignment + dashboard enhancements | ✅ |
| [8](phase-8.md) | Fuel grades + v1.0 release | ✅ |
| [9.1](phase-9.1.md) | Vehicle details: variant, first registration, starting mileage | ✅ |
| [9.2](phase-9.2.md) | Plug-in hybrids + v1.1 release | ✅ |
| [10](phase-10.md) | Vehicle history + multiple attachments + v1.2 release | ✅ |
| [10.2](phase-10.2.md) | Tall vehicle photos keep the layout + v1.2.1 | ✅ |
| [11.1](phase-11.1.md) | Tyres: fitted, stored, distance per tyre | 🚧 |

*Update the status column as each phase lands.*

---

## Phase 0 — Foundations
*A running, empty-but-correct skeleton that both deployment paths can start.*

- Slim 4 + PHP-DI + Doctrine DBAL wired against **MySQL and PostgreSQL**.
- Phinx migrations that apply and roll back cleanly on both engines.
- Twig base layout, asset pipeline (no runtime Node), i18n scaffold.
- Base-path handling for subpath reverse proxying, with deep-link hard-refresh
  working.
- `GET /health`, Docker (multi-arch incl. ARM) + bare-PHP run paths, CI on both
  databases.

→ [`phase-0.md`](phase-0.md)

## Phase 1 — Authentication + Garage
*A real single-owner app: set up an account, sign in securely, manage vehicles.*

- First-run setup, login/logout, change password, secure sessions, CSRF.
- Vehicle CRUD with photos, and archive/restore for sold vehicles (history
  retained).
- The shared **units / currency / dates** engine every later phase relies on
  (SI storage, conversion at the edges, UK **and** US mpg, zero-cost valid).
- Per-user preferences: unit system, currency, timezone, locale.

→ [`phase-1.md`](phase-1.md)

## Phase 2 — Odometer + Fuel
*Mileage as one coherent series, and fuel logging with trustworthy math.*

- First-class odometer log (manual + readings derived from fuel), with a trend
  chart and plausibility warnings that warn rather than block.
- Fuel fill-ups where any two of volume / price / total derive the third.
- **Full-to-full** consumption across partial fills and missed-fill gaps, shown
  as L/100km, mpg UK, mpg US, and km/L.
- EV support (kWh + efficiency) via the same entry shape.

→ [`phase-2.md`](phase-2.md)

## Phase 3 — Maintenance + Compliance
*Service history with recurring schedules, and compliance documents.*

- Maintenance entries (cost 0 valid), extensible categories, optional odometer
  that joins the mileage series.
- Recurring schedules ("every 10,000 km or 12 months") that compute the next due
  point — feeding reminders in Phase 4.
- Compliance documents (insurance, pollution/PUCC, registration, inspection)
  with working **create and edit**.
- General file attachments (receipts, invoices, certificates) on fuel,
  maintenance, and compliance, stored outside the web root and served only to
  the owner.

→ [`phase-3.md`](phase-3.md)

## Phase 4 — Reminders + Notifications
*Nothing gets missed: reminders that reach you, not just sit in the app.*

- Reminder engine generating from schedule due-dates, compliance expiries, and
  manual reminders, with configurable lead times and statuses.
- **Pluggable notification channels** with email (SMTP), **ntfy**, and
  **Gotify** shipped; adding a new channel (Telegram, Discord, …) needs only a
  new class + config, no change to the engine.
- Idempotent scheduled task (cron in bare installs, entrypoint-scheduled in
  Docker) so re-runs don't spam.
- Optional monthly digest and an optional authenticated iCal/webcal feed.

→ [`phase-4.md`](phase-4.md)

## Phase 5 — Expenses + Reports + Dashboard
*Understand what each vehicle costs, at a glance.*

- Fuel / maintenance / compliance costs roll up into expenses; ad-hoc expenses
  too.
- Per-vehicle and fleet reports: category breakdowns, cost/distance, cost/month,
  date-range filter, CSV export.
- The rearrangeable **widget dashboard** (drag to arrange, layout saved per
  user): fleet summary, upcoming reminders, recent fuel, spend this month,
  efficiency trend, compliance status.

→ [`phase-5.md`](phase-5.md)

## Phase 6 — Feature toggles + Import/backup + polish
*Finish the self-host story and harden the app.*

- Feature-toggle UI: disabled modules disappear from nav, routes, and dashboard.
- CSV import per module (validation + preview) and one-click **backup/restore**
  of the whole dataset (database + uploads).
- **PWA**: installable, with an offline fast "add fill-up" path.
- Accessibility pass, translation completeness with a second locale shipped, and
  full deployment/upgrade docs.

→ [`phase-6.md`](phase-6.md)

## Phase 7 — Design alignment + dashboard enhancements
*Match the design handoff, and make the dashboard answer "how is this car doing?"*

- Desktop modals for the entry forms (progressive enhancement: every form
  keeps its own page), and a "+ Log entry" chooser for everything you log.
- Sidebar: overdue + due-soon count on *Reminders*, and a *Vehicles* list
  with red / amber / green status dots.
- Dashboard: filter by vehicle with a pinned vehicle card (economy, running
  cost, spend, next due), new *Mileage* and *Recent activity* widgets, and
  photo tiles for *Your vehicles*.
- Garage cards with due badges and odometer + economy; the same header and
  CSV toolbar on every vehicle tab; 50/50 layouts for fuel trends and reports.
- Accent colour setting (Blue, Teal, Indigo, Purple) and the app version in
  the sidebar, Settings and `/health`.

→ [`phase-7.md`](phase-7.md)

## Phase 8 — Fuel grades + v1.0 release
*Record which fuel went in, compare what it costs, and cut 1.0.*

- An optional grade on each fill-up: petrol grade (E10 / E5 and octane, E85,
  E0, US AKI grades), diesel blend (B7 to B100, HVO / XTL) or, for EVs, how it
  was charged (home, public AC, DC, rapid, ultra-rapid); a default grade per
  vehicle.
- One grouped fuel picker (usual choices first, regional grades only where
  they are sold), pump-style badges, and price and — with enough data —
  economy by grade; cost per kWh and share of energy by charging type.
- Grade in CSV export / import and backups; English and German.
- Release **v1.0.0**.

→ [`phase-8.md`](phase-8.md)

## Phase 9.1 — Vehicle details
*Describe a vehicle precisely and give it a mileage figure from day one.*

- Optional variant / trim and first registration date on each vehicle, shown
  wherever the vehicle is described.
- An optional current odometer on the add form that writes the vehicle's
  first (manual) reading in the same transaction.
- Vehicle age and average mileage per year since first registration.
- No release of its own: ships with Phase 9.2 as **v1.1.0**.

→ [`phase-9.1.md`](phase-9.1.md)

## Phase 9.2 — Plug-in hybrids + v1.1 release
*Only offer charging where it makes sense.*

- The `hybrid` fuel type split into self-charging / mild *Hybrid* (petrol)
  and *Plug-in hybrid* (petrol and electricity), each labelled with a hint.
- Existing hybrids sorted from their own history: any that was ever charged
  becomes a plug-in hybrid.
- The fill-up picker leads with the families that fit each kind; charging
  stays reachable under *Other fuels*.
- Release **v1.1.0** (Phases 9.1 and 9.2).

→ [`phase-9.2.md`](phase-9.2.md)

## Phase 10 — Vehicle history + multiple attachments + v1.2 release
*What has happened to this car?*

- A **History** tab per vehicle and a fleet history page: fill-ups, service
  records, documents, expenses and readings in one list, bookended by the
  vehicle's milestones (first registered, bought, sold). Back-to-back
  fill-ups fold into one row; kind chips and one page per year.
- A **print view** that turns the history into a service history to hand to
  a buyer (with or without costs).
- **Several files per save** on every attachment input; expenses and manual
  odometer readings take files too.
- An optional **odometer on documents** (an MOT certificate shows one) that
  joins the mileage series.
- Release **v1.2.0**.

→ [`phase-10.md`](phase-10.md)

## Phase 10.2 — Tall vehicle photos + v1.2.1
*A portrait photo shouldn't stretch the card.*

- The dashboard's pinned vehicle card is as tall as its content, whatever
  the photo's shape; the photo is cropped to fit.
- Every other photo frame checked with tall and very wide photos.
- Release **v1.2.1**.

→ [`phase-10.2.md`](phase-10.2.md)

## Phase 11.1 — Tyres
*Which tyres are on it, which are in the garage, and how far did the last
ones go?*

- Each tyre recorded: brand, model, size, season and DOT date (so its age),
  and where it is — fitted at a position, stored in a set (*Winter wheels*,
  with where they are kept) or retired.
- Every tyre change logged: tyres already on the vehicle, fit new, swap
  set, rotate, repair, remove or retire, on cars and motorbikes.
- Each tyre's distance worked out from the one mileage series (time as a
  spare or in storage left out); lifetime distance and cost per distance
  for retired tyres.
- Costs stay on the linked `tyres` service record, so nothing is counted
  twice. A Tyres tab, an overview card, history, print and CSV.
- No release of its own: ships with Phase 11.2 as **v1.3.0**.

→ [`phase-11.1.md`](phase-11.1.md)

---

## After 1.0

Considered for later, not part of the phases above (see [`spec.md`](spec.md)
§12):

- REST API with API keys (OpenAPI documented) for scripting / Home Assistant.
- Multi-user with roles (admin/editor/viewer) and per-vehicle sharing.
- OIDC / SSO (Authelia, Authentik, Keycloak) and reverse-proxy header auth.
- Trip/journey log (business vs personal mileage),
  personal fuel-tank entity, VIN decode / registration lookup, PDF reports,
  OBD-II / vehicle-API mileage import.

---

*This roadmap is a plan, not a promise — phases and priorities may shift. The
`phase-*.md` files and [`spec.md`](spec.md) are the working source of truth.*
