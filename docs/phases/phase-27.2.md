# Phase 27.2 — Total loss and reading claim letters + v2.10 release

*When the insurer pays out for the car, and the letters in between.*

Status: 🚧 in progress · releases **v2.10.0** (Phases 27.1 and 27.2) · file
lives in `docs/phases/`

[Phase 27.1](phase-27.1.md) records incidents and claims. Two of its open
questions were answered "build it" (#93, #95), and they became this phase.
The first is a **total loss**: the car is written off and the claim is
settled, so archiving should record that, with the settlement as the sale
price, counted once. The second is **the letters**: claim updates arrive by
post and email over weeks, and Phase 26.4's scanning can read them into the
incident they belong to. A repair estimate is read the same way, into a new
estimate field that is never counted as a cost.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6
(Vehicle, Incident, PendingUpload), §7.1, §7.7 *Cost of ownership*, §7.27
and §7.29 first.

---

## Goals

1. *Archive* offers *Written off* for a vehicle with a settled write-off,
   with the sale date and price prefilled from the settlement, and the
   vehicle labelled *Written off*.
2. Ownership counts the settlement once: as the sale price, never also as
   an insurance payout.
3. Scanning reads insurer claim letters into the matching incident (by
   claim number) and repair estimates into the incident's estimate.
4. Release v2.10.0.

## Not in scope

- A disposal reason beyond *sold* and *written off* (scrapped, stolen and
  not recovered, exported). Archiving without a reason stays.
- Reading anything else about a claim (engineer's reports, photos of the
  damage: those are photos, kept with their EXIF by Phase 27.1).
- Contacting insurers, or valuing the car for a total-loss offer.

---

## Spec additions

Written into `spec.md` when this phase was decided (2026-10-01):

- §6 **Vehicle**: disposal (`sold` | `written_off`, optional),
  disposal_incident_id (optional, `ON DELETE SET NULL`).
- §6 **Incident**: repair_estimate (`decimal`, optional, ≥ 0; information
  only).
- §6 **PendingUpload**: target `incident`, incident_id.
- §7.1: archiving is one click unless a settled write-off makes *Written
  off* available.
- §7.7 *Cost of ownership*: the disposal incident's payout is the sale
  price, left out of *Insurance payouts*.
- §7.27: kinds `claim_letter` and `repair_estimate`, their schemas and
  mappings; entry points *Fill from a file* on the incident form and
  *Update from a letter* on the incident page.
- §7.29 *Total loss* and *Reading claim letters and repair estimates*.

---

## Decisions (and why)

- **The settlement is the sale price, not a payout** (#98). Depreciation
  is purchase price minus sale price, and the ownership figure is net of
  payouts. The money would be counted twice if it were both. As a sale
  price it also ends the value chart and the ownership period where they
  belong.
- **Archiving only asks when there is something to ask** (#99). Most
  vehicles are sold or retired, and their archive stays one click. The
  confirm page appears only for a settled write-off.
- **A letter updates the incident it is about** (#100). Most letters
  arrive in the middle of a claim. Opening a create form would leave the
  owner to merge two incidents by hand. A claim number is a reliable key.
- **An estimate is not a cost** (#101). It is what a repair may cost. The
  invoice, linked as a repair, is what it did cost.

---

## Tasks

### Spec and docs
- [x] `spec.md` as above; the Phase 27.2 line in §13.
- [x] `docs/incidents.md`: *When the car is written off*; *Reading
      insurer letters*.
- [x] `docs/ai.md`: the two scan kinds.

### Migrations (every engine, each reversible)
- [x] `vehicles.disposal`, `vehicles.disposal_incident_id`;
      `incidents.repair_estimate`; `pending_uploads.incident_id` and the
      `incident` target. Rollback drops them (archived vehicles stay
      archived).

### Code
- [x] `Domain\Vehicle\Disposal`; the archive confirm page and action
      (plain form, desktop modal), *Written off* offered only for a settled
      incident with a write-off category; *Sold* set from the sale date;
      *Restore* clears the disposal.
- [x] `OwnershipCost`: the disposal incident's payout left out of
      *Insurance payouts*, with the note; the ownership CSV.
- [x] Labels: garage cards, overview, ownership report, the *Written off*
      milestone in History and the sale pack.
- [x] Scanning: `ScanKind::ClaimLetter`, `ScanKind::RepairEstimate`,
      `ScanTarget::Incident`; schemas, the claim status and write-off
      phrase matching in `Mapper`; the edit form prefilled for a matched
      incident with the scanned marks; the estimate's incident choice;
      *Fill from a file* and *Update from a letter*.
- [x] The incident page's *Repair estimate* line; the estimate in the API,
      CSV export, backups and the Ask tools (with `ViewCosts` and
      `ViewIncidentDetails`).
- [x] Translations (en, de).

### Tests
- [x] *Written off* offered only for a settled incident with a category
      other than `none`; otherwise *Archive* is one click; prefilled date
      and price, editable; one transaction.
- [x] **No double counting:** a £9,000 settlement as the sale price leaves
      *Insurance payouts* at the repair claims' payouts only, and the
      total matches a hand calculation.
- [x] Restore clears the disposal; a module switched off keeps a
      written-off vehicle's label.
- [x] A claim letter with a matching claim number opens that incident's
      edit form with changed fields marked, and attaches the letter on
      save; no match opens *Log incident*; *Update from a letter* needs no
      match.
- [x] Claim status words: "settled", "payment issued", "declined";
      unclear words left empty.
- [x] An estimate fills the estimate and the notes line and never moves
      linked costs, Reports or ownership.
- [x] Scan fixtures: a claim letter (text PDF), a settlement letter with a
      Cat S, a phone photo of an estimate; an insurance schedule still read
      as `insurance`.
- [x] Module off (`incidents` or `ai_scan`): no *Written off*, no incident
      kinds.
- [x] Integration suite green on every engine; migrations roll back on
      every engine.

### Sample data
- [x] `DemoDataSeeder`: the archived car's at-fault collision becomes a
      settled Cat S total loss, archived as *Written off*; the Golf's
      incident gets a repair estimate.

### Release
- [ ] `CHANGELOG.md` **2.10.0** (Phases 27.1 and 27.2): incidents, damage
      and claims history; total loss; reading claim letters and estimates.
      Upgrade notes: migrations; the `incidents` module is on by default
      and can be switched off; incident photos are kept as uploaded.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
      documentation table.
- [ ] Tag `v2.10.0`.

---

## What changed while building

- **Sold** is also set when a vehicle is *added* with a sale date, not only
  edited, and clearing the date clears it (#105). A written-off vehicle is
  never changed by the edit form; only *Restore* clears it.
- **The confirm page** is reached by a link only when *Written off* is on
  offer; otherwise *Archive* stays the one-click POST it was. A `GET` with
  nothing to offer goes back to the vehicle. Several settled write-offs give
  a select whose choice refills the sale date and price (with JS), or
  *Use its settlement* (without). The sale date and price are required for
  *Written off*, with the vehicle form's rules (not before the purchase).
  The settlement date is the incident's closed_on, else the latest claim
  update, else today.
- **Labels:** the garage card and the vehicle header say "Written off
  14 Mar 2025" (the sale date) where they said "Archived"; the ownership
  report shows it beside the name and "Lifetime, written off …" for the
  total; its CSV's *Sold* column says `written off`. History's milestone is
  *Written off* (or "Written off, settled for £9,000.00" with costs) with
  "Total loss: Collision, 14 Mar 2025" as its note. The sale pack already
  left the sale milestone out and has the write-off line from Phase 27.1,
  so nothing changed there.
- **The estimate** is a claim amount: shown, exported and answered only with
  the claim's details and *Can see costs*. It is on the incident form (the
  *Insurance* section) and in the API as `claim.repair_estimate` (OpenAPI
  1.15.0), the incident CSV and the Ask/MCP `incidents` tool's rows.
- **Matching** compares claim numbers in upper case, letters and digits
  only. Only incidents the user may change are matched, chosen or scanned
  for. A scan started for an incident keeps that incident's vehicle even
  when the letter shows another plate (the plate warning still shows).
- **Editing from a scan:** only the fields the file changes are filled and
  marked; the incident's own date is kept; "Estimate from {repairer}" is
  added to the notes once. An estimate's choice of incident is a plain GET
  form above the edit form (another incident, or *A new incident*).
- **Claim status words:** "settled", "settlement", "paid", "payment
  issued/made/sent" → *Settled*; "declined", "rejected", "repudiated",
  "refused" → *Declined*; any hedge ("offer", "not", "yet", "pending",
  "awaiting", "will", …) or both kinds of word leave it empty. Write-off
  words: "Cat S", "Category N", "cat. b", and "structural" /
  "non-structural" without a letter.
- **Demo data:** the Fiesta's at-fault collision moved to 28 Oct 2025 (it
  was in 2022, but a car written off then could not have been driven until
  2025): a settled Cat S, settled for its £2,100 sale price; the sale
  paperwork is its settlement letter. The Golf's 2024 scrape has a £655
  estimate from the garage that repaired it for £640.
- **Migration:** `pending_uploads.incident_id` is `ON DELETE CASCADE`, as
  the spec says (deleting the incident drops a scan waiting for it).

## Acceptance criteria

1. Archiving a car whose Cat S claim was settled for £9,000 offers
   *Written off* with that price, and its ownership counts the £9,000 once.
2. Scanning the insurer's settlement letter updates the open incident with
   the same claim number to *settled*, with the payout and the letter
   attached.
3. A repair estimate shows on its incident and changes no cost figure.
4. Definition of done (CLAUDE.md §11) holds, and v2.10.0 is released.

## Open questions

The questions this phase answers were found while starting Phase 27.1
(#93, #95, #98–#101). One more was found while starting it:

- **Clearing a sold vehicle's sale date** (#105). *Decided 2026-10-02:*
  clearing the sale date clears disposal `sold`; a `written_off` disposal
  is never changed by the edit form (spec §7.29 *Total loss*).
