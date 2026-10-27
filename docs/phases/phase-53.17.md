# Phase 53.17 — Dev suite, search accents and decided notes from the known-issues log + patch release

*Four open rows of the known-issues log closed, each after the owner
answered its question: a suite run in the dev container isn't refused the
scheduler's lock, the AI model and station name searches treat accents
the same on every engine, Ask's and MCP's `note` hints are said to stay
English, and a part removal made without a record is said to stay as
entered.*

Status: ✅ complete · file lives in `docs/phases/` · released as **v3.19.9**, the
patch after Phase 53.16's release

This is the next batch after [Phase 53.16](phase-53.16.md). It takes K143,
K144, K72 and K67, which waited on #549, #552, #488 and #483; the owner
answered all four on 2026-10-25 while this batch was planned. It is
numbered 53.17 because 53.14–53.16 are already planned.

Read [`CLAUDE.md`](../../CLAUDE.md) (§6, §11, §12), `spec.md` (§7.25,
§7.26, §7.28, §7.34, §7.39 and §10 *Development stack*), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.16](phase-53.16.md) merged, and its release
tagged.

---

## Goals

1. **K143.** `docker-compose.dev.yml` sets `SCHEDULER_ENABLED=false` for
   `app`, so a suite run in the dev container never meets the loop's hold
   on `var/cache/scheduled-tasks.lock`. A developer sets it `true` to
   watch reminders; `CONTRIBUTING.md` says how. CI and the production
   image are unchanged.
2. **K144.** On MySQL and MariaDB the AI model search and the fuel-station
   name search no longer ignore accents: `öl` finds "Ölhandel Müller" but
   not "Olhandel", as on PostgreSQL and SQLite. The comparison uses an
   accent-sensitive collation in one documented platform branch.
3. **K72.** Spec §7.26 and §7.28 say a tool result's `note` strings are
   English whatever the user's language, and `docs/ai.md` and
   `docs/mcp.md` say so where they describe tool results. No code change.
4. **K67.** The 3.16.3 upgrade note, `docs/parts.md` and `docs/api.md`
   say a removal made without a record can't be edited or undone, and
   that a £0.00 sale stored before 3.16.3 stays unless the part is
   deleted and added again. No code change.

## Not in scope

- K1, K3, K40, K42, K43, K44, K54 and K56 (each waits on its open
  question: #484, #485, #449, #450, #451, #452, #456, #458).
- K13 (the CSV export's local time has no offset): the fix changes the
  export's columns and the importer's rule for ambiguous times, so it
  needs its own question first.
- Accent-insensitive search everywhere, and an *Undo removal* or *Edit
  removal* for a removal without a record: the owner chose the smaller
  option for #552 and #483.

---

## Decisions (and why)

- **K143 (#549, decided 2026-10-25):** option 1. One line in the dev
  compose file, no new setting. Not chosen: a `CACHE_DIR` setting, a
  separate test container, documenting only.
- **K144 (#552, decided 2026-10-25):** accent-sensitive everywhere. It
  makes MySQL and MariaDB do what PostgreSQL and SQLite already do, and
  what `LikeSearchTest` already pins for them; the usernames question
  (#452) stays separate. Not chosen: accent-insensitive everywhere
  (a stored search column and a migration), or leaving it.
- **K72 (#488, decided 2026-10-25):** the notes are hints for the model,
  so they stay English; translating them would add keys the user never
  reads. Not chosen: `ask.tool.note.*` translation keys.
- **K67 (#483, decided 2026-10-25):** leave a record-less removal as it
  is and say so. Not chosen: *Undo removal*, *Edit removal* with an API
  write, or a command to clear old £0 sales.

---

## Tasks

### 53.17.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.17.1 Dev scheduler loop (K143)

- [x] `docker-compose.dev.yml`: `SCHEDULER_ENABLED=false` on `app`;
      `CONTRIBUTING.md` and `docs/deployment.md`'s dev section say how to
      turn it on. A test (or `bin/` check) reads the compose file and
      fails if the value is missing, since the failure itself is
      intermittent.

### 53.17.2 Accent-sensitive search (K144)

- [x] `AiModelRepository::listForConnection()` and
      `ProviderStationRepository::searchByName()`: on MySQL and MariaDB
      compare with an accent-sensitive collation (`utf8mb4_0900_as_ci` or
      `utf8mb4_bin` on lowered text, whichever both engines accept),
      behind one documented platform branch.
- [x] `LikeSearchTest`: `öl` doesn't find "Olhandel" on any engine, finds
      "Ölhandel"; MySQL and MariaDB expectations change. Spec §7.25 and
      §7.34 are already updated.

### 53.17.3 Notes stay English (K72)

- [x] `docs/ai.md` and `docs/mcp.md`: one sentence each under tool
      results. A test fails if a tool-result `note` is read from a
      translation catalogue (guards the decision).

### 53.17.4 Removal without a record (K67)

- [x] `CHANGELOG.md` [3.16.3] *Upgrade notes*, `docs/parts.md` and
      `docs/api.md`: say what spec §7.39 now says.
- [x] A test that `POST` on a removal made without a record's edit
      routes returns 404 or 405 (nothing to edit or undo), so the claim
      stays true.

### 53.17.5 Finish

- [x] Known-issues rows K143, K144, K72 and K67 *Fixed*; open questions
      #488, #549, #552 and #483 marked built.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (spec-keeper, deploy-checker, bug-hunter), then merge.

### Release

- [x] `VERSION`, CHANGELOG, README status.
- [x] Tag `v3.19.9` once merged.

## Acceptance criteria

- Each code row has a regression test that fails without its fix (K143's
  compose check, K144's `LikeSearchTest`, K72's and K67's guards).
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  all four engines.
- `MarkdownLinksTest` passes.
- No migration; no new strings (none are user-facing).

## Open questions

- **#549. Decided 2026-10-25:** (1) the dev compose file sets
  `SCHEDULER_ENABLED=false`. Not chosen: a `CACHE_DIR` setting; a
  separate test container; documenting only.
- **#552. Decided 2026-10-25:** (1) accent-sensitive everywhere, in a
  documented platform branch on MySQL and MariaDB. Not chosen:
  accent-insensitive everywhere; leaving it.
- **#488. Decided 2026-10-25:** (1) the `note` strings stay English, said
  in spec §7.26 and §7.28. Not chosen: `ask.tool.note.*` keys.
- **#483. Decided 2026-10-25:** (1) leave it, and the upgrade note says
  so. Not chosen: *Undo removal*; *Edit removal*; a clearing command.
- **#556. Decided 2026-10-25:** released on its own as the patch after
  Phase 53.16's release (option 1). Not chosen: with the next releasing
  phase.
