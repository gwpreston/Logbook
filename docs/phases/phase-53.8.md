# Phase 53.8 — V5C privacy and cross-check accuracy from 53.3's review + release

*A V5C photo scanned from its vehicle never goes to an Internet provider
as a picture, a passing "DVLA" no longer empties a receipt's figures, and
the cross-check stops flagging right values on blurred photos.*

Status: 📋 planned · file lives in `docs/phases/` · releases a minor
version

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

## Not in scope

- Other ways of recognising a V5C (an 11-digit run alone, the photo's
  layout).
- Removing the warnings on routes other than a *Scan the V5C* scan.

## Tasks

### 53.8.0 Spec first
- [ ] Spec §7.27 already carries the decisions (*From Phase 53.8*);
      re-read every open question and the *Open* rows of the known-issues
      log touching Scan before starting (CLAUDE.md §12).

### 53.8.1 Scan the V5C
- [ ] The entry on the vehicle page (and its documents tab), the
      `vehicle` target kept through `ScanAction` and the pending upload.
- [ ] Such a scan: registration kind, wide redaction, text only on
      *Internet* connections; the page's warning not shown for it.

### 53.8.2 Markers and the cross-check
- [ ] #513: the stronger sign for the wide redaction; one marker still
      blocks the picture.
- [ ] #514: the per-digit-word confidence bar of 90.

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

### 53.8.4 Release
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
[53.3](phase-53.3.md)). None open; anything found while building goes
here.
