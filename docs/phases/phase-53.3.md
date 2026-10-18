# Phase 53.3 — OCR as a check on the vision model, and text instead of images

*A figure the vision model read is checked against what is actually on
the page. A photo bound for an Internet provider can go as redacted text
instead.*

Status: 🚧 in progress · file lives in `docs/phases/`

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

## Not in scope

- Rejecting values automatically. Every flag leaves the decision with
  the owner on the form, and nothing saves without *Save*.
- Running both routes and picking a winner per field.

---

## Spec (proposed)

Added to §7.27 in 53.3.0:

- **Running in parallel:** OCR runs alongside the vision request, never
  delaying it beyond OCR's own timeout. If OCR fails or times out, the
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
- [ ] OCR alongside vision, the per-value match, and confidence by area.
- [ ] Flags on the prefilled form.
- [ ] The fuel arithmetic check in the Mapper.

### 53.3.2 Text instead of images
- [ ] *Send text, not images* on connections (#509: off), refused at
      the gateway for any image.
- [ ] The reference's redaction: punctuation between groups on every
      path, look-alikes and wrapped rows on the OCR route, short gaps
      only (#500, #501; K87, K88, K93).
- [ ] V5C photos always sent as text on *Internet* when OCR is
      available, recognised by the OCR markers (#510).
- [ ] The Scan page and the connection page wording.
- [ ] Translations (every shipped locale).

### 53.3.2a Known issues
- [ ] K89: the evidence match filters candidates before the edit
      distance, with the same matches.
- [ ] K90: the fill-up's *Station and notes* opens when a scan left a
      reason there. K95: the Scan page's OCR line wraps beside its icon.
- [ ] K94: a Tesseract that fails its version check isn't asked for
      its languages.
- [ ] K85: the enlarge rule evaluated across the fixtures, and changed
      if the eval shows a better one.
- [ ] K96–K98: a size at the end of a parts line, a single capital after
      a number, and a doubled leading zero.

### 53.3.3 Tests
- [ ] Fixtures with planted wrong values (a vision answer edited to
      invent a total): flagged. Correct values: not flagged.
- [ ] OCR unsure in an area: no flag. OCR failing: scan unchanged.
- [ ] Arithmetic within and outside 1p; a receipt printing price to
      0.1p.
- [ ] No image ever sent on a connection with *Send text, not images*,
      asserted at the adapter.
- [ ] `bin/ai-eval.php --scans --crosscheck`: flags per kind and false
      flags, in the eval report.
- [ ] Carried from [53.2](phase-53.2.md): the first `bin/ai-eval.php
      --scans --via=ocr` run with the owner's models, its field accuracy
      per kind for both routes recorded in the eval report (53.2's
      acceptance criterion 3).
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; screen
      reader).

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
  point stores one, so only the markers apply.*
  - (1) Always as text on *Internet* connections when OCR is available.
  - (2) Follow the connection's option like any file.

  *Recommendation:* (1). Its reference number is the one thing §7.27
  already goes out of its way never to send.
