# Users and sharing

From 2.0.0 one Logbook install can have several people on it: a household,
each with their own account, vehicles, units, language and reminders, and
vehicles shared between them at a level the owner chooses. Your partner can
log fill-ups on the Mini without seeing its running costs or being able to
delete its service history. `spec.md` §7.9 and §7.21 have the rules.

It is still self-hosted and not multi-tenant: one install is one household,
there is no public sign-up, and only an admin adds people.

- [Admins and members](#admins-and-members)
- [Inviting someone](#inviting-someone)
- [Sharing a vehicle](#sharing-a-vehicle)
- [Costs](#costs)
- [Reminders](#reminders)
- [Leaving, transferring and deleting](#leaving-transferring-and-deleting)
- [Upgrading, rolling back and moving someone out](#upgrading-rolling-back-and-moving-someone-out)

---

## Admins and members

| | Can |
|---|---|
| **Admin** | everything a member can, plus Settings → Users, Modules, Backup and restore; the instance's default email address, ntfy topic and Gotify token |
| **Member** | their own vehicles, those shared with them, and their own settings |

The first account, made by setup, is an admin; upgrading to 2.0.0 makes the
existing account an admin. There is always at least one admin who can sign
in: the last one cannot be demoted or disabled. Admins are not owners: they
see their own vehicles and those shared with them, like anyone else (a
backup is how an admin sees everything).

**Settings → Users** (admins only) lists everyone with their picture, role,
email address, when they last signed in and whether they have access, and
has:

- **Invite** and **Add user**: see below.
- **Make admin** / **Remove admin**.
- **Send reset email** (or **Reset password** when they have no confirmed
  address, or email isn't set up): a one-time link (7 days) that asks for a
  new password. With an address it is emailed to them, in their language;
  otherwise it is shown to you once, to pass on. Never both. Their sessions
  end when you make it.
- **Sign out everywhere**: every session of theirs ends, on every device.
  It doesn't stop them signing back in (a sign-in proxy does that on its
  own); you can do it to yourself.
- **Revoke access**: they are signed out everywhere at once, cannot sign in
  (they are told their password is wrong), and their API keys and calendar
  feed stop working. Nothing of theirs is deleted. **Restore access** undoes
  it, keys included. You cannot revoke your own access, nor the last
  admin's. (Before 3.0 these were called *Disable* and *Enable*.)
- **Delete**: see [below](#leaving-transferring-and-deleting).

Members never see these, and every one of their addresses answers 403 to a
member.

## Inviting someone

Settings → Users → **Invite someone**: type their username (what they will
sign in with), their name, and whether they are an admin. Logbook shows a
**one-time link**, once, valid for 7 days. Copy it and send it any way you
like; if email is set up you can also type an address to email it to (the
address is not kept). Open links are listed with *Revoke*.

Opening the link asks for a password and for how they like figures shown
(units, currency, language, time zone), then signs them in. A used, expired
or revoked link answers "not found".

**Add user** (when email is set up) makes the account straight away instead:
username, name, their email address and *Admin*. They get an email with a
link to choose their password (7 days); using it confirms the address. As
the account exists at once, you can share or transfer vehicles to it before
they ever sign in. Without email, use an invitation.

## Email addresses

Everyone has one email address, on your **Profile** (click your name in the sidebar). It is where
reset links and reminder email go, and you can sign in with it instead of
your username. A new address is used for nothing until you confirm it: a
link (24 hours) goes to it, and the old address is told about the change.
Changing or removing it asks for your current password. Several people may
share an address (a household inbox); they then sign in by username, and a
reset request for the address emails each of them their own link.

## Forgotten passwords

When email is set up, the sign-in page has **Forgotten your password?**.
Type your username or email address; if it matches an account with a
confirmed address and a password, a link (60 minutes, once) is emailed to
it. The page answers the same whatever you type, so it never tells anyone
which accounts exist. Requests are limited to 5 per address every 15
minutes and 3 emails per account an hour. Opening the link asks for a new
password; setting it signs you in and signs out every other session (API
keys keep working). An account that signs in only through single sign-on
gets no email. `PASSWORD_RESET_ENABLED=false` turns it off.

## Pictures

Each person can add a picture (JPEG, PNG or WebP, up to 5 MB) on Settings →
Account. It is cropped square and saved again without its metadata, so a
phone photo's location never reaches anyone. Without one, your initials are
shown. Pictures are shown to everyone signed in, are in backups and in
`bin/export-user.php`, and go when the person is deleted.

## Sharing a vehicle

On a vehicle, **Sharing** (the owner's page) adds someone by username with:

| Level | Can |
|---|---|
| **View** | see the vehicle, its entries, history, documents and files |
| **Log** | also add fill-ups, readings, service records, documents, expenses and tyre changes, edit or delete **their own** entries, and mark reminders done |
| **Manage** | also edit the vehicle and every entry, schedules, reminders, valuations, CSV import and export, the sale pack |
| **Owner** | also share, archive, delete and transfer (one owner per vehicle) |

and two ticks:

- **Can see costs**: amounts, prices, reports, the cost of ownership and
  valuations. Always on for Manage; off by default for View and Log.
- **Send them its reminders**: whether they are told when something on this
  vehicle is due (off by default). They can change this themselves.

The person sees the vehicle under **Shared with you** in their garage, with
your name and their level, and it joins their dashboard, History, Reports
and *Coming up*. Everyone sees every vehicle in their own units, language
and time zone; money stays in the vehicle's currency.

Once a vehicle is shared, its lists and history say **Added by …** under the
entries someone else added (a deleted user's show as *a former user*).

## Costs

Without *Can see costs*, a vehicle's amounts are hidden everywhere: its
pages, the print view, CSV exports and the API. Amount columns show nothing
and the Expenses tab lists the expenses without their amounts.

The one exception: **people always see the amounts of their own entries**.
A driver who logs a fill-up at £61.23 sees £61.23 on it, because they paid
it. They still never see anyone else's amounts, or figures made from several
entries (totals, cost per mile, price trends). The forms still ask for the
cost, since a driver pays at the pump.

Fleet figures (Reports, the Ownership report, *Coming up*, the dashboard's
spend) leave out vehicles you cannot see costs for, and say so: "Excludes 1
vehicle shared without costs".

## Reminders

A vehicle's reminders go to its **owner**, and to each person it is shared
with who ticked *Send me its reminders*. Seeing a vehicle is not asking to
be told about it. Each person is sent each reminder once when it becomes due
and once if it becomes overdue, in their own language, units and time zone,
through their own channels. A shared vehicle is always judged by its
owner's lead times, so everyone agrees on what is due.

Channels are per person (Settings → Reminders):

- **Email** goes to your confirmed address (your Profile). The *Default
  recipient for admins* (Settings → Delivery) is the admins' default
  only, so a member without an address gets no email.
- **ntfy** and **Gotify**: set your own topic URL or application token.
  Without one, only admins receive through the instance's topic or token,
  so a household topic is never flooded by everyone's cars.
- The **webhook** stays instance-wide and receives everyone's notifications,
  each naming its `user`.

The monthly digest and the calendar feed are per person too, covering their
own vehicles and those they chose to be reminded about.

See [notification-channels.md](notification-channels.md).

## Leaving, transferring and deleting

- **Leave**: on a vehicle shared with you, *Sharing* → *Leave this vehicle*.
  The entries you added stay with it.
- **Transfer**: the owner's *Sharing* → *Transfer…* gives the vehicle to
  another user, who becomes its owner. Every entry, schedule, reminder and
  file stays with it, each still naming who added it. You keep Manage access
  unless you untick *Keep Manage access for me*.
- **Deleting a user** (Settings → Users → Delete) is refused while they own
  vehicles. The delete page lists those vehicles, and an admin can transfer
  each to someone else there (so an account nobody can sign in to any more
  can still be removed). Deleting then removes their account, shares, API
  keys, calendar feed and settings. Entries they added to other people's
  vehicles stay, shown as added by *a former user*.
- Deleting a vehicle (its owner only) removes its shares with it.

## Upgrading, rolling back and moving someone out

- **Upgrading to 2.0.0** changes nothing you can see: your account becomes
  an admin, everything you logged is named as yours, and what was already
  notified is not sent again. Take a backup first, as always.
- **2.0.0 backups do not restore into 1.x** (the schema version moves).
  Backups carry the shares and who was sent which reminder; invitation links
  are not backed up.
- **Rolling back below 2.0.0 is refused while there is more than one user**,
  since 1.x would put everyone's data in front of each of them. Move the
  others out first:

  ```sh
  php bin/export-user.php sam        # a backup-format ZIP of Sam's vehicles, in BACKUP_PATH
  ```

  The ZIP holds Sam's account (as an admin), their settings and API keys,
  and every vehicle they own with all its entries, schedules, reminders and
  files. Restore it on a fresh install of the same version
  (`php bin/backup.php restore <file> --yes`) to give Sam their own. Then
  delete Sam here and roll back. (Docker: prefix the commands with
  `docker compose exec -u www-data app`.)
