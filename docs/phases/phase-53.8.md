# Phase 53.8 — V5C privacy and cross-check accuracy from 53.3's review + release

*A V5C photo scanned from its vehicle never goes to an Internet provider
as a picture, a passing "DVLA" no longer empties a receipt's figures, and
the cross-check stops flagging right values on blurred photos.*

Status: 🚧 in progress · file lives in `docs/phases/` · releases
**v3.19.0**

Phase 53.3's merge review left five open questions about the V5C
reference and the cross-check (#511–#515). The owner decided them on
2026-10-19, before the v3.18.0 release, all the recommendation. The two
small ones were built before that release (#512's wording and warning,
#516); this phase builds the rest, and runs the evals with the owner's
models that 53.2 and 53.3 carried.

Read [`spec.md`](../../spec.md) §7.27 (*Where it goes*, *Preparing the
file*, *Reading through OCR*, *Checking values*), the
[53.3](phase-53.3.md) file's *Eval results* and *Merge review*, and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** v3.18.0 released (Phases 53.3 and 53.4).

---

## Goals

1. **#511, K104.** The vehicle page has a *Scan the V5C* entry. Its scan
   carries the target `vehicle` (`ScanAction` keeps it), is read as a
   registration document, and on an *Internet* connection always goes as
   redacted text, never as a picture, whatever OCR spots. With no OCR
   working, such a scan on an *Internet* connection isn't sent (the form
   opens empty with the file attached and the reason).
2. **#512 (4), K108.** A *Scan the V5C* scan's text gets the wide
   redaction (punctuation, look-alikes, wrapped rows) whatever its
   markers, on every route.
3. **#513, K109.** Any one V5C marker still keeps the picture from an
   *Internet* connection. The wide redaction needs a stronger sign: two
   different markers, or `V5C` or `Registration Certificate` on its own.
   "VSC warning light" on an invoice, or "DVLA" on an insurance letter,
   no longer empties fuel figures or dates.
4. **#514, K110.** The cross-check flags a value with digits only when
   each word with a digit in its row reads at a confidence of at least
   90. Documents under 30 words stay unchecked.
5. **Evals.** The owner's runs of `bin/ai-eval.php --scans --via=ocr`
   (53.2's acceptance criterion 3) and `--scans --crosscheck` (53.3's
   acceptance criterion 1), recorded in 53.3's *Eval results*; the
   fixture eval re-run for #514's new bar.
6. **#515, K114.** With the OCR eval's numbers, decide whether the
   cross-check reads the 2,000 px copy instead of the full-size file
   (#515's option 2). Not changed without them.
7. **Known issues** chosen by the owner on 2026-10-20: K111, K113 and
   K117 (in the code this phase changes); K112, the connection's
   location resolved when the scan is read (#530); K106, the sum
   sentence shown once (#531); K107, a rollback of 53.3's migration
   warns (#532); K80, an inherited page size and `/UserUnit`; K105,
   OCR's preparation alongside the vision request, built after #515.

## Not in scope

- Other ways of recognising a V5C (an 11-digit run alone, the photo's
  layout).
- Removing the warnings on routes other than a *Scan the V5C* scan.

## Tasks

### 53.8.0 Spec first
- [x] Spec §7.27 already carries the decisions (*From Phase 53.8*);
      re-read every open question and the *Open* rows of the known-issues
      log touching Scan before starting (CLAUDE.md §12). *2026-10-20:*
      none of the questions still open bears on Scan; they are carried.
      The open Scan rows K111, K113 and K117 are in code this phase
      changes; the owner added K112, K106, K107, K80 and K105 and
      decided #529–#532 (all the recommendation). Spec §7.27 and §13
      updated.

### 53.8.1 Scan the V5C
- [x] The entry on the vehicle page (and its documents tab), the
      `vehicle` target kept through `ScanAction` and the pending upload.
- [x] Such a scan: registration kind, wide redaction, text only on
      *Internet* connections; the page's warning not shown for it.

### 53.8.2 Markers and the cross-check
- [x] #513: the stronger sign for the wide redaction; one marker still
      blocks the picture.
- [x] #514: the per-digit-word confidence bar of 90.

### 53.8.3 Evals and tests
- [ ] Carried from [53.2](phase-53.2.md) and [53.3](phase-53.3.md): the
      owner's `--scans --via=ocr` and `--scans --crosscheck` runs, in
      53.3's *Eval results*.
- [x] The fixture eval re-run: flags and false flags per kind with the
      new bar (K110's 29 of 57 as the baseline). In *Eval results*: 29
      false flags fall to 2, and no planted wrong digit is caught on the
      fixtures; #533 decided by the owner.
- [ ] #515 revisited with the OCR eval's numbers (the owner decides).
- [ ] Tests: a *Scan the V5C* photo on an *Internet* connection never
      sends an image, asserted at the adapter; a misread reference
      (`9O1`, `1234-5678-901`, wrapped) removed on that scan without
      markers; an invoice with "VSC warning light" keeps its figures; a
      blurred photo's right values not flagged.
- [x] Design review of the new entry (375, 768, 1280 px; light and dark;
      JS off; at `/lb`). *2026-10-20 (design-reviewer at `d6c42f49`):* no
      HIGH. MEDIUM, fixed: the Details card's link stacked its icon above
      the text; as a `card__link` it is text only, like its neighbours.
      LOW, fixed: the Scan page promised text recognition on an *Internet*
      connection with no OCR (it now says such a scan can't be sent); a
      GET with `for=vehicle` for a vehicle the user can't manage fell
      back to the plain Scan page (it now shows the refusal). Not seen
      rendered: the unreadable-scan warning and the fuel form's sum
      marks (markup and tests only).

### 53.8.4 Known issues
- [x] K111: a wrapped reference with a label or date in its run, or a
      date after it, removed on V5C-like text.
- [x] K113: the value's own digits only; letters never folded on the
      value's side.
- [x] K117: a value whose row lost reference words is unchecked.
- [x] K112 (#530): the location resolved at the scan; K112's repro
      sends no image.
- [x] K106 (#531): the sum sentence under the total only; volume and
      price "Doesn't add up with the total."
- [x] K107 (#532): 53.3's migration's `down()` warns, naming the
      connections.
- [x] K80: an inherited MediaBox and `/UserUnit` read.

- [x] K130 (#534): the scan's JSON schema requires `kind`, `fields` and
      `lines`, names every field and list (null when absent), and each
      field's `value` and `evidence`, so LM Studio's constrained output no
      longer lets a model answer `{}` or leave `fields` out
      (`ScanSchemaTest`). The second half was found in the owner's second
      eval run: text PDFs 07, 09, 10, 13, 19, 20 and 24 read 0 fields
      with gemma-3-12b; with it, 07, 13 and 20 sent straight to LM Studio
      came back nearly whole.

### 53.8.5 K105, after #515
- [ ] OCR's preparation runs alongside the vision request (and a scanned
      PDF's later pages before the answer), as far as #515's choice of
      image leaves it worth doing.

### 53.8.6 Release
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅; tag once
      merged.

## Acceptance criteria

1. A V5C photo scanned with *Scan the V5C* never reaches an *Internet*
   provider as a picture, nor its reference in any form the wide rule
   catches.
2. A document that mentions a V5C marker once in passing keeps its
   figures.
3. The cross-check's false flags on the fixture photos fall well below
   53.3's, and an invented value is still flagged where OCR read the
   row's digits at a confidence of 90 or more (reworded 2026-10-20, #533:
   on the fixtures no bar keeps both halves of the first wording).
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided before this phase was written (#511–#515, 2026-10-19; see
[53.3](phase-53.3.md)). Found while starting it and decided by the owner
on 2026-10-20 (#529–#532), all the recommendation:

- **#529. Who runs the evals with the owner's models?** *Decided
  2026-10-20: (1) the owner, before the PR. The agent re-runs the
  fixture eval for #514 and gives the two commands; #515 is decided on
  the `--via=ocr` numbers.*
  - (1) The owner runs them before the PR.
  - (2) The agent runs them with a local model the owner names.
  - (3) Carried again; #515 stays (1).
- **#530. K112: fix it here, and how?** *Decided 2026-10-20: (1).*
  - (1) Route on the location resolved at the scan, as the gateway does
    at send.
  - (2) An acknowledged *Internet* connection always counts as
    *Internet* for V5C routing.
  - (3) Leave it open.
- **#531. K106: with the sum sentence shown once, what do volume and
  price show?** *Decided 2026-10-20: (1).*
  - (1) The sentence under the total; "Doesn't add up with the total."
    under the volume and the price.
  - (2) One line above the three fields.
  - (3) The sentence under the total only; the others unmarked.
- **#532. K107: what does 53.3's migration's `down()` do while a
  connection has *Send text, not images* on?** *Decided 2026-10-20: (1).*
  - (1) Warn, naming the connections, then roll back.
  - (2) Refuse unless forced.
  - (3) Leave it (the CHANGELOG note only).
- **#533. The fixture eval re-run for #514: no bar keeps acceptance
  criterion 3's "no invented value left unflagged that was flagged
  before".** (found while building) *Decided 2026-10-20: (1). The bar
  stays 90; criterion 3 is reworded to "an invented value is still
  flagged where OCR read the row's digits at 90 or more". The owner's
  `--crosscheck` run with real photos confirms it or reopens it.*
  - (1) Keep 90 and reword the criterion.
  - (2) A bar of 75 (3 false flags, 7 of 41 caught on the fixtures).
  - (3) Decide after the owner's `--crosscheck` run.
  - (4) Keep #508's rule (29 false flags, 39 of 41 caught).

- **#534. The owner's first eval runs read almost nothing: the scan
  schema lets a field be `{}`, and LM Studio holds gemma-4-e4b to it.
  Fix it here?** (found while running the evals) *Decided 2026-10-20:
  (1).*
  - (1) Fix in 53.8: each field requires `value` and `evidence`.
  - (2) Log it and fix it later.
- **#535. How are usable eval numbers got for #515 (and to confirm
  #533)?** (found while running the evals) *Decided 2026-10-20: (1). The
  owner re-runs both evals after #534, with a stronger vision model on
  `read_document` if one is loaded; the PR waits for them.*
  - (1) Re-run after the fix, with a bigger model.
  - (2) Re-run after the fix, with the same gemma.
  - (3) Carry the evals again; #515 stays (1) and K105 is carried.

## Eval results (2026-10-20)

**#514 on the fixtures.** Each photo and scanned PDF in
`tests/Fixtures/scans` (10 files, 57 right values) read by Tesseract
5.5.0 (eng) in the dev image; each expected value checked against OCR's
text as the vision route checks it, and again with one digit of each
value with digits changed (and in its evidence) as a planted wrong
value. Master (53.3's rule) against this branch (bar 90), without the
30-word gate, which only the V5C photo passes:

| Kind (files) | Right values | False flags, master | False flags, 53.8 | Planted | Caught, master | Caught, 53.8 |
|---|---|---|---|---|---|---|
| fuel_receipt (2) | 17 | 7 | 1 | 11 | 10 | 0 |
| inspection (1) | 5 | 2 | 0 | 4 | 4 | 0 |
| insurance (1) | 6 | 5 | 0 | 5 | 5 | 0 |
| registration (1) | 6 | 4 | 1 | 4 | 4 | 0 |
| repair_estimate (1) | 4 | 1 | 0 | 3 | 3 | 0 |
| service_invoice (4) | 19 | 10 | 0 | 14 | 13 | 0 |
| **All (10)** | 57 | 29 | 2 | 41 | 39 | 0 |

The two left are values without digits (a volume unit `L`, a V5C's
model), which keep the row-mean rule. Other bars: 60 gives 17 false
flags and 26 of 41 caught; 70, 10 and 16; 75, 3 and 7; 80, 2 and 5; 85,
2 and 1. The fixtures are blurred on purpose (mean confidence 74–86), so
OCR reads their right and wrong digits alike; a value invented on a row
OCR read at 90 or more is still flagged (`OcrTextTest`,
`ScanCrossCheckTest`). Decided as #533.

**The owner's first runs (LM Studio, gemma-4-e4b on `read_document`
and, through `ask`, on text).** Not usable: fields right 0-20% per kind
on both routes, text PDFs included (fixture 01: 0 of 12), so the
cross-check had nothing to mark (0 flags). About 70 tokens came back per
request: the model answered `"fields": {"date": {}}`, which the schema
allowed (#534, K130). With `value` and `evidence` required, fixture 01
sent straight to LM Studio came back with its date and total and their
evidence. Re-run after the fix (#535).
