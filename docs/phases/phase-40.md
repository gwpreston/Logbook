# Phase 40 — Issues log + release

*The fault you've noticed and haven't fixed yet.*

Status: 📋 planned · file lives in `docs/phases/`

Logbook records what was done to a car (service records), what happened
to it (incidents) and what is due (schedules, documents, reminders). It
has nowhere for **a fault you've noticed and haven't fixed**: "knock from
front left under braking", "slow leak, rear right", "advisory: brake pipes
corroded". Today these end up as manual reminders, which need a due date
the owner doesn't have, or in a note that nothing reads.

An **issue** has a date, the mileage, a description and a status
(*open*, *watching*, *fixed*). It closes by linking to the service record
that fixed it. Open issues appear in *Needs attention*. Two things already
in Logbook feed it: the *recommended work* lines Phase 26.4 reads from
invoices, and, from [Phase 41](phase-41.md), MOT advisories.

**No AI diagnosis** (decided 2026-10-08 by the owner). Guessing the cause
of a fault is the one suggestion where a confident wrong answer could hurt
someone, and it doesn't fit Logbook's grounded-only approach. Logbook
records the owner's words and links the fix; it never suggests what the
fault is.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §6
(MaintenanceEntry, Attachment, OdometerReading, Entry authorship), §7.4,
§7.12, §7.16, §7.20, §7.24, §7.26, §7.27 *Recommended work* and §7.29
first.

**Prerequisites:** [Phase 38](phase-38.md) complete and green. Independent
of Phases [39.1](phase-39.1.md) to [39.3](phase-39.3.md); if they are built first, issues get
[39.2](phase-39.2.md)'s edit and delete and [39.3](phase-39.3.md)'s attachment endpoints too.

---

## Goals

1. **Issues:** add, edit, delete, with status, updates over time and
   attached photos or PDFs.
2. **Fixing:** a service record can fix one or more issues, from either
   side; the issue shows what fixed it.
3. ***Needs attention*** shows open issues, and watching ones once their
   look-again point passes.
4. **Fed by scans:** the recommended-work card offers *Add as issue* beside
   *Add reminder*.
5. Everywhere entries go: History, overview, CSV, backups, the API, Ask,
   MCP, the demo seed. Release.

## Not in scope

- **AI diagnosis** in any form: no "possible causes", no Ask tool that
  suggests one, no AI insight about a fault's cause.
- **MOT advisories** as a source: [Phase 41](phase-41.md), which writes
  through this phase's create path.
- A **severity** or safety rating set by Logbook. The owner's words are
  the record (see open question D for an owner-set flag).
- Costing an issue (estimates live in quotes and, once paid, in the
  service record).
- Reminders for issues. *Needs attention* and the look-again point cover
  it (open question E).

---

## Spec addition (§7.37 Issues)

### Data

**Issue** (`issues`)
- id, vehicle_id (`ON DELETE CASCADE`), noticed_on (calendar date, as
  `performed_on`), odometer_km (optional `decimal(12,3)`), title (required,
  up to 120), description (optional, up to 2,000), category (optional,
  the maintenance categories, so a fix can be prefilled), status (`open`
  | `watching` | `fixed`), look_again_on (optional date) and
  look_again_km (optional, while watching), fixed_on (date, while fixed),
  source (`manual` | `recommended_work` | `mot_advisory`, the last from
  Phase 41), source_ref (optional, up to 100: the pending upload's entry
  or the MOT defect, for tracing), created_by (Entry authorship),
  created/updated (UTC). Index `(vehicle_id, status)`.

**IssueFix** (`issue_fixes`): issue_id (`ON DELETE CASCADE`),
maintenance_entry_id (`ON DELETE CASCADE`), unique on the pair. Several
issues can be fixed by one record (a brake job); normally one record fixes
an issue, but a second attempt can be linked too.

**IssueUpdate** (`issue_updates`): id, issue_id (`ON DELETE CASCADE`),
noted_on (date), odometer_km (optional), note (up to 1,000; optional when
the update is only a status change), status_from and status_to (optional),
created_by, created_at. *Still knocking, worse when cold* lives here, as do
status changes, which are written automatically.

- **Odometer:** an issue's or an update's odometer **adds a reading**
  (source `issue`, local noon on its date, with the usual plausibility
  warning), as a service record's does (open question A).
- **Attachments:** owner type `issue` (§7.12), stripped as every photo is.
  A video is not a supported type (§7.12 is unchanged).

### Statuses and fixing

- **Open:** noticed, not dealt with. **Watching:** the owner has decided
  to keep an eye on it (an advisory, a noise that comes and goes), with an
  optional *Look again on* date and/or *at* mileage. **Fixed:** linked to
  the record(s) that fixed it.
- **Fix from the service record:** the maintenance form (page and modal)
  gains *Fixes*: a checklist of the vehicle's open and watching issues,
  beside *Completes* (schedules). Saving links them and sets each to
  *fixed* with the record's date.
- **Fix from the issue:** *Mark fixed* offers (1) *Log the repair*, the
  maintenance form prefilled (category, title from the issue, today) with
  the issue ticked under *Fixes*; or (2) *Link an existing record*, a
  picker of the vehicle's records since the issue was noticed (open
  question B on whether fixing without a record is allowed).
- **Unlinking:** unticking an issue on the record, or deleting the
  record, takes the link away; an issue with no link left goes back to
  the status it had before it was fixed, with an update saying so
  ("Service record deleted; reopened").
- **Reopening** a fixed issue (*It's back*) keeps its links (the earlier
  fix is history) and adds an update.

### Pages

- **Issues** under the vehicle: a card on the overview (open and watching
  issues, newest first, up to five, *Show all*), and `/vehicles/{id}/issues`
  (filters *Open*, *Watching*, *Fixed*, *All*; each issue's page shows its
  description, files, updates timeline and fixes). Not a tab of its own
  (open question C). In the *Log entry* chooser as *Issue*.
- **Add / edit** as every entry form: a modal on desktop and its own page
  without JS. *Add update* on the issue page (date, odometer, note,
  optional status change).
- **Fleet:** `/issues` lists every visible vehicle's open and watching
  issues, linked from the *Needs attention* widget.
- **Archived vehicles** keep their issues read-only; open ones raise
  nothing.

### Elsewhere

- **Needs attention** (§7.24): a new *Now* item, **Open issue**, one per
  open issue: "Knock from front left under braking · noticed 12 Aug, 3
  weeks ago". Actions: *Log the repair* (`Log`), *Watch* (`Log`; sets
  *watching*, asking for an optional look-again point). A watching issue
  whose look-again date has passed (owner's today) or whose mileage has
  been reached (latest reading) comes back as **Look again**: "Brake
  pipes corroded · watching since March". No *Hide*: *Watch* is how an
  issue is set aside, so there is one place for it. Shown to everyone who
  can view the vehicle, as *Now* items are.
- **Recommended work** (§7.27): the card gains *Add as issue* per line and
  *Add all as issues* beside the reminder buttons. An issue is created
  `open`, source `recommended_work`, noticed on the entry's date at its
  odometer, title the line's text, and the line's distance or date, if
  any, as a look-again point when the owner chooses *watching* on the
  card. A line already added as a reminder or an issue is marked so.
- **History** (§7.16): kinds *Issue noticed* (dated `noticed_on`) and
  *Issue fixed* (dated `fixed_on`, linking the record). Updates are not
  listed. The printable service history includes fixed issues with their
  fix, never open ones (sale pack: open question F).
- **Ask** (§7.26): read tool `issues(vehicle?, status?)` and a draft tool
  `draft_issue`. The system text gains: "Never suggest what may be causing
  a fault, even if asked; say Logbook only records what the owner noted,
  and suggest a qualified mechanic." AI insights never take the `issues`
  topic for causes; they may note counts ("2 issues open on the Golf
  for over 3 months").
- **MCP:** the read tool, and `draft_issue` as a pending draft, as Phase
  26.5's other drafts.
- **API** (§7.20): `GET` and `POST /vehicles/{id}/issues`, `GET /issues`
  (`?status=`), `POST /vehicles/{id}/issues/{issue}/updates`; with Phase
  39, `PATCH`, `DELETE` and attachments. Duplicate key: same date, title
  and source reference.
- **CSV:** `/vehicles/{id}/export/issues.csv` (date noticed, mileage,
  title, description, category, status, fixed on, fixed by). Backups and
  `bin/export-user.php` carry the three tables.
- **Module:** `issues`, **on by default** (Settings → Modules,
  `FEATURES_ISSUES`). Off hides everything above and keeps the data.
- **Access:** viewing needs `View`; adding, updates, *Watch* and fixing
  need `Log`; editing and deleting follow `EntryAccess` (own under `Log`,
  any under `Manage`).

---

## Decisions (and why)

- **The owner's words, not a diagnosis.** A wrong guess about brakes or
  steering is dangerous in a way a wrong fuel figure isn't. Logbook keeps
  what was noticed, when, at what mileage, and what fixed it. That is
  also exactly what a mechanic or a buyer wants.
- **Fixed means linked.** The value of the log is the trail from symptom
  to repair. Making the link the normal way to close an issue keeps that
  trail.
- **Watching is a status, not a hidden item.** A deliberate decision to
  live with an advisory deserves to be recorded, and the look-again point
  brings it back without a reminder.
- ***Now*, not *Check*.** *Check* means "the data may be wrong". An open
  fault is real work, like an overdue service.

---

## Tasks

### 40.0 Spec first
- [ ] §7.37; §6 Issue, IssueFix, IssueUpdate, the reading source
      `issue`, the attachment owner type; §7.4 *Fixes*; §7.16 kinds; §7.20
      endpoints; §7.24 items; §7.26 tools and system line; §7.27 card;
      §7.10 module; §13.
- [ ] Open questions A–F decided; `ROADMAP.md` row (📋).

### 40.1 Migration and services
- [ ] Three tables and the reading link; reversible on every engine.
- [ ] `Service\Issue\IssueService` (create, edit, delete, status changes
      with automatic updates, fix and unfix), with the reading written in
      the same transaction.

### 40.2 Pages
- [ ] Overview card, issues page, issue page with timeline, fleet
      `/issues`, add/edit modal and page, *Add update*, *Mark fixed*
      (both routes), *It's back*.
- [ ] Maintenance form *Fixes* checklist (page and modal, with and
      without JS).
- [ ] *Log entry* chooser entry.

### 40.3 Integrations
- [ ] *Needs attention*: *Open issue* and *Look again* items, widget and
      garage marker counts.
- [ ] Recommended-work card buttons.
- [ ] History kinds; printable service history.
- [ ] Ask read and draft tools, system line; MCP; API; CSV; backups;
      export-user; module toggle.
- [ ] Demo seed: the Golf with one open issue (noticed knock), one
      watching (advisory, look again in 3 months), one fixed by a brake
      service record.
- [ ] Translations (every shipped locale).

### 40.4 Tests
- [ ] Validation (date not after today, title length, look-again only
      while watching, fixed needs a link per B).
- [ ] Fixing from both sides; one record fixing several issues;
      deleting the record reopens to the earlier status; *It's back*.
- [ ] Odometer readings written, moved and removed with the issue and
      updates; plausibility warnings.
- [ ] *Needs attention*: open issues listed; watching ones only past the
      look-again date or mileage; archived vehicles raise nothing.
- [ ] Recommended work: lines become issues with the right fields; marks
      for lines already added.
- [ ] Ask never offers a cause: the system line is present; an eval
      fixture asking "what's causing this knock?" is answered with the
      refusal wording.
- [ ] Access matrix for every route; module off is 404 everywhere.
- [ ] Backup round-trip; CSV; API contract; suite green on every engine;
      coverage at or above the floor.

### 40.5 Release
- [ ] `VERSION` → next minor; `CHANGELOG.md` (*Added* — issues log;
      *Upgrade notes* — one migration, module on by default).
- [ ] `docs/issues.md`, README, `ROADMAP.md` row ✅. Tag once merged.

---

## Acceptance criteria

1. A fault can be logged with date, mileage, description and files, kept
   up to date, watched, and closed by the record that fixed it.
2. Open issues are in *Needs attention*; watching ones return when their
   look-again point passes.
3. Recommended work becomes issues in one tap.
4. Nowhere does Logbook suggest what a fault is.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Does an issue's mileage add a reading?** Options: (1) yes, as a
  service record's does; (2) no, as a valuation's doesn't. *Recommendation:*
  (1): the owner reads it off the dashboard on the day, which is a
  reading. (Valuations differ: that mileage was typed into a website.)
- **B. Can an issue be fixed without a service record?** Options: (1) no,
  *Log the repair* is quick and a free DIY job is a 0-cost record; (2) yes,
  with an optional note ("went away on its own"). *Recommendation:* (2),
  with the record offered first: some faults just stop, and forcing an
  invented record would be worse data.
- **C. A tab or a card?** Options: (1) overview card plus its own page,
  as drafted; (2) an *Issues* tab beside Maintenance. *Recommendation:*
  (1) to start; the tab bar is already long. Revisit if issues are used
  heavily.
- **D. An owner-set *Affects safety* flag?** Options: (1) none; (2) a tick
  the owner sets, which puts the issue first in *Needs attention* and in
  red. It is the owner's judgement, not Logbook's. *Recommendation:* (2);
  MOT *dangerous* and *major* defects (Phase 41) would set it.
- **E. Reminders for issues?** Options: (1) none, *Needs attention* and
  look-again are enough; (2) a look-again point also raises a reminder
  (source `issue`) so it reaches the notification channels.
  *Recommendation:* (2) as a later step; (1) in this phase.
- **F. Open issues in the sale pack?** Options: (1) never; (2) an owner
  tick, off by default, listing open and watching issues;
  (3) always. *Recommendation:* (2): honest disclosure is the owner's
  choice to make.
