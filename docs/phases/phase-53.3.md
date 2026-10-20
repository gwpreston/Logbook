# Phase 53.3 — OCR as a check on the vision model, and text instead of images

*A figure the vision model read is checked against what is actually on
the page. A photo bound for an Internet provider can go as redacted text
instead.*

Status: ✅ complete · released as **v3.18.0** with 53.4 · file lives in `docs/phases/`. The eval runs with the owner's models are carried to [53.8](phase-53.8.md), with #511, #513, #514 and #512's second part.

A vision model reads receipts better than OCR does, but it can also
**invent** a value: a plausible total, or a date that isn't printed.
Evidence checking (§7.27) catches this for text PDFs, because there is
text to search. For photos there isn't, so a made-up figure reaches the
form marked "From the file, check" and looks as solid as a real one.

With [Phase 53.1](phase-53.1.md)'s OCR text, a photo has text to check
against too. The same text also closes a privacy gap Phase 26.4 could
only warn about (#85): a photo sent to an *Internet* provider carries
everything printed on it, such as a V5C's reference number or an
insurance certificate's full address. Redacted text can be sent instead.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.27
(*Checking values*, *Where it goes*), §7.25 (connections, where they
run) and Phases 53.1 and 53.2 first.

**Prerequisites:** [Phase 53.2](phase-53.2.md) complete and green.

---

## Goals

1. **The cross-check.** When a vision model reads a photo and Tesseract
   is available, OCR runs on the same file at the same time. Each value's
   evidence is matched against the OCR text with 53.2's tolerant match:
   - **Found:** marked as today.
   - **Not found, OCR confident there** (the words in that area read
     with confidence ≥ 60): the value is **kept, but flagged** "Not
     found on the page, check this carefully" (open question A).
   - **OCR unsure:** no flag. Absence proves nothing.
2. **Agreement on the money.** For a fuel receipt, the volume, price per
   unit and total are checked against each other (volume × price within
   1p of the total, allowing for printed rounding). A disagreement flags
   the three fields with the sum: "45.20 L × 142.9p = £64.59, but the
   total reads £64.95". This also runs on the text routes.
3. ***Send text, not images*,** a per-connection option for *Internet*
   connections (53.2's open question C):
   - the photo is read by OCR on this server;
   - redacted;
   - sent as text to `read_text`'s model on that connection.

   The Scan page says so. It's off by default (open question B).
4. **Registration documents:** on an *Internet* connection, a V5C photo
   is always sent as redacted text when Tesseract is available, never as
   an image (open question C). #85's warning shows only when it isn't.
   *Superseded (#510): recognised by its markers; a short form of #85's
   warning stays on *Internet* connections for a V5C OCR doesn't spot
   (K104).*

## Not in scope

- Rejecting values automatically. Every flag leaves the decision with
  the owner on the form, and nothing saves without *Save*.
- Running both routes and picking a winner per field.

---

## Spec (proposed)

Added to §7.27 in 53.3.0:

- **Running in parallel:** OCR runs alongside the vision request, never
  delaying it beyond OCR's own timeout. *Superseded: OCR's preparation
  runs before the request and only the engine's reading alongside (spec
  §7.27 *Running alongside*, K105); on an *Internet* connection OCR
  finishes first (#510).* If OCR fails or times out, the
  cross-check is skipped and the scan carries on as today.
- **Flags** sit with the existing field hints, and are tied to their
  fields by `aria-describedby`, never shown by colour alone.
- **The arithmetic check** lives in the Mapper, so every route (vision,
  OCR plus a model, and Phase 53.4's rules) uses the same one.
- ***Send text, not images*** is stored on the connection (§6
  AiConnection). The connection's page explains the trade: "More
  private; a little less accurate on faded receipts."
- **Logging:** the cross-check's result is counted per scan in the
  usage log (values found, flagged, unchecked), never the text.

---

## Tasks

### 53.3.0 Spec first
- [x] Open questions A–C decided by the owner and logged (#508–#510),
      with #500 and #501, which *Send text, not images* rests on; §6,
      §7.25, §7.27, §13.
- [x] Known issues taken into the phase (§12): K87, K88 and K93 (the
      redaction, #500, #501), K89 (the match's cost, now on every vision
      value), K90 and K95 (53.5 left them for this phase), and, offered
      and chosen by the owner, K85, K94 and K96–K98.

### 53.3.1 Cross-check
- [x] OCR alongside vision, the per-value match, and confidence by area.
- [x] Flags on the prefilled form.
- [x] The fuel arithmetic check in the Mapper.

### 53.3.2 Text instead of images
- [x] *Send text, not images* on connections (#509: off), refused at
      the gateway for any image.
- [x] The reference's redaction: punctuation between groups on every
      path, look-alikes and wrapped rows on the OCR route, short gaps
      only (#500, #501; K87, K88, K93).
- [x] V5C photos always sent as text on *Internet* when OCR is
      available, recognised by the OCR markers (#510).
- [x] The Scan page and the connection page wording.
- [x] Translations (every shipped locale).

### 53.3.2a Known issues
- [x] K89: the evidence match filters candidates before the edit
      distance, with the same matches.
- [x] K90: the fill-up's *Station and notes* opens when a scan left a
      reason there. K95: the Scan page's OCR line wraps beside its icon.
- [x] K94: a Tesseract that fails its version check isn't asked for
      its languages.
- [x] K85: the enlarge rule evaluated across the fixtures, and changed
      if the eval shows a better one.
- [x] K96–K98: a size at the end of a parts line, a single capital after
      a number, and a doubled leading zero.

### 53.3.3 Tests
- [x] Fixtures with planted wrong values (a vision answer edited to
      invent a total): flagged. Correct values: not flagged.
- [x] OCR unsure in an area: no flag. OCR failing: scan unchanged.
- [x] Arithmetic within and outside 1p; a receipt printing price to
      0.1p.
- [x] No image ever sent on a connection with *Send text, not images*,
      asserted at the adapter.
- [x] `bin/ai-eval.php --scans --crosscheck`: flags per kind and false
      flags, in the eval report. Built; the deterministic pass is in
      *Eval results*. The run with the owner's models is *Scheduled
      (Phase 53.4)*.
- [ ] Carried from [53.2](phase-53.2.md): the first `bin/ai-eval.php
      --scans --via=ocr` run with the owner's models, its field accuracy
      per kind for both routes recorded in the eval report (53.2's
      acceptance criterion 3). *Scheduled (Phase 53.4)*: it needs the
      owner's models, and 53.4 is the release phase. Not run before the
      v3.18.0 release: carried to [53.8](phase-53.8.md).
- [x] Design review (375, 768, 1280 px; light and dark; JS off; screen
      reader).

### 53.3.4 Before the release (decided 2026-10-19)
- [x] #512 (1), K108: spec §7.27, the *Send text, not images* hint and
      the guides (`reading-files.md`, `ai.md`) no longer say a V5C's
      reference never leaves on the OCR route; the Scan page shows
      #510's short warning there on *Internet* connections
      (`ScanCrossCheckTest`). K87's note corrected.
- [x] #516, K115: the sum line in the receipt's currency, else plain
      numbers (`ScanMappingTest`).

### Release
- [x] Tag `v3.18.0` once merged.

---

## Eval results (2026-10-18)

**K85, the enlarge rule.** `bin/ai-eval.php --ocr
--enlarge=20:24,20:22,0:0,15:30`, Tesseract 5.5.0 (deu+eng) in the
Docker image, GD, character accuracy, mean per kind. *gd* is 53.1's rule
(text under 20 px enlarged to about 30 px); `min:target` the others;
*0:0* never enlarges.

| Kind (files) | none | gd (20:30) | 20:24 | 20:22 | 0:0 | 15:30 |
|---|---|---|---|---|---|---|
| claim_letter (2) | 99.8% | 99.8% | 99.8% | 99.8% | 99.8% | 99.8% |
| fuel_receipt (4) | 93.1% | 92.2% | 92.8% | 93.1% | 93.1% | 93.1% |
| inspection (3) | 96.2% | 94.7% | 93.9% | 90.2% | 96.2% | 96.2% |
| insurance (3) | 93.3% | 92.6% | 92.6% | 92.6% | 92.6% | 92.6% |
| other (1) | 99.3% | 99.3% | 99.3% | 99.3% | 99.3% | 99.3% |
| registration (2) | 94.6% | 94.6% | 94.6% | 94.6% | 94.6% | 94.6% |
| repair_estimate (1) | 93.9% | 93.0% | 93.0% | 93.0% | 93.0% | 93.0% |
| service_invoice (8) | 95.0% | 94.2% | 91.3% | 88.8% | 95.0% | 95.0% |

Enlarging text of 15–20 px lowered accuracy on every kind it touched,
and no fixture has text under 15 px. The rule is now 15:30 (equal to
never enlarging on these fixtures, while still helping very small
print). The MOT photo's dates now read `12/06/2026` and `11/06/2027`
(were `12/66/2626`).

**The cross-check and the OCR route with real models.** Not yet run:
they need the owner's models (agents never call a hosted provider).
The owner runs, before the merge review:

```bash
php bin/ai-eval.php --scans --crosscheck --verbose
php bin/ai-eval.php --scans --via=ocr --verbose
```

and the results (flags and false flags per kind; field accuracy per kind
for both routes, 53.2's acceptance criterion 3) are recorded here.
*Run on 2026-10-20 in Phase 53.8* (LM Studio, gemma-3-12b): vision
model 62–83% of fields per kind, through OCR 0–85% (9 of 10 photos and
scans under OCR's 30 words); no value marked by the cross-check, none
falsely. The tables are in [53.8](phase-53.8.md)'s *Eval results*.

---

## Merge review

**Round 1 at `73262d77` (2026-10-19): REJECT.** All gates passed on the
four engines. Blocking:

1. *HIGH, bug-hunter:* the punctuated 11-digit rule (#500) removed a
   small fill-up's `9.21 1.459 13.44` and dates with seconds from every
   document. **Fixed:** the owner narrowed #500 to text with a V5C's
   markers and a registration document's answer.
2. *HIGH, security-scanner:* on an *Internet* connection, a V5C whose
   markers OCR read but too little of it went as a picture. **Fixed:**
   it isn't sent at all.
3. *4 × MEDIUM, spec-keeper:* this file's eval tasks, #511, the
   superseded proposals and this section. **Fixed** here; the real-model
   evals are scheduled to 53.4.

**Round 2 at `bacdefd9` (2026-10-19): MERGE.** The three blocking
reasons are resolved; every gate passes on the four engines.

**Issue sweep (2026-10-19)** of both rounds' follow-ups: 30 items, 14
recorded as K108–K121, 5 open questions (#512–#516), K105 widened
(a scanned PDF's later pages are read after the model answers).

---

## Acceptance criteria

1. A value the vision model invented is flagged on the form when the
   page clearly doesn't contain it. Real values are rarely flagged: the
   eval report sets the rate.
2. Fuel figures that don't add up are flagged with the sum, on every
   route.
3. An owner can keep images off an Internet provider entirely, and a
   V5C photo never goes to one as an image when OCR is available.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided by the owner on 2026-10-18 and logged as #508–#510 in
`open-questions.md`, before it was built, with #500 and #501 (Phase
53.2's review: the redaction this phase relies on), both the
recommendation.

- **A. A value not found on the page: flag or drop?** *Decided
  2026-10-18 (#508): the recommendation, with how strict "found" is,
  found while starting: #498's tolerance alone accepts "£68.28" where
  "£60.28" is printed, so a found value's digits must also appear
  exactly in the matched stretch. "OCR confident there" is the row
  sharing most of the evidence's words, else the page.*
  - (1) Keep it and flag it.
  - (2) Drop it, as an unmatched value is dropped for text PDFs.

  *Recommendation:* (1). OCR misses real text often enough on receipts
  that dropping would lose good values. Revisit once the eval shows the
  false-flag rate.

- **B. *Send text, not images* default.** *Decided 2026-10-18 (#509):
  the recommendation.*
  - (1) Off.
  - (2) On for new *Internet* connections.

  *Recommendation:* (1). The vision route is more accurate, and the
  owner chose that provider. The option is explained where the
  connection is set up.

- **C. V5C photos.** *Decided 2026-10-18 (#510): the recommendation,
  with how a V5C is recognised before anything is sent, found while
  starting (the kind is otherwise only in the model's answer): V5C
  markers in the OCR text, so OCR runs first on *Internet* vision
  connections. The owner also chose the scan target `vehicle`; no entry
  point stores one, so the owner then chose (same day) the markers only,
  `VSC` too, not an 11-digit run (UK receipts print 11-digit phone
  numbers), with a short form of #85's warning kept on *Internet*
  connections (K104). After the merge review (2026-10-19): a V5C whose
  markers OCR reads but too little of it to send is not sent at all.*
  - (1) Always as text on *Internet* connections when OCR is available.
  - (2) Follow the connection's option like any file.

  *Recommendation:* (1). Its reference number is the one thing §7.27
  already goes out of its way never to send.

Found while building, logged as #511 (K104), and in the merge review's
issue sweep (2026-10-19), logged as #512–#516 with K108–K115. *Decided
2026-10-19*, before the v3.18.0 release, all the recommendation.

- **#511. A *Scan the V5C* entry on the vehicle page?** Its target would
  send an *Internet* V5C photo as text even when OCR doesn't spot its
  markers. *Decided: (1), in [Phase 53.8](phase-53.8.md).*
  - (1) Yes, in a later phase; the warning can then go.
  - (2) No: markers only, with the warning, as built.
- **#512. A V5C without markers on text routes** (K108). *Decided: (1)
  now, built before the release (53.3.4): the narrowing kept, the
  wording corrected and #510's short warning on the OCR route; then (4)
  in [Phase 53.8](phase-53.8.md): a *Scan the V5C* scan turns the wide
  rule on.* Not chosen: the wide rule on all *Internet*-bound text;
  wording only.
- **#513. Which V5C markers, and how many** (K109). *Decided: (2), in
  [Phase 53.8](phase-53.8.md): any one marker keeps the picture back,
  but the wide redaction needs two different markers, or `V5C` or
  `Registration Certificate` alone.* Not chosen: as #510; two for both;
  drop `VSC`, `DVLA` and `Document reference`.
- **#514. When is a value "not on the page"?** (K110, revisits #508).
  *Decided: (3), in [Phase 53.8](phase-53.8.md): flagged only when each
  word with a digit in its row reads at 90 or more; documents under 30
  words stay unchecked.* Not chosen: keep the rule; also try the value
  alone; allow single-digit OCR swaps.
- **#515. What image the cross-check's OCR reads** (K114). *Decided:
  (1) for now, the full-size file; revisited in
  [Phase 53.8](phase-53.8.md) with (2) once its OCR eval has run.* Not
  chosen: a budget; a written memory budget in spec §8 first.
- **#516. The sum line's currency on a foreign receipt** (K115).
  *Decided: (2), falling back to (3): the receipt's currency when one
  was read, else plain numbers. Built before the release (53.3.4).*
  Not chosen: the vehicle's; the price as printed with sum and total in
  the vehicle's.
