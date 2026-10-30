# Phase 54.5 — The 500 ms target for the dashboard and true-cost report from the known-issues log + patch release

*The two rows [Phase 54.3](phase-54.3.md) left open closed: the dashboard and
the true-cost report meet spec §8's 500 ms on the heavy household.*

Status: ✅ complete · releases **v3.21.3**, a patch release (#584) · file lives in `docs/phases/`

Phase 54.3 removed the repeated reads (digest 861 to 247 queries, true-cost
99 to 21) and cut the dashboard from 2.3 s to 1.5 s, but what is left is
CPU, not repeated reads. The owner decided (#583, 2026-10-29) to keep the
500 ms target and reach it by lighter working-out. It is numbered 54.5
because it follows 54.4, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §8 *Page
budgets*, §7.24, §7.35, [phase-54.3.md](phase-54.3.md) (its *Profile* and
*Result*) and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.4](phase-54.4.md) merged, and `v3.21.2` tagged.

---

## Goals

1. **K149.** The dashboard answers in at most 500 ms on the heavy household
   (10 vehicles, 400 fill-ups, 150 services, 16 years of documents each;
   1.5 s after 54.3), with the same queries.
2. **K150.** The true-cost report answers in at most 500 ms on the same
   household (0.68 s after 54.3).

## Not in scope

- Loading widgets after the page, or a cache that outlives a request
  (#583 chose neither).
- Changed figures: every number shown stays the same.

---

## Decisions (and why)

- **Lighter working-out only** (#583, option 1). Profile first, as in 54.3:
  the profile is recorded here before any fix. 54.3 left these hot spots:
  the price check's exact decimal arithmetic for each fill-up (about
  370 ms), the true-cost widget (about 260 ms), the fuel analysis (about
  130 ms), reading and hydrating every table (about 110 ms), *Coming up*
  (about 90 ms), and for the report summing every item for each of up to
  16 years (0.4 s) and rendering a 350 KB page (0.3 s).
- **Figures are compared with master's** on the heavy household, byte for
  byte after masking the CSRF and CSP tokens, as in 54.3.
- **If a goal can't be met without changing a figure or adding a cache,
  stop and ask the owner**; don't relax the target (#583 rejected that).
- No migration.

---

## Tasks

### 54.5.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #583 is decided; #584 is decided.
      None of the questions still open (#221, #375–#377, #390, #391, #460,
      #462, #537–#542, #551, #565, #567, #582) bears on this phase, which
      changes no figure, only how long they take. Of the known issues,
      K149 and K150 are this phase's; the other *Open* rows (K145, K160,
      K163, K164, K169, K172) are not about the dashboard's or the
      report's working-out, and K168, K171 and K173 are Phase 54.6's.
- [x] Profile the dashboard and the report again on the heavy household and
      record it here.

#### Profile (master, SQLite, 2026-10-30)

54.3's seeder was not kept, so the household was built again through the
app's services: 10 vehicles, each with 400 fill-ups a fortnight apart
since November 2010, 150 services and 16 years of insurance and
inspection documents, on PHP 8.5 with a SQLite file (a scratch test in
`var/`, not committed). Its 16 full years make the true-cost page larger
than 54.3's (476 KB, was 350 KB), so its times are a little higher. The
500 ms target is judged on this household. Times are the median of seven
warm requests, in process, as in 54.3.

| Case | Time | Queries | Page |
|------|------|---------|------|
| Dashboard | 1.72 s | 61 | 147 KB |
| `/reports/true-cost` | 0.98 s | 21 | 476 KB |

Where the dashboard goes (ms): *Needs attention* 684 (of which the price
check 308 and the economy checks 274), the true-cost widget 321, the fuel
histories 123, reading and hydrating every table once 114, *Coming up*
83, mileage 58, spend 57, the maintenance cost check 51, insights 51,
reminders 39, the vehicle tiles 18; the template about 30.

Where the report goes (ms): working out 489 (each year's costs summed
over every item, 198; reading every table once, 121; the rest of each
vehicle's ownership, readings and valuations about 130; the ledger 39),
the charts 59, and rendering the 476 KB page about 450.

Both are CPU in PHP. The price check and the economy checks spend most of
their time in exact decimal arithmetic on strings (about 2 µs an
operation, a median of up to ten values sorted with it for each of
4,000 fill-ups).

### 54.5.1 Dashboard (K149)

- [x] Cut the price check, the fuel analysis, the true-cost widget and
      whatever else the profile names, until the dashboard is within
      500 ms. Figures unchanged; tests for each change. Counting calls
      showed that much of the time was work done more than once, not slow
      arithmetic: the cost ledger was built four times a page, each
      vehicle's mileage history seven times, and each period's distance
      scanned every reading. Done by:
      - the price, economy and maintenance cost checks comparing in whole
        numbers, exactly as the strings did, falling back to the strings
        for a value with more places than stored (differential tests
        against the old code for each);
      - the owner's fill-ups for the price check's wider comparison sorted
        and grouped once per page, from the reads the page has already,
        and each vehicle's fill-ups walked in order;
      - the true-cost widget working out only the periods it shows (not
        every year and change);
      - the cost ledger and each vehicle's mileage history worked out once
        per request (`RequestReads`, forgotten on a write to any table they
        come from), and the ledger sorted on keys worked out once;
      - a period's distance found by bisecting the readings, which are in
        order, and the mileage widget using the page's histories;
      - a fuel history's running totals kept in whole numbers
        (`DecimalSum`) and exact decimals that need no rounding read
        without splitting the string.

### 54.5.2 True-cost report (K150)

- [x] Sum a vehicle's items once, not once per period; lighten the page's
      markup. Within 500 ms; figures unchanged. Each period is handed only
      the lines that can count in it (`PeriodItems`: those dated in it and
      every line with a cover), which is the same sum without scanning the
      whole ledger for each of up to 18 periods. The markup did not need
      lightening: most of the rendering time was making a new intl
      formatter for every number and date (and a date pattern generator
      for every year's label), now made once per locale and settings
      (`IntlFormatters`). The page is byte-for-byte what it was, which also
      leaves Phase 54.6's template changes as planned.

### 54.5.3 Finish

- [x] Spec §8 states the heavy household's times as met.
- [x] Known-issues rows K149 and K150 *Fixed*.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (performance-auditor included); merge.

### Release

- [x] VERSION 3.21.3, CHANGELOG 3.21.3, README status (#584).
- [ ] Tag `v3.21.3` once merged.

## Result (SQLite, the heavy household, 2026-10-30)

| Case | Master | Now |
|------|--------|-----|
| Dashboard | 1.6 s, 62 queries | 0.42 s, 60 queries |
| `/reports/true-cost` | 0.9 s, 21 queries | 0.40 s, 21 queries |

The median of nine warm requests, in process, master and this branch
measured back to back on the same data. Every figure is the same: the
dashboard (fleet and three single vehicles), the true-cost report (fleet
and three vehicles) and its CSV, the reports and ownership pages, Insights,
History, three vehicles' overviews and fuel logs, the costs CSV, and the
API's attention list, true cost, fill-ups and vehicles are byte-for-byte
what master gives on this data, with 81 price and 10 economy findings
among them (compared after masking the CSRF and CSP tokens). The
dashboard's fewer queries include the wider price comparison using the
fill-ups the page has read. On master this household measured 1.72 s
when profiled and 1.6 s here; the machine's speed varies by about 5%
between runs, which is why the two are measured back to back.

## Acceptance criteria

- The dashboard and the true-cost report are within 500 ms on the heavy
  household on SQLite, with figures identical to master's.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration.

## Open questions

- **#583. Decided 2026-10-29:** keep the 500 ms target and reach it by
  lighter working-out. Not chosen: load the heavy widgets after the page;
  relax the target to 2 s.
- **#584. Release. Decided 2026-10-29:** on its own as patch release
  v3.21.3. Not chosen: with the next releasing phase.
