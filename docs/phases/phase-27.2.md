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
- [ ] `docs/incidents.md`: *When the car is written off*; *Reading
      insurer letters*.
- [ ] `docs/ai.md`: the two scan kinds.

### Migrations (every engine, each reversible)
- [ ] `vehicles.disposal`, `vehicles.disposal_incident_id`;
      `incidents.repair_estimate`; `pending_uploads.incident_id` and the
      `incident` target. Rollback drops them (archived vehicles stay
      archived).

### Code
- [ ] `Domain\Vehicle\Disposal`; the archive confirm page and action
      (plain form, desktop modal), *Written off* offered only for a settled
      incident with a write-off category; *Sold* set from the sale date;
      *Restore* clears the disposal.
- [ ] `OwnershipCost`: the disposal incident's payout left out of
      *Insurance payouts*, with the note; the ownership CSV.
- [ ] Labels: garage cards, overview, ownership report, the *Written off*
      milestone in History and the sale pack.
- [ ] Scanning: `ScanKind::ClaimLetter`, `ScanKind::RepairEstimate`,
      `ScanTarget::Incident`; schemas, the claim status and write-off
      phrase matching in `Mapper`; the edit form prefilled for a matched
      incident with the scanned marks; the estimate's incident choice;
      *Fill from a file* and *Update from a letter*.
- [ ] The incident page's *Repair estimate* line; the estimate in the API,
      CSV export, backups and the Ask tools (with `ViewCosts` and
      `ViewIncidentDetails`).
- [ ] Translations (en, de).

### Tests
- [ ] *Written off* offered only for a settled incident with a category
      other than `none`; otherwise *Archive* is one click; prefilled date
      and price, editable; one transaction.
- [ ] **No double counting:** a £9,000 settlement as the sale price leaves
      *Insurance payouts* at the repair claims' payouts only, and the
      total matches a hand calculation.
- [ ] Restore clears the disposal; a module switched off keeps a
      written-off vehicle's label.
- [ ] A claim letter with a matching claim number opens that incident's
      edit form with changed fields marked, and attaches the letter on
      save; no match opens *Log incident*; *Update from a letter* needs no
      match.
- [ ] Claim status words: "settled", "payment issued", "declined";
      unclear words left empty.
- [ ] An estimate fills the estimate and the notes line and never moves
      linked costs, Reports or ownership.
- [ ] Scan fixtures: a claim letter (text PDF), a settlement letter with a
      Cat S, a phone photo of an estimate; an insurance schedule still read
      as `insurance`.
- [ ] Module off (`incidents` or `ai_scan`): no *Written off*, no incident
      kinds.
- [ ] Integration suite green on every engine; migrations roll back on
      every engine.

### Sample data
- [ ] `DemoDataSeeder`: the archived car's at-fault collision becomes a
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
