# Phase 40.2 — Issues everywhere + v3.6 release

*Recommended work becomes issues in one tap, and issues go everywhere
entries go.*

Status: ✅ complete · releases **v3.6.0** (Phases 40.1 and 40.2) · file
lives in `docs/phases/`

The second of Phase 40's two parts (#318). [Phase 40.1](phase-40.1.md) has
the goals, decisions and the no-diagnosis rule for the whole phase.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.37
(*Phase 40.2 — elsewhere*) first, then §7.13, §7.20 (*Phase 39*
conventions, attachments and webhooks), §7.26, §7.27 *Recommended work*
and §7.28.

**Prerequisites:** [Phase 40.1](phase-40.1.md) complete and green.

---

## Tasks

### 40.2.0 Spec first
- [x] §7.20 issue endpoints and the `issue` webhook kind; §7.26 tools and
      system line; §7.27 card; §7.28 tools; §7.13 backups; `ROADMAP.md`
      row 🚧.
- [x] #319 (found while starting) decided: no duplicate reading for an
      issue noticed where the vehicle already has one.

### 40.2.1 Recommended work
- [x] The card shown with either right (#313); *Add as issue*, *Watch*
      (#314) and *Add all as issues*; lines already added marked (as a
      reminder or an issue; a card saved before 40.2 reads as reminders).

### 40.2.2 API and webhooks
- [x] `GET`/`POST /vehicles/{id}/issues`, `GET`/`PATCH`/`DELETE` one
      (`ETag`, `If-Match`), `GET /issues`, updates, fix, reopen,
      attachments with owner type `issue`; duplicate key; OpenAPI and
      `docs/api.md`. The `ETag` is taken over the issue with its timeline
      and fixes, so a note changes it.
- [x] Webhooks: kind `issue`; updates, fixes, unlinks and reopens are
      `entry.updated` (#317). Create, edit and delete too: #317's note
      that they "already" fired under #290 was wrong, as 40.1 queued none
      (found while starting).

### 40.2.3 Ask, MCP, CSV, backups
- [x] Ask read tool `issues` and draft tool `draft_issue`; the system
      line; AI insights never take a cause.
- [x] MCP: the read tool and the `draft_issue` pending draft.
- [x] CSV `/vehicles/{id}/export/issues.csv`.
- [x] Backups and `bin/export-user.php` carry the three tables: built in
      [40.1](phase-40.1.md).
- [x] Translations (every shipped locale).

### 40.2.4 Tests
- [x] Recommended work: lines become issues with the right fields; marks
      for lines already added; gating by each right.
- [x] Ask never offers a cause: the system line is present; an eval
      question in `bin/ai-eval.php`'s set asking "what's causing this
      knock?" expects the refusal wording (a new optional fourth element:
      words any of which the answer must contain).
- [x] API contract and access (`ApiIssuesTest`, `ApiAccessTest`,
      `ApiWriteWalkTest`, `RouteInventoryTest`); webhooks queued
      (`WebhooksTest`); backup round-trip (built and tested in 40.1:
      `BackupTest`); CSV (`CsvExportTest`); suite green on SQLite,
      PostgreSQL, MySQL and MariaDB; coverage 94.8% (floor 94%), 96% of
      the changed `src/` lines.

### 40.2.5 Release
- [x] `VERSION` → 3.6.0; `CHANGELOG.md` (*Added* — issues log; *Upgrade
      notes* — one migration, module on by default).
- [x] `docs/issues.md`, README, `ROADMAP.md` rows ✅.
- [ ] Tag v3.6.0 once merged.

---

## Acceptance criteria

1. Recommended work becomes issues in one tap.
2. Issues are in the API, webhooks, Ask, MCP and CSV (backups since 40.1).
3. Nowhere does Logbook suggest what a fault is.
4. Definition of done (CLAUDE.md §11) holds.

### Reviews (2026-10-08)

- **Bug hunt:** no high or medium. Fixed (low): `PATCH {"status":"open"}`
  on a watching issue refused the stored look-again point it had not sent
  (now cleared, as leaving watching does); an update on a watching issue
  with a new point dropped it (now moves it, as *Watch again*); `/fix`
  linked service records while the maintenance module was off (now 422, as
  the page offers none); deleting a record kept only as history told a
  webhook of an unchanged issue (now silent). Each has a regression test.
- **Security:** nothing found. Access on all 18 routes, IDOR on record
  and issue ids, read-only keys, mass assignment, the card's per-button
  rights and CSRF, CSV injection, demo mode and the prompts were tried.
  Noted, not changed: issue files and webhooks with the module off follow
  incidents' existing behaviour (View still required; links answer 404).
- **Design:** no high or medium. Fixed (low): *Add all* says *Add all as
  reminders* beside *Add all as issues*; the lead mentions *Watch*;
  *Export CSV* is hidden on an empty Issues tab, as on Valuations.
  Static review only: the card has no prototype screen.
- **Spec:** §6 draft kind and card fields, the OpenAPI version note, the
  issue field list (`created_by`), the card's archived and date rules, and
  `docs/ai.md`'s card, all brought in step.
- Not run: `deploy-checker` and `performance-auditor`.

## Acceptance criteria — checked

1. One tap: `ScanFlowTest::testRecommendedWorkBecomesIssues`.
2. API (`ApiIssuesTest`), webhooks (`WebhooksTest`), Ask
   (`IssuesToolTest`), MCP (`McpToolsTest`), CSV (`CsvExportTest`),
   backups (40.1).
3. No cause: the system lines (`ConversationTest`, `AiInsightsTest`), the
   tool descriptions (`IssuesToolTest`), the eval refusal case.
4. Definition of done: lint, analyse, four engines, coverage.

## Open questions

None open. #313, #314 and #317 (decided 2026-10-08) are built here, and
#319, found while starting:

- **#319** An issue's odometer adds a reading (#307): should *Add all as
  issues*, each noticed at the service record's odometer, add one reading
  per line? *Decided 2026-10-08:* no. An issue or update reading is not
  written when the vehicle already has a reading on the same day at the
  same odometer; the issue keeps its odometer, and the next save writes
  its own if that other reading goes (spec §7.37).
