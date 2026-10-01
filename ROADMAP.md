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
| [26.5](docs/phases/phase-26.5.md) | MCP server + v2.9 release | 🚧 |

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

---

## After 1.0

Considered for later, not part of the phases above (see [`spec.md`](spec.md)
§12):

- Server-side PDF: emailed or scheduled reports, and a one-file sale pack
  with the invoices merged in.
- Personal fuel-tank entity, VIN decode / registration lookup,
  OBD-II / vehicle-API mileage import (through the REST API, Phase 18.2).

Not planned: automatic vehicle valuation from online services (third-party
lookups and paid APIs, against keeping data local) and generic depreciation
curves (invented figures beside real ones).

---

*This roadmap is a plan, not a promise — phases and priorities may shift. The
`phase-*.md` files and [`spec.md`](spec.md) are the working source of truth.*
