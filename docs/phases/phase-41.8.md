# Phase 41.8 — Decisions carried from Phases 38 and 41 + patch release

*What the owner decided on 2026-10-10 about the questions Phase 41's
merge review and Phase 38's performance review left open.*

Status: 📋 planned · file lives in `docs/phases/`

Numbered after 41.7 because its questions came from Phases 38 and 41;
it ships after Phase 43's v3.9.0, as a patch release (v3.9.1 or later).

Five questions stayed open through Phases 41.6 to 42 (#279, #346–#349).
The owner decided them on 2026-10-10, when Phase 43 started, and chose to
build them in this phase rather than inside Phase 43's release: #346
changes how distance is measured for about ten features, which is too
wide a change to ride along with the monthly briefing.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.7
(*Distance driven*), §6 MotTest (the upgrade and rollback note), §7.28
(*Drafts to review*), §7.38 (*Look up on add*), and
[Phase 41](phase-41.md) first.

**Prerequisites:** [Phase 42](phase-42.md) merged. Independent of
[Phase 43](phase-43.md): the digest takes its distances from the same
`PeriodDistance`, so it follows #346 whichever lands first.

---

## Goals

1. Distances measured as a report's leave out readings before the
   purchase date (#346).
2. *Look up* stays as it is, and the docs say who may use it (#347).
3. Rolling back the MOT migration deletes the `mot_history` settings
   (#348).
4. Recorded DVSA answers stay off git (#349).
5. The Insights page shows the latest 5 MCP drafts with *Show all*
   (#279).
6. Discord escapes Markdown, so an AI insight in the digest can't mask a
   link (#378).

## Not in scope

- Hiding pre-purchase readings anywhere they are listed (History, the
  odometer list, the MOT history page): they stay, as the car's record.
- A cap on making MCP drafts (#279 chose none).

---

## Decisions (and why)

- **#346: ignore readings before the purchase date.** An earlier
  owner's MOT mileages are the car's history, not this owner's driving;
  counting them made "last 12 months" too long for a car bought within
  the year. Readings on the purchase day count (the usual baseline).
- **#347: keep *Look up* open, and say so.** It is already rate-limited
  per person (#343) and sends only the registration typed in.
- **#348: delete the settings on rollback.** The secrets table is
  dropped by the same rollback, so leaving the settings turned the
  provider on without credentials after upgrading again.
- **#349: git-ignore `recorded-*.json`.** They hold a real vehicle's
  registration and history, scrubbed or not; CI uses the hand-written
  fixtures already committed.
- **#378: escape Discord's Markdown** (found by Phase 43's security
  review, low; the owner asked for the fix on 2026-10-10). Since Phase
  43 the digest carries AI insight text, and Discord rendered
  `[words](url)` in it as a masked link that looked like Logbook's own.
  Escaped as Mattermost's is; Logbook's own link stays as it is, so it
  still opens.
- **#279: latest 5 + *Show all*.** The list stays short without refusing
  drafts an assistant has already made; the same disclosure as *Your
  questions* (#276).

---

## Tasks

### 41.8.0 Spec first
- [x] §7.7 *Distance driven* (#346), §6 MotTest rollback (#348), §7.38
      *Look up on add* (#347), §7.28 *Drafts to review* (#279), §13;
      `ROADMAP.md` row; the log (2026-10-10).

### 41.8.1 Distance since purchase (#346)
- [ ] `PeriodDistance` leaves out readings dated (owner's day) before the
      vehicle's purchase date, for every caller: reports, true cost,
      ownership cost, depreciation, the fuel forecast, the dashboard's
      mileage, trips' split, the API's reports and Ask's mileage tool.
- [ ] Tests: a car bought in the period with an earlier owner's MOT
      readings; a reading on the purchase day is the baseline; no
      purchase date changes nothing.

### 41.8.2 MOT history (#347, #348, #349)
- [ ] `docs/mot-history.md`: anyone who may add a vehicle can use *Look
      up*; what it sends; the per-person limit.
- [ ] The MOT migration's `down()` deletes the `mot_history` settings;
      migrate, roll back and migrate again on every engine; the upgrade
      notes.
- [ ] `.gitignore` `tests/Fixtures/mot-history/recorded-*.json`; the
      fixtures README says so.

### 41.8.3 Drafts on Insights (#279)
- [ ] The latest 5 drafts, the rest under *Show all (N)* (works without
      JS); a test with 7 drafts.

### 41.8.4 Discord Markdown (#378)
- [x] `DiscordSender::escape`: the title, body and "…and n more" line
      escaped before the limit is counted; the link not; spec §7.11,
      `docs/notification-channels.md`, `CHANGELOG.md`.
- [x] Tests: masked links, emphasis, spoilers, code, `<@…>` and
      timestamps, line-start headings, subtext, quotes and lists (also
      after a newline), the backslash; a digest with an AI insight's
      link; escapes counted against the 2000 limit.

### 41.8.5 Release
- [ ] `VERSION` → next patch; `CHANGELOG.md`; `ROADMAP.md` row ✅. Tag
      once merged.

---

## Acceptance criteria

1. A vehicle bought within a report's period, with an earlier owner's
   readings, shows only the distance since its purchase date.
2. Rolling back the MOT migration and migrating again leaves MOT history
   off.
3. `git status` never shows a recorded DVSA answer.
4. The Insights page shows at most 5 drafts before *Show all*.
5. A digest insight `[Renew here](https://example.test/a)` reaches
   Discord as those characters, not a link; the link to Logbook opens.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

None open. #279, #346–#349 and #378 were decided on 2026-10-10 (see
[`open-questions.md`](open-questions.md)).
