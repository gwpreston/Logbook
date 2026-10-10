# Phase 43 — The monthly briefing + release

*Last month in one message: what's due, how far you drove, what it cost,
and what Logbook spotted.*

Status: ✅ complete · release **v3.9.0** once merged · file lives in `docs/phases/`

The monthly digest (§7.11) lists what is due this month and, since Phase
24, the *Check* items. This phase adds **last month**: distance, spend and
cost per distance against the 12-month average, then **that day's
insights**, computed and AI. Almost every figure already exists: the
distance is a report's *distance driven* (§7.7), the spend and cost per
distance are the Reports page's running costs, and the insights are
Phase 42's and Phase 38's. It is the prototype list's "monthly briefing"
with little new code.

The digest **already goes through every channel** (Phase 36.4's
*Monthly digest* category), so nothing changes in delivery. What changes
is the content, and its order, because some channels are short (Pushover's
1,024 characters).

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.7,
§7.8 *Insights*, §7.11 (*Digest*, *Limits*, *What each channel
receives*, quiet hours), §7.21 (`ViewCosts`), §7.24, §7.26 *AI
insights*, and Phases 38, 40 and 42 first.

**Prerequisites:** [Phase 42](phase-42.md) complete and green (and so
Phase 38). [Phase 40.1](phase-40.1.md) for the open issues line (built
without it when 40 hasn't shipped).

---

## Goals

1. A **Last month** section: distance, spend and cost per distance per
   vehicle, each against the 12-month monthly average, with a fleet line.
2. An **Insights** section: the computed insights and the latest AI
   insights the user would see.
3. **Open issues** in the attention section.
4. Ordered so the most important survives a short channel's limit.
5. A user choice of what the digest includes. Release.

## Not in scope

- A new channel, schedule or frequency (weekly is §12's *Frequency*,
  #168).
- Charts or HTML layouts beyond what each channel already sends.
- Generating AI insights for the digest. It reads the kept set; it never
  calls a model.
- True cost (depreciation) in the monthly figures (B, #361).

---

## Spec changes (§7.11 *Digest*)

Written into `spec.md` §7.11 *The monthly briefing* and §6 on
2026-10-10, with the decisions below; the spec is the source of truth
where this draft differs (the preference shape, the spend average,
dismissals).

### Sections, in this order

Each channel's message is cut at a line boundary as today, so this order
decides what a short channel keeps:

1. **Due this month** (unchanged).
2. **Needs attention** (unchanged *Check* items), plus, with `issues` on,
   one line per vehicle with open issues: "Golf: 2 open issues" (*Now*
   items aren't repeated because they are the due reminders; open issues
   aren't reminders, so they would otherwise be missed).
3. **Last month** (new, below).
4. **Insights** (new, below).
5. The link.

### Last month

For the previous calendar month in the user's time zone, per recipient
vehicle the user can view (as the digest's vehicles today), active and
with a distance or (with `ViewCosts`) a spend in that month (as built;
spec §7.11; the averages still look back 12 months):

- **Distance:** the month's *distance driven* (§7.7), against the
  average of the 12 months before it (each measured the same way; months
  with no measurable distance are left out of the average, and with fewer
  than 3 the comparison is left out). "Golf: 812 mi, about 10% more than
  your monthly average (738 mi)."
- **Spend** (`ViewCosts`, or omitted): the month's running costs as the
  Reports page counts them (§7.7), against the 12 months before's
  monthly average, in the vehicle's currency. The average divides by the
  months from the vehicle's first reading or ledger line, at most 12,
  and with fewer than 3 the comparison is left out (#364). When one entry is more than
  half of the month's spend, it is named, since an annual payment
  otherwise reads as an alarming month: "£604, including insurance
  £412".
- **Cost per distance** (`ViewCosts`): the month's running cost per
  distance against the last 12 months', only when the month's distance is
  at least 100 km (as §7.35 keeps short distances out of comparisons; the
  month's figure is otherwise "—").
- **Fleet line** (two or more vehicles): distance summed; spend summed
  **per currency**, never converted.
- **Wording:** each comparison as a fixed, translated sentence, with "about
  N% more/less" from the figures shown, and "about the same" within ±5%.
  All from the report services: the digest's figures always equal the
  Reports page's for that month.

### Insights

- The **computed insights** (§7.8) for the user's recipient vehicles,
  all of them, in their order.
- The **AI insights** of the user's most recent kept set, when it was made
  for today or yesterday (their time zone) and AI is on for them, marked
  "AI:", after the computed ones. Dismissed kinds (Phase 38) are left
  out (there are none: Phase 38 added no dismissals, and computed
  insights have none, #365), and so is **any AI insight with an unmatched figure**: a text
  channel can't show the grounding mark, so an unbacked figure would
  arrive looking as trustworthy as Logbook's own. (Phase 42's C,
  #354, dropped them already: the digest reads the set as the Insights
  page shows it.)
- **No model call**, ever, from the digest job; with no recent set, the
  AI part is left out.

### When it is sent

- Today: only when something is due or needs attention. From this phase:
  also when an included section has content (A, #360). A month with
  nothing at all still sends nothing.

### What the user chooses

- Settings → Reminders, the digest card gains **Include**: *What's due*
  (always), *Needs attention*, *Last month*, *Insights*, each on by
  default, stored as `digest_include` (a list of `attention`,
  `last_month`, `insights`; absent means all) beside the boolean
  `digest` in the `notifications` preference (#363), so rolling back
  keeps the digest on. An empty choice gives the digest of what's due
  alone.

### Webhook JSON

- Beside `items` and `attention` (unchanged): `last_month` (per vehicle:
  `vehicle_id`, `vehicle`, `distance`, `distance_average`, `spend`,
  `spend_average`, `cost_per_distance`, `cost_per_distance_average`, each
  raw (canonical units and decimal strings, as the REST API) with a
  `display` string; amounts omitted without `ViewCosts`), `fleet`,
  `issues` (counts per vehicle), and `insights` (`kind`, `source`
  `computed` | `ai`, `vehicle_ids`, `title`, `body`). As built, rows also
  carry `month` and `currency`; spec §7.11 *Webhook JSON* has the full
  shapes of `fleet` and `issues`.

---

## Decisions (and why)

- **Report services only.** The digest must never show a number the
  Reports page disagrees with.
- **Short channels keep what matters.** Due work first, a briefing last:
  a Pushover user still gets the reminders.
- **Name the big entry.** One annual bill makes a month look expensive;
  saying which one turns an alarm into information.
- **No unbacked AI figures in plain text.** The grounding highlight is the
  safeguard on a page; a text message can't carry it, so the insight
  stays out.

---

## Tasks

### 43.0 Spec first
- [x] §7.11 *The monthly briefing*; §6 `digest_include`; §13; open
      questions A–C and #363–#365 decided (2026-10-10) and logged;
      `ROADMAP.md` row.

### 43.1 Content
- [x] `Service\Notification\Digest\DigestSummary` (worded by `DigestWording`): last month's figures and averages
      per vehicle and the fleet line, from the report services; the
      named large entry.
- [x] Insights section from §7.8's service and the kept AI set, with the
      dismissal and unmatched-figure filters.
- [x] Open issues line (with `issues` on).
- [x] Section order; every channel's text; the webhook JSON.

### 43.2 Settings
- [x] *Include* on the digest card (works without JS); `digest_include`,
      absent reading as all.

### 43.3 Tests
- [x] Figures equal the Reports page's for the month, for every demo
      vehicle; averages skip empty months; fewer than 3 months drops the
      comparison; under 100 km gives "—"; currencies never mixed.
- [x] `ViewCosts`: spend and cost per distance omitted for a View share
      without it, in text and JSON.
- [x] Large entry named only above half the month's spend.
- [x] Insights: computed listed; AI only from today's or yesterday's set,
      never dismissed kinds, never one with an unmatched figure; the job
      makes no model call (asserted with a failing model fake).
- [x] Cutting: a Pushover-length message keeps *Due* and *Needs
      attention* and ends with "…and N more" and the link.
- [x] *Include* choices; no `digest_include` reads as all; sending rules
      (A); quiet hours unchanged.
- [x] Suite green on every engine (SQLite, PostgreSQL, MySQL, MariaDB:
      3,827 tests each); coverage 94.99%, floor 94%.

### 43.4 Release
- [x] `VERSION` → next minor (3.9.0); `CHANGELOG.md` (*Changed* — the monthly
      digest includes last month's figures and insights; choose what it
      includes in Settings → Reminders). No migration.
- [x] README; `ROADMAP.md` row ✅.
- [ ] Tag `v3.9.0` once merged.

---

## Acceptance criteria

1. The digest shows last month's distance, spend and cost per distance
   against the 12-month average, equal to the Reports page's.
2. It shows the computed insights and, with AI on, recent AI insights that
   are neither dismissed nor unbacked.
3. A short channel still receives what's due first.
4. A user can turn each new section off.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Send when only *Last month* has content?** Options: (1) yes; (2)
  no, keep today's rule (only when something is due or needs attention).
  *Recommendation:* (1): a briefing that skips quiet months loses its
  point. Users who don't want it untick *Last month*.
  *Decided 2026-10-10:* (1). (#360)
- **B. Running cost or true cost per distance?** Options: (1) running
  costs (Reports), as drafted; (2) Phase 32's true cost, depreciation
  included. *Recommendation:* (1): depreciation is interpolated between
  valuations, which makes one month's figure an estimate; true cost
  stays a 12-month figure. *Decided 2026-10-10:* (1). (#361)
- **C. AI insights from yesterday?** The digest goes on the first run of
  the month, often before the day's AI insights are made. Options: (1)
  today's or yesterday's set, as drafted; (2) today's only; (3) the
  digest job waits for today's set up to a few hours. *Recommendation:*
  (1): yesterday's are still fresh, and the job never waits on a model.
  *Decided 2026-10-10:* (1). (#362)
- **Found while starting it (2026-10-10):**
  - **The preference shape.** The drafted `digest: {"on", "include"}`
    object would read as *off* in v3.8 after a rollback. Options: (1) a
    separate `digest_include` key beside the boolean; (2) the object,
    with an upgrade note. *Decided:* (1). (#363)
  - **The spend average for a vehicle in use under 12 months.** Options:
    (1) divide by the months since its first reading or ledger line,
    fewer than 3 dropping the comparison; (2) always 12, as the Reports
    page's monthly average. *Decided:* (1). (#364)
  - **"Dismissed kinds (Phase 38)".** Answered from the code: Phase 38
    added no dismissals (Phase 42 says so) and §7.8's computed insights
    have none, so there is nothing to filter. (#365)
  - **Found while building it (2026-10-10), decided by the owner the
    same day:** the server's `WEBHOOK_URL`, which receives every
    member's notifications, gets the digest without spend, cost per
    distance or insights (#366); a month with no spending reads "nothing
    spent", with no comparison and cost per distance "—" (#367); *Last
    month* only while the reports module is on (#368); a vehicle is
    listed with a distance or a spend in the month, not "any record in
    13 months" (#369); AI insights tied only to non-recipient vehicles
    are left out (#370); the *Needs attention* heading counts lines
    (#371).
  - The carried #279 and #346–#349 were decided the same day and moved
    to [Phase 41.8](phase-41.8.md), at the owner's choice, rather than
    built in this release.
