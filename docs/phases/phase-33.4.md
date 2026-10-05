# Phase 33.4 — Cost of ownership, Ask and Fuel stations to the prototype + v3.0 release

*What each car has really cost, in one look; and the last two pages
brought into line with the design.*

Status: 📋 planned · releases **v3.0.0** · file lives in `docs/phases/`

The prototype in `design-import/` has a *Cost of ownership* page: a card
per vehicle with a multicolour bar showing what its cost is made of, and
four summary cards above them (*Total cost*, *Per month*, *Depreciation*,
*Finance interest*). Logbook already has every figure (Phase 14.2's
ownership, Phase 29's finance, Phase 32's breakdown) but shows them as a
table. The prototype also redraws Ask and Fuel stations. This phase builds
the three pages and releases **v3.0.0** with Phases 33.1–33.3.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.7
(cost ledger, Ownership report), §7.26 (Ask), §7.32 (finance: cost of
credit), §7.33–7.34 (stations and prices), §7.35 (true cost) and §8 first,
and the *Working from the prototype* section of
[Phase 33.2](phase-33.2.md).

**Prerequisites:** [Phase 33.3](phase-33.3.md) built; [Phase 32](phase-32.md)
released (its breakdown is the bar).

---

## Goals

1. **Cost of ownership page:** the four summary cards and a card per
   vehicle with its multicolour bar, from existing figures only.
2. **Ask** laid out as, and doing what, the prototype's Ask does, within
   Ask's existing rules.
3. **Insights page and AI insights** (decided in Phase 33.3, #174,
   #178): the prototype's Insights page (`auto_awesome` in the sidebar)
   with *Ask* above every insight card, and AI insights (spec §7.26 *AI
   insights*) when AI is on; the dashboard widget's title links to it.
4. **Fuel stations** with whichever of the prototype's features the
   owner picks after the audit.
5. Release **v3.0.0** (Phases 33.1–33.4).

## Not in scope

- New cost figures, forecasts or comparisons with other people's cars.
- Map tiles, geocoding or anything else fetched from a third party for
  stations, unless the owner decides otherwise (see open questions).
- Changing Ask's grounding, privacy or tool rules (§7.26).

---

## Spec additions

### §7.7 Cost of ownership page (changed: replaces the Ownership report's layout)

> `/reports/ownership` keeps its URL, period choice (*Since bought*, *Last
> 12 months*, a year), CSV export and print view (a table, as today). On
> screen it becomes:
>
> - **Four summary cards** for the vehicles shown, each per currency when
>   vehicles use more than one (as every cross-vehicle total):
>   - *Total cost*: running costs plus depreciation for the period
>     (Phase 14.2 / §7.35, net of incident payouts as today);
>   - *Per month*: *Total cost* ÷ months in the period, each vehicle over
>     its own owned months within it;
>   - *Depreciation*: §7.35's depreciation for the period (a gain is
>     shown as negative and labelled);
>   - *Finance interest*: the credit charges counted in costs in the
>     period, from HP, PCP and loan agreements (§7.32's derived lines).
>     Lease rentals are not interest and are left out; with no such
>     agreement the card reads "No finance".
> - **A card per vehicle** (photo, name, registration, the period's total
>   and per distance), with a **multicolour bar**: one segment per §7.35
>   part (*Fuel*, *Maintenance*, *Insurance, tax and MOT*, *Other*,
>   *Depreciation*) in proportion to its amount, each with a legend entry,
>   amount and percentage. Colours are design tokens, distinct in both
>   themes, and the legend carries the labels so colour is never the only
>   cue. A negative depreciation (a gain) is left out of the bar and shown
>   under it.
> - Ordered as the prototype orders them (drafted: highest total first);
>   archived vehicles behind the same *Include sold* toggle as today.
> - Only vehicles the user may see costs for (`ViewCosts`); the summary
>   cards add up exactly the vehicle cards shown.

### §7.26 Ask (changed; subject to the audit)

> The Ask page is laid out as the prototype's Ask (recorded in
> *Prototype notes*). Features the prototype shows that use only what Ask
> already has (conversations, suggestions, sources, drafts, feedback) are
> built; anything needing a new tool, new data or a different model use is
> an open question. Every answer still passes the grounding check, uses
> only the read-only tools under the user's access, and nothing is saved
> without *Add*.

### §7.33 Fuel stations (changed; subject to the audit and the owner)

> The Fuel stations page is laid out as the prototype's. Features listed
> in *Prototype notes* are each marked *build* or *not now* by the owner
> before work starts; only *build* ones are specified here, from local
> data and the Phase 30.2 provider only.

---

## Tasks

### 33.4.0 Spec first
- [ ] `spec.md` §7.7, §7.26, §7.33 and §13 as above, after the audit.

### 33.4.1 Prototype audit
- [ ] *Prototype notes* for *Cost of ownership*, Ask and Fuel stations.
- [ ] For Fuel stations, a list of the prototype's features with, for
      each, whether the app already has the data. The owner marks each
      *build* or *not now* before 33.4.4 starts.

### 33.4.2 Cost of ownership
- [ ] `OwnershipSummary` (the four totals per currency) from the existing
      ownership, true cost and finance services.
- [ ] Vehicle cards with the stacked bar macro (`ui.cost_bar(parts)`),
      reusable by the overview's true cost card.
- [ ] Print and CSV unchanged; the screen layout to the prototype.

### 33.4.3 Ask
- [ ] Page to the prototype within §7.26.

### 33.4.3a Insights page and AI insights
- [ ] Insights page (`/insights`, sidebar `auto_awesome`) with *Ask*
      above the insight cards; the widget's *All insights* link.
- [ ] AI insights (spec §7.26 *AI insights*): daily per user, cached,
      *Refresh*, grounding check, marked as AI; only with AI on.
- [ ] Tests: nothing generated or shown with AI off; grounding
      highlights an unmatched number; the cache serves the day.

### 33.4.4 Fuel stations
- [ ] The features marked *build*.

### 33.4.5 Tests
- [ ] Summary cards equal the sum of the vehicle cards; per currency with
      two currencies; *Per month* uses each vehicle's owned months.
- [ ] *Finance interest*: HP and PCP credit charges counted, lease
      rentals not, "No finance" without either; matches §7.32's figures.
- [ ] Bar segments sum to the vehicle's total; a depreciation gain is left
      out of the bar; switched-off modules remove their part.
- [ ] Access: a View share without costs shows no vehicle card and no
      totals for it.
- [ ] Ask and Fuel stations: the existing suites stay green; new
      behaviour tested as specified after the audit.
- [ ] Design-reviewer clean of HIGH findings on all three pages.

### 33.4.6 Release
- [ ] `CHANGELOG.md` **3.0.0**: forgotten password by email, admin
      controls, avatars, the redesigned sign-in and Settings, the vehicle
      tabs (Finance tab, Insights, trips, incidents, tyres), Cost of
      ownership, Ask and Fuel stations, Mailpit in development.
- [ ] Upgrade notes: migrations (user email and avatar columns; reminder
      email addresses move to the user); a forgotten-password link
      appears on the sign-in page when email is configured
      (`PASSWORD_RESET_ENABLED=false` to hide it); *Stations* is now
      *Fuel stations* (URLs unchanged); Settings links have moved; the
      sample users no longer have fixed passwords.
- [ ] Why 3.0: the sign-in flow and the account model change (self-service
      reset, one email per user), and the app's navigation and Settings
      are reorganised. No API change: the API stays v1.
- [ ] Bump `VERSION`, rebuild assets, update README and ROADMAP status.

---

## Prototype notes

*Filled in by task 33.4.1.*

---

## Acceptance criteria

1. Reports → *Cost of ownership* shows *Total cost*, *Per month*,
   *Depreciation* and *Finance interest*, then a card per vehicle whose
   coloured bar shows what its cost is made of, adding up exactly.
2. Ask and Fuel stations look and work like the prototype, for the
   features the owner chose.
3. `VERSION` is 3.0.0, the changelog and upgrade notes are written, and an
   upgrade from 2.16.0 on each engine keeps every user's reminder email.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Finance interest:** interest counted so far in the period (drafted),
  or the agreement's whole cost of credit?
- **Per month for the fleet:** each vehicle over its own owned months
  (drafted), or the whole period for every vehicle?
- **Station maps:** if the prototype shows a map, tiles come from a third
  party, against keeping data local. A map behind an admin switch, a
  static distance list (today), or no map?
- **Ask features** the existing tools can't support (listed by 33.4.1).
- **Fuel stations features:** the owner's *build* / *not now* list from
  33.4.1.
