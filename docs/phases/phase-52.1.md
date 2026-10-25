# Phase 52.1 — Quick wins from the known-issues log + patch release

*Eight open rows of the known-issues log closed, each small, each with a
regression test.*

Status: ✅ complete · released as **v3.16.3** ([#118](https://github.com/gwpreston16/Logbook/pull/118)), a patch release · file lives in `docs/phases/`

The known-issues log has more open rows than one phase should take on.
This phase takes the smallest ones that need no decision from the owner:
a stray comment, an error message, one template attribute, and four
small logic bugs in parts, the API, Ask and the backup command line. It
is numbered 52.1 because it branches off master after Phase 52 and
before Phase 53.1 starts. It ships on its own as patch release v3.16.3
(#481). Later batches can follow the same pattern.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13
(*Backup and restore*), §7.20 (*Writes*, *Warnings*), §7.26 (the Ask
tools) and §7.39 (*Parts and modifications*, *Access*) first.

**Prerequisites:** [Phase 52](phase-52.md) merged (v3.16.2).

---

## Goals

1. **K4.** `POST` and `PATCH /vehicles/{id}/modifications` return the
   `odometer_backwards` or `odometer_jump` warning, as every other write
   does and as the web form already flashes.
2. **K5.** A part sold by someone without *Can see costs* is stored with
   no sale price, not `0.000`, so the owner no longer sees "sold for £0".
3. **K6.** Ask's and MCP's `vehicle_summary` puts only running costs in
   `running_cost_last_12_months.total`, as `costs` and
   `cost_per_distance` do. Build spend is given beside it as
   `build_total`.
4. **K7.** *Clear* on the *For your insurer* page works when the date
   field is blank or in the future.
5. **K22.** `Validator::raw()`'s comment goes back above `raw()`.
6. **K23.** The dev-only `symfony/yaml` CVE: already fixed in
   `fbf21b24`. Marked *Fixed*.
7. **K26.** The API's error for more than 20 rows in a parts list says
   the limit is 20.
8. **K64.** `php bin/backup.php check` and `restore` find a bare file
   name in `BACKUP_PATH`, as `docs/deployment.md` shows. A missing file
   says "No such file", not "This is not a ZIP file."
9. **K66**, found while running the suite: Phase 53.1's prerequisite
   links to a phase that no longer exists, which fails
   `MarkdownLinksTest`. It now names Phase 52.

## Not in scope

- Rows that wait on an open question (K1, K3, K29, K39, K44, K47, K48,
  K54, K56, K57).
- Larger rows (K40, K41, K42, K43, K49). They need a phase each.

---

## Decisions (and why)

None needed the owner. Each fix makes the code do what the spec or the
code's own comments already say:

- **A sale with no price is stored with none** (K5), whoever records it.
  Spec §6 makes `sold_price` optional, and `CostInput` already said "a
  sale is recorded without a price". 0 stays a valid price when someone
  types it (CLAUDE.md §8). The part page and the Ask tools already handle
  a null price.
- **`vehicle_summary`'s total is the running total** (K6), so it matches
  the rate beside it and the other two cost tools. `build_total` is
  there only when the period has build spend, as in `costs`. The tool's
  description is unchanged, since it already said "running cost".
- **Only *Clear* skips the browser's date check** (K7). *Clear* doesn't
  use the date. *Mark* needs one and the server refuses the same dates,
  so its browser check stays (#480, decided 2026-10-17).
- **Phase 53.1's prerequisite is Phase 52** (K66). It linked to a
  Phase 45.3 "hardening" phase that `f17b7c63` removed, together with
  every hardening prerequisite. The Phase 53 plan brought one back by
  mistake. The phases around it need the phase before them.
- **Released on its own** as patch v3.16.3 (#481, decided 2026-10-17),
  like Phase 51.4: the fixes are small and don't depend on Phase 53.
- **The parts-list error gets its own key** (K26),
  `api.validation.rows` with `{max}`. `api.validation.list` ("Use a list
  of text values.") stays for the lists of codes and ids it describes.
- **A bare name is looked for in `BACKUP_PATH`** (K64), only when it
  isn't a file where it was typed and has no directory part.
  `BackupService::locate()` holds the rule, so the CLI script stays thin
  and the rule is tested.

---

## Tasks

- [x] `ApiModifications::warnings()`, used by `logModification()` and
      `patch()` (K4).
- [x] `PartForm::removal()`: no price stays null (K5).
- [x] `VehicleSummary`: `runningTotal()` and `build_total` (K6).
- [x] `formnovalidate` on the insurer page's *Clear* button (K7).
- [x] `Validator` docblock (K22).
- [x] `JsonInput::MAX_ROWS` and `api.validation.rows`, in English and
      German (K26).
- [x] `BackupService::locate()`, `backup.error.not_found` (English and
      German), and `open()` telling a missing file from one that isn't a
      ZIP. `bin/backup.php` uses `locate()` (K64).
- [x] Regression tests: `ApiPartsTest`, `PartsTest`,
      `BuildCostsToolTest`, `InsurerListTest`, `BackupTest`.
- [x] spec.md §7.13, §7.20, §7.26 and §13; ROADMAP; CHANGELOG 3.16.3;
      `docs/api.md` and `docs/mcp.md`.
- [x] Known-issues rows K4, K5, K6, K7, K22, K23, K26 and K64 *Fixed*.
      K66 recorded and fixed.
- [x] The suite on PostgreSQL, MySQL and MariaDB: CI on PR #118 and on
      master (`c98c8757`), PHP 8.4 on all four engines and 8.5 on
      PostgreSQL and MySQL.
- [x] Merge (PR #118), then the release check
      (`/merge-review`, 2026-10-17): first REJECT on paperwork (spec
      §7.20, §7.13 and `docs/mcp.md` not updated), fixed in the release
      commit, along with upgrade notes and `docs/api.md`.
- [x] Release: VERSION 3.16.3, CHANGELOG 3.16.3, README status (#481).
- [x] Tag `v3.16.3` once merged.

## Acceptance criteria

- Each row has a regression test that fails without its fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB.
- No migration. New strings are translated (English and German).

## Open questions

- **#480. Decided 2026-10-17:** *Mark declared* keeps the browser's date
  check (option 1). Only *Clear* has `formnovalidate`. K7 named both
  buttons, but the browser blocks only dates the server would refuse
  anyway.
- **#481. Decided 2026-10-17:** released on its own as patch v3.16.3
  (option 1), not with Phase 53.2.
- **#483. Decided 2026-10-25: (1) leave it, and say so**, built in
  [Phase 53.17](phase-53.17.md) (K67) (found in the v3.16.3 release review, K67):
  how does an owner correct a part removal made without a record (the
  part page's *Remove*), including sales stored as £0 before 3.16.3?
  Options: (1) leave it, and the upgrade note says so; (2) an *Undo
  removal* for the last removal made without a record; (3) an *Edit
  removal* form, with a matching API write; (4) leave old 0.000 sales
  alone, with a manual or command way to clear them in the upgrade note.
