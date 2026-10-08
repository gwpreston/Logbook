# Phase 43 — The monthly briefing + release

*Last month in one message: what's due, how far you drove, what it cost,
and what Logbook spotted.*

Status: 📋 planned · file lives in `docs/phases/`

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
Phase 38). [Phase 40](phase-40.md) for the open issues line (built
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
- True cost (depreciation) in the monthly figures (open question B).

---

## Spec changes (§7.11 *Digest*)

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
with any reading or entry in the 13 months:

- **Distance:** the month's *distance driven* (§7.7), against the
  average of the 12 months before it (each measured the same way; months
  with no measurable distance are left out of the average, and with fewer
  than 3 the comparison is left out). "Golf: 812 mi, about 10% more than
  your monthly average (738 mi)."
- **Spend** (`ViewCosts`, or omitted): the month's running costs as the
  Reports page counts them (§7.7), against the 12 months before's
  monthly average, in the vehicle's currency. When one entry is more than
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
  out, and so is **any AI insight with an unmatched figure**: a text
  channel can't show the grounding mark, so an unbacked figure would
  arrive looking as trustworthy as Logbook's own. (If Phase 42's open
  question C dropped them already, this is a no-op.)
- **No model call**, ever, from the digest job; with no recent set, the
  AI part is left out.

### When it is sent

- Today: only when something is due or needs attention. From this phase:
  also when last month has any figure (open question A). A month with
  nothing at all still sends nothing.

### What the user chooses

- Settings → Reminders, the digest card gains **Include**: *What's due*
  (always), *Needs attention*, *Last month*, *Insights*, each on by
  default, stored in the digest preference (`digest: {"on": true,
  "include": [...]}`; a stored `true` reads as all on, so upgrading
  changes nothing but the content). An empty choice beyond *What's due*
  gives today's digest.

### Webhook JSON

- Beside `items` and `attention` (unchanged): `last_month` (per vehicle:
  `vehicle_id`, `vehicle`, `distance`, `distance_average`, `spend`,
  `spend_average`, `cost_per_distance`, `cost_per_distance_average`, each
  raw (canonical units and decimal strings, as the REST API) with a
  `display` string; amounts omitted without `ViewCosts`), `fleet`,
  `issues` (counts per vehicle), and `insights` (`kind`, `source`
  `computed` | `ai`, `vehicle_ids`, `title`, `body`).

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
- [ ] §7.11 *Digest* as above; §6 the digest preference shape; §13;
      open questions A–C; `ROADMAP.md` row.

### 43.1 Content
- [ ] `Service\Notify\DigestSummary`: last month's figures and averages
      per vehicle and the fleet line, from the report services; the
      named large entry.
- [ ] Insights section from §7.8's service and the kept AI set, with the
      dismissal and unmatched-figure filters.
- [ ] Open issues line (with `issues` on).
- [ ] Section order; every channel's text; the webhook JSON.

### 43.2 Settings
- [ ] *Include* on the digest card (works without JS); preference shape
      with the `true` reading.

### 43.3 Tests
- [ ] Figures equal the Reports page's for the month, for every demo
      vehicle; averages skip empty months; fewer than 3 months drops the
      comparison; under 100 km gives "—"; currencies never mixed.
- [ ] `ViewCosts`: spend and cost per distance omitted for a View share
      without it, in text and JSON.
- [ ] Large entry named only above half the month's spend.
- [ ] Insights: computed listed; AI only from today's or yesterday's set,
      never dismissed kinds, never one with an unmatched figure; the job
      makes no model call (asserted with a failing model fake).
- [ ] Cutting: a Pushover-length message keeps *Due* and *Needs
      attention* and ends with "…and N more" and the link.
- [ ] *Include* choices; stored `true` reads as all; sending rules (A);
      quiet hours unchanged.
- [ ] Suite green on every engine; coverage at or above the floor.

### 43.4 Release
- [ ] `VERSION` → next minor; `CHANGELOG.md` (*Changed* — the monthly
      digest includes last month's figures and insights; choose what it
      includes in Settings → Reminders). No migration.
- [ ] README; `ROADMAP.md` row ✅. Tag once merged.

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
- **B. Running cost or true cost per distance?** Options: (1) running
  costs (Reports), as drafted; (2) Phase 32's true cost, depreciation
  included. *Recommendation:* (1): depreciation is interpolated between
  valuations, which makes one month's figure an estimate; true cost
  stays a 12-month figure.
- **C. AI insights from yesterday?** The digest goes on the first run of
  the month, often before the day's AI insights are made. Options: (1)
  today's or yesterday's set, as drafted; (2) today's only; (3) the
  digest job waits for today's set up to a few hours. *Recommendation:*
  (1): yesterday's are still fresh, and the job never waits on a model.
