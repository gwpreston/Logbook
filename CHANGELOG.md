# Changelog

All notable changes to Logbook are recorded here. Database changes are always
shipped as reversible migrations; any upgrade step beyond "pull and restart"
is called out explicitly.

## [Unreleased]

## [2.1.0] — 2026-09-30

Phases 20, 21.1 and 21.2: small things that make everyday use smoother, and
a reminder for the one MOT nobody has paperwork for yet.

### Added
- **First MOT due** (Phase 21.2). A new car's first MOT is the one reminder
  nobody has a certificate for. The vehicle form has an optional *First MOT
  due* date under *First registered*. It is suggested from the first
  registration date by the region of your language setting: 3 years for
  Great Britain and Germany, 4 years for France, Ireland, Italy and Spain.
  The hint mentions that Northern Ireland tests at 4 years, and the date is
  always yours to change. With JS the date fills in as you type *First
  registered*. Without JS, adding a vehicle with the field blank sets the
  suggestion and says so. A suggestion that has already passed is never
  offered. The date drives a **First MOT** reminder on your document lead
  time. It is sent, put in the digest and in the calendar feed like any
  other reminder, and shows in *Coming up*, on the overview, on the
  Documents tab and on the sale pack's *Inspection* line. Logging the first
  MOT certificate marks it done, and from then on the certificate's expiry
  drives MOT reminders, as before. After that the field shows as read-only
  text.
- **Drag and drop files** (Phase 21.1) onto every file input: attachments,
  the vehicle photo, purchase and sale paperwork, CSV import and backup
  restore. Dropped files are added to what you already chose, with a list
  to remove them from, the same type, size and count limits before
  submitting, and announcements for screen readers. Without JS it is the
  plain file input, as before.
- **Sale pack cover page** (Phase 21.1): *Include the vehicle photo* (off by
  default) prints a cover page with the photo before the summary. The photo
  is never in the paperwork ZIP.
- A hint on purchase paperwork to keep the registration certificate (V5C) as
  a *Registration* document instead.
- API: `first_inspection_due_on` on the vehicle, and `first_inspection` as a
  source of *Coming up* items (`GET /upcoming`, the summary's
  `next_due`) and of reminders. A client that switches on `source` should
  expect the new value.

### Changed
- **Tyre forms open in a modal on desktop** (Phase 21.1): editing a tyre, a
  tyre change and a set, and their delete confirmations, like every other
  entry form. Each is still its own page for phones, deep links and JS off.
- **The monthly digest is on for new users** (Phase 21.1): accounts created
  by first-run setup or an invitation get it on. It is still only sent when
  a channel is set up and something is due.
- **Phase files moved to `docs/phases/`** (Phase 20), beside a log of every
  phase's open questions and what was decided
  ([docs/phases/open-questions.md](docs/phases/open-questions.md)). The test
  suite now checks that every link in the repository's Markdown resolves.
  No change to the app.

### Upgrade notes
- **One migration** adds the empty `vehicles.first_inspection_due_on`
  column. It rolls back cleanly on every engine. Rolling back also deletes
  the *First MOT* reminders and the prompt settings.
- **Existing users keep their digest choice.** Nobody who upgraded starts
  getting a monthly email they didn't turn on.
- **Existing vehicles get no date.** Nothing is guessed. Instead, each
  vehicle with *First registered* set, no MOT certificate and a suggestion
  still to come shows a one-time card on its overview: "Set a reminder for
  the first MOT? Suggested: 14 Jun 2027", with *Set it* and *Not needed*.
  Either answer, or saving the vehicle's edit form, puts it away for good.

## [2.0.0] — 2026-09-30

Phase 19: multiple users and vehicle sharing. A household on one install:
everyone has their own account, vehicles, units, language and reminders,
and a vehicle can be shared at a level its owner chooses. Your partner can
log fill-ups on the Mini without seeing its running costs or being able to
delete its service history. Guide: [docs/users-and-sharing.md](docs/users-and-sharing.md).

**Read the upgrade notes below before upgrading.** Nothing you use today
changes, but backups, notifications and what an install can hold do, so
this is a major version.

### Added
- **Users.** Admins and members. **Settings → Users** (admins) lists
  everyone with their role, last sign-in and status, and can *invite*
  someone (a one-time link shown once, valid for 7 days, which can also be
  emailed to an address that is not kept), *make* or *remove* an admin,
  *disable* (signed out everywhere, keys and calendar feed stop at once) and
  *enable*, *reset a password* (a one-time link), *revoke* open links, and
  *delete* (refused while they own vehicles, which an admin can transfer
  from the same page). There is always an active admin. Opening an
  invitation asks for a password, units, currency, language and time zone
  and signs the new user in.
- **Sharing.** On a vehicle, **Sharing** adds people by username at
  **View**, **Log** (also add entries and change their own) or **Manage**
  (also edit the vehicle and every entry, schedules, valuations, import,
  export, the sale pack), with **Can see costs** (always on for Manage) and
  **Send them its reminders**. The owner can also **transfer** the vehicle,
  keeping Manage access unless they untick it; anyone with a share can
  **leave**. Shared vehicles appear under **Shared with you** in the garage,
  naming the owner and your level, and join your dashboard, History,
  Reports and *Coming up*.
- **Who added what:** every fill-up, reading, service record, document,
  expense, tyre change, valuation and file records who added it; once a
  vehicle is shared, lists and history say **Added by …** (a deleted user's
  entries show as *a former user*).
- **Your own amounts:** someone who may not see a vehicle's costs still sees
  the amounts of the entries they added, on the pages and in the API, and
  nothing else. Fleet figures say how many vehicles they leave out
  ("Excludes 1 vehicle shared without costs").
- **Reminders per person:** a vehicle's reminders go to its owner and to
  those it is shared with who asked for them, each in their own language,
  units and time zone, once per status. Settings → Reminders takes your own
  **ntfy topic URL** and **Gotify token** as well as your email address.
  The webhook's payload names the `user`.
- **`php bin/export-user.php <username>`**: a backup-format ZIP of one
  user's vehicles and settings, which restores as an install of their own.
- Sample data (`bin/dev-setup.sh --with-sample-data`) has a second user,
  `partner` / `logbook-demo`, with Log access to the self-charging hybrid
  (without costs, logging its fill-ups) and View access to the Golf.

### Changed
- Entry edit and delete pages are open to Log access for one's own entries;
  others' entries show without edit links. CSV export and import need
  Manage. The Expenses tab is open to anyone who can see the vehicle, with
  the amounts hidden from those who may not see its costs.
- Buttons and links on a vehicle's pages show only what you may do.
- A shared vehicle's reminders are always judged by its owner's lead times
  and time zone, and its money stays in the owner's currency.
- The calendar feed and the monthly digest cover your own vehicles and those
  you asked to be reminded about.
- `MAIL_TO`, and the instance's ntfy topic and Gotify token, are the
  **admins'** defaults only; members receive email, ntfy and Gotify at their
  own address, topic or token.

### Upgrade notes
- **Take a backup first** (Settings → Backup, or `php bin/backup.php
  create`).
- **One migration**, applied on start in Docker or with
  `vendor/bin/phinx migrate -e production` on bare PHP. It adds the admin
  and disabled flags, the shares, invitation links, reminder deliveries and
  who added each entry.
- **Your existing account becomes an admin** and is named as the author of
  everything already there. What 1.x already notified is recorded as sent,
  so nothing is sent again. Nothing else changes until you invite someone.
- **`MAIL_TO` is now the admins' default only.** With one account (an
  admin) nothing changes. Members you invite need their own address (or
  ntfy topic, or Gotify token) in Settings → Reminders.
- **Rolling back is refused once a second user exists**, with a message
  naming `php bin/export-user.php`: export and delete the others first.
  With one user it rolls back cleanly.
- **2.0.0 backups do not restore into 1.x** (the schema version moves).
  Invitation links are not in backups.
- `SESSION_SECRET` also keys invitation links: changing it disables open
  ones.
- **Custom notification channels** need one new method,
  `reaches(Recipient $recipient): bool` (can it deliver to this person?);
  see [docs/notification-channels.md](docs/notification-channels.md).

## [1.10.0] — 2026-09-30

Phases 18.1 and 18.2: one access policy, and a REST API. Home Assistant,
Apple Shortcuts, Android automations, Grafana, Node-RED and OBD tools can
read your garage and log fill-ups and odometer readings with an API key.

### Added
- **REST API** under `/api/v1` ([docs/api.md](docs/api.md)), described by
  an OpenAPI 3.1 file your install serves at `/api/v1/openapi.json`.
  - **Read:** vehicles, a per-vehicle summary for sensors (odometer, economy,
    last fill-up, 12-month cost per mile or km, what is due next, reminder
    counts, documents, tyres, and the same figures as text in your units),
    fill-ups with the economy of each tank and its economy-check flag,
    odometer readings, service records, documents, expenses, tyres, *Coming
    up* and reminders. Lists are newest first, paged, and filtered by date.
  - **Write:** log a fill-up (any two of volume, price and total, in litres
    or UK or US gallons, miles or km) and add an odometer reading, checked
    exactly as the forms check them, with the plausibility and economy
    warnings returned. Retrying the same fill-up (same time and odometer)
    returns the existing one instead of doubling it.
  - Values are canonical (km, litres, L/100 km, the vehicle's currency) as
    exact decimal strings, instants in UTC, errors as problem details with a
    stable code, and amounts left out for anyone who may not see costs.
- **API keys:** Settings → API keys creates a named key that can read, or
  read and log; it is shown once, only its hash is stored, it can be
  revoked at once, and the list shows when each was last used. A key sees
  exactly what its user sees. `bin/api-key.php` creates, lists and revokes
  keys on the command line. 20 failed keys from one address in 10 minutes
  block it for 10 minutes.
- Guides for a Home Assistant REST sensor, an Apple Shortcut that logs a
  fill-up, a Grafana economy chart and a Node-RED flow.
- `API_ENABLED` (default `true`) and `API_CORS_ORIGINS` (default none).

### Changed
- **Access policy:** who may do what with a vehicle (view, see costs, log,
  manage, own) and with the install (modules, backup, restore) is decided in
  one place. Every `/vehicles/{id}` and `/reminders/{id}` route declares what
  it needs; a vehicle you cannot see answers 404, one you can see but not
  change this way a 403 page that says so. The garage, dashboard, History,
  *Coming up*, Reports, reminders, the calendar feed and the scheduler all
  take their vehicles from it.
- Every amount shown for a vehicle sits behind a cost-visibility check, and
  Reports, the Ownership report and *Coming up* count only vehicles whose
  costs can be seen, so a later policy can hide costs without touching the
  pages again.
- A test fails the build for any route that does not say what access it
  needs, and another for any amount a template shows outside a cost check.
- The CSV import's duplicate rule is shared with the API's safe retries.
- The restore page says when a backup holds API keys, which keep working
  only with the same `SESSION_SECRET`.

### Upgrade notes
- **One migration** (the `api_keys` table), applied on start in Docker or
  with `vendor/bin/phinx migrate -e production` on bare PHP. It rolls back
  cleanly.
- **New optional variables:** `API_ENABLED` (the API is on by default; every
  call needs a key, so nothing is reachable until you create one; set
  `false` to switch it off entirely) and `API_CORS_ORIGINS`.
- **`SESSION_SECRET`** now also keys the API key hashes: changing it (or
  restoring a backup into an install with another one) disables every API
  key, as it already signed everyone out and disabled calendar feed links.
- Apache with **PHP-FPM**: the `Authorization` header must reach PHP.
  `public/.htaccess` hands it over; a hand-written vhost without it needs
  `CGIPassAuth On` ([docs/deployment.md](docs/deployment.md#the-rest-api-behind-a-proxy)).
  The Docker image needs nothing.

## [1.9.0] — 2026-09-29

Phases 17.1 and 17.2: the sale pack, and printable reports. A buyer's view
of the car with its paperwork, and a clean paper or PDF copy of every
report, without a PDF library.

### Added
- **Printable reports:** a *Print* button on Reports (fleet or one
  vehicle), the Ownership report, *Coming up* (fleet or one vehicle), the
  Fuel tab and the Mileage tab; the browser's *Save as PDF* makes the PDF.
  Each printout starts with a header saying what it is, for which vehicle
  (name and registration) or all vehicles, the period (the report's range,
  *Coming up*'s months, or the first to the latest record), your units and
  the date printed. The filters, chips, tabs and buttons stay off the
  paper; their choices are in the header.
- **Charts on paper** are drawn again in black and grey, their series told
  apart by solid, dashed and dotted lines, point shapes, and solid, striped,
  dotted and cross-hatched bars, so none depends on colour. Each is sized to
  the page and printed with its table: the economy, price and mileage
  charts gain one on paper, listing every point.
- Printouts are black on white in the dark theme and with any accent;
  cards and table rows are not split across pages, table headers repeat
  on each page and totals print once at the end; wide tables print
  smaller instead of being cut off. On the Fuel tab both the economy and
  the cost per mile or km trends print, and economy check flags print as
  their words.
- The sale pack's mileage chart now fills the page width when printed, at
  the same proportions as the report charts, instead of keeping its screen
  width and printing squashed.
- **Prepare for sale** on every vehicle (and *Sale pack* in the History
  toolbar): a buyer's view to print or save as a PDF. A summary page (what
  the car is, how long you have owned it and how far it has gone since, the
  current mileage, servicing, when the MOT runs out, the tyres, what is due
  next, how much paperwork there is); a mileage record of readings a buyer
  can check (services, inspections, tyre changes and dashboard photos,
  never fill-ups) with where each came from and a chart; and the history
  grouped as service and repairs, inspections and certificates, and tyres,
  with the full timeline as an option. UK owners get a pointer to
  gov.uk/check-mot-history; nothing is fetched.
- **The paperwork as a ZIP:** service invoices, MOT certificates and
  dashboard photos by default, purchase paperwork and insurance if you tick
  them, each file readably named ("2024-03-12 Service - Kwik Fit.pdf") with
  a `contents.txt`. *Choose files* drops single files, with or without
  JavaScript. Registration documents, sale paperwork, valuations, fill-ups
  and expenses are never offered. The ZIP is streamed from your uploads with
  no temporary copy and needs no PHP extension.
- A notice (never printed) when a reading in the mileage record looks
  wrong, with a link to the entry.
- Purchase and sale prices, fuel, expenses, valuations and ownership costs
  are never in the pack; *Show the cost of work* adds the cost of work only.
- The demo Golf has a service history back to its purchase, invoices,
  both MOT certificates and a dashboard photo from the day it was bought.
  Its brake pads record no longer reads below the fill-up before it.
- [docs/sale-pack.md](docs/sale-pack.md).

### Upgrading
- Pull and restart. No migrations, no new configuration, and the backup
  format is unchanged.

## [1.8.0] — 2026-09-29

Phase 16: fuel insights. Is the dearer fuel worth it, what does a mile
really cost, and how much does winter take?

### Added
- **Grade verdict** on the *By grade* card: for each petrol or diesel grade
  with enough data, how much more or less it costs per mile or km than the
  grade you use most (by volume over the last 12 months), for example
  "E5 98 costs about 10% more per mile than E10 95 — 7% more per litre,
  3% more fuel used". The price difference comes only from fill-ups of the
  two grades bought within 30 days of each other (the median of those
  pairs, at least three), never from all-time averages, which mostly
  measure fuel prices changing. The economy part is the existing
  *Economy by grade*. The card says what the verdict rests on, and says
  exactly what is missing when there is not enough data.
- **Cost per mile or km for each charging type** in the charging card:
  each type's cost per kWh × the car's average consumption.
- **Cost per distance trend:** the *Economy trend* card switches between
  *Economy* and *Cost per mile/km* (the fuel used in each tank, costed at
  the price of that fuel, plus a running average). The switch is plain
  links (`?trend=cost`) that work without JavaScript, survive a refresh
  and respect `APP_BASE_PATH`; without JavaScript the points are a table.
- **Economy by month:** a table of months by year (the last five) with an
  average weighted across every year, and a chart of the average with this
  year and last as lines. Each full-to-full stretch is shared between the
  months it spans by time, in your time zone; months with under 200 km of
  driving show "—", and stretches over 92 days are left out. A plug-in
  hybrid gets one card for fuel and one for charging.
- The demo data's Golf now alternates E10 and E5 97 and uses a little more
  fuel in winter, so it shows a verdict and a seasonal dip.

### Changed
- Nothing that was already shown changes value: average economy, the
  headline fuel cost per distance, the economy trend, economy checks,
  reports and *Coming up* are all as before.

### Upgrade notes
- No migrations and no configuration changes. Nothing new is stored and the
  backup format is unchanged; a 1.7.0 backup restores into 1.8.0 (and the
  other way round).

## [1.7.0] — 2026-09-29

Phase 15: *Coming up*, the next 12 months of maintenance, renewals and
fuel.

### Added
- **Coming up** (`/upcoming`): everything the app already knows is due in
  the next 12 months (this month and the 11 after), per vehicle and for the
  fleet. Services from your maintenance schedules, repeated as often as they
  fall due in the year (a 6-monthly service shows twice, whichever of the
  months or the mileage comes first each time); document renewals, repeated
  at the document's own term; tyres wearing out or reaching your age limit;
  and your own reminders. Overdue work is listed first, once; a service due
  at a mileage that cannot be placed on the calendar yet is listed under
  *Date not known yet*.
- Each item shows what it cost **last time**, from your own records: the
  service record that last completed the schedule, the current document's
  price, or the tyres' share of the service record that fitted them. When
  there is no such record the cost is shown as unknown and counted, never
  guessed.
- A **fuel estimate** per vehicle and month: your average daily distance
  times the last 12 months' fuel cost per mile or km (it needs 90 days of
  fill-ups). A plug-in hybrid's petrol and electricity are covered together.
- Month-by-month and 12-month totals per currency (never converted):
  planned, fuel and the two together, "at least" while some item's cost is
  unknown. A stacked bar chart (a table without JavaScript), the dashboard's
  vehicle chips and a CSV export.
- A *Coming up* card on each vehicle's overview and a *Coming up* dashboard
  widget, each with the next five items and the 12-month total.
- It reads the same due dates your reminders use, but not the reminders
  themselves: it works with the Reminders module off, a dismissed reminder's
  service still appears, and viewing it never changes a reminder or sends a
  notification. A switched-off module's items leave it.

### Upgrade notes
- No migrations and no configuration changes. Nothing new is stored and the
  backup format is unchanged; the database schema is the same as 1.6.0's,
  so a backup restores into either version.
- The new dashboard widget is added to saved layouts at the end; move or
  hide it under *Customise*. Without a saved layout it comes second, after
  *Upcoming reminders*.

## [1.6.0] — 2026-09-29

Phases 14.1 and 14.2: valuations, depreciation and the total cost of
ownership.

### Added
- **Cost of ownership** (Phase 14.2): what each vehicle has really cost
  since you bought it, on a new overview card beside *Ownership*. Running
  costs (every ledger line since the purchase date, by group) plus
  depreciation, as a total and per mile or km and per month. Each part is
  worked out over its own period and the two are added: running costs to
  today, depreciation to the date of the latest value, and the total says
  so ("depreciation to 1 Mar 2026"). A sold vehicle's figures run to the
  sale date and are exact ("Lifetime, sold 12 Mar 2026").
- Nothing is shown as complete when a part is missing. Without a purchase
  price or a value the card is titled *Running costs since …* and has no
  total. Rates that lack their depreciation part are marked "running costs
  only". Per distance needs the mileage log to reach back to the start of
  ownership, and the card says when it doesn't. There are no rates for the
  first 90 days or before any cost is logged.
- **Reports → Cost of ownership** (`/reports/ownership`): every vehicle
  side by side, grouped by currency (never converted), with a fleet row,
  the reports' vehicle and *include archived* filters, and a CSV export.
  Part of the Reports module; the overview card stays when it is off.
- **Finance and lease**, a new expense category for loan interest, lease
  and PCP payments. The form reminds you not to log the payments that pay
  off a purchase price you have already entered. CSV import accepts it by
  code or label.
- Demo data: the EV is leased (monthly payments, no purchase price) and the
  sold Fiesta has nine years of services, road tax and mileage, so it shows
  exact lifetime figures.
- **Valuations.** A small log of what each vehicle is worth (a dealer's
  part-exchange offer, an online valuation, an insurer's figure) at
  *Valuations* on the overview's *Ownership* card, with a screenshot or PDF
  attached. Nothing is fetched from an online service: a value is always
  one someone quoted.
- **Depreciation** on the overview's *Ownership* card: what the vehicle has
  lost (or gained) from the purchase price to the latest valuation, or to
  the sale price once sold, as an amount and a percentage, per year and per
  mile or km. Both rates are measured to the value's own date, not today, and
  per distance appears only when the mileage log reaches back to the
  purchase. Nothing is extrapolated; a valuation more than a year old says
  so. With two or more values, a small value-over-time chart (a table
  without JavaScript).
- Valuations appear in History (under *Everything*) and *Recent activity*
  as "Valued at £9,800", never in the amount column, and **never in the
  print view**: a service history handed to a buyer does not carry your own
  valuations. They are not costs and stay out of expenses and reports.
- Valuations export to CSV (Date, Amount, Currency, Source, Notes) and are
  included in backups with their files.

### Changed
- The overview's *Currency* and *Added* rows moved from *Ownership* to
  *Details*. The *Ownership* card now leaves out rows that are not set and
  is hidden until the vehicle has a purchase, a sale or a valuation.

### Unchanged, on purpose
- Every existing figure: costs, reports, economy and mileage are as they
  were. The dashboard's *Running cost* tile (last 12 months) and cost of
  ownership (since bought) answer different questions, and both stay.
- Depreciation per distance uses the same "mileage reaches back to the
  purchase" rule as before, now shared with cost of ownership.

### Upgrade notes
- One migration: a new table `vehicle_valuations`, whose files use a new
  attachment owner type, `valuation`. It runs automatically on start (Docker) or
  with `vendor/bin/phinx migrate` (bare PHP). Nothing in existing data
  changes.
- The *Finance and lease* expense category is a new code (`finance`) in an
  existing column: no migration.
- No configuration changes.
- Backups record the database schema, so a backup made with 1.5.x cannot be
  restored into this version (restore it with its own version first, then
  upgrade), and a backup from this version cannot be restored into 1.5.x.
- **Going back to 1.5.0:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261009100000`. The
  valuations and their attachment rows are removed (the files stay under
  `UPLOAD_PATH`); everything else is unchanged.

## [1.5.0] — 2026-09-29

Phase 13: economy checks.

### Added
- **Economy checks.** Each full-to-full tank (or charge) of at least 100 km
  is compared with the median of the vehicle's previous ten, from its sixth
  on, in fuel used per distance, so mpg UK, mpg US, L/100 km and km/L all
  agree. A tank that used at least 25% more or 20% less than usual (35% more
  or 26% less for charging, which swings more with the seasons) is flagged
  with the likely cause and links to the fill-ups to check. Most flags are
  typing mistakes, so the hints send you to the data first.
- **A mistyped reading shows as a pair.** One odometer typed too high makes
  one tank look thrifty and the next thirsty; both flags name the fill-up
  they share and say when the two tanks are normal taken together.
- **Looks right** confirms a genuine one (a winter trip with a roof box) and
  keeps it quiet until that tank's figures change; *Undo* brings the flag
  back. A plain form: works without JavaScript.
- Where flags show: beside the economy on the Fuel tab, with "N fill-ups to
  check" in the summary and a *To check* list (`?check=1`); in the notice
  after saving a fill-up; above the fill-up's edit form; beside the economy
  on the dashboard's *Recent fuel*; and as a count after a CSV import of
  fill-ups ("3 imported fill-ups look unusual").
- The demo Golf has a mistyped odometer and a confirmed thirsty January tank.
- German translations for all of the above.

### Unchanged, on purpose
- Every average, trend, cost per distance and report figure is exactly as
  before: flagged tanks still count until you correct them. No notification
  or reminder is ever sent for a flag. Flags never appear in History, the
  print view, reports or the garage.

### Upgrade notes
- One migration: a nullable column `fuel_entries.economy_confirmed`, empty
  for every existing fill-up. It runs automatically on start (Docker) or
  with `vendor/bin/phinx migrate` (bare PHP). Every existing figure is
  unchanged.
- No configuration changes.
- Backups record the database schema, so a backup made with 1.4.x cannot
  be restored into this version (restore it with its own version first,
  then upgrade), and a 1.5.0 backup cannot be restored into 1.4.x.
- **Going back to 1.4.0:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261008100000`. Only the
  *Looks right* confirmations are lost.

## [1.4.0] — 2026-09-29

Phase 12: buyer-first print, ownership paperwork and a dated starting
mileage.

### Added
- **Purchase and sale paperwork.** The vehicle form (add and edit, page and
  modal) takes files under the purchase fields and under the sale fields:
  the purchase invoice, the sale receipt, a V5C slip. They show as a
  paperclip on the *Bought* and *Sold* rows in History and the fleet
  history, beside the dates on the overview's *Ownership* card, and as file
  names under those rows in the print view. Up to 10 files per save for
  both together, all or nothing, as on every other form. The files need
  their date: adding sale paperwork without a sale date is refused, and so
  is clearing a date while files are attached. Archiving keeps them;
  deleting the vehicle deletes them.
- **As of** beside *Current odometer* when adding a vehicle, defaulting to
  today: the date the figure was read (on the MOT certificate, at the
  sale). An earlier date writes the reading at local noon on that day, so it
  sits in order with fill-ups logged before and after it. A date before the
  first registration is saved with a warning.
- German translations for all of the above.

### Changed
- **The print view hides costs by default.** *Show costs* starts unticked,
  so the printout is the copy you can hand to a buyer; purchase and sale
  prices are hidden with the costs. Tick it for your own copy. An old link
  never shows costs it used to hide.
- **Average per year since first registered** is now measured to the date
  of the latest reading, not to today, so a starting reading dated months
  back, or a vehicle that has not been driven for a while, is no longer
  understated. The figure changes for vehicles whose latest reading is not
  recent. *Age* is still measured to today.
- The overview no longer lists the latest fill-ups: *Recent history* lists
  them with everything else, the Fuel tab lists them all, and the economy,
  cost per distance and spend figures stay at the top of the overview. Its
  *Add fill-up* and *Add reading* buttons went with the card; *Log entry*
  covers both.
- The demo data's sold Fiesta has a sale receipt.

### Upgrade notes
- One migration, which changes no column: attachments gain the owner types
  `purchase` and `sale` (the column already takes any short code). It
  exists to move the schema version, because 1.3.x cannot read those owner
  types. It runs automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). Nothing in existing data changes.
- No configuration changes.
- Backups record the database schema, so a backup made with 1.3.x cannot
  be restored into this version (restore it with its own version first,
  then upgrade), and a 1.4.0 backup cannot be restored into 1.3.x.
- **Going back to 1.3.0:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261007100000`. The
  purchase and sale files are unlinked (their files stay under
  `UPLOAD_PATH`); everything else is unchanged.

## [1.3.0] — 2026-09-29

Phases 11.1 and 11.2: tyres, then tread depth, wear and tyre reminders.

### Added
- **Tyres tab** on every vehicle, after Maintenance: the tyres on the
  vehicle (a card per position: brand, model, size, season, age and
  distance), those in storage grouped by set with where the set is kept,
  retired tyres with how far they went, and every tyre change.
- **Tyre changes:** *Tyres already on the vehicle* (to start with), *Fit
  tyres* (one description for a pair, a DOT code per tyre, and what happens
  to the ones they replace), *Swap set* (summers off into a set, winters
  on), *Rotate*, *Repair* and *Remove* (into storage or retired, with a
  reason). Cars have front left / right, rear left / right and a spare;
  motorbikes front and rear.
- **Age from the DOT code** (`2323` = week 23 of 2023) and **distance per
  tyre** from the mileage log, leaving out time as a spare or in storage.
  A retired tyre shows its lifetime distance and, when its fitting was
  costed, its cost per distance ("£4.90 per 1,000 mi").
- **Costs stay in maintenance:** a cost typed on a tyre form writes a
  `tyres` service record, or a change can be linked to one already logged.
  It is counted once, under maintenance, everywhere.
- Tyre changes in **History** (a *Tyres* chip), the **print view** (with a
  *Tyres fitted* header block) and *Recent activity*; a *Tyres* card on the
  overview; *Tyre change* in *Log entry*; a new mileage source *Tyres*.
- CSV export of tyres and of tyre changes; German translations.
- **Tread depth** in millimetres or 32nds of an inch (a new *Tread depth*
  unit beside the others in Settings; the US preset picks 32nds). Recorded
  when tyres are fitted (*Tread depth when new*), already on the vehicle,
  swapped or removed, and with a new **Check tread**: one depth per fitted
  tyre, from the Tyres tab or *Log entry*. A reading more than 0.5 mm
  deeper than the last is saved with a notice to check it.
- **Wear estimate** for every fitted tyre, from its own distance (time in
  storage or as the spare leaves it out): depth now, distance left to the
  replace-at depth and roughly when, always labelled as an estimate ("about
  3.4 mm now · about 6,000 mi left · around Mar 2027"). The overview's
  *Tyres* card shows each tyre's depth and the soonest distance left.
- **Settings → Tyres:** replace-at (and a winter replace-at for cars),
  legal minimum and an age limit from the DOT date (6 years by default;
  0 turns it off), for cars and motorbikes. Flags say *Below the legal
  minimum* when a measured depth is, and *May be below the legal minimum —
  check it* when only the estimate is.
- **One tyre reminder per vehicle** for worn or ageing tyres ("Tyres: front
  left and front right worn", "Tyres: rear due in about 800 mi"), due
  within the service lead time and distance, through every channel, the
  digest and the calendar feed. Daily driving updates it quietly; a new
  check, fit or swap opens it again. The Tyres tab shows the same verdict
  as a badge, reminders module or not.
- Depths in tyre history ("Checked tread: 5.1–6.3 mm"), the print view's
  *Tyres fitted* block (measured depths only: estimates are never printed)
  and both tyre CSV exports.

### Upgrade notes
- **Tyres (11.1):** four new tables (`tyre_sets`, `tyres`, `tyre_changes`,
  `tyre_change_lines`), a new nullable column
  `odometer_readings.tyre_change_id` and a new reading source `tyre`.
- **Tread depth (11.2):** a new column `users.depth_unit` and a nullable
  `tyre_change_lines.tread_mm`. Owners whose fuel volume unit is US gallons
  are set to 32nds of an inch; everyone else to millimetres. New tyre
  change kind `check` and reminder source `tyre`.
- The migrations run automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). Nothing in existing data changes,
  and every existing figure is unchanged.
- New optional `FEATURES_TYRES` (default on), like the other modules; see
  `.env.example` and `docs/configuration.md`.
- Backups record the database schema, so a backup made with 1.2.x cannot
  be restored into this version: restore it with its own version first,
  then upgrade. (Every release that adds a column moves the schema
  version.)
- **Going back to 1.2.1:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261005100000`. Tread
  checks' readings and every tyre reading become ordinary manual readings,
  so no mileage is lost; tread checks, tyre reminders, the tyre thresholds
  and the tyre tables are removed.

## [1.2.1] — 2026-09-29

Phase 10.2: tall vehicle photos.

### Fixed
- A tall (portrait) vehicle photo no longer stretches the dashboard's pinned
  vehicle card on wide screens. The card is as tall as its content and the
  photo is cropped to fit, as a landscape photo already was.

### Upgrade notes
- None: no migrations, no configuration changes and no change to the backup
  format. Stored photos are untouched.

## [1.2.0] — 2026-09-28

Phase 10: vehicle history, multiple attachments and the document odometer.

### Added
- **History tab** on every vehicle, second after Overview: fill-ups,
  service records, documents, expenses and odometer readings in one list,
  newest first, one year per page with month headings and *Newer* /
  *Older* links. The vehicle's own milestones — *First registered*,
  *Bought* and *Sold* — bookend it. Back-to-back fill-ups fold into one row
  ("4 fill-ups · 2 Sep – 17 Sep · £284.10") that opens to show them. Chips
  narrow it to *Service*, *Fuel*, *Documents*, *Expenses* or *Mileage*.
  Every row opens its entry, and saving brings you back to the same page.
- **Fleet history** (`/history`), reached from *View all* on the
  dashboard's *Recent activity*: the same list across every active vehicle,
  with the dashboard's vehicle chips.
- **Print view** of a vehicle's history, for printing or *Save as PDF*: a
  service history to hand to a buyer, with the vehicle's details, every
  entry and the names of its files. Choose what to include (everything but
  fuel by default) and whether to show costs. It prints black on white in
  either theme.
- *Recent history* on the vehicle overview (the latest five, *Full
  history →*).
- **Several files at once** on every attachment input (up to 10 per save,
  each up to `MAX_UPLOAD_MB`). One bad file saves nothing and the message
  names it.
- **Attachments on expenses and manual odometer readings** (a parking
  receipt, a penalty notice, a photo of the dashboard).
- A **paperclip with the number of files** on the Fuel, Maintenance,
  Mileage and Expenses lists, *Recent activity* and History.
- **Odometer on documents**: the reading an MOT certificate shows. It joins
  the mileage log (source *Document*, at noon on the document's start date,
  which it needs), moves and goes with the document, and is in the
  documents CSV export and import.
- German translations for everything new (*Verlauf*, *Fahrzeughistorie*,
  *Gekauft*, *Verkauft*, *# Tankvorgänge*, *# Ladevorgänge*, …).

### Changed
- *Recent activity* reads the same feed as History: the same entries in
  the same order, plus paperclips; an EV charge is named *Charge*.
- Paperclip counts read "2 files" rather than "2 attachments".

### Upgrade notes
- Two new nullable columns, `compliance_documents.odometer_km` and
  `odometer_readings.compliance_document_id`, and a new reading source
  `document`. The migration runs automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). Existing data and every existing
  figure are unchanged.
- **Bare PHP:** a save now sends up to 10 files. Check `max_file_uploads`
  (at least 10; PHP's default is 20) and that `post_max_size` allows
  10 × `MAX_UPLOAD_MB` (100 MB by default). The Docker image sets both.
- Backups record the database schema, so a backup made with 1.1.0 cannot be
  restored into this version: restore it with 1.1.0 first, then upgrade.
- **Going back to 1.1.0:** roll back first, while still on 1.2.0, with
  `vendor/bin/phinx rollback -e production -t 20261004100000`. Document
  readings become ordinary manual readings, so no mileage is lost. Files
  attached to expenses and readings are unlinked (1.1.0 cannot show them;
  the files stay under `UPLOAD_PATH`): download any you need first.
- No configuration changes.

## [1.1.0] — 2026-09-28

Phase 9.1 (vehicle details) and Phase 9.2 (plug-in hybrids).

### Added — Phase 9.1: vehicle details
- **Variant / trim** on each vehicle ("1.5 EcoBoost ST-Line X", "xDrive30d M
  Sport"): free text, shown after year, make and model on the garage cards,
  the vehicle header, the dashboard's vehicle tiles and pinned vehicle card,
  the delete page and the vehicle pickers. Long variants are cut short with
  "…" where space is tight; hover or long-press shows the whole line.
- **First registered**: the date on the registration document (V5C /
  logbook). The vehicle's overview shows it with the vehicle's age
  ("7 yrs 6 mo"), and the Mileage tab adds the *average per year since
  first registered*. A model year more than a year after the registration
  year is saved with a warning to check both.
- **Current odometer** when adding a vehicle: fill it in and the vehicle
  starts with a mileage reading, so the garage card, the dashboard and the
  Mileage tab have a figure straight away instead of "—" until the first
  fill-up. It is an ordinary reading on the Mileage tab, edited there if it
  was wrong. The edit form shows the current reading with an *Add reading*
  link.
- German translations for everything new (*Variante / Ausstattung*,
  *Erstzulassung*, *Aktueller Kilometerstand*, …).

### Added — Phase 9.2: plug-in hybrids
- **Hybrid** and **Plug-in hybrid** are now two fuel types. *Hybrid* is a
  self-charging or mild hybrid that fills with petrol only; *Plug-in hybrid*
  fills with petrol and charges from a plug. The vehicle form explains both
  under the fuel type, and the garage cards, vehicle header and dashboard
  show *Plug-in hybrid* where it applies.
- A hybrid's fill-up form now leads with petrol only. Charging is still
  there under *Other fuels*, and *Used on this vehicle* still lists a
  charging type the vehicle has used. A plug-in hybrid's form is what every
  hybrid's was: petrol and electricity, each remembering its own last grade.
- The capacity field reads *Tank capacity*, or *Battery capacity* for an
  electric vehicle (it was *Tank or battery capacity*).
- The sample data (`bin/dev-setup.sh --with-sample-data`) adds a plug-in
  hybrid with half a year of home charges and petrol fills beside the
  self-charging Corolla: six vehicles in all.
- German: *Hybrid (Voll- oder Mildhybrid)*, *Plug-in-Hybrid*, *Tankinhalt*,
  *Akkukapazität*.

### Changed
- Figures are unchanged: fill-ups still record petrol or electricity, never
  the kind of hybrid, so economy, cost, grades and CSV import and export
  work as before.

### Upgrade notes
- **Check your hybrids.** On upgrade, every *Hybrid* with at least one
  electricity fill-up (charge) logged in Logbook, archived vehicles
  included, becomes a *Plug-in hybrid*. Every other hybrid stays a *Hybrid*.
  The upgrade decides from what you logged, so a plug-in hybrid you never
  logged a charge for stays a *Hybrid*: change it on its edit page. The
  migration is reversible; rolling back turns every plug-in hybrid back into
  a hybrid.
- New nullable columns `vehicles.variant` and `vehicles.first_registered_on`
  (9.1). No other schema change. Both migrations run automatically on start
  (Docker) or with `vendor/bin/phinx migrate` (bare PHP). Existing vehicles
  are otherwise unchanged and every existing figure stays the same.
- Backups record the database schema, so a backup made with 1.0.0 cannot be
  restored into this version. Restore it with 1.0.0 first, then upgrade; the
  upgrade then sorts its hybrids as above.
- **Going back to 1.0.0** needs the rollback first, while still on 1.1.0:
  1.0.0 does not know plug-in hybrids and fails on any page that lists one.
  Run `vendor/bin/phinx rollback -e production -t 20261002100000` (Docker:
  `docker compose exec app vendor/bin/phinx rollback -e production -t
  20261002100000`), then switch to the 1.0.0 image or code.
- No configuration changes.

## [1.0.0] — 2026-09-28

Phase 8: fuel grades, and the first stable release. Everything in the
roadmap's core phases is done.

### Added — Phase 8: fuel grades
- **Which fuel went in**: a fill-up can record the petrol grade (E10 or E5
  and its octane, E85, ethanol-free E0), the diesel blend (B7, B7 premium,
  B10, B20, B100, HVO / XTL) or, for electric vehicles, how it was charged
  (at home, public AC, DC, rapid DC or ultra-rapid DC). It is optional: leave
  it as "grade not recorded" whenever the receipt does not say.
- **One fuel picker** on the fill-up form, grouped: *Used on this vehicle*
  (your usual choices from the last 12 months, so it is one tap on a phone),
  then the fuels that fit the vehicle, *Other fuels*, and *More grades*. US
  pump grades (Regular 87, Mid 89, Premium 91+, E15) are listed with petrol
  for owners whose language setting is for the US or Canada, E20 for India;
  everyone else finds them under *More grades*. The last grade you bought is
  preselected (for a plug-in hybrid, separately for petrol and charging). It
  works without JavaScript, in the pop-up and offline.
- **Default grade** per vehicle (add / edit vehicle), used until the vehicle
  has a fill-up with a grade; *Home charging* is the usual choice for an EV.
- **Badges** like the ones on pumps and chargers: a circle for petrol, a
  square for diesel, a rhombus for LPG and a hexagon for charging, with the
  short name ("E10 95", "B7", "Rapid"). They appear in the fuel list, the
  vehicle overview, the dashboard's *Recent fuel* and *Recent activity*, and
  the Expenses list.
- **By grade** on the Fuel tab: fills, amount bought, average price and — once
  there are two full-to-full stretches driven on one grade — an indicative
  economy per grade. For electric vehicles it is **By charging type**: the
  share of energy and cost per kWh of home, AC and rapid charging (free
  charges count at 0), then the blended cost per kWh.
- The **price trend** shows one line per grade, so E5 against E10, or home
  against rapid charging, can be compared.
- CSV: the fuel export has *Grade* and *Grade code* columns; import accepts
  the code, the name or the short name ("E10", "B7", "Rapid", "Home") in your
  language or English. Files without the column import as before.
- German translations for everything new ("Super E10", "Laden zu Hause", …).

### Changed
- Existing fill-ups are unchanged and show "Not recorded"; average economy
  and every other figure is exactly as before. Economy by grade is a view of
  the same full-to-full stretches, credited to the fuel burned over each
  (what went in at its start), never to the fill that closed it.
- Import: when a file has several columns that could fill a field, the one
  named like Logbook's own export wins.

### Upgrade notes
- New nullable columns `fuel_entries.grade` and `vehicles.default_grade`,
  added by a reversible migration that runs automatically on start (Docker)
  or with `vendor/bin/phinx migrate` (bare PHP). Nothing is backfilled.
- Backups record the database schema, so a backup made with 0.7.0 cannot be
  restored into 1.0.0. Restore it with 0.7.0 first, then upgrade.
- No configuration changes.
- Docker images are published as `1.0.0`, `1.0`, `1` and `latest`. From now
  on `latest` is the newest release; the newest development build is
  `master`.

## [0.7.0] — 2026-09-28

Phase 7: design alignment and dashboard enhancements.

### Added — Phase 7: design alignment and dashboard enhancements
- **Forms in a pop-up on desktop**: on a wide screen, adding or editing a
  vehicle, fill-up, odometer reading, service record, service interval,
  document or expense opens in a window over the page. Mistakes are shown
  right there; saving closes it and shows the usual confirmation. On phones,
  without JavaScript, or if anything goes wrong, the form opens as its own
  page as before, and every form still has its own address.
- **"+ Log entry"** (sidebar, and the "+" in the phone tab bar) replaces
  "Log fill-up": choose fill-up, odometer reading, service record, expense,
  document or service interval, then the vehicle (skipped when you have one).
  Choices for switched-off modules are left out. It works offline for
  fill-ups, like the fill-up form.
- **Sidebar**: the *Reminders* link shows how many reminders are overdue or
  due soon, and a *Vehicles* list shows each active vehicle with a red, amber
  or green dot. The dot's meaning ("1 overdue, 1 due soon", "All up to date")
  is shown as a tooltip and read out by screen readers.
- **Dashboard vehicle filter**: with two or more vehicles, chips under the
  greeting show one vehicle at a time. Every widget then covers that vehicle
  only, and a card is pinned at the top with its economy (last 12 months),
  running cost per mile or km, spend over the last 12 months and what is due
  next, plus *Log fill-up*, *Add reading* and *Open vehicle*. The choice is
  part of the address, so it can be bookmarked.
- **New dashboard widgets**: *Mileage* (this month, this year and monthly
  average, with a bar chart of the last 12 months) and *Recent activity* (the
  last eight things logged, of every kind). Saved layouts gain them at the end.
- **Expenses tab**: a *Last 12 months* chart beside *By category*. It always
  covers the last 12 months, whichever period is picked above.
- **Garage cards** show an "N due" badge on the photo, the current odometer
  and the average economy, in your units. *Your vehicles* on the dashboard is
  now a row of photo tiles with the same badge.
- **Accent colour** (Settings → Appearance): Blue, Teal, Indigo or Purple for
  buttons, links, highlights and charts, in light and dark themes. Overdue,
  due soon and OK keep their red, amber and green, and number plates stay
  yellow.
- **Version**: shown in the sidebar and on Settings ("Logbook v0.7.0"), and
  returned by `/health` (`"version"`).
- German translations for everything new.

### Changed
- The dashboard's default order now starts with *Upcoming reminders*, *Spend
  this month* and *Recent fuel*, then *Your vehicles*. A layout you have
  already arranged is kept.
- Every vehicle tab has *Edit*, *Archive* and *Delete* in the same place, and
  *Export CSV* / *Import CSV* next to the add button on the right (the
  *Mileage* tab now matches the others).
- Fuel trend charts, and Reports' *By category* and *By vehicle*, sit side
  by side on wide screens. *By vehicle* names are no longer links; each has a
  car or motorbike icon.
- Development: `bin/dev`, `docker-compose.dev.yml` and `composer start` now
  default to port **8090** (was 8080). `APP_PORT` still overrides, and a port
  already remembered in `var/dev.env` is kept. Production defaults are
  unchanged.

### Upgrade notes
- New column `users.accent` (default `blue`), added by a reversible
  migration that runs automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP).
- Backups record the database schema, so a backup made with 0.6.0 cannot be
  restored into 0.7.0. Restore it with 0.6.0 first, then upgrade.
- The release number now comes from the `VERSION` file in the app's root; keep
  it when copying the app to a bare-PHP server.

## [0.6.0] — 2026-09-28

Phase 6: feature toggles, import, backup and polish.

### Added — Phase 6: feature toggles, import, backup and polish
- **Modules** (Settings → Modules): switch off fuel, maintenance, documents,
  reminders or reports. A switched-off module disappears everywhere — menus,
  vehicle tabs and overview, dashboard, reports, reminders and notifications,
  the calendar feed — and its pages answer "not found". Its data is kept:
  switch it back on and everything is as it was. `FEATURES_*` set the
  defaults until the setting is saved.
- **CSV import** ("Import CSV" next to "Export CSV" on each vehicle tab):
  upload a file, match its columns (familiar names are matched for you), pick
  its date order, units and time zone, then preview every row before
  anything is saved. Rows are checked exactly like the forms check what you
  type; rows with problems are listed with their row number and reason, and
  are only skipped when you say so. Entries already logged are recognised, so
  importing a file twice changes nothing, and odometer readings that imported
  fill-ups or services already created are never doubled. Logbook's own
  exports import back exactly, in any units; so do files from spreadsheets
  (semicolons, day-first dates, Windows-1252 text). See `docs/import.md`.
- **Backup and restore** (Settings → Backup and restore): download everything
  — database, photos and attachments — as one ZIP; restore one after checking
  it and confirming, with an automatic safety backup of the current data
  first. Backups restore onto any supported database (SQLite → PostgreSQL
  works). `php bin/backup.php create | check | restore` does the same from the
  command line or cron.
- **Installable app (PWA)**: add Logbook to a phone's home screen. The
  fill-up form works offline: a fill-up saved without a connection is kept on
  the phone and sent when it is back online (with a *Review* option if the
  server rejects it). Works at a subpath too.
- **German** translation, complete. The language is picked from your settings
  or the browser.
- Guides: `docs/configuration.md` (every variable), `docs/import.md`,
  `docs/translations.md`; the deployment guide now covers the phone app,
  backups (including nightly cron backups and moving between databases) and a
  step-by-step upgrade procedure.

### Changed
- Accessibility: the green, amber and red status labels in the light theme
  are slightly darker, to meet WCAG AA contrast on their tinted backgrounds.
  Core pages are checked automatically for labels, headings, names, contrast
  and translations in every language.
- Settings → Reminders shows only the lead times while the reminders module
  is off.

### Upgrade notes
- No database changes.
- New optional variables: `BACKUP_PATH` (default `var/backups`; Docker
  `/data/backups`) and `MAX_RESTORE_MB` (default 256).
- The Docker image now includes PHP's `zip` extension and accepts uploads up
  to 256 MB (for restoring backups; attachments are still capped by
  `MAX_UPLOAD_MB`). Behind nginx, raise `client_max_body_size` if you want to
  restore large backups through the browser.
- Bare PHP: install `php-zip` to use backups (`composer` lists it under
  "suggest"); nothing else needs it.

## [0.5.0] — 2026-09-28

Phase 5: expenses, reports and dashboard.

### Added — Phase 5: expenses, reports and dashboard
- **Expenses**: every fill-up, and every maintenance job and document with a
  cost, now counts as an expense automatically — nothing to enter twice and
  nothing counted twice. Add anything else (parking, tolls, road tax,
  cleaning, fines…) on the vehicle's new **Expenses** tab; free is fine.
- **Reports** (new *Reports* page): the whole garage or one vehicle over this
  month, the last 3 or 12 months, this year, all time or any dates you pick.
  Total spend, running cost per mile or km (from your mileage log), distance
  driven, average per month, spend by category and per month (table and
  chart) and per vehicle. Sold (archived) vehicles are left out unless you
  tick "Include archived vehicles". Vehicles in different currencies get
  separate totals — amounts are never converted.
- **CSV export** of any report, and of each vehicle's fuel, mileage,
  maintenance, documents and expenses ("Export CSV" on each tab). Values are
  in your units with the unit in the header, precise enough to import back.
- **Dashboard**: widgets for your vehicles, upcoming reminders, spend this
  month, recent fuel, efficiency trend and documents. *Customise* moves or
  hides them (drag and drop too); the layout is saved to your account.
- On phones, Reports takes Settings' place in the bottom bar; Settings moves
  to the top bar.
- `FEATURES_*` variables now take effect for the dashboard and reports
  (e.g. `FEATURES_COMPLIANCE=false` hides the documents widget and leaves
  document costs out of reports).

### Upgrade notes
- New table `expense_entries`, created by a reversible migration that runs
  automatically on start (Docker) or with `vendor/bin/phinx migrate` (bare
  PHP). Existing costs appear in reports straight away; nothing is copied.

## [0.4.0] — 2026-09-27

Phase 4: reminders and notifications.

### Added — Phase 4: reminders and notifications
- **Reminders** (new *Reminders* page in the navigation): every maintenance
  schedule and every document with an expiry date becomes a reminder,
  grouped as overdue, due soon and upcoming. Mark done, dismiss or reopen in
  one click; logging the work or renewing the document clears it. Add your
  own reminders too ("Pay road tax on 1 Oct"). The home page shows what needs
  attention.
- **Lead times** in Settings → Reminders: how many days (and, for
  maintenance, how many miles or km) before something counts as due. The
  vehicle pages use the same lead times.
- **Notifications**: reminders are sent when they come due and again if they
  become overdue — never more — by email (SMTP), [ntfy](https://ntfy.sh),
  [Gotify](https://gotify.net) and/or any JSON webhook. Several at once
  arrive as one message. Choose channels and your email address in Settings,
  and send a test from there. Optional monthly "what's due this month" digest.
- **Calendar feed**: subscribe to your reminders from any calendar app
  (iCal / webcal), with an alert at each lead time. The secret link can be
  replaced or turned off.
- **Scheduled task**: `bin/run-scheduled-tasks.php` now does the work. The
  Docker image runs it every 15 minutes by itself (`SCHEDULER_ENABLED`,
  `SCHEDULER_INTERVAL`); bare-PHP installs add one cron line.
- Notification channels are pluggable: adding one (Telegram, Discord, …) means
  implementing one interface — see `docs/notification-channels.md`.

### Upgrade notes
- New table `reminders`, created by a reversible migration that runs
  automatically on start (Docker) or with `vendor/bin/phinx migrate` (bare
  PHP).
- Bare PHP: install the cron entry from docs/deployment.md (if you added it
  earlier, it now does something). Docker: nothing to do.
- New optional variables: `MAIL_TO`, `GOTIFY_URL`, `GOTIFY_TOKEN`,
  `GOTIFY_PRIORITY`, `SCHEDULER_ENABLED`, `SCHEDULER_INTERVAL`. The compose
  files now pass the notification variables through from `.env`. Set
  `APP_URL` to your public address so links in notifications and the
  calendar feed work.
- Calendar feed links are keyed with `SESSION_SECRET`: changing it disables
  existing links (create a new one in Settings).

## [0.3.0] — 2026-09-27

Phase 3: maintenance and documents.

### Added — Phase 3: maintenance and documents
- Vehicle pages gain two tabs: **Maintenance** and **Documents**. The
  overview shows what maintenance is due next and where each document stands.
- Service history: log services, repairs, tyres, brakes and more with date,
  optional odometer (it joins the mileage log), cost — free work at 0 is
  fine — garage and details. Newest first, filterable by category.
- Recurring schedules ("every 10,000 mi or 12 months, whichever comes
  first"): the next due date and odometer are worked out from the last time
  it was logged (or the "last done" you enter), the distance is placed on
  the calendar from your average mileage, and each schedule shows whether it
  is on track, due soon or overdue. "Log it" pre-fills the entry.
- Documents: insurance, pollution certificates (PUC), registration,
  inspections (MOT) and anything else, with provider, number, validity dates
  and cost. Create and edit both work (regression-tested). Expiring and
  expired documents are flagged; a renewal replaces the old document.
- Attachments: add receipts, invoices and certificates (PDF, JPEG, PNG or
  WebP, up to `MAX_UPLOAD_MB`) to fill-ups, maintenance and documents. Files
  are checked by content, stored outside the web root and served only to
  you; deleting an entry or a vehicle deletes its files.
- The demo seed now includes schedules, a service history and documents.

### Changed
- Vehicle photos and attachments share one upload check and one
  authenticated file handler.

### Upgrade notes
- New tables `maintenance_schedules`, `maintenance_entries`,
  `compliance_documents` and `attachments`, and a nullable
  `maintenance_entry_id` column on `odometer_readings`, created by reversible
  migrations that run automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). No configuration changes;
  attachments live under the existing `UPLOAD_PATH` — include it in backups.

## [0.2.0] — 2026-09-27

Phase 2: mileage and fuel.

### Added — Phase 2: mileage and fuel
- Vehicle pages have tabs — Overview, Mileage and Fuel — each its own URL
  (works without JavaScript and survives a hard refresh). The overview shows
  the current odometer, average economy, fuel cost per distance, total spend
  and the latest fill-ups.
- Mileage log: add, edit and delete odometer readings (typed in your distance
  unit and time zone; stored in km and UTC). Fill-ups add their reading to the
  same series automatically. Current reading, monthly average, distance
  logged, a trend chart, and warnings — never refusals — for readings that go
  backwards or jump implausibly (over 2,000 km a day).
- Fuel log: add, edit and delete fill-ups with date and time, odometer, fuel,
  volume, price per unit and total — any two work out the third, exactly —
  plus "partial fill" and "missed the previous fill-up" flags, station and
  notes. Economy is measured full tank to full tank, so partial fills and
  missing receipts never distort it; figures are recalculated on every view.
  Shown in L/100 km, km/L, mpg (UK) and mpg (US), with average price, cost
  per distance, total spend, and economy and price trend charts.
- Electric vehicles use the same log in kWh, with efficiency in kWh/100 km
  or mi/kWh (following your distance unit). A plug-in hybrid's petrol and
  charging figures are kept apart.
- A "Log fill-up" button in the sidebar and in the middle of the mobile tab
  bar: one tap to the form with a single vehicle, a vehicle picker with more.
- Long logs are paginated (25 per page).
- The demo seed (`bin/dev seed`) now includes a year of fill-ups and readings.

### Upgrade notes
- Two new tables (`fuel_entries`, `odometer_readings`), created by reversible
  migrations that run automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). No configuration changes.

## [0.1.0] — 2026-09-27

First release: Phases 0 and 1 (foundations, accounts and garage).

### Added — Phase 1: accounts and garage
- First-run setup: a fresh instance asks for the owner account (username,
  password, display name, units, currency, language, time zone) and is
  unreachable once an account exists.
- Sign in / sign out / change password. Argon2id hashes; database-backed
  sessions (`HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, scoped to
  `APP_BASE_PATH`, 30-day idle expiry, new id on sign-in); changing the
  password signs out other devices; failed sign-ins are logged for fail2ban.
- CSRF protection (slim/csrf) on every form, with a friendly "form expired"
  page; oversized uploads are reported as "too large".
- Garage: add, edit, delete (with a confirmation page) and archive/restore
  vehicles — cars and motorbikes, fuel type, tank or battery capacity, VIN,
  purchase and sale details, and a per-vehicle currency. Archived vehicles are
  hidden from active views and left out of fleet totals, history kept.
- Vehicle photos (JPEG, PNG, WebP up to `MAX_UPLOAD_MB`), checked by content,
  stored under `UPLOAD_PATH` with random names and served only to the
  signed-in owner.
- Settings: display name, theme (System/Light/Dark, also from the quick
  toggle), distance / fuel volume / fuel economy units (L/100 km, km/L,
  mpg UK and mpg US; Metric/UK/US presets), default currency, language with
  regional formats (e.g. English (United Kingdom)) and time zone. Changes apply
  on the next page.
- Units, money and dates engine for later phases: SI storage with conversion
  at the edges, exact decimal money (zero is valid, ≥3 decimals), calendar
  dates vs UTC instants, DST-safe local-time parsing.
- New configuration: `APP_CURRENCY` (default `GBP`). `SESSION_SECRET` is now
  used (optional).

### Development
- `bin/dev`: start/stop the Docker dev stack, switch between PostgreSQL,
  MySQL, MariaDB and SQLite (each keeps its own data), reset the database and
  load sample data (`DemoDataSeeder`: a demo owner and five vehicles).
- Shell scripts in `bin/` are now committed as executable.

### Database
- New tables `users`, `sessions` and `vehicles` (reversible migrations,
  tested on PostgreSQL, MySQL, MariaDB and SQLite). No manual upgrade steps.

### Added — Phase 0: foundations
- Slim 4 application skeleton with PHP-DI, Doctrine DBAL, Twig, Monolog and
  symfony/translation (ICU); English catalogue as default and fallback.
- PostgreSQL, MySQL/MariaDB and SQLite support. Every database session is
  pinned to UTC.
- Phinx migrations; baseline `settings` table.
- `GET /health` (200/503 JSON) for monitoring and the Docker `HEALTHCHECK`.
- Subpath hosting via `APP_BASE_PATH`, working whether the reverse proxy
  forwards or strips the prefix; deep links survive a hard refresh.
- Friendly, translated error pages; exception details only with `APP_DEBUG`,
  always HTML-escaped.
- Docker image (PHP 8.4 + Apache; amd64 and arm64, 64-bit only) with a single `/data`
  volume, auto-migration on start, and compose files for PostgreSQL, MySQL and
  development.
- Bare-PHP support: `.htaccess`, Apache/nginx/Caddy examples, cron runner
  placeholder, `composer start` dev server.
- Quality gates: phpcs (PSR-12), PHPStan (level max), PHPUnit. CI runs them on
  PostgreSQL, MySQL and MariaDB (PHP 8.4 and 8.5) and smoke-tests the image.

### Added — design system and app shell
- Design tokens (colour, typography, spacing, radii) for light and dark themes,
  following the OS by default with a JS toggle that is remembered per browser.
- Responsive shell: sidebar on wide screens, top bar and bottom tab bar on
  phones; Logbook logo and wordmark.
- Self-hosted Outfit and Plus Jakarta Sans fonts and a Material Symbols icon
  sprite (no CDN requests); base components for cards, lists, buttons, chips,
  forms, pills and alerts.

[Unreleased]: https://github.com/gwpreston16/Logbook/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/gwpreston16/Logbook/compare/v1.10.0...v2.0.0
[1.10.0]: https://github.com/gwpreston16/Logbook/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/gwpreston16/Logbook/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/gwpreston16/Logbook/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/gwpreston16/Logbook/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/gwpreston16/Logbook/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/gwpreston16/Logbook/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/gwpreston16/Logbook/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gwpreston16/Logbook/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/gwpreston16/Logbook/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/gwpreston16/Logbook/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/gwpreston16/Logbook/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gwpreston16/Logbook/compare/v0.7.0...v1.0.0
[0.7.0]: https://github.com/gwpreston16/Logbook/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/gwpreston16/Logbook/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/gwpreston16/Logbook/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/gwpreston16/Logbook/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/gwpreston16/Logbook/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/gwpreston16/Logbook/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/gwpreston16/Logbook/releases/tag/v0.1.0
