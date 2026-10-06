# Phase 34.3 — Reminders calendar and dashboard widget + v3.1 release

*See what is due as a month, not only as a list.*

Status: ✅ complete · releases **v3.1.0** with Phases 34.1 and 34.2 · file
lives in `docs/phases/`

Reminders are a list (overdue, due, upcoming) and an optional iCal feed for
other calendar apps. There is no month grid inside Logbook, so "what is
happening in March?" means reading the list and doing the sums. This phase
adds a **Calendar** view to Reminders and a **Calendar** dashboard widget.
Both show the reminders the list already shows, placed on their dates.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.6
(reminders, statuses, sync on read, the calendar feed), §7.8 (dashboard,
vehicle filter, layout rule), §7.10 (what `reminders` off removes) and §8
(app shell, accessibility, small screens), and
[Phase 19](phase-19.md) (who sees which reminders) first.

**Prerequisites:** [Phase 34.2](phase-34.2.md) built (the release includes
34.1 and 34.2).

---

## Goals

1. A **Calendar** view of reminders, a month at a time, switchable with
   the list.
2. A **Calendar** dashboard widget (`calendar`): a small month with the
   days that have reminders marked.
3. The same reminders, statuses and access as the list. A different layout
   of the same data, nothing new stored.
4. Usable on a phone, with the keyboard and a screen reader, without
   JavaScript.
5. Release **v3.1.0** (Phases 34.1–34.3), with the light-theme *Tax* and
   *Other* chart colours darkened to 3:1 (#242, from Phase 34.2).

## Not in scope

- Dragging a reminder to another date, or creating one by clicking a grid
  cell (a day's *Add reminder* link, below, is the only shortcut).
- Week and day views, times of day (reminders are all-day dates).
- Logged history (fill-ups, services) on the calendar. That is History.
- *Coming up* projections (see the open questions).
- Changing the iCal feed, its token or its contents.
- Printing the calendar.

---

## Spec additions

As written into `spec.md` with the owner's answers (#207–#211, #242–#244).

### §7.6 Reminders: calendar view (new)

> - **Calendar view** (Phase 34.3): the same reminders a month at a time.
>   - **Switch.** `/reminders` gets *List* and *Calendar* (two links; the
>     current one has `aria-current`). The calendar is
>     `/reminders/calendar?month=YYYY-MM`, with `&vehicle=`, `&closed=0`
>     and `&day=YYYY-MM-DD` (below). Without `month` it is the month of
>     `day`, else the current month in the viewer's time zone; an invalid
>     `month` falls back the same way. A `day` outside the month shown, or
>     not a real date, is ignored. Months more than five years either side
>     of today still render (empty), but *Previous* and *Next* stop there.
>     The sidebar and the mobile bottom navigation do not change: the
>     calendar is a view of Reminders, not a new destination.
>   - **What appears.** Exactly the reminders `/reminders` shows this
>     viewer: the same sources, module gates and access (Phase 19), no
>     archived vehicles, read through the same service and its sync on read
>     (one read per page, however many vehicles). Each is placed on its
>     `due_on`, a calendar date never shifted through a time zone. Projected
>     *Coming up* items are not shown (#207): *Coming up* stays its own page.
>   - **Closed reminders** (#208, #243): done and dismissed ones are shown
>     by default, muted, with their status in words. `closed=0` hides them;
>     the page offers *Hide done and dismissed* / *Show done and dismissed*
>     as links (a URL choice, not a stored setting).
>   - **Overdue strip.** Above the grid, a line with the number of overdue
>     reminders, links to the first three (most overdue first) and a link to
>     the list, so an old overdue item is found without paging back through
>     months. An overdue reminder also appears on its own date.
>   - **Not on the calendar yet.** Open reminders with no `due_on` (a
>     distance-only schedule or manual reminder, a tyre wear-out without a
>     date) are listed under the grid, as on the list.
>   - **Markup** (#211, found while starting: week numbers need rows). The
>     month is an ordered list of **weeks**; each week is a list item with
>     its week number ("Week 41", from ICU with the viewer's locale, so it
>     follows the same rules as the first day of the week: ISO-style in
>     `en_GB` and `de`, US-style in `en_US`) and an ordered list of its
>     seven days. On wide screens a week is a row of the grid with its
>     number in a narrow first column. Each day is a list item with an
>     anchor id (`day-YYYY-MM-DD`), its date as text (the weekday included,
>     visually hidden on wide screens) and its items. There is no ARIA
>     `grid` role: it would promise arrow-key behaviour the page does not
>     have. The first day of the week comes from the viewer's locale (ICU),
>     not a setting. Days of the neighbouring months fill the first and
>     last week, dimmed, with no items. Weekday names and month names come
>     from ICU.
>   - **Small screens:** while the month is under 720 px wide (a phone, or
>     a tablet beside the sidebar; measured on the month, not the window,
>     found by the design review), days without items and weeks with none
>     are hidden and the rest read as an agenda, today marked; each week
>     keeps its number as a small heading. Links there are 44 px touch
>     targets.
>   - **A day shows up to three items**, open ones first by urgency, then
>     closed ones, so a closed item never pushes an open one out; more
>     become a *+N more* link to `?day=` for that date. With a `day`, a
>     panel under the grid lists that day's items in full, each with its
>     actions as on the list (done, dismiss, reopen and edit return to the
>     same calendar page), and an *Add reminder* link (the manual-reminder
>     form with the date filled in, `/reminders/new?due=YYYY-MM-DD`; #209)
>     wherever the list offers *Add reminder*. This works without
>     JavaScript. The form ignores a `due` that is not a real date.
>   - **An item** shows its source icon, its title, a status in words and
>     an icon (*Overdue*, *Due*, *Upcoming*, *Done*, *Dismissed*: colour is
>     never the only cue) and its vehicle (the plate chip, §8). It links to
>     the same place the list links.
>   - **Vehicle filter:** the dashboard's chips (`?vehicle=`, §7.8), with
>     two or more active vehicles; the month links keep it.
>   - **Feed hint.** Under the grid: "See these in your own calendar app",
>     linking to the feed section of Settings → Reminders.
>   - **`reminders` off:** the calendar answers 404 and the *Calendar*
>     switch and widget are gone, as the list is.

### §7.8 Dashboard: Calendar widget (new)

> - **Calendar** (id `calendar`, Phase 34.3; with `reminders` on; after
>   *upcoming reminders* in the default order, appended to saved layouts by
>   the rule above; #210): a small month, the viewer's current month in
>   their time zone: weekday initials from ICU in the viewer's first day of
>   the week, the days, today marked, no week numbers. It counts **open**
>   reminders only (#244). A day with reminders shows how many and its most
>   urgent status (overdue before due before upcoming) as an icon, with
>   the words in its `aria-label` ("12 October: 2 reminders, 1 overdue"),
>   and links to that day on the calendar page (`/reminders/calendar?month=
>   …&day=…`, keeping `?vehicle=`). Under the grid, one line: "N reminders
>   this month, M overdue". *Previous* and *Next* month are links
>   (`/?calendar=YYYY-MM`, keeping `?vehicle=`, the same five-year limit as
>   the page; an invalid value is the current month); the title links to
>   the calendar page for the month shown. It follows the vehicle chips; the
>   pinned vehicle shows that vehicle only. It shares the dashboard's one
>   read of the reminders with *Upcoming reminders*.
>
> - **Default order** becomes: needs attention, upcoming reminders,
>   **calendar**, insights, coming up, spend this month, expense
>   breakdown, monthly spend, recent fuel, your vehicles, efficiency
>   trend, compliance status, mileage, recent activity, business mileage,
>   finance, cheapest fuel, true cost. (The draft left out *Insights*,
>   Phase 33.3; found while starting.)

### §7.8 Expense breakdown (changed, #242)

> Every group colour is at least 3:1 against the card in both themes: the
> light theme's *Tax* and *Other* are darkened.

### §7.10 Feature toggles (changed)

> `reminders` off also removes the calendar view (404) and the *Calendar*
> widget; a saved layout keeps its place.

### §8 App shell (unchanged)

> The sidebar and the mobile bottom navigation do not change: the calendar
> is a view of Reminders, not a new destination.

---

## Decisions (and why)

- **Reminders only.** A calendar of reminders has one meaning. Mixing in
  *Coming up*'s projected costs and dates (estimates) or logged history
  would blur it. *Coming up* stays its own page.
- **One list, styled as a grid.** A real table or an ARIA grid for a month
  is hard to make right for a keyboard or a screen reader. A list of days
  is a list everywhere and becomes a grid with CSS.
- **An overdue strip.** Overdue items sit on dates in the past, which is
  exactly where a month view stops looking.
- **No new setting or stored data.** The week's first day and week
  numbers come from the locale and the view is a URL, like the
  dashboard's filter.
- **A list of weeks** (#211). Week numbers need rows; an ordered list of
  weeks, each with an ordered list of days, is still lists everywhere.
  Numbers follow the locale's rules, not ISO everywhere, so they agree with
  the locale's first day of the week (a Sunday-first week is never split
  between two numbers).
- **Closed shown, open counted** (#208, #243, #244). The page shows done
  and dismissed muted unless hidden; the overdue strip, the widget and
  the *+N more* order count open reminders first or only, so a closed
  item never hides an open one.
- **A switch, not a sidebar entry.** The sidebar and the mobile bottom
  navigation keep their slots.

---

## Tasks

### 34.3.0 Spec first
- [x] `spec.md` §7.6, §7.8, §7.10 as above; §13 entry; `ROADMAP.md` row
      and section.

### 34.3.1 Code
- [x] `Service\Reminder\CalendarMonth` (a value object built from the
      reminders the list service returns): its weeks (with their numbers)
      and days, their items (open first by urgency, then closed), the
      overdue summary, the *not on the calendar yet* list, the first day of
      the week (`IntlCalendar`, the viewer's locale). No second query path
      for reminders.
- [x] `ReminderCalendarAction` (`GET /reminders/calendar`) declaring the
      same ability as the list, so the route inventory sees it.
- [x] Dashboard service for the widget, reusing `CalendarMonth` with the
      vehicle filter and `?calendar=`.
- [x] `?due=` prefill on the manual reminder form (a valid date only).
- [x] Day-panel actions return to the calendar page (the validated
      `return` field the status forms already accept).

### 34.3.2 Templates, CSS
- [x] Calendar page and day panel; widget; the *List* / *Calendar* switch
      on `/reminders`.
- [x] CSS: the 7-column layout on wide screens; the agenda while the month is under 720 px;
      status icons and words; dimmed neighbouring days; muted closed items;
      the week-number column; the focus style on every link.
- [x] #242: darken `--c-tax` and `--c-other` in the light theme to at
      least 3:1 against the card; check Reports, the Expenses tab and the
      spend widgets.

### 34.3.3 Translations
- [x] English and German strings. Month and weekday names come from ICU,
      not hand-written lists.

### 34.3.4 Tests
- [x] Unit: month construction for 28-, 29-, 30- and 31-day months (February
      2028), a month starting on each weekday, and weeks starting on
      Monday, Sunday and Saturday (`en_GB`, `en_US`, `ar_EG`).
- [x] Unit: "today" at a time-zone boundary (a viewer in `Europe/London`
      across the clock change); `due_on` is a calendar date and is never
      shifted through a time zone.
- [x] Unit: week numbers by locale (December 2026 ends in week 53 under
      `en_GB`, week 1 under `en_US`).
- [x] Integration: the calendar shows the same reminders as the list for
      the same viewer, source by source; no archived vehicles; a View-share
      user sees what the list shows them and no more; a vehicle's
      reminders do not appear for a user with no access.
- [x] Integration: overdue strip counts and links; an overdue reminder
      appears on its date and in the strip; reminders with no date appear
      under the grid; done and dismissed ones show muted by default and
      `closed=0` hides them; a closed item never pushes an open one into
      *+N more*.
- [x] Integration: more than three items give *+N more* and the `day`
      panel lists them all with working actions and an *Add reminder* link
      with the date; an invalid `day`, `month` or `due` is ignored.
- [x] Integration: `reminders` off gives 404 for the page and removes the
      widget and links; layouts keep the widget's place.
- [x] Integration: the widget's marks and `aria-label`s are right for a
      day with several statuses; closed reminders are not counted;
      previous and next keep `?vehicle=`; an old saved layout gets the
      widget appended.
- [x] Without JavaScript every test above holds (these are plain pages).
- [x] Query count: one reminders read per page, however many vehicles.

### 34.3.5 Checks
- [x] `design-reviewer` agent at 375, 768 and 1280 px, light and dark, all
      four accents, keyboard only, and with a screen reader reading one
      month. *2026-10-06:* no horizontal scroll at any width, AA contrast
      in every theme and accent, status always in words with an icon, 45
      tab stops in order with visible focus, a list of weeks and days for
      a screen reader, works without JS; #242's tokens at 3.8:1. Fixed: the
      grid crammed seven ~63 px columns beside the sidebar (640–1000 px), so
      the agenda now switches on the month's own width (under 720 px) and
      titles no longer hyphenate; links in the agenda and the widget's
      days are 44 px touch targets. Carried to the log: *Open day* on every
      day with an item (#245) and the three overdue counts' wording (#246).
      German was not rendered by the reviewer.
- [x] `bug-hunter`, `security-scanner` and `performance-auditor`: nothing
      found but one LOW (ICU date formatters rebuilt per call), fixed by
      caching one per locale and skeleton.

### Sample data
- [x] `DemoDataSeeder`: reminders spread over at least two months, with an
      overdue one, a due one, a manual one, a done one and a distance-only
      schedule with no date, so the calendar and the widget are not empty.

### Release (with Phases 34.1 and 34.2)
- [x] `CHANGELOG.md` **3.1.0**: *Added* — registration plates (34.1); the
      *Expense breakdown* and *Monthly spend* widgets (34.2); the Reminders
      calendar and *Calendar* widget (34.3). No migration, no configuration
      change, no backup change. *Upgrade notes*: the new widgets are added
      to the end of existing dashboards; move or hide them under
      *Customise*.
- [x] Bump `VERSION`, rebuild assets, update the README status and
      `ROADMAP.md`.
- [ ] Tag `v3.1.0` once merged.

---

## Acceptance criteria

- `/reminders/calendar` shows a month of the reminders the list shows, in
  the viewer's first day of the week, with an overdue strip and a list of
  those with no date.
- A busy day opens in full without JavaScript.
- On a phone the month reads as an agenda.
- The dashboard widget marks the days with reminders and links each to its
  day.
- `reminders` off removes the calendar, its link and its widget.
- `composer check` passes on every engine; the image builds; subpath hosting
  and hard refresh work for the new URLs.

## Open questions

All decided by the owner on 2026-10-06, before the phase was built
([`open-questions.md`](open-questions.md) #207–#211, and #243–#244 found
while starting it).

- **Projected *Coming up* items.** *Decided 2026-10-06 (#207):* no;
  reminders only.
- **Closed reminders.** *Decided 2026-10-06 (#208):* shown muted by
  default. *Found while starting (#243):* `closed=0` hides them, from a
  *Hide done and dismissed* link. *(#244):* the widget counts open
  reminders only.
- **Add reminder from a day.** *Decided 2026-10-06 (#209):* keep the
  prefilled link.
- **The widget.** *Decided 2026-10-06 (#210):* a small month.
- **Week numbers.** *Decided 2026-10-06 (#211):* built, from the locale's
  week rules, on the page only (not the widget); the month becomes a
  list of weeks.

Found by the design review, carried to the log (nothing changed for them):

- **Open day on every day with an item** (#245). Each day with items has
  an *Open day* link, the only way to its actions from the grid; on a
  phone that repeats down the agenda. Keep it on every such day, or only
  where items are hidden (and let the title link do the rest)?
- **Three overdue counts** (#246). The strip counts every overdue
  reminder, the sidebar badge counts overdue and due soon, and the widget
  counts this month's. Align the wording, or leave each as it is?
