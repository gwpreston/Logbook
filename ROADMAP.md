# Roadmap

This is the planned build order for the vehicle tracker — a self-hosted app for
keeping everything about your cars and bikes in one place (vehicles, mileage,
fuel, maintenance, insurance/certificate expiry, reminders, expenses and
reports, on a rearrangeable dashboard).

The work is split into **independently runnable phases**: each phase leaves the
app working, tested on both MySQL and PostgreSQL, and deployable via Docker or a
bare PHP 8.4 server. Detailed task breakdowns live in
[`docs/phases/`](docs/phases/); the *what* and *why* live in
[`spec.md`](spec.md); repo conventions live in [`CLAUDE.md`](CLAUDE.md).

**Stack:** PHP 8.4 · Slim 4 · PHP-DI · Doctrine DBAL · Phinx · Twig ·
symfony/translation · Monolog · Alpine.js / Chart.js / SortableJS. Server-
rendered, no SPA, no runtime Node. See [`spec.md`](spec.md) §4 for rationale.

## Status

Legend: ✅ complete · 🚧 in progress · 📋 planned

| Phase | Theme | Status |
|------:|-------|:------:|
| [0](docs/phases/phase-0.md) | Foundations | ✅ |
| [1](docs/phases/phase-1.md) | Authentication + Garage | ✅ |
| [2](docs/phases/phase-2.md) | Odometer + Fuel | ✅ |
| [3](docs/phases/phase-3.md) | Maintenance + Compliance | ✅ |
| [4](docs/phases/phase-4.md) | Reminders + Notifications | ✅ |
| [5](docs/phases/phase-5.md) | Expenses + Reports + Dashboard | ✅ |
| [6](docs/phases/phase-6.md) | Feature toggles + Import/backup + polish | ✅ |
| [7](docs/phases/phase-7.md) | Design alignment + dashboard enhancements | ✅ |
| [8](docs/phases/phase-8.md) | Fuel grades + v1.0 release | ✅ |
| [9.1](docs/phases/phase-9.1.md) | Vehicle details: variant, first registration, starting mileage | ✅ |
| [9.2](docs/phases/phase-9.2.md) | Plug-in hybrids + v1.1 release | ✅ |
| [10](docs/phases/phase-10.md) | Vehicle history + multiple attachments + v1.2 release | ✅ |
| [10.2](docs/phases/phase-10.2.md) | Tall vehicle photos keep the layout + v1.2.1 | ✅ |
| [11.1](docs/phases/phase-11.1.md) | Tyres: fitted, stored, distance per tyre | ✅ |
| [11.2](docs/phases/phase-11.2.md) | Tread depth, wear and age reminders + v1.3 release | ✅ |
| [12](docs/phases/phase-12.md) | Buyer-first print, ownership paperwork, dated starting mileage + v1.4 release | ✅ |
| [13](docs/phases/phase-13.md) | Economy checks + v1.5 release | ✅ |
| [14.1](docs/phases/phase-14.1.md) | Valuations and depreciation | ✅ |
| [14.2](docs/phases/phase-14.2.md) | Total cost of ownership + v1.6 release | ✅ |
| [15](docs/phases/phase-15.md) | Coming up: maintenance and cost forecast + v1.7 release | ✅ |
| [16](docs/phases/phase-16.md) | Fuel insights + v1.8 release | ✅ |
| [17.1](docs/phases/phase-17.1.md) | Sale pack | ✅ |
| [17.2](docs/phases/phase-17.2.md) | Printable reports + v1.9 release | ✅ |
| [18.1](docs/phases/phase-18.1.md) | Access policy | ✅ |
| [18.2](docs/phases/phase-18.2.md) | REST API v1 + v1.10 release | ✅ |
| [19](docs/phases/phase-19.md) | Multiple users and vehicle sharing + v2.0 release | ✅ |
| [20](docs/phases/phase-20.md) | Phase files into `docs/phases/`, open-questions review | ✅ |
| [21.1](docs/phases/phase-21.1.md) | Tyre modals, drag-and-drop files, digest on by default, sale pack cover | ✅ |
| [21.2](docs/phases/phase-21.2.md) | First MOT due + v2.1 release | ✅ |
| [22](docs/phases/phase-22.md) | Trips and business mileage claims + v2.2 release | ✅ |
| [23.1](docs/phases/phase-23.1.md) | Single sign-on with OpenID Connect | ✅ |
| [23.2](docs/phases/phase-23.2.md) | Reverse-proxy header sign-in + v2.3 release | ✅ |
| [24](docs/phases/phase-24.md) | Needs attention + v2.4 release | ✅ |
| [25](docs/phases/phase-25.md) | Trend and cost checks + v2.5 release | ✅ |
| [26.1](docs/phases/phase-26.1.md) | AI foundation: connections, models and task routing | ✅ |
| [26.2](docs/phases/phase-26.2.md) | Ask Logbook + v2.6 release | ✅ |
| [26.3](docs/phases/phase-26.3.md) | Actions: say it, check it, add it + v2.7 release | ✅ |
| [26.4](docs/phases/phase-26.4.md) | Read receipts and documents + v2.8 release | ✅ |
| [26.5](docs/phases/phase-26.5.md) | MCP server + v2.9 release | ✅ |
| [27.1](docs/phases/phase-27.1.md) | Incidents, damage and insurance claims | ✅ |
| [27.2](docs/phases/phase-27.2.md) | Total loss and reading claim letters + v2.10 release | ✅ |
| [28.1](docs/phases/phase-28.1.md) | Scheduled jobs in Settings | ✅ |
| [28.2](docs/phases/phase-28.2.md) | Update check and dashboard banner + v2.11 release | ✅ |
| [29.1](docs/phases/phase-29.1.md) | Finance and lease agreements | ✅ |
| [29.2](docs/phases/phase-29.2.md) | Mileage, ending and finance everywhere + v2.12 release | ✅ |
| [30.1](docs/phases/phase-30.1.md) | Fuel stations + v2.13 release | ✅ |
| [30.2](docs/phases/phase-30.2.md) | Live fuel prices and cheapest near me + v2.14 release | ✅ |
| [31](docs/phases/phase-31.md) | Import from Fuelio + v2.15 release | ✅ |
| [31.2](docs/phases/phase-31.2.md) | The Fuel stations module shows its icon + v2.15.1 | ✅ |
| [32](docs/phases/phase-32.md) | True cost per mile, its breakdown and its trend + v2.16 release | ✅ |
| [33.1](docs/phases/phase-33.1.md) | Accounts: forgotten password, admin controls, avatars, dev mail | ✅ |
| [33.2](docs/phases/phase-33.2.md) | Sign-in and Settings to the prototype, and the sidebar | ✅ |
| [33.3](docs/phases/phase-33.3.md) | Vehicle pages: Finance tab, Insights, trips, incidents, tyres | ✅ |
| [33.4](docs/phases/phase-33.4.md) | Cost of ownership, Ask and Fuel stations + v3.0 release | ✅ |
| [34.1](docs/phases/phase-34.1.md) | Registration plates | ✅ |
| [34.2](docs/phases/phase-34.2.md) | Expense breakdown and monthly spend widgets | ✅ |
| [34.3](docs/phases/phase-34.3.md) | Reminders calendar and dashboard widget + v3.1 release | ✅ |
| [35.1](docs/phases/phase-35.1.md) | Demo mode | ✅ |
| [35.2](docs/phases/phase-35.2.md) | Proxmox LXC, Traefik and Caddy guides + v3.2 release | ✅ |
| [36.1](docs/phases/phase-36.1.md) | Email server settings (admin) | ✅ |
| [36.2](docs/phases/phase-36.2.md) | Personal notification channels | ✅ |
| [36.3](docs/phases/phase-36.3.md) | Telegram, Discord, Pushover, Mattermost and Slack | ✅ |
| [36.4](docs/phases/phase-36.4.md) | What each channel receives, and quiet hours + v3.3 release | ✅ |
| [37](docs/phases/phase-37.md) | Space between the Fuel prices providers + patch release | ✅ |
| [38](docs/phases/phase-38.md) | Ask lives on Insights; the Ask page goes + v3.4 release | ✅ |
| [39.1](docs/phases/phase-39.1.md) | API reads and reminder actions | ✅ |
| [39.2](docs/phases/phase-39.2.md) | API writes, edit and delete | ✅ |
| [39.3](docs/phases/phase-39.3.md) | API attachments and entry webhooks + v3.5 release | ✅ |
| [40.1](docs/phases/phase-40.1.md) | Issues log | ✅ |
| [40.2](docs/phases/phase-40.2.md) | Issues everywhere + v3.6 release | ✅ |
| [41](docs/phases/phase-41.md) | DVSA MOT history + release | ✅ |
| [41.6](docs/phases/phase-41.6.md) | MOT history follow-ups + patch release | ✅ |
| [41.7](docs/phases/phase-41.7.md) | Dashboard and overview query batching + patch release | 🚧 |
| [42](docs/phases/phase-42.md) | Fuel saving and economy up as computed insights + release | 📋 |
| [43](docs/phases/phase-43.md) | The monthly briefing + release | 📋 |

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

→ [`docs/phases/phase-0.md`](docs/phases/phase-0.md)

## Phase 1 — Authentication + Garage
*A real single-owner app: set up an account, sign in securely, manage vehicles.*

- First-run setup, login/logout, change password, secure sessions, CSRF.
- Vehicle CRUD with photos, and archive/restore for sold vehicles (history
  retained).
- The shared **units / currency / dates** engine every later phase relies on
  (SI storage, conversion at the edges, UK **and** US mpg, zero-cost valid).
- Per-user preferences: unit system, currency, timezone, locale.

→ [`docs/phases/phase-1.md`](docs/phases/phase-1.md)

## Phase 2 — Odometer + Fuel
*Mileage as one coherent series, and fuel logging with trustworthy math.*

- First-class odometer log (manual + readings derived from fuel), with a trend
  chart and plausibility warnings that warn rather than block.
- Fuel fill-ups where any two of volume / price / total derive the third.
- **Full-to-full** consumption across partial fills and missed-fill gaps, shown
  as L/100km, mpg UK, mpg US, and km/L.
- EV support (kWh + efficiency) via the same entry shape.

→ [`docs/phases/phase-2.md`](docs/phases/phase-2.md)

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

→ [`docs/phases/phase-3.md`](docs/phases/phase-3.md)

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

→ [`docs/phases/phase-4.md`](docs/phases/phase-4.md)

## Phase 5 — Expenses + Reports + Dashboard
*Understand what each vehicle costs, at a glance.*

- Fuel / maintenance / compliance costs roll up into expenses; ad-hoc expenses
  too.
- Per-vehicle and fleet reports: category breakdowns, cost/distance, cost/month,
  date-range filter, CSV export.
- The rearrangeable **widget dashboard** (drag to arrange, layout saved per
  user): fleet summary, upcoming reminders, recent fuel, spend this month,
  efficiency trend, compliance status.

→ [`docs/phases/phase-5.md`](docs/phases/phase-5.md)

## Phase 6 — Feature toggles + Import/backup + polish
*Finish the self-host story and harden the app.*

- Feature-toggle UI: disabled modules disappear from nav, routes, and dashboard.
- CSV import per module (validation + preview) and one-click **backup/restore**
  of the whole dataset (database + uploads).
- **PWA**: installable, with an offline fast "add fill-up" path.
- Accessibility pass, translation completeness with a second locale shipped, and
  full deployment/upgrade docs.

→ [`docs/phases/phase-6.md`](docs/phases/phase-6.md)

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

→ [`docs/phases/phase-7.md`](docs/phases/phase-7.md)

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

→ [`docs/phases/phase-8.md`](docs/phases/phase-8.md)

## Phase 9.1 — Vehicle details
*Describe a vehicle precisely and give it a mileage figure from day one.*

- Optional variant / trim and first registration date on each vehicle, shown
  wherever the vehicle is described.
- An optional current odometer on the add form that writes the vehicle's
  first (manual) reading in the same transaction.
- Vehicle age and average mileage per year since first registration.
- No release of its own: ships with Phase 9.2 as **v1.1.0**.

→ [`docs/phases/phase-9.1.md`](docs/phases/phase-9.1.md)

## Phase 9.2 — Plug-in hybrids + v1.1 release
*Only offer charging where it makes sense.*

- The `hybrid` fuel type split into self-charging / mild *Hybrid* (petrol)
  and *Plug-in hybrid* (petrol and electricity), each labelled with a hint.
- Existing hybrids sorted from their own history: any that was ever charged
  becomes a plug-in hybrid.
- The fill-up picker leads with the families that fit each kind; charging
  stays reachable under *Other fuels*.
- Release **v1.1.0** (Phases 9.1 and 9.2).

→ [`docs/phases/phase-9.2.md`](docs/phases/phase-9.2.md)

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

→ [`docs/phases/phase-10.md`](docs/phases/phase-10.md)

## Phase 10.2 — Tall vehicle photos + v1.2.1
*A portrait photo shouldn't stretch the card.*

- The dashboard's pinned vehicle card is as tall as its content, whatever
  the photo's shape; the photo is cropped to fit.
- Every other photo frame checked with tall and very wide photos.
- Release **v1.2.1**.

→ [`docs/phases/phase-10.2.md`](docs/phases/phase-10.2.md)

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

→ [`docs/phases/phase-11.1.md`](docs/phases/phase-11.1.md)

## Phase 11.2 — Tread depth, wear and age reminders
*When do these tyres need replacing, before an MOT tester or a wet roundabout
says so?*

- Tread depth in mm or 32nds of an inch (a new unit preference), recorded
  when tyres are fitted, swapped or removed, and with a new *Check tread*.
- A wear estimate per fitted tyre from its own distance: depth now, distance
  left to the owner's replace-at depth and roughly when, always labelled as
  an estimate.
- Replace-at, legal-minimum and age-limit settings (from the DOT date).
- One tyre reminder per vehicle through the existing engine and channels,
  quiet across fill-ups and reopened by a new check.
- Released with Phase 11.1 as **v1.3.0**.

→ [`docs/phases/phase-11.2.md`](docs/phases/phase-11.2.md)

## Phase 12 — Buyer-first print, ownership paperwork, dated starting mileage
*Hand the printout to a buyer without thinking twice, and keep the purchase
invoice with the purchase.*

- The print view hides costs (and the purchase and sale prices) unless
  *Show costs* is ticked; no old link shows costs it used to hide.
- Purchase and sale paperwork on the vehicle form, shown on the *Bought*
  and *Sold* milestones, the overview and the print view.
- An *As of* date for the add form's current odometer; the lifetime average
  measured to the date of the reading it uses.
- The overview's latest fill-ups list removed (*Recent history* and the
  Fuel tab cover it).
- Release **v1.4.0**.

→ [`docs/phases/phase-12.md`](docs/phases/phase-12.md)

## Phase 13 — Economy checks + v1.5 release
*Is that tank really that bad, or was the odometer mistyped?*

- Each full-to-full segment compared with the median of the vehicle's own
  previous ten, in litres (or kWh) per 100 km so every unit agrees.
- Flags for tanks far outside the usual, with the likely cause and links to
  the fill-ups to check; a mistyped reading shows as a pair pointing at one
  fill-up.
- *Looks right* confirms a genuine one until its figures change. Averages
  are never altered and nothing is sent as a notification.
- Release **v1.5.0**.

→ [`docs/phases/phase-13.md`](docs/phases/phase-13.md)

## Phase 14.1 — Valuations and depreciation
*What is it worth, and what has it lost?*

- A valuation log per vehicle (date, amount, source, attachments). The
  purchase and sale paperwork already came with Phase 12.
- Depreciation from the purchase price to the latest value or the sale
  price: amount, percentage, per year and per distance, measured to the
  value's own date. Nothing is fetched from third parties or extrapolated.
- Valuations in History and *Recent activity*, never in the print view or
  the cost ledger.
- No release of its own: ships with Phase 14.2 as **v1.6.0**.

→ [`docs/phases/phase-14.1.md`](docs/phases/phase-14.1.md)

## Phase 14.2 — Total cost of ownership + v1.6 release
*What has this car really cost?*

- Running costs plus depreciation over the time owned, per distance and per
  month; exact lifetime figures for sold vehicles.
- A *Cost of ownership* card on the overview and an *Ownership* report
  comparing vehicles, with CSV export.
- A *Finance and lease* expense category.
- Release **v1.6.0** (Phases 14.1 and 14.2).

→ [`docs/phases/phase-14.2.md`](docs/phases/phase-14.2.md)

## Phase 15 — Coming up + v1.7 release
*What is due in the next year, and roughly what will it cost?*

- Schedules (with repeats), document renewals, tyres and manual reminders
  over the next 12 months, from the same due-point logic as reminders.
- Each item costed from its last occurrence, never guessed; a fuel estimate
  from the current rate of driving and cost per distance.
- A fleet page, an overview card and a dashboard widget; CSV export.
- Release **v1.7.0**.

→ [`docs/phases/phase-15.md`](docs/phases/phase-15.md)

## Phase 16 — Fuel insights + v1.8 release
*Is the dearer fuel worth it, what does a mile really cost, and how much does
winter take?*

- A grade verdict on the *By grade* card: how much more or less a grade
  costs per mile or km than the usual one, from its economy and a price
  premium taken from fills bought within a month of each other.
- Cost per mile or km for each charging type.
- A cost per distance mode on the *Economy trend* chart.
- *Economy by month*: the seasonal effect, per year and averaged.
- Nothing new is stored; release **v1.8.0**.

→ [`docs/phases/phase-16.md`](docs/phases/phase-16.md)

## Phase 17.1 — Sale pack
*Everything a buyer wants to see, and nothing they shouldn't.*

- *Prepare for sale*: a summary page (ownership, mileage, last service, MOT,
  tyres, what's due next, paperwork on file), a mileage record from readings
  a buyer can check, and history grouped by type.
- The invoices and certificates as a ZIP, readably named. Registration
  documents are never offered.
- Printed through the browser like History. No prices paid, fuel,
  valuations or ownership costs, ever; work costs only on request.
- No release of its own: ships with Phase 17.2 as **v1.9.0**.

→ [`docs/phases/phase-17.1.md`](docs/phases/phase-17.1.md)

## Phase 17.2 — Printable reports + v1.9 release
*A clean paper or PDF copy of any report, without a PDF library.*

- Print layouts and a *Print* button for Reports, the Ownership report,
  *Coming up*, and the Fuel and Mileage tabs.
- A shared print header (what, for which vehicle and period, units, date),
  charts in a print palette with their tables.
- Release **v1.9.0** (Phases 17.1 and 17.2).

→ [`docs/phases/phase-17.2.md`](docs/phases/phase-17.2.md)

## Phase 18.1 — Access policy
*One place that decides who may do what, before anyone else can sign in.*

- A vehicle access policy and an instance policy asked by every route and
  every cross-vehicle read; cost visibility behind one check.
- A route inventory test that fails the build for any unclassified route.
- No visible change and no migration. Ships with Phase 18.2 as **v1.10.0**.

→ [`docs/phases/phase-18.1.md`](docs/phases/phase-18.1.md)

## Phase 18.2 — REST API v1 + v1.10 release
*Let Home Assistant, Shortcuts, Grafana and OBD tools read and log.*

- API keys per user (read, or read and write), shown once, revocable.
- Read endpoints for vehicles, a summary, entries, tyres, *Coming up* and
  reminders; write endpoints for fill-ups and odometer readings through the
  same services as the forms, safe to retry.
- OpenAPI 3.1, contract-tested; guides for Home Assistant, Shortcuts,
  Grafana and Node-RED.
- Release **v1.10.0** (Phases 18.1 and 18.2).

→ [`docs/phases/phase-18.2.md`](docs/phases/phase-18.2.md)

## Phase 19 — Multiple users and vehicle sharing + v2.0 release
*A family garage: everyone sees their own cars and the ones shared with
them.*

- Admins and members; invitations by one-time link; disable, transfer,
  delete.
- Per-vehicle sharing at Manage, Log or View, with a separate *Can see
  costs* flag; "added by" on entries.
- Reminders to the owner and to shared users who opt in, each in their own
  language and units.
- Release **v2.0.0**.

→ [`docs/phases/phase-19.md`](docs/phases/phase-19.md)

## Phase 20 — Phase files into `docs/phases/`, open-questions review
*A tidier repository, and nothing left undecided by accident.*

- Every `phase-*.md` moves to `docs/phases/` with its history; every link to
  and from them is fixed, and a test keeps Markdown links resolving.
- `CLAUDE.md` gains a standing rule: before a phase, review earlier phases'
  open questions, check what the app already does, and ask the owner before
  acting on anything undecided.
- A one-off review of Phases 1–19 into `docs/phases/open-questions.md`.
- No app change; noted in the 2.1.0 changelog.

→ [`docs/phases/phase-20.md`](docs/phases/phase-20.md)

## Phase 21.1 — Tyre modals, drag-and-drop files, digest on by default, sale pack cover
*Small things that make everyday use smoother.*

- Tyre, tyre change and tyre set edit forms open as desktop modals.
- The monthly digest is on for new users; existing users keep their choice.
- Drag-and-drop onto every file input, added to what is already chosen,
  with the same limits and one parser.
- An optional vehicle photo on a cover page before the sale pack's summary
  (off by default).
- Ships with Phase 21.2 as **v2.1.0**.

→ [`docs/phases/phase-21.1.md`](docs/phases/phase-21.1.md)

## Phase 21.2 — First MOT due + v2.1 release
*A new car's first MOT is the one reminder nobody has paperwork for yet.*

- An optional *First MOT due* date on the vehicle, suggested from the first
  registration date (3 years in GB and DE, 4 in FR, IE, IT and ES) and
  stored, so owners in Northern Ireland or elsewhere can set their own.
- A one-time prompt offers it for vehicles already in the garage.
- It drives a reminder, a *Coming up* item and the overview until the first
  MOT certificate is logged; then the certificate's expiry takes over.
- Release **v2.1.0** (Phases 20, 21.1 and 21.2).

→ [`docs/phases/phase-21.2.md`](docs/phases/phase-21.2.md)

## Phase 22 — Trips and business mileage claims + v2.2 release
*Log the journeys you claim for; Logbook works out the rest.*

- A `trips` module, off until switched on: business trips per vehicle with
  optional odometer, returns, passengers, saved journeys and *Log again*.
- Private mileage from the mileage log, never from logged private trips.
- Dated mileage rates per user, with HMRC's provided once for UK users.
- A claim report by tax year: the 10,000-mile split, passengers, employer
  payments and the difference, printed or as CSV; cost per business mile
  beside the claim value.
- Destinations stay with their driver: never in *Everything*, the print
  view or the sale pack.
- Release **v2.2.0**.

→ [`docs/phases/phase-22.md`](docs/phases/phase-22.md)

## Phase 23.1 — Single sign-on with OpenID Connect
*Sign in with the Authelia, Authentik or Keycloak you already run.*

- One OIDC provider by environment variables: discovery, code flow with
  PKCE, and full ID token validation.
- Explicit account linking by default (username linking, automatic
  creation and admin-from-groups optional).
- Local sign-in can be switched off; a CLI break-glass link always works.
- Ships with Phase 23.2 as **v2.3.0**.

→ [`docs/phases/phase-23.1.md`](docs/phases/phase-23.1.md)

## Phase 23.2 — Reverse-proxy header sign-in + v2.3 release
*When Authelia or Authentik already guards the door, don't ask twice.*

- Trust a username header (`Remote-User`, …) only from listed proxy
  addresses, or Authentik's signed JWT header; refuse to start
  half-configured.
- The session follows the header; guides for Authelia (nginx, Traefik,
  Caddy) and Authentik outposts.
- Release **v2.3.0** (Phases 23.1 and 23.2).

→ [`docs/phases/phase-23.2.md`](docs/phases/phase-23.2.md)

## Phase 24 — Needs attention + v2.4 release
*What is wrong right now, on one short list, with the fix one tap away.*

- Overdue work (from *Coming up*), implausible readings, unusual fill-ups,
  stale mileage and stale valuations, in a fixed order on the overview and
  a dashboard widget.
- Data checks can be hidden until their data changes. Deliberately not a
  health score.
- Release **v2.4.0**.

→ [`docs/phases/phase-24.md`](docs/phases/phase-24.md)

## Phase 25 — Trend and cost checks + v2.5 release
*Spot a car getting thirstier, or a price typed wrong, without any AI.*

- Economy drift (recent tanks against the year, allowing for the season),
  fuel price outliers and cost outliers as *Needs attention* checks, with
  likely causes from recorded facts.
- Plain statistics; no model, no network. Release **v2.5.0**.

→ [`docs/phases/phase-25.md`](docs/phases/phase-25.md)

## Phase 26.1 — AI foundation: connections, models and task routing
*Use whichever model you trust: on this server, on your network, or in
the cloud.*

- Any number of connections through four adapters (OpenAI-compatible,
  covering Ollama, llama.cpp, LM Studio and vLLM; Ollama; Anthropic;
  Gemini), each shown as *This server*, *Your network* or *Internet*.
- Tasks routed to a connection and model each; internet use acknowledged;
  encrypted keys; a usage log without content; off until an admin sets it
  up. Ships with Phase 26.2 as **v2.6.0**.

→ [`phase-26.1.md`](docs/phases/phase-26.1.md)

## Phase 26.2 — Ask Logbook + v2.6 release
*Ask a question in plain words; get Logbook's own numbers back.*

- Read-only tools over the existing services and access policy, with
  display strings in the user's units; answers with sources and links; a
  grounding check on every number. Release **v2.6.0**.

→ [`phase-26.2.md`](docs/phases/phase-26.2.md)

## Phase 26.3 — Actions: say it, check it, add it + v2.7 release
*"Filled the BMW with 51 litres of E10 at £1.39, mileage 72,341." Add?*

- Draft tools for fill-ups, readings, service records, documents,
  expenses, tread checks and reminders through the API's input adapter;
  Logbook computes and validates; nothing is saved without *Add*. Release
  **v2.7.0**.

→ [`phase-26.3.md`](docs/phases/phase-26.3.md)

## Phase 26.4 — Read receipts and documents + v2.8 release
*Photograph the garage invoice; check the form; save.*

- Photos and PDFs read into the right prefilled form with the file
  attached; text PDFs read as text; EXIF stripped; V5C references never
  extracted; recommended work offered as reminders. Release **v2.8.0**.

→ [`phase-26.4.md`](docs/phases/phase-26.4.md)

## Phase 26.5 — MCP server + v2.9 release
*Use Logbook from Claude Desktop, or any assistant that speaks MCP.*

- The same tools over MCP with API keys; fill-ups and readings written as
  the API does, everything else as drafts confirmed in Logbook. Release
  **v2.9.0**.

→ [`phase-26.5.md`](docs/phases/phase-26.5.md)

## Phase 27.1 — Incidents, damage and insurance claims
*What happened, what was fixed, what the insurer did, and the five-year
answer your next quote will ask for.*

- Incidents with type, fault, damage, photos (EXIF kept), driver, the
  other party and the claim (number, status, excess, payout, no-claims
  effect, write-off category), linking existing repairs, expenses and
  tyre changes rather than copying their costs.
- A claims history across every vehicle, sold ones included, printable for
  insurance quotes.
- An optional incident summary in the sale pack (repairs only, never claim
  details); ownership net of payouts; stalled claims in *Needs attention*;
  the API, Ask and MCP.

→ [`phase-27.1.md`](docs/phases/phase-27.1.md)

## Phase 27.2 — Total loss and reading claim letters + v2.10 release
*When the insurer pays out for the car, and the letters in between.*

- Archiving offers *Written off* for a settled write-off, with the
  settlement as the sale price, counted once in ownership.
- Scanning reads insurer claim letters into the matching incident and
  repair estimates into a new estimate field (never counted in costs).
- Release **v2.10.0** (Phases 27.1 and 27.2).

→ [`phase-27.2.md`](docs/phases/phase-27.2.md)

## Phase 28.2 — Update check and dashboard banner + v2.11 release
*Know when a new Logbook is out, without anything updating itself.*

- A daily job asks GitHub's latest-release endpoint (off until an admin
  switches it on), validates the answer, and compares versions.
- An admin-only dashboard banner with release notes and the upgrade step
  for Docker or bare PHP, dismissible per version.
- Release **v2.11.0** (Phases 28.1 and 28.2).

→ [`phase-28.2.md`](docs/phases/phase-28.2.md)

## Phase 29.1 — Finance and lease agreements
*Payments left, what's still owed, and what the car is worth against what
you owe.*

- HP, PCP, loan and lease agreements typed from the paperwork, with a
  derived monthly schedule (payments assumed paid, exceptions recorded).
- Exact payments remaining and remaining to pay; settlement as the lender's
  quote or a labelled estimate; cost of credit; the half-paid point;
  equity from the latest valuation.
- Credit charges or lease rentals counted in costs automatically, with an
  overlap warning for manual finance expenses. No release of its own.

→ [`phase-29.1.md`](docs/phases/phase-29.1.md)

## Phase 29.2 — Mileage, ending and finance everywhere + v2.12 release
*Whether you'll go over the miles, and what happens at the end.*

- Mileage against the allowance with the projected excess charge.
- Ending, handing back and selling with finance owing, through archiving.
- *Coming up*, reminders, *Needs attention*, the widget, the API and Ask.
- Release **v2.12.0** (Phases 29.1 and 29.2).

→ [`phase-29.2.md`](docs/phases/phase-29.2.md)

## Phase 30.1 — Fuel stations + v2.13 release
*Where you fill up, what you paid there, and how far it is from home.*

- Stations as shared records (name, brand, address, position, grades,
  hours) linked from fill-ups; existing station names become stations on
  upgrade, with a duplicates view and merge.
- Per-station visits, spend and price history from the user's own
  fill-ups; favourites; private places (Home, Work) with straight-line
  distances. Entirely local. Release **v2.13.0**.

→ [`phase-30.1.md`](docs/phases/phase-30.1.md)

## Phase 30.2 — Live fuel prices and cheapest near me + v2.14 release
*Today's prices near you, ranked by what the trip really saves.*

- A provider interface (bulk or area) starting with UK Fuel Finder, synced
  as a scheduled job; off until an admin enables it.
- Logbook stations linked to provider stations; listed prices and history
  beside what the user paid.
- *Cheapest near me* ranked by effective cost (usual fill plus the fuel to
  get there and back), with the sum shown in full: fuel saving, extra
  distance, fuel for it, actual saving.
- *Was it worth it?* after a fill-up against the usual station, and a
  12-month *Shopping around* total; the listed price one tap away on the
  fill-up form; price alerts on favourite stations, a dashboard widget, an
  API endpoint and an Ask tool. Release **v2.14.0**.

→ [`phase-30.2.md`](docs/phases/phase-30.2.md)

## Phase 31 — Import from Fuelio + v2.15 release
*Bring years of fill-ups, services and costs across from Fuelio in one
go.*

- A Fuelio CSV on the web page, and a Fuelio backup ZIP with its fill-up
  photos on the command line, with strict ZIP safety limits; sections read
  into fill-ups, maintenance records, expenses and stations through the
  existing row parsers.
- CNG as a fuel family (kg, its own consumption series).
- Built from real anonymised exports as fixtures; a mapping step for
  vehicles, units (with an economy sanity check), formats, cost categories
  and fuel types; optional schedules from recurring costs.
- A preview and one transaction across every vehicle; source ids so a newer
  export adds only new rows. Built as a general app importer for later
  readers. Release **v2.15.0**.

→ [`phase-31.md`](docs/phases/phase-31.md)

## Phase 31.2 — The Fuel stations module shows its icon + v2.15.1
*Settings → Modules shows the Fuel stations icon again.*

- The `pin_drop` icon, missing from the bundled sprite since v2.13.0, is
  vendored; a test checks every icon an enum names is in the sprite.
- Release **v2.15.1**.

→ [`docs/phases/phase-31.2.md`](docs/phases/phase-31.2.md)

## Phase 32 — True cost per mile, its breakdown and its trend + v2.16 release
*One number for what a car costs to run, what it is made of, and why it
changed.*

- Cost per distance split into fuel, maintenance, insurance, tax and MOT,
  other and depreciation, since bought or over the last 12 months, with
  depreciation interpolated between dated values (never extrapolated).
- A `true_cost` dashboard widget ranking vehicles, and a yearly trend in
  Reports.
- *What changed* splits each year's change exactly into its causes: each
  part, fuel price against economy, and distance driven. Ask Logbook can
  explain them. Release **v2.16.0**.

→ [`phase-32.md`](docs/phases/phase-32.md)

## Phase 33.1 — Accounts: forgotten password, admin controls, avatars and dev mail
*Get back in without asking anyone, and see every email the app sends while
developing.*

- *Forgotten your password?* on sign-in: a 60-minute link by email, with
  no account enumeration, throttled; supersedes #36.
- One confirmed email address per user (reset links, sign-in by email,
  reminders); changing it needs the current password and a confirmation
  link.
- Admins: send a reset email, sign a user out everywhere, revoke access
  (the renamed *Disable*), add a user. Avatars for everyone, re-encoded and served privately.
- Mailpit in the dev stack; fresh random sample passwords on every
  `--with-sample-data` run. Ships with 33.4 as **v3.0.0**.

→ [`phase-33.1.md`](docs/phases/phase-33.1.md)

## Phase 33.2 — Sign-in and Settings to the prototype, and the sidebar
*The first page anyone sees and the page everyone configures from.*

- Sign-in, forgotten and reset password (and the other signed-out pages)
  drawn from `design-import/`.
- Settings laid out as the prototype, cards regrouped so *Reminders and
  notifications* holds only reminders; unit presets show hover and which
  one matches.
- *Fuel stations* in the sidebar; *Settings* below *Ask*.

→ [`phase-33.2.md`](docs/phases/phase-33.2.md)

## Phase 33.3 — Vehicle pages: Finance tab, Insights, trips, incidents, tyres
*Every tab of a vehicle looks like the same page.*

- The vehicle's name the same size on every tab; Finance becomes a tab.
- An *Insights* dashboard widget from figures Logbook already computes
  (the Insights page and AI insights are 33.4's).
- Trips: the *Business and private* card; the dashboard's *Your vehicles*
  three to a row; incidents and *Current tyres* to the prototype; the
  vehicle's seller and mileage when bought.

→ [`phase-33.3.md`](docs/phases/phase-33.3.md)

## Phase 33.4 — Cost of ownership, Ask and Fuel stations + v3.0 release
*What each car has really cost, in one look.*

- *Total cost*, *Per month*, *Depreciation* and *Finance interest*, then a
  card per vehicle with a multicolour cost bar.
- A vehicle *Cost of ownership* tab (#191).
- Ask to the prototype's card, keeping its menu entry; an Insights page
  with *Ask* above every insight and the day's AI insights.
- Fuel stations with *Prices nearby* (area average, saving banner,
  favourite, directions, *Log fill-up here*) above *Your stations*.
- Release **v3.0.0** (Phases 33.1–33.4).

→ [`phase-33.4.md`](docs/phases/phase-33.4.md)

---

## Phase 34.1 — Registration plates
*A registration that looks like one.*

- One `ui.plate()` macro: a yellow UK plate with a blue "UK" band for an
  owner in GB, a neutral white plate for everyone else, chosen by the
  **owner's** locale so a shared car looks the same to all.
- On garage cards, the dashboard tiles and pinned card, the vehicle
  header, the *Cost of ownership* cards, the pickers and the sale pack
  cover; text everywhere else (#198–#202).
- Fixed colours in both themes and every accent, a contrast test, a
  forced-colours border, and copy-and-paste that gives the registration
  only. Ships with Phase 34.3 as v3.1.0.

→ [`phase-34.1.md`](docs/phases/phase-34.1.md)

---

## Phase 34.2 — Expense breakdown and monthly spend widgets
*Where the money went, and how it moved month to month, without leaving
the dashboard.*

- **Expense breakdown:** the period's spend by Reports' groups, with
  *This month*, *Last 12 months* and *This year* as links; a CSS bar,
  whole percentages that add up to 100%, and every row linking to Reports
  for that group (#204, #205).
- **Monthly spend:** the last 12 months as the Expenses tab's stacked
  bars, a table without JS, the average per month, and each month linking
  to Reports for that month (#206).
- Both follow the vehicle filter, count only vehicles whose costs the
  viewer may see, never convert currencies and go with the `reports`
  module; *Spend this month* stays (#203). Ships with Phase 34.3 as
  v3.1.0.

→ [`phase-34.2.md`](docs/phases/phase-34.2.md)

---

## Phase 34.3 — Reminders calendar and dashboard widget + v3.1 release
*See what is due as a month, not only as a list.*

- **Calendar view** of Reminders (`/reminders/calendar`), switchable with
  the list: the same reminders, sources and access, a month at a time as
  a list of weeks laid out as a grid, with week numbers and the first day
  of the week from the locale (#211), and an agenda on a phone.
- An overdue strip, the reminders with no date under the grid, a day
  panel with every item's actions and *Add reminder* with the date filled
  in (#209); done and dismissed shown muted unless hidden (#208, #243);
  no projected *Coming up* items (#207).
- **Calendar** dashboard widget: a small month of open reminders (#210,
  #244), each marked day linking to the calendar page.
- The light-theme *Tax* and *Other* chart colours darkened to 3:1 (#242).
- Releases **v3.1.0** with Phases 34.1 and 34.2.

→ [`phase-34.3.md`](docs/phases/phase-34.3.md)

---

## Phase 35.1 — Demo mode
*A public demo that resets itself and cannot hurt anyone, including you.*

- `DEMO_MODE` seeds an empty database with the sample data and marks it as
  a demo; `DEMO_PASSWORD` is the demo owner's, shown on the sign-in page
  (#212, #215, #217).
- A guard: only an instance seeded as a demo can ever be reset; the same
  switch on a real instance changes nothing and tells the admin why.
- A `demo_reset` job (24 hours by default) and `bin/demo-reset.php`, with
  every seeded date relative to the run (#213, #216).
- Visitors cannot reach administration, change credentials, send anything
  out or upload files (#214); every route declares `demo: allowed` or
  `blocked`. A banner on every page and no search indexing.
- No release of its own; ships with Phase 35.2 as v3.2.0.

→ [`phase-35.1.md`](docs/phases/phase-35.1.md)

---

## Phase 35.2 — Proxmox LXC, Traefik and Caddy guides + v3.2 release
*Run it on the Proxmox box in the cupboard, behind the proxy you already
use.*

- `docs/reverse-proxies.md`: nginx, Apache, Caddy and Traefik at the root
  and at a subpath, with HTTPS, and exactly what Logbook trusts from a proxy.
- Caddy and Traefik examples in `docker/examples/`, which CI runs unchanged
  over HTTPS (#220: in the existing smoke job).
- `docs/proxmox-lxc.md`: Docker in an unprivileged container first, PHP 8.4
  natively second (#219); docs only, no script (#218). The owner runs it on a
  real host before the tag.
- Releases **v3.2.0** with Phase 35.1.

→ [`phase-35.2.md`](docs/phases/phase-35.2.md)

---

## Phase 36.1 — Email server settings (admin)
*The server's email, set up in the app by the person who runs it.*

- Settings → Delivery → *Email server*, admins only: server, port,
  encryption, username, password, From address and name, and the default
  recipient for admins; *Send test email* with the typed values, unsaved.
- Settings are the only source: the `MAIL_*` variables are removed, with
  nothing imported (#223, #225).
- The password is a secret, sealed as AI secrets are, in a new
  `notification_secrets` table, never shown, redacted and never backed up
  (#224).
- One `MailerFactory` builds every mail transport.
- The Docker image writes a `SESSION_SECRET` to `/data` on a fresh volume
  (#222). OAuth 2 for SMTP is parked (#226).
- No release of its own; ships with Phase 36.4 as v3.3.0.

→ [`phase-36.1.md`](docs/phases/phase-36.1.md)

---

## Phase 36.2 — Personal notification channels
*Everyone chooses where their own reminders go, and sets it up themselves.*

- Settings → Account → Notifications: In-app, Email, ntfy, Gotify and a
  personal webhook, each a card from one definition, with a status, the
  last result and *Send test* (unsaved values, 5 per 10 minutes).
- The `NTFY_*` and `GOTIFY_*` variables are imported once into admins'
  own channels, then no longer read (#227, #247); `WEBHOOK_URL` stays as
  the server's webhook, deprecated (#228).
- An admin chooses where members' channels may send (default: the
  internet and your network, #229); every address is checked and pinned,
  link-local is always refused for members, redirects are never followed.
- A channel that fails 5 times in a row switches off and says so (#233);
  email never does.
- Members' secrets are sealed, never `env:`, never shown; admins see
  nothing of members' channels (#232).
- No release of its own; ships with Phase 36.4 as v3.3.0.

→ [`phase-36.2.md`](docs/phases/phase-36.2.md)

---

## Phase 36.3 — Telegram, Discord, Pushover, Mattermost and Slack
*Five more places a reminder can reach you.*

- Five personal channels on Account → Notifications, each set up with the
  user's own bot, application or webhook (#236), with a status and *Send
  test*; Slack with a bot token and a channel (#241).
- Messages fit each service's limits, cut at a line with "…and N more",
  and can't ping anyone or inject formatting; the monthly digest arrives
  quietly (#238).
- Tokens are checked on saving where the service allows (saved with a
  notice if it can't be reached, #261), never shown, never in an error.
- Telegram's *Find my chat* (#237); Mattermost's optional channel (#239);
  a third-party notice on the cards that send through someone else's
  servers (#240).
- Saved settings are re-checked against their kind's rules on every send
  (#258).
- No release of its own; ships with Phase 36.4 as v3.3.0 (#254).

→ [`phase-36.3.md`](docs/phases/phase-36.3.md)

---

## Phase 36.4 — What each channel receives, and quiet hours + v3.3 release
*Choose what reaches you where, and when it may.*

- A choice per channel of what it receives (due, overdue, digest, price
  alerts, and job failures for admins); quiet hours per person that hold
  a message until they end, with nothing queued: the first run after sends
  what still applies, one message per kind (#234, #250–#253).
- A job run skips a service that stopped answering (#264).
- Released as **v3.3.0** (Phases 36.1 to 36.4; #254).

→ [`phase-36.4.md`](docs/phases/phase-36.4.md)

---

## Phase 37 — Space between the Fuel prices providers + patch release
*The provider choices on Settings → Fuel prices sit apart like every other
list of choices in Settings.*

- The *Off*, *UK Fuel Finder* and *Sample prices (demo)* options get the
  same gap as Settings → Jobs → *How jobs run*, through the shared list
  wrapper rather than a one-off rule.
- Every other bordered list of choices checked for the same fault and
  fixed the same way.
- The Phase 36.4 reviews' fixes (#265–#269): 44 px chips with a tick, a
  demoted admin's held job failures cleared, the per-run breaker over the
  alert after a run, an all-ticked *Receives* saved as "all" (existing
  ones converted, #271), held failures sent by one run only.
- Releases **v3.3.1**.

→ [`phase-37.md`](docs/phases/phase-37.md)

---

## Phase 38 — Ask lives on Insights; the Ask page goes + v3.4 release
*One place for what Logbook has spotted and what you've asked it.*

- *Your questions* (the thread list, with *Delete* and *Delete all*) and
  the MCP *Drafts to review* move to the Insights page; each thread opens
  on its own page under Insights.
- The `/ask` page and its sidebar entry go; old `/ask` links redirect to
  the matching Insights page. Reverses #192 and #193.
- Open questions A–E (#272–#276: where *Your questions* sits, the top-bar button,
  thread page or inline, retention, *Show all*) decided 2026-10-07.
- Released as **v3.4.0**.

→ [`phase-38.md`](docs/phases/phase-38.md)

---

## Phase 39.1 — API reads and reminder actions
*Everything a vehicle's pages show, an automation can read.*

- Single-entry reads with `ETag`; maintenance and documents filters;
  schedules, valuations, ownership, history (vehicle and fleet); the four
  reports (costs, cost per distance, fuel, mileage) from the Reports
  page's services; tyre changes and sets; closed reminders; *Needs
  attention*; price alerts; every finance agreement.
- Reminder *done*, *dismiss* and *reopen*, safe to retry.
- Phase 39 planned as one, split in three (#281); open questions A–H and
  one found while starting (#281–#289) decided 2026-10-08. Ships with
  39.3 as **v3.5.0**.

→ [`phase-39.1.md`](docs/phases/phase-39.1.md)

---

## Phase 39.2 — API writes, edit and delete
*What the pages let you write, correct or remove, a key can too.*

- `PATCH` (partial) and `DELETE` for every entry under `EntryAccess`, with
  optional `If-Match` (412 on a stale tag).
- Vehicles (create, edit, archive, restore), valuations, schedules, tyre
  changes and tyres, journeys, station favourites, price alerts,
  attention hiding, manual reminders, and finance (agreements, payments,
  quotes, *End*).
- Deleting a vehicle and account administration stay out. Ships with 39.3
  as **v3.5.0**.

→ [`phase-39.2.md`](docs/phases/phase-39.2.md)

---

## Phase 39.3 — API attachments and entry webhooks + v3.5 release
*Files in and out, and other systems hear when an entry changes.*

- Attachments: list, download (incident photos per #104), upload (one
  file per request), delete.
- Signed entry webhooks carrying ids and links only, sent by the
  scheduler with backoff, paused after 50 failures, under §7.11's
  destination rules; Settings → API keys → Webhooks; `WEBHOOKS_ENABLED`.
  Backed up without their secret.
- One migration. Release **v3.5.0** (Phases 39.1 to 39.3; the API stays
  `v1`).

→ [`phase-39.3.md`](docs/phases/phase-39.3.md)

---

## Phase 40.1 — Issues log
*The fault you've noticed and haven't fixed yet.*

- Issues with a date, mileage, description, files and status (*open*,
  *watching*, *fixed*), an owner-set *Affects safety* and updates over
  time; an issue's mileage joins the mileage log.
- A service record fixes one or more issues, from either side, or an
  issue is fixed without one; open issues, and watching ones past their
  look-again point, appear in *Needs attention*, and the look-again point
  raises a reminder.
- An Issues tab and overview card, History, print and the sale pack's
  *Include open issues*; backups and `bin/export-user.php`. No AI diagnosis, in any form (decided
  2026-10-08).
- Phase 40 planned as one, split in two (#318); open questions A–F and
  six found while starting (#307–#318) decided 2026-10-08. Ships with
  40.2 as **v3.6.0**.

→ [`phase-40.1.md`](docs/phases/phase-40.1.md)

---

## Phase 40.2 — Issues everywhere + v3.6 release
*Recommended work becomes issues in one tap, and issues go everywhere
entries go.*

- The recommended-work card offers *Add as issue*, *Watch* and *Add all
  as issues*.
- Issues in the API (with edit, delete, attachments and `issue`
  webhooks), Ask (a read tool, a draft tool, and a system line that never
  suggests a cause), MCP and CSV. Release v3.6.0.

→ [`phase-40.2.md`](docs/phases/phase-40.2.md)

---

## Phase 41 — DVSA MOT history + release
*Past MOT tests, their mileages and advisories, from the official UK
record.*

- Reopens #7. Settings → *MOT history* for admins, off until enabled,
  behind a provider interface like Fuel Finder's; only the registration
  (or VIN) leaves the server, for vehicles whose owner fetches.
- Per vehicle *Fetch MOT history* and *Refresh*: every test and its
  defects stored; test mileages join the mileage log and its plausibility
  checks; passed tests can become inspection documents and fill *First
  MOT due*.
- Advisories and defects offered as Phase 40.1 issues; the recall state
  and its *Needs attention* item; *Look up* on the add-vehicle form; a
  weekly refresh around each MOT with a keep-alive; History, the sale
  pack, Ask, MCP, API, CSV and backups. Individuals can get credentials
  (#320). Open questions #320–#345 decided 2026-10-08/09; #346–#350,
  from the merge review, left open. Released as
  **v3.7.0**.

→ [`phase-41.md`](docs/phases/phase-41.md)

---

## Phase 41.6 — MOT history follow-ups + patch release
*The small things Phase 41's merge review found, and the edge cases it
couldn't prove.*

- The LOW findings: Settings → MOT history at 375 px (the *Test* button,
  the *Sends* hint's icon); History counting defects instead of loading
  them; *Add all as issues* reading once per call, not per defect (330
  queries → 213 for 15 tests and 30 defects, 180 of them the issues'
  own creates); one status word in the phase file and the log.
- The unconfirmed edge cases, each proved and fixed or closed with a
  test: a registration in the stored error, defects reordered by DVSA,
  an "advised again" note dated too early. Open questions #346–#349 stay
  with the owner. Release v3.7.1.

→ [`phase-41.6.md`](docs/phases/phase-41.6.md)

---

## Phase 41.7 — Dashboard and overview query batching + patch release
*The dashboard reads each vehicle once, not once per widget.*

- The HIGH finding, already on master: 813 queries (9.4 s) on the
  dashboard and 344 on an overview for a 10-vehicle household, from
  per-vehicle reads repeated inside loops.
- Measure, batch the repeated reads across vehicles, and hold it with
  query-count tests that don't grow with the number of vehicles; nothing
  shown changes. Page budgets in the spec are #350. Patch release.

→ [`phase-41.7.md`](docs/phases/phase-41.7.md)

---

## Phase 42 — Fuel saving and economy up as computed insights + release
*Sums done by Logbook; the model keeps only what no single service can
see.*

- **Fuel saving:** yearly volume × (usual station's price − cheapest
  nearby effective price), from figures *Cheapest near me* already has.
- **Economy up:** Phase 25's drift check judged for an improvement.
- AI insights no longer asked for these or to work out any figure, and
  kept from repeating a computed insight; an Ask tool for the computed
  insights. Partly replaces #174. Release.

→ [`phase-42.md`](docs/phases/phase-42.md)

---

## Phase 43 — The monthly briefing + release
*Last month in one message: what's due, how far you drove, what it cost,
and what Logbook spotted.*

- The monthly digest gains **Last month** (distance, spend and cost per
  distance per vehicle against the 12-month average, with a fleet line)
  and **Insights** (computed and the latest AI insights; no model call).
- Open issues in the attention section; sections ordered so a short
  channel keeps what matters most.
- A user choice of what the digest includes; delivery unchanged. Release.

→ [`phase-43.md`](docs/phases/phase-43.md)

---

## After 1.0

Considered for later, not part of the phases above (see [`spec.md`](spec.md)
§12):

- Server-side PDF: emailed or scheduled reports, and a one-file sale pack
  with the invoices merged in.
- Personal fuel-tank entity, VIN decode / registration lookup,
  OBD-II / vehicle-API mileage import (through the REST API, Phase 18.2).
- More imports, as further readers on Phase 31's app importer: Drivvo
  (CSV exports, whose headers and values are translated into the phone's
  language), Tesla (charging history and mileage, from the owner's data
  export or the Tesla API) and ABRP (A Better Routeplanner: driven trips,
  charging stops and consumption).

Not planned: automatic vehicle valuation from online services (third-party
lookups and paid APIs, against keeping data local) and generic depreciation
curves (invented figures beside real ones).

---

*This roadmap is a plan, not a promise — phases and priorities may shift. The
`phase-*.md` files and [`spec.md`](spec.md) are the working source of truth.*
