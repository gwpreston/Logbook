# Phase 34.3 — Reminders calendar and dashboard widget + v3.1 release

*See what is due as a month, not only as a list.*

Status: 📋 planned · releases **v3.1.0** with Phases 34.1 and 34.2 · file
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
5. Release **v3.1.0** (Phases 34.1–34.3).

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

### §7.6 Reminders: calendar view (new)

> - **Switch.** `/reminders` gets *List* and *Calendar* (two links; the
>   current one has `aria-current`). The calendar is
>   `/reminders/calendar?month=YYYY-MM`, with `&vehicle=` and `&closed=1`
>   (below) and `&day=YYYY-MM-DD`. Without `month` it is the current month
>   in the viewer's time zone; an invalid `month` falls back to it. Months
>   more than five years either side of today still render (empty), but
>   *Previous* and *Next* stop there.
> - **What appears.** Exactly the reminders `/reminders` shows this viewer:
>   the same sources, the same module gates, the same access (§7.6, Phase
>   19), no archived vehicles. The page reads them through the same
>   service, including its sync on read. Each is placed on its `due_on`.
>   Open reminders by default; `closed=1` adds done and dismissed ones,
>   muted. Reminders with **no** `due_on` (a distance-only schedule) are
>   listed under the grid as *Not on the calendar yet*.
> - **Overdue strip.** Above the grid, a line with the number of overdue
>   reminders and links to the first three and to the list, so an old
>   overdue item is found without paging back through months. An overdue
>   reminder also appears on its own date.
> - **Markup.** The month is one ordered list of days, laid out as a
>   7-column grid on wide screens. Each day is a list item with an
>   anchor id (`day-YYYY-MM-DD`), its date as text (the weekday is
>   included, visually hidden on wide screens), and its items. There is no
>   ARIA `grid` role: it would promise arrow-key behaviour the page does
>   not have. The first day of the week comes from the viewer's locale
>   (ICU), not a setting. Days of neighbouring months fill the first and
>   last row empty and dimmed, carrying no items.
> - **Small screens** (under 640 px): days without items are hidden and the
>   rest read as an agenda, today marked.
> - **A cell shows up to three items**; more become a *+N more* link to
>   `?day=` for that date. With a `day`, a panel under the grid lists that
>   day's items in full, each with its actions as on the list, and an
>   *Add reminder* link (the manual-reminder form with the date filled in,
>   `?due=YYYY-MM-DD`) wherever the list offers *Add reminder*. This works
>   without JavaScript.
> - **An item** shows its source icon, its title, a status in words and an
>   icon (*Overdue*, *Due*, *Upcoming*, *Done*, *Dismissed*: colour is
>   never the only cue), and its vehicle (the plate chip of Phase 34.1).
>   It links to the same place the list links.
> - **Vehicle filter:** the dashboard's chips (`?vehicle=`), with two or
>   more active vehicles.
> - **Feed hint.** Under the grid: "See these in your own calendar app",
>   linking to the feed section of Settings → Reminders.
> - **`reminders` off:** the calendar answers 404 and the *Calendar* link
>   and widget are gone, as the list is.

### §7.8 Dashboard: Calendar widget (new)

> - **Calendar** (id `calendar`; with `reminders` on; appended to saved
>   layouts; in the default order after *Upcoming reminders*). A small
>   month for the viewer's current month: weekday initials, the days, today
>   marked. A day with reminders shows how many and the most urgent status
>   (overdue before due before upcoming), as an icon with text in its
>   `aria-label` (for example "12 October: 2 reminders, 1 overdue"), and is
>   a link to that day on the calendar page. Under the grid, one line: "N
>   reminders this month, M overdue".
> - *Previous* and *next* month are links (`/?calendar=YYYY-MM`, keeping
>   `?vehicle=`); the widget's title links to the calendar page for the
>   month shown. It follows the vehicle chips, and the pinned vehicle shows
>   that vehicle only.
> - The default order becomes: needs attention, upcoming reminders,
>   **calendar**, coming up, spend this month, expense breakdown, monthly
>   spend, recent fuel, your vehicles, efficiency trend, compliance status,
>   mileage, recent activity, business mileage, finance, cheapest fuel,
>   true cost.

### §7.10 Feature toggles (changed)

> `reminders` off also removes the calendar view and the *Calendar*
> widget.

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
- **No new setting or stored data.** The week's first day comes from the
  locale and the view is a URL, like the dashboard's filter.
- **A switch, not a sidebar entry.** The sidebar and the mobile bottom
  navigation keep their slots.

---

## Tasks

### 34.3.0 Spec first
- [ ] `spec.md` §7.6, §7.8, §7.10 as above; §13 entry; `ROADMAP.md` row
      and section.

### 34.3.1 Code
- [ ] `Service\Reminders\CalendarMonth` (a value object built from the
      reminders the list service returns): the days, their items, the
      overdue summary, the *not on the calendar yet* list, the first day of
      the week (`IntlCalendar`). No second query path for reminders.
- [ ] `ReminderCalendarAction` (`GET /reminders/calendar`) declaring the
      same ability as the list, so the route inventory sees it.
- [ ] Dashboard service for the widget, reusing `CalendarMonth` with the
      vehicle filter and `?calendar=`.
- [ ] `?due=` prefill on the manual reminder form (a valid date only).

### 34.3.2 Templates, CSS
- [ ] Calendar page and day panel; widget; the *List* / *Calendar* switch
      on `/reminders`.
- [ ] CSS: the 7-column layout on wide screens; the agenda below 640 px;
      status icons and words; dimmed neighbouring days; the focus style on
      every link.

### 34.3.3 Translations
- [ ] English and German strings. Month and weekday names come from ICU,
      not hand-written lists.

### 34.3.4 Tests
- [ ] Unit: month construction for 28-, 29-, 30- and 31-day months (February
      2028), a month starting on each weekday, and weeks starting on
      Monday, Sunday and Saturday (`en_GB`, `en_US`, `ar_EG`).
- [ ] Unit: "today" at a time-zone boundary (a viewer in `Europe/London`
      across the clock change); `due_on` is a calendar date and is never
      shifted through a time zone.
- [ ] Integration: the calendar shows the same reminders as the list for
      the same viewer, source by source; no archived vehicles; a View-share
      user sees what the list shows them and no more; a vehicle's
      reminders do not appear for a user with no access.
- [ ] Integration: overdue strip counts and links; an overdue reminder
      appears on its date and in the strip; reminders with no date appear
      under the grid; `closed=1` adds done and dismissed ones muted.
- [ ] Integration: more than three items give *+N more* and the `day`
      panel lists them all with working actions and an *Add reminder* link
      with the date; an invalid `day`, `month` or `due` is ignored.
- [ ] Integration: `reminders` off gives 404 for the page and removes the
      widget and links; layouts keep the widget's place.
- [ ] Integration: the widget's marks and `aria-label`s are right for a
      day with several statuses; previous and next keep `?vehicle=`; an old
      saved layout gets the widget appended.
- [ ] Without JavaScript every test above holds (these are plain pages).
- [ ] Query count: one reminders read per page, however many vehicles.

### 34.3.5 Checks
- [ ] `design-reviewer` agent at 375, 768 and 1280 px, light and dark, all
      four accents, keyboard only, and with a screen reader reading one
      month.

### Sample data
- [ ] `DemoDataSeeder`: reminders spread over at least two months, with an
      overdue one, a due one, a manual one, a done one and a distance-only
      schedule with no date, so the calendar and the widget are not empty.

### Release (with Phases 34.1 and 34.2)
- [ ] `CHANGELOG.md` **3.1.0**: *Added* — registration plates (34.1); the
      *Expense breakdown* and *Monthly spend* widgets (34.2); the Reminders
      calendar and *Calendar* widget (34.3). No migration, no configuration
      change, no backup change. *Upgrade notes*: the new widgets are added
      to the end of existing dashboards; move or hide them under
      *Customise*.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
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

- **Projected *Coming up* items.** Show them dimmed and labelled
  *Projected*, or keep the calendar to reminders (drafted)?
- **Closed reminders.** Hidden unless `closed=1` (drafted), or shown muted
  by default?
- **Add reminder from a day.** Keep the prefilled link (drafted) or leave
  creation to the list?
- **The widget.** A small month (drafted), or a list of the next few days'
  reminders (which *Upcoming reminders* already is)?
- **Week numbers** on the grid: not built (drafted).
