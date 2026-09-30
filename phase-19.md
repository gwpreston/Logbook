# Phase 19 — Multiple users and vehicle sharing + v2.0 release

*A family garage: everyone sees their own cars and the ones shared with
them.*

Status: ✅ complete · released as **v2.0.0**

An instance can have more than one user. An admin invites people; each user
has their own vehicles, preferences and reminders; and a vehicle can be
shared with others at a chosen level. Your partner can log fill-ups on the
Mini without seeing its running costs or being able to delete its service
history.

Phase 18.1 already made every route and every cross-vehicle read ask an
access policy. This phase gives that policy real data: a sharing table, an
admin flag and a record of who added each entry. The rest of the work is the
screens around it, notifications, and the rules for leaving and deleting.

Read [`CLAUDE.md`](CLAUDE.md), [`spec.md`](spec.md) §5, §6 and §7.9, and
Phase 18.1 first.

---

## Roles

Access has two levels.

**Instance:**
- **Admin:** manages users, modules, backup and restore, and notification
  channels. The first user, from setup, is an admin. There is always at
  least one.
- **Member:** everyone else.

**Per vehicle:**

| Level | Can | Maps to Phase 18.1 abilities |
|---|---|---|
| **Owner** | everything, including sharing, archiving, deleting and transferring | all |
| **Manage** | edit the vehicle and any entry, schedules, valuations, import, sale pack | `View`, `Log`, `Manage` (+ `ViewCosts`) |
| **Log** | see it, add entries, edit or delete their own entries | `View`, `Log` |
| **View** | see it | `View` |

Each share also has a **Can see costs** flag. It is always on for Manage;
for Log and View it is off by default.

The roles you suggested map onto this as follows. *Owner* is the vehicle's
owner. *Admin* is the instance admin. *Driver* is Log without costs.
*Viewer* is View.

## Not in scope

- OIDC or SSO and reverse-proxy header sign-in. That is a later phase; the
  user model here is what it will attach to.
- Public sign-up. Only admins create users.
- Groups, households as an entity, or organisations. A share is one user
  and one vehicle.
- Per-entry permissions, public read-only share links, and approval flows.
- Commercial fleet features (spec §2 non-goals).

---

## Spec changes

### §2 and §3
Replace "Single owner per instance to start" with "One or more users per
instance, sharing vehicles (Phase 19)". It is still self-hosted and not
multi-tenant: one install is one household.

### §6 Data model

**User** gains `is_admin` (bool, default false) and `disabled_at` (UTC,
nullable). Upgrading to 2.0.0 sets `is_admin = true` on every existing
user (there is one). Rolling back is refused while more than one user
exists, with a message naming the command that exports their data first.

**VehicleShare** (new): id, vehicle_id (`ON DELETE CASCADE`), user_id
(`ON DELETE CASCADE`), level (`view`|`log`|`manage`), can_see_costs
(bool), notify (bool, default false: whether this user gets the vehicle's
reminders), created_at, updated_at. The pair (vehicle_id, user_id) is
unique. The owner is `vehicles.user_id` and never has a share row.

**Invitation** (new): id, token_hash (HMAC-SHA256 with `SESSION_SECRET`,
like other tokens), created_by (user), username (reserved), display_name,
is_admin, expires_at (7 days), used_at, created_at.

**Entry authorship:** `created_by` (user id, nullable, `ON DELETE SET
NULL`) on fuel_entries, odometer_readings (manual only; derived readings
take their entry's), maintenance_entries, compliance_documents,
expense_entries, tyre_changes, vehicle_valuations and attachments
(`uploaded_by`). Existing rows stay null, which reads as the vehicle's
owner. Imported rows take the importing user.

Backups carry every new table and column. A 2.0.0 backup never restores
into 1.x (the schema version moves).

### §7.9 Authentication, users and invitations

- **Setup** is unchanged: it creates the first user, an admin.
- **Users** (Settings → Users, admin only): list with role, last sign-in
  and status; *Invite*; *Make admin* or *Remove admin* (never the last
  one); *Disable*, which ends their sessions and API keys at once and
  blocks sign-in; *Enable*; and *Delete* (below).
- **Invite:** the admin types a username, display name and whether they
  are an admin. The app shows a one-time link
  (`/invite/{token}`, 7 days) to copy. If email is configured, it can also
  send it to an address typed then, which is not stored. Opening the link
  asks for a password, locale, time zone, unit preset and currency, as
  setup does, then signs the user in. Used, expired or revoked links
  answer 404.
- **Password reset** by an admin: *Reset password* creates the same kind
  of one-time link for an existing user and ends their sessions. There is
  no self-service reset by email in this phase.
- **Deleting a user:** refused while they own vehicles, with a list of
  those vehicles and *Transfer*. Their shares go. Entries they added to
  other people's vehicles stay, with `created_by` null, shown as "a former
  user". Their API keys, calendar feed and sessions go.
- Sign-in, sessions and CSRF are as before, per user.

### §7.21 Sharing

- **Share** (vehicle header menu, `Own` only): add a user by username
  with a level, *Can see costs* and *Send me its reminders*; change or
  remove existing shares. Plain forms, working without JS. The owner can
  also **transfer** the vehicle to another user, who becomes the owner;
  the old owner keeps Manage unless they untick it.
- **Leave:** a user with a share can remove it themselves.
- **Garage:** two groups, *Your vehicles* and *Shared with you* (the
  latter naming the owner and your level). The dashboard's vehicle chips,
  fleet history, Reports and *Coming up* cover every vehicle you can see.
  Cost figures leave out vehicles you cannot see costs for, and say so
  ("Excludes 1 vehicle shared without costs").
- **Costs without `ViewCosts`:** amounts, prices, reports, the cost of
  ownership card, valuations and the *Coming up* costs are hidden, and
  amount columns show "—". The one exception is a user's **own entries**:
  they see the amounts they typed, because they paid them. Forms still
  take costs, since a driver pays at the pump.
- **Log level:** add forms for every kind; edit and delete only on
  entries with `created_by` = themselves. Other entries show without edit
  links. Import, schedules, valuations, the sale pack, CSV export and
  vehicle edits are Manage.
- **"Added by":** when a vehicle has any shares, list rows and history
  show a small "Added by {display name}" on entries not added by the
  viewer. Entries with `created_by` null count as the owner's.
- **Per-user preferences** already exist: each user sees every vehicle in
  their own units, language and time zone. Money stays in the vehicle's
  currency.
- **Dashboard layouts** are per user already.

### §7.11 Notifications

- A vehicle's reminders go to its **owner**, and to each shared user
  whose share has `notify` on. Each user's run is separate, in their
  language, units and time zone, with only the vehicles they can see.
  `reminders.notified_status` stays per reminder (one status change, one
  run), and each recipient is sent once per status. That needs
  `reminder_deliveries` (reminder_id, user_id, status, sent_at, unique
  triple) instead of the single `channels_notified` list.
- **Channels per user:** email goes to the user's own address (set in
  their Settings → Reminders; `MAIL_TO` is the admin's default). ntfy and
  Gotify accept a per-user topic URL or token that overrides the
  instance's. Without one, only admins receive through the instance topic,
  so a household topic is never flooded by everyone's cars. The webhook
  stays instance-level, admin-configured, and its payload gains `user`.
- The digest and calendar feed are per user and cover their `notify`
  vehicles plus their own.

### §7.20 API
Keys already follow their user's access (Phase 18.2). Keys of a disabled or
deleted user stop working at once.

---

## Decisions (and why)

- **Per-vehicle levels, not global roles.** Households share some cars and
  not others. A global "Driver" can't express "logs on the Mini, only views
  the BMW".
- **Costs are a flag, not a level.** Whether someone sees running costs is
  a family decision independent of whether they log fuel.
- **Own entries show their amounts.** Hiding the price from the person who
  typed it makes the form feel broken, and they already know it.
- **Invitations are links, not email.** Email is optional in Logbook. A
  link can be sent by any means.
- **Rollback refused with more than one user.** Rolling back would collapse
  several people's data into one owner silently. Refusing, with an export
  route, is the honest option.
- **v2.0.0.** No existing feature breaks, but backups, notifications and the
  meaning of an install change. A major version tells self-hosters to read
  the upgrade notes.

---

## Tasks

### Spec and docs
- [x] §2, §3, §6, §7.9, §7.11, §7.21 (new), §7.20 and §13 in `spec.md`;
      remove multi-user from §12.
- [x] `docs/users-and-sharing.md`: roles, sharing, costs, notifications, and
      the rollback rule.

### Migrations (every engine, each reversible)
- [x] `users.is_admin`, `users.disabled_at`; existing users become admins.
- [x] `vehicle_shares`, `invitations`, `reminder_deliveries` (backfilled
      from `channels_notified` for the owner).
- [x] `created_by` and `uploaded_by` columns with indexes.
- [x] A rollback guard: refuse with more than one user, and name
      `bin/export-user.php`.

### Policy
- [x] `SharedVehicleAccess` replaces `SingleOwnerVehicleAccess`. It reads
      ownership and shares (one query per request, memoised) and adds the
      own-entry rule for `Log` edits and amounts.
- [x] `AdminInstanceAccess`: instance abilities for admins only.
- [x] Disabled users are refused by the auth guard, sessions and API key
      verification.

### Screens
- [x] Settings → Users, invite and reset links, and the invitation page.
- [x] Share page, transfer, leave.
- [x] Garage groups; the "Excludes N vehicles" notes on cost figures;
      "Added by"; hidden edit links for others' entries at Log level.
- [x] Settings → Reminders: personal email address, ntfy topic and Gotify
      token overrides.

### Services
- [x] Reminder dispatch per recipient with `reminder_deliveries`.
      Idempotency is claimed per (reminder, user, status).
- [x] Every "created" path (forms, import, API) sets `created_by`.
- [x] `bin/export-user.php <username>`: a backup-format ZIP of one user's
      vehicles, for moving someone to their own install.

### Tests
- [x] **Access matrix:** every route in the inventory, as owner, manage,
      log, view, log without costs, no share and admin non-owner, with the
      expected status for each. The table lives in one test file, so a new
      route has to state its row.
- [x] Costs: no amount reaches the HTML, CSV, API or print for a user
      without `ViewCosts`, except their own entries. Use seeded sentinel
      amounts.
- [x] Log level: can edit their own fill-up, not the owner's; delete the
      same.
- [x] Notifications: owner plus two shares (one with `notify`), each in its
      own language and units, each once per status; a retry after a
      partial failure never doubles one recipient; the household ntfy topic
      gets only admins' reminders.
- [x] Invitations: expiry, single use, revoked, and a taken username.
- [x] Deleting and disabling users: the paths above, and API keys stop
      working at once.
- [x] The last admin cannot be removed or disabled.
- [x] Transfer keeps every entry, schedule, reminder and file.
- [x] Upgrade from 1.10.0 with real data: the single user becomes an admin,
      nothing changes visibly, and every figure is identical.
- [x] Rollback: works with one user; refused with two.
- [x] Integration suite green on every engine.

### Sample data
- [x] `DemoDataSeeder` gains a second user (`partner` / `logbook-demo`)
      with Log access to the self-charging hybrid without costs and View access to the
      Golf, and fill-ups on the hybrid added by them.

### Release
- [x] `CHANGELOG.md` **2.0.0** with prominent upgrade notes: take a
      backup; migrations; existing user becomes admin; `MAIL_TO` is now the
      admin's default; rollback refused once a second user exists; 1.x
      cannot restore 2.0 backups.
- [x] Bump `VERSION`, rebuild assets, update the README (status,
      documentation table, "first visit creates the owner account" →
      "creates the first admin").

---

## Acceptance criteria

1. An admin invites a second user, who signs up from the link and sees
   only their own vehicles and those shared with them.
2. A Log-level user without costs can add a fill-up and edit it, sees its
   amount, and never sees any other amount, report figure or valuation for
   that vehicle, in HTML, CSV, print or the API.
3. Each user receives their reminders in their own language and units,
   once, only for vehicles they own or chose to be notified about.
4. Upgrading an existing single-user install changes nothing visible.
5. Definition of done (CLAUDE.md §11) holds on every engine.

## Open questions (answered)

- **Admins see only their own and shared vehicles**, as drafted. Backup is
  how an admin sees everything; an admin who needs a car shares it or is
  given a share like anyone else.
- **Log users see the documents** (policy numbers, registration), as part
  of View. A *Can see documents* flag can come later if a household asks.
- **No self-service password reset by email.** The admin's one-time reset
  link is enough for a household, and email stays optional in Logbook.

---

## Changed while building it

spec.md §5, §6, §7.9, §7.11, §7.13, §7.20 and §7.21 are the current text.

- **Null `created_by` means a former user, not the owner.** The plan had
  existing rows stay null and read as the owner's, but also had a deleted
  user's entries (null through `ON DELETE SET NULL`) shown as "a former
  user"; both cannot hold. The upgrade names each vehicle's owner on the
  rows already there (manual readings only; a derived reading's author is
  its entry's), and entries added where there is no signed-in user (the
  command line, seeds) name the vehicle's owner. Transfers and edits never
  change it.
- **The own-entry rules live in `EntryAccess`**, not on the
  `VehicleAccess` interface, which gained only `recipientVehicleIds()`:
  `canChange()` and `canSeeAmount()` are the same for any vehicle policy,
  and the test double did not have to learn them. Entry edit and delete
  routes declare `Log`; `Action\EntryGuard` answers 403 for someone
  else's entry without Manage. Templates ask `can_change()`,
  `can_see_amount()`, `can_vehicle()` and `added_by()`; the cost scan
  accepts `own_amount` / `can_see_amount()` beside `costs`.
- **Invitations have a kind.** Reset links are rows of the same table
  (`kind` = `reset`, `user_id` = whom), with `revoked_at` for *Revoke*; a
  new reset link revokes the user's older ones. Invitations are not in
  backups, like sessions.
- **The Expenses tab is `View`.** It needed `ViewCosts`, which would have
  left a Log driver without costs unable to find the expenses they added;
  without costs it lists the ad-hoc expenses, amounts hidden bar their own,
  with no totals or chart. The API's expenses list still needs `ViewCosts`.
- **An admin can transfer a vehicle when deleting its owner.** Admins are not
  owners, so the owner's *Transfer* is out of their reach; the delete page
  has a transfer form per vehicle so an account nobody can sign in to can
  still be removed.
- **Channels say whether they reach someone** (`NotificationChannel::reaches()`),
  and the registry uses it with the recipient. A member's ntfy topic URL can
  be on any server (the instance token goes only to the instance's
  server); a Gotify token is always on `GOTIFY_URL`. The webhook reaches
  everyone.
- **A shared vehicle is synced with its owner's settings**: `ReminderSync`
  judges each vehicle by its owner's lead times, today and tyre limits, so a
  shared user opening Reminders never moves a status.
- **Claims moved to `reminder_deliveries`.** Claiming inserts the row (the
  unique key decides, outside any transaction); release deletes it while
  unsent; the reminder's own `notified_status`, `last_notified_at` and
  `channels_notified` keep the latest delivery to anyone. A new occurrence
  deletes the rows.
- **Route arguments are `{member}`, not `{user}`**: Slim puts route
  arguments on the request as attributes, and `user` is the signed-in user.
- **No separate author indexes.** The columns have their foreign keys (MySQL
  indexes those itself); nothing lists entries by author.
- **Rolling back refuses cleanly inside Phinx's transaction** (it is rolled
  back before the message), and `bin/dev-setup.sh --reset` deletes the
  accounts before rolling back, since the sample data has two users.
- **The seeder's hybrid had no fill-ups** (it only had readings), so the
  partner's fill-ups are new: one in the middle of each of the last six
  months, between the owner's readings.
- The API response shapes did not change (no `created_by` in them), so the
  OpenAPI description stands.
