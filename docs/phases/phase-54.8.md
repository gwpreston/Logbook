# Phase 54.8 — Offline fill-ups from the known-issues log + patch release

*Two open rows of the known-issues log closed: the PWA's *Log fill-up*
shortcut works offline for an owner with one vehicle, and one refused queued
fill-up no longer holds up the rest.*

Status: 📋 planned · releases **v3.21.6**, a patch release (#588) · file lives in `docs/phases/`

This is the batch after [Phase 54.6](phase-54.6.md) (and 54.7's planned
digest decisions). It takes the two rows 54.6 left out as "another batch",
K163 and K164, which share the service worker and `assets/js/app.js`. It is
numbered 54.8 because it follows 54.7, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.15 (the
PWA: *Offline fill-up*), `docs/deployment.md` (*Offline*) and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.7](phase-54.7.md) merged, and `v3.21.5` tagged.

---

## Goals

1. **K163.** For an owner with one active vehicle, the *Log fill-up*
   shortcut (`/fuel/new`) opens offline after it was used online: the
   worker keeps it under both `/fuel/new` and the vehicle's form path.
2. **K164.** A queued fill-up whose form now answers 403 or 404 (vehicle
   deleted, share revoked) stays queued and is passed over; the entries
   after it are still sent on reconnect, and the page says the user is
   signed out only when the sign-in page answered.

## Not in scope

- **K172**: the missing merge-review record for 54.4 and 53.16–53.20 is a
  paperwork question for the owner, not a code fix.
- **K169** waits on #582; **K145** needs an API key; **K160** waits on an
  upstream `symfony/mime` release.

---

## Decisions (and why)

- **K163** makes the worker do what spec §7.15 and `docs/deployment.md`
  already say (the shortcut is kept for offline use). The worker fetches
  navigations with redirect mode `manual`, so it never sees the redirect's
  target; it follows the redirect for the form paths and keeps the result
  under both paths. The row is settled from the code and the Fetch
  standard, not a real browser: reproduce it first, with a test that fakes a
  redirect (`offline.test.js` never does).
- **K164** treats a form that now answers 403 or 404 like a rejected entry
  (spec §7.15: it stays queued, #589), rather than as the sign-out the code
  assumes, and goes on to the next item. The fill-up page names it ("1
  fill-up couldn't be sent: its vehicle is gone or no longer shared with
  you") with the review link (#590). Spec §7.15 says so (written in the
  planning commit, as CLAUDE.md §12 asks).
- **K163** follows redirects for the form paths and stores the result under
  both paths (#591). The `/fuel/new` copy is served offline only while it is
  still the one-vehicle redirect; with several vehicles the chooser, else the
  offline page, shows (#592).

## Tasks

### 54.8.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #588–#592 are decided.

### 54.8.1 The fill-up shortcut offline (K163)

- [ ] `assets/js/service-worker.js` (`page()`, `detached()`) follows the
      redirect for the form paths and keeps the page under `/fuel/new` and
      the vehicle's path (#591); `cacheOfflineForms` (`assets/js/app.js`) no
      longer skips it. Offline, the cached `/fuel/new` is served only while it
      is the one-vehicle redirect (#592). `tests/js/offline.test.js` fakes a
      redirect, and a second vehicle, and fails without the fix.

### 54.8.2 A refused queued fill-up (K164)

- [ ] `sendQueued` / `flushOutbox` (`assets/js/app.js`) pass over an item
      whose form answers 403 or 404, keep it queued (#589), and send the
      rest; the fill-up page's note names it with the review link (#590),
      and "signed out" shows only for a sign-in page. New strings in every
      catalogue. A JS test with three queued items, the first refused, fails
      without the fix.

### 54.8.3 Finish

- [ ] Known-issues rows K163 and K164 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.6, CHANGELOG 3.21.6, README status (#588).
- [ ] Tag `v3.21.6` once merged.

## Acceptance criteria

- K163 and K164 each have a regression test that fails without its fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB; the JS tests pass.
  `MarkdownLinksTest` passes.
- No migration. New strings are in every catalogue in `translations/`.

## Open questions

- **#588. Release. Decided 2026-10-31:** on its own as patch release
  v3.21.6, after 54.7. Not chosen: with the next releasing phase.
- **#589. Decided 2026-10-31:** a refused queued entry stays queued and is
  passed over. Not chosen: discard it; stop the queue.
- **#590. Decided 2026-10-31:** a note naming the entry, with the review
  link. Not chosen: a generic note.
- **#591. Decided 2026-10-31:** follow redirects and store under both
  paths. Not chosen: cache only the vehicle path.
- **#592. Decided 2026-10-31:** serve the cached `/fuel/new` only while it
  is the one-vehicle redirect. Not chosen: always the cached form.
