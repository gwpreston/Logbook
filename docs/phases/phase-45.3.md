# Phase 45.3 — Hardening: phones and real people + release

*Logging a fill-up at the pump works on a real phone, for someone who
has never seen Logbook.*

Status: 📋 planned · file lives in `docs/phases/`

Every phase ends with a design review at 375, 768 and 1280 px, in light
and dark, with JS off. That proves the layout. It doesn't prove:

- that iOS Safari's keyboard leaves the litres field visible;
- that a receipt photo from the camera uploads on a weak signal;
- that a household member can find *Log fill-up* without being shown.

This part tests those things on real devices, then with three to five
real people. It closes Phase 45 with a release, and with a hardening
report that names what was found and fixed.

Part of this phase can only be done by the owner: the device runs and the
sessions with people. Claude Code builds the automated journeys, the
session kit and the fixes.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5 and §8
(the UI rules and budgets), and the Phase 33 design system first.

**Prerequisites:** [Phase 45.2](phase-45.2.md) complete and green.

---

## Goals

1. **Automated mobile journeys** in real browser engines (WebKit and
   Chromium, emulating phones), with a throttled connection.
2. **A device pass** on the owner's own phones (and borrowed ones),
   recorded against a checklist.
3. **Usability sessions** with three to five people who haven't used
   Logbook, each given set tasks and no help, and the findings written
   up.
4. **Fixes** for every blocker found. Everything else is filed as a
   phase candidate. Phase 50's getting-started work starts from these
   findings.
5. **The hardening report** (`docs/research/hardening-2026.md`) and the
   release.

## Not in scope

- A redesign. Fixes are the smallest change that removes the problem.
- Native apps, or offline use beyond what the pages already do.
- Analytics. Logbook collects none, so these sessions are the only
  evidence.

---

## The journeys (used by all three goals)

1. **Sign in** from the invitation link on a phone, and complete the
   welcome form.
2. **Add the first vehicle,** including its current mileage.
3. **Log a fill-up at the pump.** Photograph the receipt, enter litres
   and total, and save on a slow connection.
4. **Mark a reminder done** from the reminder email.
5. **Log a service** with two photos.
6. **Find out** what needs attention and what last month cost.
7. **Share the vehicle** with a household member who can log but not
   see costs.

## Device checklist (per device and journey)

- The keyboard never hides the field being typed in.
- Number fields open the right keyboard: decimal for litres and money,
  numeric for mileage.
- Camera upload, rotation and size are right.
- A slow save shows progress, and no tap is lost.
- A failed save keeps what was typed.
- Tap targets are at least 44 px.
- The text is still readable at the largest system text size.
- The screen reader (VoiceOver, TalkBack) reads each step in order.

## Sessions (proposed)

- **Who:** three to five adults who drive and don't work in software.
  Household members and friends count.
- **Format:** about 30 minutes, on their own phone if possible, on a
  demo instance seeded for them.
  1. Warm-up.
  2. Journeys 1–7 as tasks ("You've just filled up. Record it.").
  3. Five minutes of questions.
- **Recorded:** for each task, completed or not, the time taken, where
  they hesitated, what they said. No screen recording without consent.
- **Rating:** *Blocker* (can't complete the task), *Major* (completed
  with real difficulty), *Minor*. Findings are grouped by theme in the
  report.

---

## Tasks

### 45.3.0 Spec first
- [ ] Open questions A–C decided by the owner and logged; §13.

### 45.3.1 Automated journeys
- [ ] Journeys 1–7 in a browser test runner, on WebKit and Chromium
      phone profiles, with a throttled network. Run in CI against the
      Docker image (A).

### 45.3.2 Device pass (owner)
- [ ] The checklist on at least one iPhone and one Android phone, on
      current OS versions; results in the report.

### 45.3.3 Sessions (owner, with Claude Code's kit)
- [ ] The session kit: task cards, the observer's sheet, the consent
      note, and the demo seed for sessions.
- [ ] Three to five sessions run, and the findings written up.

### 45.3.4 Fixes and release
- [ ] Every *Blocker* fixed, with a test where one is possible.
      Every *Major* fixed, or filed with a reason.
- [ ] The hardening report covers 45.1's mismatches, 45.2's leaks and
      restore results, and this part's findings.
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` rows ✅ for 45.1–45.3;
      tag once merged.

---

## Acceptance criteria

1. Journeys 1–7 pass automatically on WebKit and Chromium phone
   profiles, on a slow connection.
2. The device checklist passes on a real iPhone and a real Android
   phone.
3. At least three people completed journeys 1–7 without help, after the
   blockers were fixed.
4. Phase 45's exit criteria all hold: the golden figures match, the
   journeys and sweep pass, the restore matrix is green, and no blocker
   is open. Feature phases may resume.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Browser test runner.**
  - (1) Playwright (Node, a dev dependency only, run against the Docker
    image).
  - (2) A PHP driver such as Symfony Panther (Chromium only).
  - (3) None, device pass only.

  *Recommendation:* (1). It is the only one that runs WebKit, which is
  where iOS problems show up. It stays out of the shipped image.

- **B. What if a session finds a blocker in a flow Phase 50 will
  replace?**
  - (1) Fix it now anyway.
  - (2) Leave it to Phase 50.

  *Recommendation:* (1) if the fix is small; otherwise (2), noted in the
  report, with Phase 50 starting straight after.

- **C. Release type.**
  - (1) A patch release: fixes only.
  - (2) A minor release, if the fixes change behaviour anywhere.

  *Recommendation:* (1) unless a fix changes behaviour in a way the
  changelog has to explain.
