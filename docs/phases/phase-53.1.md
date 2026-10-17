# Phase 53.1 — Reading text from images: the OCR engine

*Logbook can turn a photo or a scanned PDF into text with positions,
on its own server.*

Status: 📋 planned · file lives in `docs/phases/`

Today, reading a file (§7.27, Phase 26.4) has two routes:

- **Photos** go to a vision model (`read_document`).
- **Text PDFs** have their text extracted and go to a text model
  (`read_text`).

Phase 26.4 put OCR engines out of scope: "the vision model reads
images". That leaves three gaps:

- an owner with only a **text** model can't scan a photo;
- a photo's evidence can't be checked;
- with **no AI at all**, nothing can be read.

Phase 53 adds Tesseract as a local OCR engine to fill all three:

| Part | What |
|---|---|
| **53.1 (this part)** | The engine: binary, preparing the image, text with positions, the admin status. No user-facing change yet. |
| [53.2](phase-53.2.md) | Photos and scanned PDFs read through OCR and a text model + release |
| [53.3](phase-53.3.md) | OCR as a check on the vision model, and redacted text instead of an image |
| [53.4](phase-53.4.md) | Reading without AI: rules for fuel receipts and a partial fill for invoices + release |

This reverses part of Phase 26.4's *Not in scope* (logged as a new
decision in 53.1.0). The vision model stays the first choice wherever
one is set up.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.27
(*Preparing the file*), §7.25 (tasks), §7.12 (uploads), §4 (Ghostscript,
the pattern for an optional binary) and §9 (`GHOSTSCRIPT_BINARY`) first.

**Prerequisites:** [Phase 45.3](phase-45.3.md) complete: hardening
passed (no feature phase starts before it).

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
5. **Status on Settings → AI** (and Settings → System when AI is off):
   - Tesseract's version;
   - its languages;
   - a *Test it* button that reads a bundled sample receipt and shows the
     layout text.

## Not in scope

- Any change to what users see when scanning ([53.2](phase-53.2.md)).
- Other OCR engines (PaddleOCR, docTR, a cloud OCR service). See open
  question A.
- Handwriting.

---

## Spec (proposed)

Added to §7.27 and §9 in 53.1.0:

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
- [ ] Open questions A–D decided by the owner and logged. The new
      decision reverses Phase 26.4's "OCR engines" line.
- [ ] §4, §7.27, §9 (`TESSERACT_BINARY`, `OCR_TIMEOUT`), §13;
      `ROADMAP.md` rows for 53.1–53.4.

### 53.1.1 Engine
- [ ] `OcrEngine`, its TSV parser and the layout text.
- [ ] The per-user lock, shared with the AI lock (§7.25 *Limits*), so a
      scan never runs two heavy jobs at once.
- [ ] Dockerfile: `tesseract-ocr` and the language packs; image size
      recorded in the release notes.

### 53.1.2 Preparation
- [ ] `ImagePreparer`: each step with a unit test on a known image.
- [ ] Scanned PDFs rendered at 300 dpi for OCR, reusing the Ghostscript
      or Imagick renderer.

### 53.1.3 Status and fixtures
- [ ] Settings status and *Test it*.
- [ ] `bin/ai-eval.php --ocr`: character accuracy of the layout text
      against hand-typed transcripts of `tests/Fixtures/scans/`, per
      kind, with and without preparation.
- [ ] Transcripts added for every existing scan fixture, plus at least
      ten real fuel receipts (faded, creased, angled).

### 53.1.4 Tests
- [ ] Engine missing, `off`, timing out, failing, or given an image
      that's too large: each a clean "OCR isn't available" result,
      never an exception reaching the user.
- [ ] No shell: arguments with spaces and quotes pass through untouched.
- [ ] The test suite passes without Tesseract installed (the OCR tests
      are skipped, and run in a CI job with it).

---

## Acceptance criteria

1. With Tesseract installed, Logbook turns a photo or scanned PDF into
   layout text with positions and confidences within the timeout.
2. Preparation measurably improves character accuracy on the receipt
   fixtures, with the numbers in the eval report.
3. Without Tesseract, nothing changes and nothing breaks.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Which OCR engine?**
  - (1) Tesseract 5, a local binary.
  - (2) PaddleOCR or docTR, as a separate container reached over HTTP.
  - (3) Both, behind one `OcrEngine` interface.

  *Recommendation:* (1), behind an interface so (2) can be added later.
  Tesseract needs no Python, runs on a Pi, and is packaged everywhere.

- **B. In the Docker image by default?**
  - (1) Yes, with English plus the shipped locales' languages (about
    20–30 MB).
  - (2) A separate `-ocr` image tag.

  *Recommendation:* (1). One image is simpler, and the size is
  modest next to Ghostscript.

- **C. Tesseract's model set.**
  - (1) `tessdata_fast`.
  - (2) `tessdata_best` (slower, a little more accurate).

  *Recommendation:* (1), unless 53.1.3's eval shows `best` clearly
  better on receipts.

- **D. Deskew in GD, or require Imagick?**
  - (1) GD, as above.
  - (2) Imagick when loaded, GD otherwise.

  *Recommendation:* (2). GD remains the guaranteed path, and the eval
  compares the two.
