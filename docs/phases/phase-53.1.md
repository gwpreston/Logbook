# Phase 53.1 — Reading text from images: the OCR engine

*Logbook can turn a photo or a scanned PDF into text with positions,
on its own server.*

Status: 🚧 in progress · file lives in `docs/phases/`

Today, reading a file (§7.27, Phase 26.4) has two routes:

- **Photos** go to a vision model (`read_document`).
- **Text PDFs** have their text extracted and go to a text model
  (`read_text`).

Phase 26.4 put OCR engines out of scope: "the vision model reads
images". That leaves three gaps:

- an owner with only a **text** model can't scan a photo;
- a photo's evidence can't be checked;
- with **no AI at all**, nothing can be read.

Phase 53 adds OCR to fill all three: Tesseract on this server, and
PaddleOCR as a service the admin runs, behind one interface (#489):

| Part | What |
|---|---|
| **53.1 (this part)** | The engines (Tesseract and PaddleOCR), preparing the image, text with positions, the admin status. No user-facing change yet. |
| [53.2](phase-53.2.md) | Photos and scanned PDFs read through OCR and a text model + release |
| [53.3](phase-53.3.md) | OCR as a check on the vision model, and redacted text instead of an image |
| [53.4](phase-53.4.md) | Reading without AI: rules for fuel receipts and a partial fill for invoices + release |

This reverses part of Phase 26.4's *Not in scope* (logged as a new
decision in 53.1.0). The vision model stays the first choice wherever
one is set up.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.27
(*Preparing the file*), §7.25 (tasks), §7.12 (uploads), §4 (Ghostscript,
the pattern for an optional binary) and §9 (`GHOSTSCRIPT_BINARY`) first.

**Prerequisites:** [Phase 52](phase-52.md) merged (v3.16.2); in practice
built on [Phase 52.2](phase-52.2.md) (v3.16.4).

---

## Goals

1. **Tesseract as an optional binary**, found and switched as
   Ghostscript is: `TESSERACT_BINARY` (default `tesseract` on `PATH`;
   `off` turns it off). It is installed in the Docker image with the
   language packs for every shipped locale.
2. **`Service\Ocr\OcrEngine`**, which runs it without a shell, with a
   timeout, a memory limit and one run per user at a time.
3. **Preparing the image** for OCR, kept separate from the file sent to a
   vision model:
   - turned upright;
   - greyscale;
   - contrast stretched;
   - deskewed;
   - scaled so text is roughly 30 px high.
4. **The result:** words, lines and blocks with their positions and
   confidences (Tesseract's TSV), plus a **layout text**: the lines in
   reading order, with wide gaps kept as columns, so "Total" and "£48.20"
   stay on one line.
5. **PaddleOCR** (#489, #493) as a second engine behind the same
   interface, reached over HTTP at `OCR_URL`, chosen by `OCR_ENGINE`
   (#494), with an optional `ocr` profile in `docker-compose.yml`
   (#495).
6. **Status on Settings → AI** (and Settings' *Installation* group when
   AI is off; there is no Settings → System page):
   - the engine and its version;
   - its languages;
   - a *Test it* button that reads a bundled sample receipt and shows the
     layout text.

## Not in scope

- Any change to what users see when scanning ([53.2](phase-53.2.md)).
- Other OCR engines (docTR, a cloud OCR service). PaddleOCR is in
  scope (open question A, decided 2026-10-17: both engines now).
- Handwriting.

---

## Spec

Added to §4, §7.27 (*Reading text from images*) and §9 in 53.1.0. The
draft below was the starting point; where the spec differs (the TSV goes
to a temporary file, not stdout, so a long result can't stall a pipe;
PaddleOCR; `OCR_ENGINE`), the spec wins:

- **The engine** runs as
  `tesseract <in> stdout -l <langs> --psm <mode> tsv` through
  `proc_open` with an argument list.
  - Timeout: `OCR_TIMEOUT` (default 30 s).
  - Input: at most 4,000 px on the long edge.
  - Pages: the same three-page limit as scanned PDFs, rendered at 300 dpi
    for OCR rather than 150.
  - Languages: the user's locale's language plus English (`deu+eng`).
- **Page segmentation:** mode 4 (single column of text of variable
  sizes) for receipts, mode 3 (automatic) for A4. The mode is chosen
  from the image's aspect ratio: taller than 2:1 is a receipt.
- **`OcrResult`:**
  - `words` (text, confidence 0–100, box);
  - `lines`;
  - `layout_text`;
  - `mean_confidence`;
  - `language`;
  - `engine_version`.

  It is never stored. It lives for one scan request, like the prepared
  file.
- **Preparation** (`Service\Ocr\ImagePreparer`, GD only, so no new
  required extension):
  - EXIF orientation;
  - greyscale;
  - a percentile contrast stretch;
  - deskew by the angle that maximises row variance (±10°, in 0.5° steps);
  - an upscale when the median word height is under 20 px.
- **Size:** the Docker image grows by Tesseract and its language packs
  (open question B). Bare-PHP installs are told how to add it in
  `docs/reading-files.md`.

---

## Tasks

### 53.1.0 Spec first
- [x] Open questions A–D decided by the owner and logged (#489–#492),
      with four found while starting (#493–#496). The new decision
      reverses Phase 26.4's "OCR engines" line.
- [x] §4, §7.27, §9 (`OCR_ENGINE`, `TESSERACT_BINARY`, `OCR_URL`,
      `OCR_TIMEOUT`), §13; `ROADMAP.md` rows for 53.1–53.4;
      `.env.example`; `docs/reading-files.md`.

### 53.1.1 Engine
- [x] `OcrEngine`, its TSV parser and the layout text (column gaps kept
      between words as well as between lines).
- [x] The per-user lock, shared with the AI lock (§7.25 *Limits*), so a
      scan never runs two heavy jobs at once (`OcrReader::read()`;
      `readHeld()` for a caller already holding it).
- [x] Dockerfile: `tesseract-ocr` and the language packs; image size
      recorded in the release notes (78 MB uncompressed on arm64,
      measured 2026-10-17; for 53.2's release notes).
- [x] `PaddleOcrEngine` (#493): the PaddleX serving request and answer,
      words estimated from lines; `OCR_ENGINE` choosing (#494). Checked
      against a live PaddleX 3.7.2 service on arm64.
- [x] `docker-compose.yml` (and the MySQL file's OCR variables): an
      optional `ocr` profile running PaddleOCR, built from
      `docker/paddleocr/` for amd64 and arm64 (#495: there is no official
      arm64 serving image). 1.5 GB built on arm64.

### 53.1.2 Preparation
- [x] `ImagePreparer`: each step with a unit test on a known image (GD;
      Imagick's tests run where it is loaded).
- [x] Scanned PDFs rendered at 300 dpi for OCR, reusing the Ghostscript
      or Imagick renderer.

### 53.1.3 Status and fixtures
- [x] Settings status and *Test it* (`/settings/ocr`; a card on Settings
      → AI; Installation when AI is off), reading
      `resources/ocr/sample-receipt.png` (made by
      `bin/tools/make-ocr-sample.php`).
- [x] `bin/ai-eval.php --ocr`: character accuracy of the layout text
      against transcripts of `tests/Fixtures/scans/`, per kind, with and
      without preparation, GD and Imagick; `--degraded` adds faded,
      tilted, shrunk copies of the photos.
- [x] Transcripts added for every existing scan fixture (`<name>.txt`).
      They are what was printed, written from `manifest.php` by
      `build.php`, so exact rather than hand-typed; real receipts go in
      `receipts/`, which `build.php` leaves alone.
- [ ] At least ten real fuel receipts (faded, creased, angled), photos
      supplied by the owner with personal details redacted (#496), and
      their transcripts. Open until they arrive.

### 53.1.4 Tests
- [x] Engine missing, `off`, timing out, failing, or given an image
      that's too large: each a clean "OCR isn't available" result,
      never an exception reaching the user (`TesseractEngineTest`,
      `PaddleOcrEngineTest`, `OcrReaderTest`, `GdImagePreparerTest`).
- [x] No shell: arguments with spaces and quotes pass through untouched
      (`ProcessRunnerTest`, `TesseractEngineTest`).
- [x] The test suite passes without Tesseract installed (a stand-in
      script for the engine tests; `RealTesseractTest` and the Imagick
      tests skip, and CI installs Tesseract on every row). PostgreSQL and
      MySQL runs of the OCR tests pass.

---

## Eval results (2026-10-17)

`bin/ai-eval.php --ocr --degraded`, character accuracy, mean per kind.

Tesseract 5.5.0 (`deu+eng`, Docker, arm64), all 24 fixtures:

| Kind | none | GD | Imagick |
|---|---|---|---|
| service invoice (8) | 95.0% | 94.1% | 94.2% |
| fuel receipt (4) | 93.1% | 92.2% | 92.2% |
| fuel receipt, degraded (2) | 42.2% | 91.6% | 92.2% |
| service invoice, degraded (3) | 84.9% | 92.5% | 92.7% |
| registration, degraded (1) | 74.3% | 90.1% | 93.1% |
| inspection, degraded (1) | 87.5% | 88.6% | 73.9% |

PaddleOCR (PaddleX 3.7.2, PP-OCRv6, CPU), the 8 photos: 95.5–100%
unprepared; degraded copies 57.9–100% unprepared and 96.0–98.9% with GD.
About 8 s a page unprepared and 21 s prepared (enlarged) on this CPU,
so the docs suggest `OCR_TIMEOUT=120` for it.

On the clean synthetic fixtures preparation costs about one point: their
bitmap-font lines (17 px) are enlarged by the 20 px rule. On degraded
copies it is the difference between half and nearly all of the text.
Criterion 2 is measured on the owner's real receipts once they arrive
(#496).

## Acceptance criteria

1. With Tesseract installed, Logbook turns a photo or scanned PDF into
   layout text with positions and confidences within the timeout.
2. Preparation measurably improves character accuracy on the receipt
   fixtures, with the numbers in the eval report.
3. Without Tesseract, nothing changes and nothing breaks.
4. Definition of done (CLAUDE.md §11) holds.

Checked so far (2026-10-17):

1. Yes: the image built from this Dockerfile reads the sample receipt;
   `RealTesseractTest` in CI; the eval above.
2. Open until #496: preparation lifts degraded copies (42% → 92% for
   fuel receipts) but costs about a point on clean synthetic ones; the
   owner's real receipts decide it.
3. Yes: with `TESSERACT_BINARY=off` (`OcrSettingsTest`,
   `TesseractEngineTest`) the scan routes are untouched; nothing outside
   `/settings/ocr` and its links uses OCR yet.
4. Lint, analyse, tests on SQLite, PostgreSQL and MySQL; coverage in CI.

## Open questions

Decided by the owner on 2026-10-17 and logged as #489–#492 in
`open-questions.md`, before it was built, with #493–#496, found while
starting it.

- **A. Which OCR engine?** *Decided 2026-10-17 (#489): (3), both now,
  behind one `OcrEngine` interface. Not the recommendation.*
  - (1) Tesseract 5, a local binary.
  - (2) PaddleOCR or docTR, as a separate container reached over HTTP.
  - (3) Both, behind one `OcrEngine` interface.

  *Recommendation:* (1), behind an interface so (2) can be added later.
  Tesseract needs no Python, runs on a Pi, and is packaged everywhere.

- **B. In the Docker image by default?** *Decided 2026-10-17 (#490):
  the recommendation.*
  - (1) Yes, with English plus the shipped locales' languages (about
    20–30 MB).
  - (2) A separate `-ocr` image tag.

  *Recommendation:* (1). One image is simpler, and the size is
  modest next to Ghostscript.

- **C. Tesseract's model set.** *Decided 2026-10-17 (#491): the
  recommendation. Debian's language packs are `tessdata_fast` (their
  `debian/copyright` names it).*
  - (1) `tessdata_fast`.
  - (2) `tessdata_best` (slower, a little more accurate).

  *Recommendation:* (1), unless 53.1.3's eval shows `best` clearly
  better on receipts.

- **D. Deskew in GD, or require Imagick?** *Decided 2026-10-17 (#492):
  the recommendation.*
  - (1) GD, as above.
  - (2) Imagick when loaded, GD otherwise.

  *Recommendation:* (2). GD remains the guaranteed path, and the eval
  compares the two.

Found while starting it (2026-10-17), after A chose both engines:

- **#493. Which HTTP engine?** *Decided 2026-10-17: PaddleOCR*
  (PaddleX basic serving, `POST /ocr`), the recommendation. Not chosen:
  docTR (its API is a sample app); a contract of Logbook's own.
- **#494. Which engine when both are set up?** *Decided 2026-10-17:*
  an `OCR_ENGINE` setting, `auto` | `tesseract` | `paddleocr` | `off`;
  `auto` uses PaddleOCR when `OCR_URL` is set, else Tesseract. The
  recommendation (offered as `http`; named `paddleocr` for what it
  speaks). Not chosen: Tesseract first; PaddleOCR first.
- **#495. PaddleOCR in docker-compose?** *Decided 2026-10-17:* an
  optional `ocr` profile, off by default. Not chosen: docs only.
- **#496. Where do the real fuel receipts come from?** *Decided
  2026-10-17:* the owner supplies photos, personal details redacted;
  transcripts are typed for them. The task stays open until they
  arrive. Not chosen: synthetic receipts.
