# Phase 53.18 — Date range, far-out due dates, usernames and unsent claims from the known-issues log

*Four open rows of the known-issues log closed, each after the owner
answered its question: an entered or URL date has to be in 1900–2100, a
due date projected past the horizon isn't shown, usernames that differ
by an accent are different users on every engine, and an interrupted
reminder claim is said to stay unsent.*

Status: ✅ complete · file lives in `docs/phases/` · released as **v3.19.10**

This is the next batch after [Phase 53.17](phase-53.17.md). It takes K42,
K43, K44 and K56, which waited on #450, #451, #452 and #458; the owner
answered all four on 2026-10-25 while this batch was planned. Unlike the
earlier batches it has a migration (K44). It is numbered 53.18 because
53.14–53.17 are already planned.

Read [`CLAUDE.md`](../../CLAUDE.md) (§6, §8, §11, §12), `spec.md` (§6
*User*, §7.4, §7.6, §7.9, §8 *Dates/timezone*), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.17](phase-53.17.md) merged.

---

## Goals

1. **K42.** A date typed into a form, sent to the API or put in a URL
   (`?year=`, a report or claim range) is refused outside 1900-01-01 to
   2100-12-31, with a validation message. Nothing the app accepts can
   then make `/`, `/history`, the reports, the trip claim or the
   reminders return 500 or loop.
2. **K43.** A due date projected from the average daily distance is
   shown only when it falls within 100 years of today. Beyond that, or
   when it can't be stored, the schedule and the tyre show the distance
   left and no date, and no reminder date is stored. `/`, `/reminders`
   and the vehicle page stay up for a vehicle driven almost nothing.
3. **K44.** `josé` and `jose` are different usernames on every engine.
   On MySQL and MariaDB a username is compared exactly, so an admin can
   add `jose` beside `josé`, signing in as `jose` doesn't sign in as
   `josé`, and a proxy `Remote-User: jose` doesn't either. The upgrade
   lists any pair that now differs only by accent before changing
   anything.
4. **K56.** Spec §7.6 says a reminder claim that was never sent
   (interrupted run) is not retried, and why, so the behaviour is
   documented rather than silent. No code change.

## Not in scope

- K1, K3, K40, K54 (each waits on its open question: #484, #485, #449,
  #456).
- K13 (the CSV export's local time has no offset): needs its own
  question first, as 53.17 said.
- K145 (record real Claude scan requests): needs an API key, not a fix.
- Making readers cope with dates already stored outside 1900–2100
  (#558): the fix stops new ones; an existing one is corrected by hand.

---

## Decisions (and why)

- **K42 (#450, decided 2026-10-25):** option 1, one fixed range in
  `LocalTime::parseDate()` and so in `Validator::date()`, the API and
  URL parsing, rather than a fix in each consumer of the edge years.
  Not chosen: keep 0001–9999 and fix every consumer; a range per field;
  1000–2999 everywhere.
- **#558 (decided 2026-10-25):** refuse new input only; the upgrade note
  tells owners to correct a stored date outside the range. Not chosen:
  readers that tolerate stored edge years (bigger than a quick fix).
- **K43 (#451, decided 2026-10-25):** option 1. No projected date, only
  the distance; never a wrong date. The same pattern in `TyreWear` gets
  the same rule. Not chosen: a 100-year cap shown as a date; "not in the
  foreseeable future".
- **K44 (#452, decided 2026-10-25):** option 1, accent-sensitive
  everywhere, as PostgreSQL and SQLite already are. It needs a migration
  that reports collisions first, and a username lookup that is exact on
  MySQL and MariaDB behind one documented platform branch (CLAUDE.md §6).
  Not chosen: accent-folded everywhere (a folded column and a migration
  that finds collisions on PostgreSQL and SQLite); ASCII-only for new
  names.
- **K56 (#458, decided 2026-10-25):** option 1, keep at-most-once: a
  duplicate notification costs more than a missed one a user can still
  see in the app. Not chosen: expiring unsent claims after an hour;
  retrying only claims whose run `job_runs` records as failed.
- **Vehicle date floor (found while building):** the vehicle forms'
  own floor of 1 January 1885 for *First registered*, *First MOT due* and
  a starting reading's *As of* is now 1900, the range every date field
  shares (#450), so the three `…_too_early` messages and their checks are
  gone: an earlier date gets the range message. The *Year* (model year)
  field keeps its own 1885 floor, being a year and not a date.
- **Release (#557, decided 2026-10-25):** with the next releasing
  phase, so this file has no *Release* section. Not chosen: a patch on
  its own.

---

## Tasks

### 53.18.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). The still-open #374–#377 and
      #559 don't touch dates, due dates, usernames or claims; nothing to
      ask. The spec already carries all four decisions (§6 *User*, §7.4,
      §7.6, §8).

### 53.18.1 Date range (K42)

- [x] `LocalTime::parseDate()` returns null outside 1900–2100, so
      `Validator::date()` (message `validation.date`, or a new
      `validation.date_range` key in every catalogue), the API's date
      fields and URL dates refuse it. `ActivityQuery::newYear()`,
      `ReportPeriod`, `TaxYear` and `ClaimFilter` clamp or refuse a URL
      year the same way.
- [x] Tests: a fill-up, expense and document dated 9999-12-31 or
      0001-01-01 are refused on the form and the API (422); `?year=0000`
      and a custom report range in 9999 answer 4xx or the default, never
      500 or a loop. `docs/api.md` and `openapi.json` give the range.
- [x] Upgrade note: a stored date outside the range stays and should be
      corrected.

### 53.18.2 Far-out due dates (K43)

- [x] `DueState` and `TyreWear`: no projected date when the days would
      end beyond 100 years from today (or overflow `+N days`); the
      distance left still shows. Tests: the 100,000 / 100,001 km case
      from K43 stores no `due_on` and `/`, `/reminders` and the vehicle
      page answer 200; a near projection is unchanged.

### 53.18.3 Usernames by accent (K44)

- [x] `UserRepository::findByUsername()` and the unique check compare
      exactly on MySQL and MariaDB (binary collation on
      `users.username` and the invitation username), behind one
      documented platform branch.
- [x] A Phinx migration that applies and rolls back on all four engines.
      It first reports, and stops on, any pair of usernames differing
      only by an accent (none can exist on PostgreSQL or SQLite).
- [x] Tests on every engine: `jose` can be added beside `josé`; signing
      in as `jose` with `josé`'s password fails; `AUTH_PROXY_LINK=username`
      with `Remote-User: jose` doesn't sign in as `josé`. Spec §6 *User*
      and §7.9 already say so. `upgrade-tester` runs the migration.

### 53.18.4 Unsent claims (K56)

- [x] Spec §7.6 and `docs/notification-channels.md`: say an interrupted run's claim
      (empty `sent_at`) is never retried. A test pins it: a claim row
      with empty `sent_at` isn't sent by the next pass.

### 53.18.5 Finish

- [x] Known-issues rows K42, K43, K44 and K56 *Fixed*; open questions
      #450, #451, #452, #458 and #558 marked built.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (spec-keeper, bug-hunter, upgrade-tester), then merge.

### Release

- [x] `VERSION`, CHANGELOG, README status.
- [x] Tag `v3.19.10` once merged.

## Acceptance criteria

- Each code row has a regression test that fails without its fix (K42's
  refused dates, K43's 200s, K44's three cases, K56's pinned claim).
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  all four engines.
- `MarkdownLinksTest` passes.
- The migration applies and rolls back on all four engines, and stops
  with a clear list when two usernames differ only by an accent.
- New strings are in every catalogue in `translations/`.

## Open questions

- **#450. Decided 2026-10-25:** (1) a fixed range, 1900–2100, for every
  date field. Not chosen: 0001–9999 with every consumer fixed; a range
  per field; 1000–2999.
- **#451. Decided 2026-10-25:** (1) no projected date, only the
  distance. Not chosen: a 100-year cap; "not in the foreseeable
  future".
- **#452. Decided 2026-10-25:** (1) accent-sensitive on every engine.
  Not chosen: accent-folded everywhere; ASCII-only new names.
- **#458. Decided 2026-10-25:** (1) keep at-most-once, said in §7.6. Not
  chosen: expire unsent claims; retry failed runs' claims.
- **#557. Decided 2026-10-25:** released with the next releasing phase.
  Not chosen: a patch on its own.
- **#558. Decided 2026-10-25:** refuse new out-of-range dates only; the
  upgrade note says to correct stored ones. Not chosen: readers that
  tolerate stored edge years.
