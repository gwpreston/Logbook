# Phase 33.2 — Sign-in and Settings to the prototype, and the sidebar

*The first page anyone sees and the page everyone configures from, drawn
the way the design says.*

Status: 📋 planned · no release of its own (ships with Phase 33.4 as
**v3.0.0**) · file lives in `docs/phases/`

The prototype in `design-import/` has new designs for the sign-in,
forgotten-password and reset-password pages and for Settings, including
user management. This phase brings the app's pages into line with them,
regroups the Settings cards so each link sits where people look for it,
fixes the unit presets that give no feedback, and makes two small changes
to the sidebar.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.9,
§7.10, §8 (design system, tokens, app shell, accessibility) and §13,
[Phase 7](phase-7.md) (the first design import) and
[Phase 33.1](phase-33.1.md) first.

**Prerequisites:** [Phase 33.1](phase-33.1.md) built (its forgotten-password
and admin pages are what this phase draws).

---

## Working from the prototype

`design-import/` is the visual source of truth; `spec.md` §8 and the app's
own CSS tokens are how it is built. Every screen in Phases 33.2–33.4 is
done the same way:

1. **Audit first.** Before any code for a screen, open its prototype file,
   list what it shows (sections, cards, controls, states, wording) and
   what the app shows today, and record the differences in this file
   under *Prototype notes*, one subsection per screen.
2. **Sort each difference** into one of three:
   - *Layout and style* (spacing, order, type, colour, components): build
     it, using the app's tokens and macros, never colours or sizes copied
     from the prototype's CSS.
   - *Behaviour or data the app already has*: build it from the existing
     services.
   - *Behaviour or data the app doesn't have* (a new setting, a new
     figure, anything stored, anything fetched): **don't build it**. Add
     it to *Open questions* for the owner, as CLAUDE.md §12 requires.
3. **Keep the rules the prototype can't show:** both themes and all four
   accents; works without JS; keyboard and screen reader; 375 px to wide;
   translatable strings (en and de); subpath; print where it exists.
4. **Check** with the `design-reviewer` agent (`.claude/agents/`) at 375,
   768 and 1280 px, light and dark, before the screen is ticked off.

---

## Goals

1. **Sign-in, forgotten password and reset password** pages as the
   prototype draws them; setup, invitation, welcome and the break-glass
   page in the same style so the signed-out pages match each other.
2. **Settings** laid out as the prototype lays it out (its sections,
   navigation and card styles), including the user management pages.
3. **Cards that make sense:** the *Reminders and notifications* card holds
   only reminder and notification settings; tyres, trips and mileage
   claims, places, importing and API keys move to cards named for what
   they are.
4. **Unit presets that answer back:** the Metric, UK and US buttons on
   *Units and currency* show hover and focus, and show which preset the
   current units match.
5. **Sidebar:** *Stations* is called *Fuel stations*; *Settings* sits
   below *Ask*.

## Not in scope

- New settings. Anything the prototype's Settings shows that the app has
  no setting for goes to *Open questions*.
- Changing what any setting does or where it is stored.
- The vehicle pages, Ask and Fuel stations ([33.3](phase-33.3.md),
  [33.4](phase-33.4.md)).

---

## Spec additions

### §8 App shell (changed)

> - **Sidebar order:** Dashboard, Garage, Reminders, Reports, Fuel
>   stations (module on), Ask (AI on), **Settings**, then the vehicles
>   list. *Settings* moves from above *Ask* to below it.
> - The module and its pages are called **Fuel stations** everywhere a
>   person reads it (sidebar, bottom navigation, page titles, Settings →
>   Modules, breadcrumbs), in every locale. Route names, URLs (`/stations`)
>   and the module key (`stations`) are unchanged, so links and API
>   clients keep working.
> - The mobile bottom navigation keeps its five slots; its labels follow
>   the rename.

### §8 Settings layout (new)

> Settings is laid out as the prototype's Settings: its sections, the
> navigation between them and the card style (recorded in *Prototype
> notes*). Every section keeps its own URL so it works without JS and
> survives a hard refresh. Cards, in order, each shown only to those who
> can use it:
>
> - **Account:** profile (name, email, avatar), password, single sign-on,
>   *Use AI*.
> - **Preferences:** appearance, units and currency, region, preview.
> - **Reminders and notifications:** reminder settings (channels, lead
>   times, digest, calendar feed). Nothing else.
> - **Vehicles and driving:** tyre thresholds (tyres on), trips and
>   mileage claims (trips on), places (fuel stations on).
> - **Your data:** import from another app (fuel on), export.
> - **Developers:** API keys (and MCP, which uses them).
> - **Administration** (admins): users, modules, AI connections, fuel
>   prices, backup and restore.
> - **Installation** (admins): health, scheduled jobs, updates,
>   deep-link check.
>
> The grouping above is the draft; the prototype's names and order win
> where they differ, and are recorded in *Prototype notes*.
>
> **Unit presets:** each preset button is `aria-pressed="true"` when the
> four unit fields match it exactly (worked out on the server for the
> first render, and again in JS whenever a field changes), and `false`
> otherwise, including when none matches ("custom"). Pressed uses the
> chip's selected style; every preset has the chip's hover and
> `:focus-visible` styles. Without JS the presets stay hidden as today.

### §7.9 Sign-in pages (changed)

> Sign-in, forgotten password, reset password, setup, invitation,
> welcome and break-glass pages share one signed-out layout drawn from the
> prototype. Behaviour and wording rules from §7.9 and Phase 33.1 are
> unchanged (no account enumeration; SSO button above the password form;
> only the button with local sign-in off).

---

## Tasks

### 33.2.0 Spec first
- [ ] `spec.md` §8 and §7.9 as above; §13 entry.

### 33.2.1 Prototype audit
- [ ] *Prototype notes* for: sign-in, forgotten password, reset password,
      each Settings section, Settings → Users (list, invite, add user, a
      user's actions, delete), Settings → Account profile with avatar.
- [ ] Open questions for anything in them the app doesn't have.

### 33.2.2 Signed-out pages
- [ ] One signed-out layout; sign-in, forgotten password and reset
      password to the prototype.
- [ ] Setup, invitation, welcome, break-glass and the proxy signed-out
      page in the same layout.
- [ ] Errors tied to their fields and announced; the password field's
      show/hide control (if the prototype has one) works by keyboard and
      without JS falls back to a plain field.

### 33.2.3 Settings
- [ ] Sections and navigation to the prototype, each with its own URL
      (`/settings`, `/settings/account`, …; old URLs keep working).
- [ ] Cards regrouped as above; *Reminders and notifications* holds only
      reminders.
- [ ] User management pages to the prototype, with Phase 33.1's controls
      and avatars.

### 33.2.4 Unit presets
- [ ] `UnitPreset::matching(...)` on the server; `aria-pressed` on first
      render.
- [ ] `assets/js/app.js`: update `aria-pressed` when a preset is clicked
      and when any unit field changes.
- [ ] CSS: `.chip:hover`, `.chip:focus-visible` and the pressed style
      apply to `[data-unit-preset]` in both themes and every accent.

### 33.2.5 Sidebar
- [ ] *Fuel stations* label (sidebar, bottom nav, page titles, modules
      page, docs), en and de (*Tankstellen*).
- [ ] *Settings* below *Ask* in the sidebar.

### 33.2.6 Tests
- [ ] Sign-in pages render for: local only, SSO and local, SSO only,
      email off (no forgotten link), header sign-in hint.
- [ ] Each Settings section answers at its URL, shows only the cards the
      user may use (member, admin, modules off, AI off) and works without
      JS.
- [ ] *Reminders and notifications* contains no link to tyres, trips,
      places, import or API keys.
- [ ] Unit presets: UK settings render UK pressed and the others not;
      mixed units render none pressed.
- [ ] Sidebar order and the *Fuel stations* label (also with the module
      off: absent).
- [ ] `DesignAlignmentTest` updated; design-reviewer report clean of HIGH
      findings.

---

## Prototype notes

Audited 2026-10-05 against `design-import/Logbook.dc.html` (the only
prototype file; `support.js` is its runtime). Each difference is sorted as
**L** layout and style (build), **H** behaviour the app has (build from
existing services), **N** new (not built; see *Open questions*), or **K**
kept as the app does it because an earlier decision says so.

### Signed-out shell (all signed-out pages)

The prototype draws one column, 420 px wide, centred, top padding
`clamp(28px, 8vh, 96px)`, gap 20 px:

- **L** The Logbook mark and wordmark (*Log* / *book* in the two brand
  colours) centred **above** the card, not inside it. The app shows the
  card only.
- **L** One card: surface, 1 px border, 22 px radius, 24 px padding,
  shadow, 16 px gap between its parts.
- **L** Heading 24 px Outfit 600, lead 14 px muted under it.
- **L** Fields: label 13 px bold muted above a 48 px input on `surface2`
  with a 12 px radius. Primary button 50 px, full width.
- **L** Errors: one red-soft banner with the `error` icon at the bottom of
  the card. The app shows it at the top, and ties field errors to fields;
  keep both (the banner where the prototype puts it is a layout choice;
  field-tied errors are the accessibility rule).
- **N/demo** The "Email preview · demo only" panel and the demo hint line
  are prototype scaffolding; not built.

### Sign in

- **K** Field is "Email"; the app keeps *Username or email* (#162).
- **L** *Forgot password?* sits right-aligned **between** the password
  field and the button; the app has it below the field already (move to
  right-aligned).
- **L** Lead "Welcome back. Your vehicles are waiting." The app's lead
  stays translatable; wording may follow the prototype (new key, en/de).
- **H** Show/hide password: an eye icon inside the password field
  (`visibility` / `visibility_off`). Built as progressive enhancement: a
  real button with `aria-pressed` and a label, added by JS; without JS
  the plain field.
- **H** SSO button, *or* divider, local-off and header-sign-in notices:
  not in the prototype (it has no SSO); kept, drawn in the same card
  style.

### Forgotten password

- **L** Back link *‹ Sign in* at the top of the card (the app has it).
- **L** Heading "Reset your password", lead about the email link.
- **K** Field label *Username or email* (#162).
- **L** *Sent* state: a 52 px rounded icon tile (`mark_email_read`,
  accent-soft) above the heading "Check your inbox"; two buttons side by
  side, *Resend email* (outlined) and *Back to sign in* (surface2); a
  muted spam-folder hint under them.
- **K** The prototype says the link works for 30 minutes; the app's is 60
  (#159). It names the typed address in bold; the app may, as it is only
  an echo of what was typed (no enumeration).

### Reset password (and invitation's password step)

- **L** Heading "Choose a new password", lead "For {address}". The app's
  lead names the username; keep the username (the link is per user, and
  it may have no address).
- **L** New password and confirm fields; a *Show passwords* text toggle
  under them (JS, as above).
- **H/N** A live checklist under the fields. Of its three rules, *At least
  8 characters* and *Both passwords match* are what the app already
  checks (build, JS only, server still decides). *Includes a letter and a
  number* is **not** an app rule: **N**, see *Open questions*.
- **K** A *Password updated* end screen with *Sign in*, saying other
  devices were signed out. The app signs the user straight in after a
  reset (spec §7.9) and does end every other session, so there is no end
  screen; the confirmation is a flash on the page they land on.

### Setup, invitation, welcome, break-glass, proxy signed-out, email confirmation

- Not in the prototype. Drawn in the same shell (mark above, one card,
  same field and button sizes) so the signed-out pages match. Setup and
  invitation keep their wider card for the longer form.

### Settings (single page)

The prototype's Settings is **one page**, max 720 px wide, title 28 px,
a column of cards (surface, 20 px radius, 18 px padding, card title 17 px
Outfit 600). **It has no section navigation, no per-section URLs and no
user management.** Its cards, in order:

1. **Account:** a 44 px initial circle, the address, "Signed in on this
   device", *Reset password* and *Sign out* buttons.
   - **H** Initial circle → the app's avatar (Phase 33.1) or initial.
   - **H** *Sign out* (the app has it at the foot of the page).
   - **N** *Reset password* that emails yourself a link: the app has a
     change-password form instead (keep the form).
   - The app's profile, email, avatar, password, SSO and *Use AI* cards
     aren't drawn; they go in this group in the same card style.
2. **Appearance:** theme as a segmented control (System / Light / Dark
   with icons). **L** The app has the same three as chips, plus the
   accent picker (kept, under it).
3. **Units & currency:** *Quick setup* row of presets as pill chips, then
   one chip row per unit group (distance, volume, economy, currency),
   each row divided by a hairline, and a muted note on conversion.
   - **L** Rows and hairlines; presets as pills with pressed state
     (task 33.2.4).
   - **H** The app uses selects for the four fields; chips are a style
     choice the app's `ui.chips` macro already offers.
4. **Reminder lead time:** day chips and distance chips.
   **H** The app has lead days and distance on *Settings → Reminders*.
5. **Reminder delivery:** rows with a 40 px icon tile, title, hint and a
   switch: In-app (always on, badge), Email (address and *Send test*),
   Push via webhook (format chips ntfy / Gotify / Home Assistant / any
   URL, URL and *Send test*), *Send at* time chips and *Frequency* chips,
   Calendar feed (URL, copy, *Download .ics*, reset).
   - **H** In-app, email, ntfy, test send, calendar feed, the monthly
     digest: the app has them (on *Settings → Reminders*).
   - **N** Gotify / Home Assistant / generic webhook formats, a *Send at*
     time, a *Frequency* choice, *Download .ics* from Settings.
6. **Data:** *Export expenses (CSV)*, *Reset dashboard layout*, reset demo.
   - **H** Exports exist per vehicle and on Reports; no single expenses
     export on Settings.
   - **N** *Reset dashboard layout* (the app has no such action).
   - **demo** Reset demo: not built.

Not drawn at all, so kept and drawn in the card style: preferences
region and preview, tyres, trips and mileage claims, places, import from
another app, API keys, and every admin and installation link.

### Settings → Users (list, invite, add user, actions, delete)

- **Not in the prototype.** No list, invite, add-user, per-user action or
  delete screen is drawn. Only the shared card, list-row (icon tile,
  title, hint) and button styles above can be applied.

### Settings → Account profile with avatar

- **Not in the prototype** beyond the Account card's initial circle.

### Sidebar and bottom navigation

- **L** Order: Dashboard, Garage, Reminders, Reports, Fuel stations,
  Insights (the app's *Ask*), Settings. Matches Goal 5.
- **L** Label *Fuel stations*. Matches Goal 5.
- Bottom nav: Home, Garage, Log, Reminders, More; Settings in *More*. The
  app's bottom nav is unchanged apart from the label.

---

## Acceptance criteria

1. Sign-in, forgotten password and reset password look like the
   prototype in light and dark, on a phone and a desktop.
2. Settings is laid out like the prototype; *Reminders and notifications*
   holds only reminder settings, and tyres, trips, places, import and API
   keys are each in a card named for them.
3. Hovering a unit preset changes its colour; the preset matching the
   current units is shown selected, and changes as the units change.
4. The sidebar says *Fuel stations*, and *Settings* is under *Ask*.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Settings grouping:** the cards and names above are a draft; the
  prototype's win where they differ. Does the owner want any link kept
  where it is today?
- **Anything new in the prototype's Settings** (settings the app doesn't
  have) is listed here by task 33.2.1 for a decision.
