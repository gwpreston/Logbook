# Phase 53.4 — Reading without AI + release

*No model set up? A fuel receipt still fills the fill-up form, and an
invoice still gives its date, total and mileage.*

Status: 🚧 in progress · file lives in `docs/phases/`

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

## Spec (written into §7.27 in 53.4.0)

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
- [x] Open questions A–H decided by the owner and logged (#517–#524);
      §7.10, §7.27, §12, §13.
- [x] Known issues K116, K118, K119 and K120 (53.3's merge review),
      chosen by the owner for this phase: fixed.

### 53.4.1 Rules
- [x] The classifier and its keyword lists per shipped locale.
- [x] The fuel receipt reader with the agreeing-triple search.
- [x] The service invoice partial reader, and the date-only reader for
      other kinds (and the V5C's registration, #522).

### 53.4.2 Pages
- [x] Scan entry points shown without AI when OCR is available (routed
      with `AI_ENABLED=false` too, #521).
- [x] The notice; *Read it as*; the field marks.
- [x] `docs/reading-files.md`: what reading without AI can and can't do.
- [x] Translations (every shipped locale).

### 53.4.3 Tests and release
- [x] Merge-review fixes (2026-10-19, two rounds): the set search bounded
      (12 candidates of each, shop-basket amounts counted only below
      that); month names built once per locale; an invoice's own date
      beside a *next service* date; strict lone totals and no rules
      vendor except a known station (#526); a V5C's reference scrubbed
      from every kind's reading. Third round: the strict total is the
      first amount directly after a grand-total label, never a qualified
      one, none when two differ (#526); numbers of more than 12 digits
      passed over; amounts scanned in linear time; a V5C reference with
      look-alikes across a wide gap scrubbed too. Each with a
      `RuleReaderTest` case. The
      `--via=rules` table below was re-run by the merge review's AI
      evaluator after the first round and matched.
- [x] The fuel fixtures: volume, price, total and date accuracy, and **no
      wrong agreeing triple** on any fixture (`RuleReaderTest`, the eval
      below). The real receipts (#496) haven't arrived: run again on them.
- [x] Invoices: date, total, registration and mileage accuracy. No value
      filled from the wrong label.
- [x] Classification accuracy per kind; *Read it as* re-mapping.
- [ ] Carried from [53.3](phase-53.3.md): the owner's runs of
      `bin/ai-eval.php --scans --crosscheck` (flags and false flags per
      kind: 53.3's acceptance criterion 1) and `--scans --via=ocr` (53.2's
      acceptance criterion 3), recorded in 53.3's *Eval results*.
- [x] `bin/ai-eval.php --scans --via=rules` in the eval report beside the
      other routes (below).
- [x] No request to any connection on this route, asserted
      (`ScanWithoutAiTest`).
- [x] Design review (375, 768, 1280 px; light and dark; JS off;
      German at 375 px): no high or medium findings; two low fixed (the
      notice's read-by line's contrast on its tint; the screen-reader
      prefix of a field read by rules). Not rendered: the `rules_text`
      and PaddleOCR lines, and the invoice's *Copied* hint (tested).
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` rows ✅ for 53.3
      and 53.4; tag once merged.

---

- **I. How short may an OCR'd photo be for rules?** (found while
  building: 53.2's 30-word minimum left every photo fixture empty, and
  without it a misread lone total, `66,28` for 60.28, was filled)
  *Decided 2026-10-19 (#525): (1).*
  - (1) No 30-word minimum for rules (OCR's confidence floor of 40
    only), and a total no sum checks (a fuel receipt's with no agreeing
    set, an invoice's) is filled only when the amount itself reads at a
    confidence of at least 90.
  - (2) No minimum, a lone total always filled.
  - (3) Keep the 30-word minimum.

- **J. When does reading without AI fill a vendor, and a total no sum
  checks?** (found in the merge review: each round of label rules gave
  new wrong values, an address as the station, a price as the total)
  *Decided 2026-10-19 (#526): fill less, more surely.*
  - The station only when it matches one of the install's stations; an
    invoice's garage never.
  - A lone total only after a word labelling the total itself, never a
    number with a unit or a price, never after a not-total word, and
    read clearly (#525). After the third merge review (same day): the
    first amount *directly* after a grand-total label, never a
    qualified label (`Net total`, `Total labour`, `Total mileage`), and
    nothing when two different such totals are printed.
  - Not chosen: fixing each layout case by case.

## Eval results (2026-10-19)

**`bin/ai-eval.php --scans --via=rules`**, Tesseract 5.5.0 (deu+eng) in
the dev Docker image, GD, the `demo` user (en_GB), after #525, #526 and
the merge-review fixes. *Fields ok* counts every field a model would
fill, so the rules' partial reading scores low by design. Since #526 no
fixture fills a station or garage (none is one of the demo's stations),
which lowered *Fields ok* for receipts (50% → 44%) and invoices (41% →
31%).

| Kind (files) | Kind ok | Fields ok |
|---|---|---|
| service_invoice (8) | 7 | 31% |
| fuel_receipt (4) | 4 | 44% |
| inspection (3) | 3 | 17% |
| insurance (3) | 3 | 11% |
| registration (2) | 2 | 10% |
| other (1) | 1 | 20% |
| claim_letter (2) | 2 | 12% |
| repair_estimate (1) | 0 | 0% |

- The only wrong values filled are OCR digit misreads on photos: 02's
  odometer `47,618` (marked *Hard to read*) and 15's registration
  `AB12 COE`.
- **No wrong value** in a volume, price or total on any fixture, and no
  wrong agreeing set. The two text-PDF receipts (07, 09) fill volume,
  price, total and date exactly; the text-PDF invoices their date and
  total.
- On the synthetic photos Tesseract reads 0 as 6 or 8 and `.` as `,`
  (`TOTAL GBP 66,28`, `14/16/2026 68:42`), so no set adds up. Before
  #525 the misread totals (`66,28`, `129,88`, `245,68`, `25,68`,
  `32,68`) were filled; now a total no sum checks needs a confidence of
  at least 90, and all of them are left for the owner, with one right
  total (08's `57.56`, read at under 90) left too. Before the 30-word
  minimum was dropped for rules (#525), every photo fixture gave an
  empty form. The owner's real receipts (#496) are the better test.
- The German invoice (20) is *other* for an English user: the German
  keywords are read only under `de` (with English always). Under `de` it
  is an invoice (`RuleReaderTest`).
- The repair estimate photo (23) reads as an invoice: OCR reads
  `ESTIHATE` and `Estinate`, so no estimate word is found, and `Reg` and
  `repair` count for an invoice. *Read it as* puts it right.
- No registration reference in any output.

## Acceptance criteria

1. Without any AI, a clear fuel receipt photo fills date, volume, price
   and total correctly, with the sum agreeing to the penny, or leaves
   them for the owner. It never fills a wrong agreeing set.
2. A printed service invoice fills at least its date and total.
3. Nothing is sent off the server, and every field is marked for
   checking.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided by the owner on 2026-10-19 and logged as #517–#524 in
`open-questions.md`, before it was built: A–D as the phase file
recommended, and E–H, found while starting it, also the recommendation.
I (#525) was found while building it and decided the same day, the
recommendation.

- **A. Which module switches it?** *Decided 2026-10-19 (#517): (1).
  `ai_scan` becomes `scan` ("Reading files"), listed on Settings →
  Modules whenever a model or a working OCR engine can read a file. The
  stored `ai_scan` setting and `FEATURES_AI_SCAN` still work, as aliases.*
  - (1) Rename to `scan`, listed whenever any reading route exists, with
    `ai_scan` kept as an alias.
  - (2) A new `scan_rules` module.
  - (3) Always on with OCR, with no switch.

- **B. Does the user's *Use AI features* setting cover reading without
  AI?** *Decided 2026-10-19 (#518): (1). A user with it off is read by
  rules, and no model ever sees their file, whatever is set up.*
  - (1) No: nothing is sent anywhere, so it needs no consent.
  - (2) Yes, for one simple rule.

- **C. Invoice work lines.** *Decided 2026-10-19 (#519): (1), marked
  "Copied from the invoice, tidy as needed".*
  - (1) The OCR lines between the header and the totals go into the
    description, unparsed.
  - (2) Nothing; the owner types the work.

- **D. Learning a station's layout from corrections?** *Decided
  2026-10-19 (#520): (1), parked in spec §12.*
  - (1) Not now, parked in §12.
  - (2) Remember, per station, which line held the total after the owner
    corrects it.

- **E. With AI switched off for the install (`AI_ENABLED=false`), does
  reading without AI still show?** (found while starting) *Decided
  2026-10-19 (#521): (1).*
  - (1) Yes: the `scan` module alone decides; OCR is not an AI feature
    (`.env.example` already says so).
  - (2) No: AI off hides every Scan entry point.

- **F. A V5C read without AI.** (found while starting: the classifier's
  list had no registration kind, so a V5C would be *other* and attached
  to an `other` document with its reference) *Decided 2026-10-19 (#522):
  (1).*
  - (1) Classified as a registration document; only the registration is
    read (the UK plate pattern); the usual V5C page, the file kept only
    when ticked.
  - (2) Classified, nothing read.
  - (3) *Other*, date only.

- **G. Fall back to rules when a model's read fails?** (found while
  starting) *Decided 2026-10-19 (#523): (1), parked in spec §12.*
  - (1) No: failures stay as today; rules read only when no model reads
    for this user.
  - (2) Yes, with OCR working.

- **H. How strong is a V5C marker in the classifier?** (found while
  starting; bears on #513, K109) *Decided 2026-10-19 (#524): (1). The
  check that redacts and blocks pictures (#510) is unchanged until #513
  is decided.*
  - (1) A registration keyword like any other: an invoice's or an
    insurance document's own words can outscore it, so "VSC warning
    light" on a Toyota invoice stays an invoice.
  - (2) A marker wins outright.
