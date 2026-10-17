# Phase 50.2 — Warranties, the insurer's list and parts everywhere + release

*A reminder before a warranty runs out, a list of modifications to read to
an insurer, and parts wherever entries go.*

Status: ✅ complete · released as **v3.15.0** · file lives in `docs/phases/`

[Phase 50.1](phase-50.1.md) records parts, modifications and their
warranties on Logbook's own pages, and keeps build spend out of every
running cost per distance. This part takes them further: a warranty that
is about to end raises a reminder; the current modifications print as a
list for an insurance quote or a renewal; the sale pack can include them;
a scanned invoice's parts lines become parts; and the API, Ask, MCP, CSV
and webhooks carry them. Then the release.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.39
(written in Phase 50.1), then §7.6 (reminder sources), §7.19 (sale pack),
§7.20 (API and webhooks), §7.26 (Ask tools), §7.27 (reading files), §7.28
(MCP), §7.29 (the printable claims history is the nearest pattern for the
insurer's list) and §7.13 (CSV).

**Prerequisites:** [Phase 50.1](phase-50.1.md) complete and green.

---

## Goals

1. **Warranty reminders** for the parts the owner chooses.
2. **For your insurer:** a printable list of the vehicle's current
   modifications, each with when it was declared.
3. **Sale pack:** an *Include modifications* option.
4. **Scanning:** an invoice's parts lines offered as parts on the
   prefilled service record or modification.
5. **API, webhooks, Ask, MCP and CSV** for parts and modifications.
6. Release.

## Not in scope

- Making a warranty claim through Logbook, or contacting a supplier.
- Logbook judging whether a modification must be declared, or what an
  insurer would charge for it: the insurer's list is the owner's record.
- CSV **import** of parts or modifications (open question G).
- Anything in 50.1's *Not in scope*.

---

## Spec (proposed)

Added to §7.39 and the sections it touches in 50.2.0 once the open
questions below are decided; in outline:

- **Warranty reminders** (§7.6): a part's *Remind me before it ends*
  tick (open question A) raises a reminder with source `part`, due at the
  warranty's end (the sooner of its date and, projected as a schedule's
  distance is, its mileage), with the owner's document lead time; *Done*
  reads *Checked it*; it goes when the part is gone, the tick is cleared
  or the warranty is edited away. Sent through every channel and the
  calendar as any reminder; needs `reminders`.
- **For your insurer** (`/vehicles/{id}/parts/insurer`, printable as
  §8 *Printing reports*): the vehicle, its registration and each current
  (not reverted) modification, ECU tunes first, with category, date fitted, what it
  changed (its parts' names, brands and part numbers) and *Declared on*,
  or *Not declared yet*; never costs. A *Mark declared* action sets
  `declared_on` for all listed, or one at a time (open question B).
- **Sale pack** (§7.19): *Include modifications* (open question C):
  current modifications, those reverted with the `original` parts refitted,
  and stored parts that go with the car, never prices or proceeds.
- **Scanning** (§7.27): the parts lines Phase 26.4 already reads become
  an *Add as parts* list on the prefilled form, each with name, part
  number and price filled and ticked off by default (open question D);
  warranty terms are never guessed from a line; nothing is saved without
  *Save*. §7.27's *Not in scope* line on a parts inventory and a
  warranty entity is updated to point here.
- **API** (§7.20): read, create, edit (`PATCH`, `If-Match`) and delete
  modifications and parts, fit and remove, with attachments; ledger
  reads carry the `build` group; webhook kinds `modification` and `part`
  with the usual events (open question F).
- **Ask** (§7.26): read tools `modifications` and `parts`; a draft tool
  for a modification (confirmed in Logbook, as every draft); the running
  cost tools say that build spend is left out and give it separately
  (open question E). **MCP** (§7.28): the same tools.
- **CSV** (§7.7): `modifications.csv` and `parts.csv` per vehicle, with
  each part's fittings as its own export (`part-fittings.csv`); the
  report export's group column carries `build`.

---

## Tasks

### 50.2.0 Spec first
- [x] Open questions A–G decided by the owner and logged in
      `open-questions.md` (#437–#443), and the four found while starting
      (#444–#447) decided the same way (K34).
- [x] §7.39, §7.6, §7.19, §7.20, §7.26, §7.27, §7.28, §7.7 CSV, §13.

### 50.2.1 Warranty reminders
- [x] The tick on the part form; source `part` in *Sync* (created,
      moved, removed with the warranty and the part's status); *Checked
      it*; the reminders list, calendar and channels.

### 50.2.2 Insurer's list and sale pack
- [x] *For your insurer* page and print layout; *Mark declared*; a
      *Not declared* badge on the *Parts* tab.
- [x] Sale pack *Include modifications*.

### 50.2.3 Scanning, API, webhooks, Ask, MCP and CSV
- [x] *Add as parts* on scanned service records (a modification is made from
      a record or the form, which never carry the scanned lines).
- [x] API endpoints, OpenAPI, contract tests; webhook kinds and events.
- [x] Ask read tools and the draft tool; the running cost tools' build
      line; MCP.
- [x] CSV exports.
- [x] Translations (every shipped locale).

### 50.2.4 Tests and release
- [x] Warranty reminder due dates by date and by projected mileage; the
      lead time; removed, sold, returned or edited away; module off
      neither lists nor sends.
- [x] The insurer's list: current only, never costs, `ViewCosts` not
      needed; *Mark declared*.
- [x] Sale pack: modifications only with the tick; never prices.
- [x] Scanning fixtures with parts lines; nothing saved without *Save*.
- [x] API contract tests and the access matrix; webhooks signed and
      carrying ids and links only; Ask's grounding check on the build
      figure.
- [x] CSV exports of quantities and dates and the formula-injection guard
      (export only: no round trip, #443).
- [x] Suite green on every engine; coverage at or above the floor.
- [x] Design review (375, 768, 1280 px; light and dark; JS off; print
      preview; subpath).
- [x] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` rows ✅ for 50.1
      and 50.2.
- [x] Tag `v3.15.0` once merged.

---

## Acceptance criteria

1. A part's warranty can remind the owner before it ends, through their
   channels.
2. The owner can print or read out every current modification for an
   insurer, with when each was declared.
3. Parts and modifications reach the sale pack (by choice), scanning,
   the API, Ask, MCP and CSV, and every running cost per distance there
   still leaves build spend out.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided by the owner on 2026-10-15 and logged as #437–#443 in
`open-questions.md`; each took its recommendation.

- **A. Which warranties remind?** *Decided 2026-10-15 (#437): the recommendation.* Options: (1) a per-part tick, off by
  default; (2) every part with a warranty; (3) none, the badge only.
  *Recommendation:* (1): most parts' warranties need nothing doing, and a
  clutch's or a turbo's is worth a look before it ends.
- **B. Undeclared modifications.** *Decided 2026-10-15 (#438): the recommendation.* Options: (1) a *Not declared* badge
  and the insurer's list only; (2) also a *Needs attention* item for a
  modification not declared within 30 days on a vehicle with insurance on
  file. *Recommendation:* (1): Logbook doesn't know the policy's terms,
  and §7.24 keeps to what is certainly wrong.
- **C. Modifications in the sale pack.** *Decided 2026-10-15 (#439): the recommendation.* Options: (1) an owner tick, off
  by default, as open issues (#312); (2) on by default. *Recommendation:*
  (1).
- **D. Scanned parts lines.** *Decided 2026-10-15 (#440): the recommendation.* Options: (1) offered as *Add as parts*,
  unticked; (2) ticked; (3) not offered. *Recommendation:* (1): oil and
  screen wash are parts lines too, and few owners want them as parts.
- **E. Ask and build spend.** *Decided 2026-10-15 (#441): the recommendation.* Options: (1) the running cost tools leave
  build out and return it as its own figure, so an answer can say both;
  (2) a separate tool only. *Recommendation:* (1).
- **F. Webhook kinds.** *Decided 2026-10-15 (#442): the recommendation.* Options: (1) `modification` and `part`, with a
  part's fittings as `part` updates; (2) a third kind for fittings.
  *Recommendation:* (1).
- **G. CSV import.** *Decided 2026-10-15 (#443): the recommendation.* Options: (1) export only; (2) import too, matched
  by date and title as maintenance is. *Recommendation:* (1): few owners
  have a parts list in another app; import can follow when one asks.

- **Who may *Mark declared*?** (found while starting) *Decided 2026-10-15
  (#444): `Manage`.* Options: `Manage`; `Log`; `EntryAccess` per row.
- **What date does *Mark declared* set?** (found while starting) *Decided
  2026-10-15 (#445): a date field, today by default, not in the future and
  not before the modification's date.* Option: always today.
- **A warranty already ended when the tick is set** (found while
  starting) *Decided 2026-10-15 (#446): no reminder.* Option: an overdue
  reminder at once.
- **Where the tick goes** (found while starting) *Decided 2026-10-15
  (#447): the part form and the inline new-part row.* Option: the part form
  only.
