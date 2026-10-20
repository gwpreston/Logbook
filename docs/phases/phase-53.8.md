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
- [ ] The fixture eval re-run: flags and false flags per kind with the
      new bar (K110's 29 of 57 as the baseline).
- [ ] #515 revisited with the OCR eval's numbers (the owner decides).
- [ ] Tests: a *Scan the V5C* photo on an *Internet* connection never
      sends an image, asserted at the adapter; a misread reference
      (`9O1`, `1234-5678-901`, wrapped) removed on that scan without
      markers; an invoice with "VSC warning light" keeps its figures; a
      blurred photo's right values not flagged.
- [ ] Design review of the new entry (375, 768, 1280 px; light and dark;
      JS off).

### 53.8.4 Known issues
- [x] K111: a wrapped reference with a label or date in its run, or a
      date after it, removed on V5C-like text.
- [x] K113: the value's own digits only; letters never folded on the
      value's side.
- [x] K117: a value whose row lost reference words is unchecked.
- [x] K112 (#530): the location resolved at the scan; K112's repro
      sends no image.
- [ ] K106 (#531): the sum sentence under the total only; volume and
      price "Doesn't add up with the total."
- [ ] K107 (#532): 53.3's migration's `down()` warns, naming the
      connections.
- [ ] K80: an inherited MediaBox and `/UserUnit` read.

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
   53.3's, with no invented value left unflagged that was flagged
   before.
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
