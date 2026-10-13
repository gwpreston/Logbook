# Phase 46 — What a share allows, in plain words + release

*"Can log fuel, can't see costs", before you press Add.*

Status: 🚧 in progress · file lives in `docs/phases/`

Sharing (§7.21) is four levels, plus *Can see costs* and *Send them its
reminders*. The form shows the level names (*Manage*, *Log*, *View*),
but not what each one means. Some consequences surprise owners:

- **View** shows documents, and documents carry the policy number and
  the registration.
- **Log** lets someone see the amounts on their own entries, even
  without *Can see costs*.
- **Manage** always includes costs, exports and the sale pack.
- Ticking ***Admin*** on Settings → Users → *Invite* is a much bigger
  grant than any share. An admin manages users, modules and the server's
  settings, and runs backups and restores. A backup holds every vehicle
  on the install.

All of these are written in the spec. None of them is written on the
form where the choice is made.

This phase shows, beside each choice, what it allows and what it doesn't,
in plain words. The sentences are built from the same ability table the
access policy enforces (§5), so the description can't drift from the
behaviour. It also adds a few quick picks for the common cases.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5 (the
access policy and abilities), §7.21 and §7.9 (*Users and invitations*)
first.

**Prerequisites:** none. Independent of Phase 45.

---

## Goals

1. A *This lets {name}* summary beside the sharing form, for adding a
   share and for changing one. It updates as the choices change, and
   works without JS.
2. The same summary on a shared user's own *Sharing* page (*What you can
   do*).
3. A plain summary of the *Admin* tick on the invite and user pages.
4. Quick picks that fill in the level and its ticks (open question A).
5. Release.

## Not in scope

- New levels or abilities. Every rule stays as §7.21 has it.
- Attaching vehicle shares to an invitation (open question B parks it).
- Per-entry or per-module permissions.

---

## Spec (proposed)

Added to §7.21 and §7.9 in 46.0:

- **`Service\Access\ShareSummary`** turns a level and its ticks into two
  short lists, *Can* and *Can't*. Each line comes from the abilities
  (§5) and from which modules are switched on: no fuel line while `fuel`
  is off. Lines, in this order (wording settled in open question C):
  - *see the vehicle, its history, mileage and reminders*
  - *see its documents, including policy numbers and the registration*
  - *add fill-ups, service records and other entries*
  - *edit or delete their own entries only* or *any entry*
  - *see costs, valuations and reports*, or *see the amounts they type
    themselves, but no totals or reports*
  - *export, import and prepare the sale pack*
  - *get its reminders*
  - *share, transfer, archive or delete it* (always a *Can't* line,
    since only the owner can)
- **A test fails the build** when an ability exists that no line covers,
  or a line names an ability the level doesn't grant. This works the
  same way as the route inventory test (Phase 18.1).
- **The sharing form:** the summary sits under *Add*, and under each
  existing share's *Save*. With Alpine it updates as the level and ticks
  change. Without JS, a small table of every level's summary sits below
  the form, and the result page repeats the saved one. After a save that
  widens access, the flash message names what changed: "Sam can now see
  costs".
- **A shared user's *Sharing* page:** *What you can do*, the same
  summary written in the second person.
- ***Admin* on invite, *Make admin* and *Add user*:** one line,
  "Admins can manage users and modules, change server settings, and
  download or restore backups containing every vehicle." *Make admin*
  gets a confirmation page that repeats it.
- **Quick picks** (open question A): *Driver* sets Log without costs,
  with reminders on. *Co-owner* sets Manage, with reminders on.
  *Viewer* sets View without costs. A pick only fills in the controls,
  and the share stores its level and ticks as today.

---

## Tasks

### 46.0 Spec first
- [x] Open questions A–D decided by the owner and logged; §7.21, §7.9,
      §13; `ROADMAP.md` row.

### 46.1 Summary
- [ ] `ShareSummary` and its translations (every shipped locale),
      following the enabled modules.
- [ ] The coverage test against the ability table.

### 46.2 Pages
- [ ] The sharing form: add and edit, the live summary, the no-JS
      table, the flash line when access widens.
- [ ] *What you can do* on a shared user's *Sharing* page.
- [ ] The *Admin* line on invite and *Add user*, and the *Make admin*
      confirmation.
- [ ] Quick picks, if A decides for them.

### 46.3 Tests and release
- [ ] Every level and tick combination, with modules on and off.
- [ ] The summary matches what the policy allows: for each line, a
      request that the line says *Can* is allowed, and one it says
      *Can't* is refused.
- [ ] The access matrix is unchanged. No migration.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; German
      at 375 px; screen reader reads *Can* and *Can't* as words, not
      icons only).
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅; tag once
      merged.

---

## Acceptance criteria

1. Before adding or changing a share, the owner can read what it will
   allow and what it won't, including documents and own-entry amounts.
2. Before making someone an admin, the inviter reads that an admin can
   take a backup of every vehicle.
3. The summary cannot disagree with the policy without a test failing.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Quick picks?**
  - (1) None. The summary alone does the work.
  - (2) Quick picks that fill in the controls (*Driver*, *Co-owner*,
    *Viewer*), with nothing stored but the level and ticks.
  - (3) Named roles stored on the share.

  *Recommendation:* (2). The levels already are presets, and the mistake
  people make is with the ticks. Stored roles would be a second
  vocabulary for the same rules.

- **B. Shares on the invitation?** Today, an admin invites, and the
  owner shares by username afterwards.
  - (1) Leave it, parked in §12.
  - (2) Let the invite form choose vehicles and a pick, applied when the
    link is used.

  *Recommendation:* (1). It is a bigger change, and the preview helps
  either way. *Decided 2026-10-13:* (1), parked in spec §12. (#393)

- **C. Wording.** Name the documents line's contents (policy numbers,
  registration), or say only "documents"?

  *Recommendation:* name them. That is the surprise this phase exists to
  prevent. *Decided 2026-10-13:* name them. (#394)

- **D. Confirm when access widens?**
  - (1) A flash line after saving.
  - (2) A confirmation page before turning *Can see costs* on, or moving
    to Manage.

  *Recommendation:* (1) for shares, since they are easily undone, and a
  confirmation page for *Make admin* only. *Decided 2026-10-13:* (1),
  with the *Make admin* confirmation page. (#395)

- **Found while starting it (2026-10-13):** Manage also grants
  `ViewOthersTrips` and `ViewIncidentDetails`, which the draft's lines
  didn't cover; the coverage test needs a line for each, so the summary
  has *see other drivers' trips and destinations* (trips on) and *see an
  incident's fault, claim numbers and payouts* (incidents on). Not a new
  rule, only words for an existing one.
- The carried #221, #374–#377, #390 and #391 were reviewed and change
  nothing in Phase 46.
