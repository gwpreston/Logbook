# Phase 53.4 — Reading without AI + release

*No model set up? A fuel receipt still fills the fill-up form, and an
invoice still gives its date, total and mileage.*

Status: 📋 planned · file lives in `docs/phases/`

With no AI set up, the *Scan* entry points are hidden (§7.27). Many
owners will never set up a model: no GPU, no wish to pay a provider, or
no wish to send anything anywhere.

With [Phase 53.1](phase-53.1.md)'s OCR, Logbook can read some documents
by **rules** alone. The two kinds of document differ a lot here:

- **Fuel receipts** are regular. They also carry a built-in check: the
  volume × price per unit must equal the total. A triple that agrees to
  the penny is almost certainly right.
- **Service invoices** vary too much for rules beyond a few labelled
  fields.

So this part fills receipts properly, invoices partly, and other kinds
only with a date. Every field is marked "Read without AI, check every
field". The rules output goes through the same Mapper and the same
prefilled form as every other route.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.27,
§7.3 (fill-ups, grades and the Phase 26.3 grade resolver), §7.10
(modules) and Phases 53.1 to 53.3 first.

**Prerequisites:** [Phase 53.3](phase-53.3.md) complete and green.

---

## Goals

1. **Scanning without AI.**
   - With Tesseract available and no model, the scan entry points show
     (open question A covers the module).
   - Text PDFs use their text layer; photos and scanned PDFs use OCR.
2. **Classification by keywords:**
   - *fuel receipt*: litres or kWh, price per litre, grade words, pump;
   - *service invoice*: labour, parts, invoice, VAT, mileage;
   - *MOT certificate*: MOT, test, expiry, advisory;
   - *insurance*: policy, insurer, cover;
   - otherwise *other*.

   The page offers *Read it as* the other kinds, as today.
3. **Fuel receipt rules:**
   - Candidates for volume (a number followed by `L`, `LTR`, `LITRES`,
     `kWh` or `G`/`GAL`), price per unit (`p/L`, `PPL`, `£/L`, `/kWh`)
     and total (`TOTAL`, `AMOUNT`, `£` near the bottom).
   - The triple that satisfies volume × price = total (within 1p, or the
     price's own rounding) wins. With no agreeing triple, only the total
     is filled, and the volume and price are left for the owner.
   - Date and time in the locale's order, with the existing ambiguous
     date warning.
   - Grade words through the Phase 26.3 grade resolver.
   - The station's name from the top lines, matched to the owner's
     stations when one matches (§7.33).
4. **Service invoice partial fill:**
   - date;
   - total (the largest amount next to `TOTAL`, `BALANCE DUE` or
     `AMOUNT DUE`);
   - registration (the UK plate pattern, then matched to a vehicle as
     today);
   - odometer (a number next to `MILEAGE`, `ODO` or `MILES`);
   - vendor (the first line of text).

   The work lines go into the description unparsed (open question C).
5. **Other kinds:** the date only, with the rest left empty.
6. Release.

## Not in scope

- Learning from corrections, per-user templates, or per-station layouts
  (open question D).
- Rules for languages other than the shipped locales'. Keywords are
  translated with the locale files.
- Recommended work, advisories or schedule matching from rules alone.

---

## Spec (proposed)

Added to §7.27 in 53.4.0:

- **`Service\Scan\Rules\RuleReader`** returns the same per-kind values
  (`{value, evidence}`) as a model, with `evidence` being the OCR line
  it came from, so the Mapper, the hints and the cross-check work
  unchanged.
- **Keyword lists** live in the locale files (`scan.keywords.*`), so a
  German receipt's `GESAMT` and `Liter` work under `de`.
- **The notice** at the top of the form: "Read without AI on this
  server. Check every field before saving."
- **Nothing leaves the server.** The Scan page says *This server*, as
  for a local model.
- **No per-user lock or usage log entry.** That is §7.25's and AI's.
  OCR's own one-per-user limit still applies.

---

## Tasks

### 53.4.0 Spec first
- [ ] Open questions A–D decided by the owner and logged; §7.10 if A,
      §7.27, §13.

### 53.4.1 Rules
- [ ] The classifier and its keyword lists per shipped locale.
- [ ] The fuel receipt reader with the agreeing-triple search.
- [ ] The service invoice partial reader, and the date-only reader for
      other kinds.

### 53.4.2 Pages
- [ ] Scan entry points shown without AI when OCR is available.
- [ ] The notice; *Read it as*; the field marks.
- [ ] `docs/reading-files.md`: what reading without AI can and can't do.
- [ ] Translations (every shipped locale).

### 53.4.3 Tests and release
- [ ] The fuel fixtures (53.1.3's real receipts): volume, price, total
      and date accuracy, and **no wrong agreeing triple** on any fixture.
- [ ] Invoices: date, total, registration and mileage accuracy. No value
      filled from the wrong label.
- [ ] Classification accuracy per kind; *Read it as* re-mapping.
- [ ] Carried from [53.3](phase-53.3.md): the owner's runs of
      `bin/ai-eval.php --scans --crosscheck` (flags and false flags per
      kind: 53.3's acceptance criterion 1) and `--scans --via=ocr` (53.2's
      acceptance criterion 3), recorded in 53.3's *Eval results*.
- [ ] `bin/ai-eval.php --scans --via=rules` in the eval report beside the
      other routes.
- [ ] No request to any connection on this route, asserted.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off;
      German at 375 px).
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` rows ✅ for 53.3
      and 53.4; tag once merged.

---

## Acceptance criteria

1. Without any AI, a clear fuel receipt photo fills date, volume, price
   and total correctly, with the sum agreeing to the penny, or leaves
   them for the owner. It never fills a wrong agreeing set.
2. A printed service invoice fills at least its date and total.
3. Nothing is sent off the server, and every field is marked for
   checking.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Which module switches it?** `ai_scan` is listed only while AI is
  set up.
  - (1) Rename to `scan`, listed whenever any reading route exists, with
    `ai_scan` kept as an alias.
  - (2) A new `scan_rules` module.
  - (3) Always on with OCR, with no switch.

  *Recommendation:* (1). It is one switch for "reading files", whatever
  reads them.

- **B. Does the user's *Use AI features* setting cover reading without
  AI?**
  - (1) No: nothing is sent anywhere, so it needs no consent.
  - (2) Yes, for one simple rule.

  *Recommendation:* (1). The setting exists to keep data from models.
  The scan page's notice still says how the file was read.

- **C. Invoice work lines.**
  - (1) The OCR lines between the header and the totals go into the
    description, unparsed.
  - (2) Nothing; the owner types the work.

  *Recommendation:* (1), marked "Copied from the invoice, tidy as
  needed".

- **D. Learning a station's layout from corrections?**
  - (1) Not now, parked in §12.
  - (2) Remember, per station, which line held the total after the owner
    corrects it.

  *Recommendation:* (1). The agreeing-triple check already does most of
  the work, and stored layouts would be a new kind of data to keep and
  back up.
