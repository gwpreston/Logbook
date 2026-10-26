# Phase 53.15 — OCR speed and the PaddleOCR image from the known-issues log + patch release

*Three open rows of the known-issues log closed: Imagick prepares a
photo for OCR as fast as GD does, a scan's OCR preparation overlaps the
model request, and the optional PaddleOCR image installs from pinned
sources and runs as a non-root user.*

Status: ✅ complete · file lives in `docs/phases/` · released as **v3.19.7**, the
patch after Phase 53.14's release

This is a batch of open rows that wait on no open question. K114 is
left out: it waits on #515.

Read [`CLAUDE.md`](../../CLAUDE.md) (§8, §11, §12),
[`spec.md`](../../spec.md) (the OCR and scan sections), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.14](phase-53.14.md) merged, and its release
tagged.

---

## Goals

1. **K74 (MEDIUM).** `ImagickImagePreparer` resizes with `scaleImage`
   (or equivalent) instead of a Lanczos resize under the 128 MB pixel
   cache, so a 4032×3024 photo takes about GD's time or less; the class
   docblock says what is measured. If the A4 scan gap needs a higher
   pixel-cache cap or reopens #492, that is raised as an open question
   and the task waits; the resize change goes ahead either way.
2. **K105.** The cross-check's OCR preparation of the first page runs
   alongside the vision request, and later pages of a scanned PDF are
   prepared while the model answers, so the time after the answer
   shrinks. Measure first; keep only a change that is faster on the
   fixtures.
3. **K79.** `docker/paddleocr/Dockerfile` installs PaddlePaddle from
   PyPI with pinned versions and hashes where available, pins what
   `paddlex --install serving` pulls in, and runs as a non-root `USER`
   with its model volume moved off `/root`.

## Not in scope

- K114 (full-size file for the cross-check): waits on #515.
- Changing what OCR reads or how a scan's fields are checked.

---

## Decisions (and why)

- **Measure before and after** for K74 and K105, on the fixtures in
  `tests/Fixtures`, with GD and Imagick. K105's restructure is dropped
  if it isn't measurably faster.
- **Release:** a patch on its own after 53.14's release.

---

## Tasks

### 53.15.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.15.1 Imagick preparation (K74)

- [x] Replace the Lanczos resize in `ImagickImagePreparer::resize()`;
      re-measure 4032×3024, 8000×6000 and an A4 scan against GD.
- [x] Update the docblock to the measured result. Test: output size and
      orientation unchanged.

### 53.15.2 Overlap preparation with the request (K105)

- [x] Measure the current order; start the request before preparation
      where the HTTP client allows, and prepare pages 2–3 while the
      model answers (`Extractor::extractChecked()`, `OcrReader::begin()`,
      `OcrJob::result()`). Keep the "OCR finishes first on an Internet
      connection" rule (#510).

### 53.15.3 PaddleOCR image (K79)

- [x] Pin and de-risk the install; add `USER`; move the volume; update
      `docker-compose.yml` and `docs/` for the optional profile.
- [x] Build the image and run the OCR smoke path.

### 53.15.4 Finish

- [x] Known-issues rows K74, K79 and K105 *Fixed* (or updated if a
      measurement shows no gain).
- [x] CHANGELOG; spec §13; ROADMAP status.
- [x] Merge review (performance-auditor, deploy-checker, ai-evaluator),
      then merge.

### Release

- [x] `VERSION`, CHANGELOG, README status.
- [x] Tag `v3.19.7` once merged.

## Acceptance criteria

- Recorded timings before and after for K74 and K105.
- The PaddleOCR image builds multi-arch and runs without root.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass.
  No migration.

## Results

Timings are best of three on an arm64 Docker container (PHP 8.4, one
ImageMagick thread, Tesseract 5), on synthetic 4,000 px-class pages made
for the purpose (the fixtures in `tests/Fixtures` are all under 2,000 px).

| K74: prepare a photo | Imagick before | Imagick after | GD |
|---|---|---|---|
| 4032×3024 photo | 8.2 s | 2.2 s | 2.5 s |
| 8000×6000 photo | 8.6 s | 2.1 s | 1.8 s |
| A4 scan, 2480×3508 | 3.1 s | 1.6 s | 1.6 s |

Where the 8.2 s went: the resize 0.3 s once `scaleImage` replaced the
Lanczos-class filter (4.4–5.8 s paging under the pixel cache), deskew of a
full-size copy 1.7 s (now measured on a 1,000 px copy, the page turned
only when its angle is 0.25° or more; straightness of tilted pages is
unchanged, tested), PNG encoding 1.5 s (zlib level 1: 0.7 s, the file a
fifth larger). The pixel-cache cap stays, so #492 is not reopened.

| K105: 3-page scanned PDF, Tesseract | Total | After the answer |
|---|---|---|
| 3 s model, before | 6.7 s | 2.6 s |
| 3 s model, after | 5.5 s | 0 s |
| 0.5 s model, before | 4.1 s | 2.5 s |
| 0.5 s model, after | 3.2 s | 0.3 s |

`begin()` takes 2.4 s instead of 1.0 s (it prepares and starts all three
pages), so the request starts about 1.4 s later; the model's time more than
repays it. PaddleOCR (HTTP, read after the request) keeps preparing only the
first page before it. Not done: overlapping the *first* page's preparation
with the request, which needs the adapters' synchronous `chat()` to answer
while PHP works (logged in K105's row).

K79: both architectures built from `requirements.txt` (3,171 lines, 2,864
hashes, wheels only); the arm64 image ran as uid 10001, downloaded its
models into the volume and read the sample receipt through `POST /ocr`.

## Open questions

- None. The pixel-cache cap needed no change, so #492 was not reopened.
