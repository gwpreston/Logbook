# Phase 53.15 — OCR speed and the PaddleOCR image from the known-issues log + patch release

*Three open rows of the known-issues log closed: Imagick prepares a
photo for OCR as fast as GD does, a scan's OCR preparation overlaps the
model request, and the optional PaddleOCR image installs from pinned
sources and runs as a non-root user.*

Status: 🚧 in progress · file lives in `docs/phases/` · releases the patch
after Phase 53.14's release, a patch release

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

- [ ] Replace the Lanczos resize in `ImagickImagePreparer::resize()`;
      re-measure 4032×3024, 8000×6000 and an A4 scan against GD.
- [ ] Update the docblock to the measured result. Test: output size and
      orientation unchanged.

### 53.15.2 Overlap preparation with the request (K105)

- [ ] Measure the current order; start the request before preparation
      where the HTTP client allows, and prepare pages 2–3 while the
      model answers (`Extractor::extractChecked()`, `OcrReader::begin()`,
      `OcrJob::result()`). Keep the "OCR finishes first on an Internet
      connection" rule (#510).

### 53.15.3 PaddleOCR image (K79)

- [ ] Pin and de-risk the install; add `USER`; move the volume; update
      `docker-compose.yml` and `docs/` for the optional profile.
- [ ] Build the image and run the OCR smoke path.

### 53.15.4 Finish

- [ ] Known-issues rows K74, K79 and K105 *Fixed* (or updated if a
      measurement shows no gain).
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (performance-auditor, deploy-checker, ai-evaluator),
      then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag.

## Acceptance criteria

- Recorded timings before and after for K74 and K105.
- The PaddleOCR image builds multi-arch and runs without root.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass.
  No migration.

## Open questions

- None yet. A raised pixel-cache cap for the A4 case (K74, #492) is
  added here if the resize change leaves a gap.
