# Phase 45.2 — Warranties, the insurer's list and parts everywhere + release

*A reminder before a warranty runs out, a list of modifications to read to
an insurer, and parts wherever entries go.*

Status: 📋 planned · file lives in `docs/phases/`

[Phase 45.1](phase-45.1.md) records parts, modifications and their
warranties on Logbook's own pages, and keeps build spend out of every
running cost per distance. This part takes them further: a warranty that
is about to end raises a reminder; the current modifications print as a
list for an insurance quote or a renewal; the sale pack can include them;
a scanned invoice's parts lines become parts; and the API, Ask, MCP, CSV
and webhooks carry them. Then the release.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.39
(written in Phase 45.1), then §7.6 (reminder sources), §7.19 (sale pack),
§7.20 (API and webhooks), §7.26 (Ask tools), §7.27 (reading files), §7.28
(MCP), §7.29 (the printable claims history is the nearest pattern for the
insurer's list) and §7.13 (CSV).

**Prerequisites:** [Phase 45.1](phase-45.1.md) complete and green.

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
- Anything in 45.1's *Not in scope*.

---

## Spec (proposed)

Added to §7.39 and the sections it touches in 45.2.0 once the open
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

### 45.2.0 Spec first
- [ ] Open questions A–G decided by the owner and logged in
      `open-questions.md`.
- [ ] §7.39, §7.6, §7.19, §7.20, §7.26, §7.27, §7.28, §7.7 CSV, §13.

### 45.2.1 Warranty reminders
- [ ] The tick on the part form; source `part` in *Sync* (created,
      moved, removed with the warranty and the part's status); *Checked
      it*; the reminders list, calendar and channels.

### 45.2.2 Insurer's list and sale pack
- [ ] *For your insurer* page and print layout; *Mark declared*; a
      *Not declared* badge on the *Parts* tab.
- [ ] Sale pack *Include modifications*.

### 45.2.3 Scanning, API, webhooks, Ask, MCP and CSV
- [ ] *Add as parts* on scanned service records and modifications.
- [ ] API endpoints, OpenAPI, contract tests; webhook kinds and events.
- [ ] Ask read tools and the draft tool; the running cost tools' build
      line; MCP.
- [ ] CSV exports.
- [ ] Translations (every shipped locale).

### 45.2.4 Tests and release
- [ ] Warranty reminder due dates by date and by projected mileage; the
      lead time; removed, sold, returned or edited away; module off
      neither lists nor sends.
- [ ] The insurer's list: current only, never costs, `ViewCosts` not
      needed; *Mark declared*.
- [ ] Sale pack: modifications only with the tick; never prices.
- [ ] Scanning fixtures with parts lines; nothing saved without *Save*.
- [ ] API contract tests and the access matrix; webhooks signed and
      carrying ids and links only; Ask's grounding check on the build
      figure.
- [ ] CSV round trips of quantities and dates; formula-injection guard.
- [ ] Suite green on every engine; coverage at or above the floor.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; print
      preview; subpath).
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` rows ✅ for 45.1
      and 45.2.
- [ ] Tag once merged.

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

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Which warranties remind?** Options: (1) a per-part tick, off by
  default; (2) every part with a warranty; (3) none, the badge only.
  *Recommendation:* (1): most parts' warranties need nothing doing, and a
  clutch's or a turbo's is worth a look before it ends.
- **B. Undeclared modifications.** Options: (1) a *Not declared* badge
  and the insurer's list only; (2) also a *Needs attention* item for a
  modification not declared within 30 days on a vehicle with insurance on
  file. *Recommendation:* (1): Logbook doesn't know the policy's terms,
  and §7.24 keeps to what is certainly wrong.
- **C. Modifications in the sale pack.** Options: (1) an owner tick, off
  by default, as open issues (#312); (2) on by default. *Recommendation:*
  (1).
- **D. Scanned parts lines.** Options: (1) offered as *Add as parts*,
  unticked; (2) ticked; (3) not offered. *Recommendation:* (1): oil and
  screen wash are parts lines too, and few owners want them as parts.
- **E. Ask and build spend.** Options: (1) the running cost tools leave
  build out and return it as its own figure, so an answer can say both;
  (2) a separate tool only. *Recommendation:* (1).
- **F. Webhook kinds.** Options: (1) `modification` and `part`, with a
  part's fittings as `part` updates; (2) a third kind for fittings.
  *Recommendation:* (1).
- **G. CSV import.** Options: (1) export only; (2) import too, matched
  by date and title as maintenance is. *Recommendation:* (1): few owners
  have a parts list in another app; import can follow when one asks.
