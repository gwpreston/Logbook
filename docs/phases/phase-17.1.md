# Phase 17.1 — Sale pack

*Everything a buyer wants to see, and nothing they shouldn't.*

Status: ✅ complete · ships with Phase 17.2 as **v1.9.0**

The print view (§7.16, Phases 10 and 12) already gives a buyer the history
they need. It includes every service, repair, inspection and tyre event,
with costs hidden and valuations never shown. Three things are missing.
There is no summary a buyer can read in a minute. There is no evidence for
the mileage, which is what buyers worry about most. And the invoices
themselves are not included, only their file names. The sale pack adds
those three, reached from a *Prepare for sale* button.

It stays with the app's print approach: the pack is a page the browser
saves as a PDF. The paperwork comes as a ZIP beside it. Nothing new is
stored, there is no PDF library and there are no third-party requests.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.16 first. The spec
text below goes in as §7.19 **before** any code.

---

## Goals

1. **Prepare for sale** on every vehicle, active or archived. It opens the
   sale pack with the options a seller needs.
2. **A summary page** covering what the car is, how long it has been owned
   and how far it has gone, when it was last serviced, when the MOT expires,
   the tyres, what is due next and how much paperwork is on file.
3. **A mileage record** made from readings taken when a third party was
   present (services, inspections, tyre fitting), with their paperwork.
4. **History grouped by type** (service and repairs, inspections, tyres),
   with the full timeline available as an option.
5. **A paperwork ZIP** of the invoices and certificates the seller ticks,
   named so a buyer can find each one.

## Not in scope

- **Ownership costs, fuel, expenses, valuations, depreciation and the
  purchase or sale price.** None of them tell a buyer anything about the
  car, and they weaken the seller's position. Work costs are an explicit
  opt-in (below).
- A server-generated PDF, or one PDF with the invoices merged in. Merging
  arbitrary invoice PDFs reliably needs a PDF parser beyond pure-PHP free
  libraries (many invoices are PDF 1.5+ with compressed object streams).
  This is left for a later optional phase (ROADMAP *After 1.0*).
- Fetching MOT history from DVSA, or any other lookup. A link is printed
  instead.
- Public share links. The pack is printed or saved and sent by the seller.
- Redacting names and addresses inside invoices. The seller is warned
  instead.

---

## Spec addition (§7.19 Sale pack)

> ### 7.19 Sale pack (Phase 17.1)
> A buyer's view of one vehicle (`/vehicles/{id}/sale-pack`), for printing
> or the browser's *Save as PDF*, plus the paperwork as a ZIP. It is derived
> on every read from the same sources as History (`ActivityFeed`), the
> Mileage tab, *Coming up* and Tyres, and is never stored. It is core,
> available for active and archived vehicles.
>
> - **Entry points:** *Prepare for sale* in the vehicle header's menu, on
>   every tab, and *Sale pack* in the History toolbar beside *Print*.
> - **Options** (a plain GET form, works without JS, bookmarkable):
>   - *Show what's due next*: on by default.
>   - *Include descriptions*: on by default. Service descriptions often say
>     what was done, which buyers value, but they can hold private notes.
>   - *Include the full timeline*: off by default.
>   - *Show the cost of work*: off by default. Only `costs=1` shows it. It
>     adds the cost of each service, repair, inspection and tyre record, and
>     one total ("£3,240 spent on servicing and repairs since March 2021").
>     Purchase and sale prices, fuel, expenses, valuations and ownership
>     costs are **never** in the pack, whatever the options.
>   - Which kinds of paperwork go in the ZIP (below).
>   The options panel and every seller notice are screen-only and are never
>   printed.
> - **Summary** (the first printed page):
>   - *Vehicle:* name, make, model and variant, model year, fuel type,
>     registration, VIN, and first registered with age (as the print header
>     shows them today).
>   - *Ownership:* "Owned since March 2021" (the purchase month) and the
>     distance covered since then. That distance is the latest reading minus
>     the earliest reading on or after the purchase date. It is shown only
>     when both exist and the earliest is within 31 days of the purchase.
>     Without a purchase date the line is left out; it is never guessed.
>     Sold vehicles say "Owned March 2021 to May 2026".
>   - *Mileage:* the current odometer with its date ("78,421 mi on 12 Sep
>     2026") and the average per year since first registered (§7.2).
>   - *Servicing:* the number of service and repair records, the last
>     `service` or `oil` record (date and odometer), and how many records
>     carry paperwork.
>   - *Inspection:* for each current `inspection` or `pollution` document
>     with an expiry, "MOT valid until 14 Jun 2027" (the label is the
>     document type's). For an owner whose locale region is GB and a vehicle
>     with a registration, the line "Check the full MOT history at
>     gov.uk/check-mot-history" follows. The URL is plain printed text, a
>     constant on the service, checked at release. Nothing is fetched.
>   - *Tyres:* the fitted tyres with their latest **measured** tread and
>     date, as the print view's *Tyres fitted* block. Estimates are never
>     printed.
>   - *Due next* (with that option on): from *Coming up* (§7.18), up to
>     five items within the next 12 months, overdue ones first as "Due now",
>     each with its date or distance. Costs are never shown here. Archived
>     vehicles have no *Coming up*, so the block is left out.
>   - *Paperwork:* "31 invoices and certificates available". This is the
>     count of files the current ZIP choice would hold, and the line is
>     left out when that is zero.
> - **Mileage record:** the readings a buyer can check, oldest first. That
>   means every `maintenance`, `document` and `tyre` reading (§6
>   OdometerReading), plus `manual` readings that have an attachment (a
>   dashboard photo). Each shows the date, odometer, distance since the one
>   before, its source ("Service invoice, Kwik Fit", "MOT certificate",
>   "Tyre fitting", "Dashboard photo"), and a paperclip mark with the file
>   count when the owning entry has files. Fill-up readings are never
>   listed, because there are hundreds and they are the owner's own typing.
>   A small line chart of the listed readings (odometer against date)
>   prints above the table when JS is on; without JS, only the table.
>   **Seller notice** (screen only): when a listed reading would raise the
>   Mileage tab's plausibility warning (it goes backwards, or jumps
>   implausibly), "This reading looks wrong. Check it before you share the
>   pack", with a link to the entry. The pack still renders.
> - **History, grouped:** *Service and repairs* (every maintenance record;
>   a linked tyre change shows as its second line, as in History), then
>   *Inspections and certificates* (`inspection` and `pollution` documents),
>   then *Tyres* (tyre changes not linked to a record). Each group is newest
>   first, with date, odometer, title or summary, vendor, description (if
>   that option is on) and the attachment file names. Insurance,
>   registration and `other` documents are not listed: they are about the
>   seller, not the car. The milestones *First registered* and *Bought*
>   head the pack without prices.
> - **Full timeline** (option): the print view's rows for the included
>   kinds, as §7.16 prints them, with the same cost rule.
> - **Module toggles:** a switched-off module's parts leave the pack, as in
>   History (`tyres` off: no tyre block or group; `compliance` off: no
>   inspection line or group; `maintenance` off: no servicing line or group
>   and no *Due next* schedules).
> - **Paperwork ZIP** (`/vehicles/{id}/sale-pack/paperwork.zip`, GET, owner
>   only, streamed, needs PHP's `zip` extension as backups do):
>   - Kinds offered, with their defaults: service and repair records (on),
>     inspection and pollution documents (on), manual-reading photos (on),
>     purchase paperwork (off), insurance (off). Registration documents,
>     sale paperwork, valuations, fill-ups and expenses are **never
>     offered**. A registration document (the V5C in the UK) carries a
>     reference that can be used for fraud.
>   - A *Choose files* disclosure lists every file of the ticked kinds with
>     a checkbox each, all ticked, so the seller can drop one. Without JS it
>     is a plain `<details>`. The ZIP link carries the choice (`kinds[]`,
>     plus `exclude[]` attachment ids).
>   - Files are named `YYYY-MM-DD <kind> <title or vendor>.<ext>` in the
>     owner's language (`2024-03-12 Service - Kwik Fit.pdf`). Names are
>     sanitised; duplicates get ` (2)`, ` (3)`. `contents.txt` lists each
>     file with its entry, date and odometer.
>   - Screen-only warning above the link: "Invoices often show your name
>     and address. Check them before you send them."
>   - Every attachment is loaded by vehicle and owner type (§7.12). An id
>     from another vehicle or a never-offered type is ignored, never an
>     error that confirms it exists.
> - **Print CSS:** the print view's rules (black on white, no app shell, rows
>   never split), plus a page break after the summary. The pack's header
>   repeats name and registration. The date printed is in the owner's
>   format.

Update §12 (future): "server-side PDF (emailed reports, one-file sale pack
with invoices merged)".

---

## Decisions (and why)

- **A browser PDF and a ZIP, not a merged PDF.** Browser PDFs are already
  how History prints. A reliable merge of third-party invoices needs a PDF
  parser the pure-PHP free libraries lack, and a binary tool would break the
  Raspberry Pi and bare-PHP goals. Two files are a small cost to the
  seller.
- **Evidence over polish.** A pack the seller generates is only as credible
  as what can be checked: invoices, certificates and readings tied to them.
  The mileage record lists only readings with a third party present, and
  says where each came from.
- **Costs are narrower than in the print view.** There, `costs=1` also
  shows purchase and sale prices. In the pack it shows work costs only.
  "£3,240 of servicing" helps a sale; "bought for £12,500" does not.
- **Due next is on by default.** Buyers ask anyway, and a pack that hides a
  due cambelt is found out on the test drive. The seller can switch it off.
- **Changed while building it** (spec.md §7.19 is the current text):
  the ZIP is a small pure-PHP stored archive streamed from the uploads, so
  it needs no zip extension and makes no temporary copy; *Prepare for sale*
  is a header button, as the header has no menu; the options form carries
  `options=1` as the print view's does, and a bare `timeline=1` link still
  works; `other` documents are never offered either; ZIP names use the
  vendor, else the title.
- **No insurance, registration or `other` documents in the history.** They
  are about the owner, and the registration scan is a fraud risk.

---

## Tasks

### Spec and docs
- [x] Add §7.19 to `spec.md`, the §12 wording, and the Phase 17.1 line to
      §13.
- [x] `ROADMAP.md` row and section (done in the roadmap update).
- [x] `docs/sale-pack.md`: what is in it, what never is, and how to save the
      PDF on desktop and phone.

### Services
- [x] `Service\SalePack\SalePackBuilder`: assembles the view model from
      `ActivityFeed`, the odometer series, `Service\Forecast\ComingUp`, the
      tyre summary and compliance documents. Typed result, no arrays crossing
      the boundary.
- [x] `Service\SalePack\MileageEvidence`: selects the readings (by source,
      and manual readings with files), works out the distance since the one
      before, and reuses the existing plausibility check to mark readings
      for the seller notice.
- [x] `Service\SalePack\OwnershipSpan`: purchase month, the earliest
      reading within 31 days of purchase, distance covered, sold range.
- [x] `Service\SalePack\PaperworkSelector`: the offered kinds (an enum with
      defaults and a `neverOffered` list), the files by vehicle, and the
      exclusions applied.
- [x] `Service\SalePack\PaperworkArchive`: streams the ZIP (no temp file
      over `MAX_UPLOAD_MB`), handles naming, de-duplication and
      `contents.txt`.

### Actions, routes, templates
- [x] `Action\SalePack\ShowSalePack` (GET) and
      `Action\SalePack\DownloadPaperwork` (GET), both behind the auth guard.
      Option parsing is strict and unknown values fall back to defaults.
      `costs` is only true at `1`.
- [x] `templates/sale_pack/show.twig`, with partials for the summary,
      mileage record, groups and options. It reuses the print view's partials
      for rows and the tyre block rather than copying them.
- [x] Header menu item and History toolbar link. (The header has no menu:
      *Prepare for sale* is a button beside Edit.)
- [x] Print CSS additions: page break after the summary; screen-only classes
      for options and notices.
- [x] Mileage chart: progressive enhancement, print palette (never dark
      tokens), table always rendered.

### Translations
- [x] Every new string in English and German. For German, the inspection
      line uses the document type's label ("HU gültig bis …"). The gov.uk
      line exists only for GB, so it needs no German variant beyond the
      catalogue key.

### Tests
- [x] The pack never contains purchase or sale prices, fuel, expenses,
      valuations or ownership figures, with any combination of options,
      `costs=1` included. Test this against the rendered HTML with seeded
      sentinel amounts.
- [x] `costs` values `0`, `true`, `yes` and absent all hide costs; `1`
      shows work costs and the total.
- [x] The ownership span: purchase with a reading on the day; the first
      reading 45 days later (line left out); no purchase date; a sold
      vehicle.
- [x] Mileage record: includes maintenance, document and tyre readings and
      manual readings with files; excludes fill-up readings and manual
      readings without files. A backwards reading raises the seller notice
      and never breaks the page.
- [x] *Due next*: overdue first, at most five, left out for archived
      vehicles, and no costs even with `costs=1`.
- [x] GB and registration show the gov.uk line; `de_DE` or no registration
      leaves it out.
- [x] ZIP: defaults, exclusions and never-offered kinds (a registration
      document's id in `exclude[]` or a forged `kinds[]=registration` adds
      nothing); another vehicle's attachment id is ignored; names,
      duplicates and `contents.txt`; module off → its files are not
      offered.
- [x] Module toggles remove each part of the pack.
- [x] Without JS, the options, the file choice and the tables all work.
      `APP_BASE_PATH` links and a hard refresh work.
- [x] Translation suite (keys, placeholders, templates) passes for en and
      de.
- [x] Integration suite green on SQLite, PostgreSQL, MySQL and MariaDB.

### Sample data
- [x] The Golf in `DemoDataSeeder` gets attachments on most service records
      and its MOT documents, one manual reading with a dashboard photo, and
      a purchase date matching its first reading. The demo pack then shows
      every block.

---

## Acceptance criteria

1. *Prepare for sale* opens a pack whose first page answers what the car
   is, how far it has gone, when it was serviced, when the MOT runs out,
   the state of the tyres, what is due and how much paperwork exists.
2. The mileage record lists only readings a buyer can check, with their
   source. A suspicious reading is flagged to the seller and never printed
   as a flag.
3. The ZIP contains exactly the ticked files, readably named, never a
   registration document or anything with a price paid for the car.
4. No option combination prints purchase or sale prices, fuel, expenses,
   valuations or ownership costs.
5. It works for archived vehicles, without JS, at a subpath, and in both
   languages. Definition of done (CLAUDE.md §11) holds, with no migrations.

## Open questions

- Should the pack include the vehicle photo on the summary page? It helps
  a buyer, but the photo may show the seller's house or a plate the seller
  wants hidden. Off by default, or not at all?
- Is 31 days the right tolerance between purchase and first reading, or
  should the line say "since the first reading on …" instead of hiding?
