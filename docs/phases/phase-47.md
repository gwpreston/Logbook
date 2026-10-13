# Phase 47 — How old is the last backup? + release

*Data that lives on your own box is only as safe as your last backup.*

Status: 🚧 in progress · file lives in `docs/phases/`

Logbook can back itself up on demand, on the command line, and on a
schedule (§7.13, §7.30). However, scheduled backups are **off by
default**, and nothing ever says how long it has been. An install can run
for a year, collecting service invoices and MOT certificates, with no
backup at all. If a scheduled backup job fails, admins already hear
about it (Phase 36.4). If the job is simply off, or someone downloaded a
backup once in March, nobody hears anything.

This phase does three things:

- records when the last backup was made, and how;
- shows that on Settings → Backup;
- warns admins on the dashboard once the last backup is older than a
  threshold they set, or once there has never been one.

Some owners back up another way (`pg_dump`, Proxmox snapshots, restic on
the Docker volume). They can say so, and the warning steps aside.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13
(backup), §7.30 (the `backup` job), §7.31 (the update banner is the
pattern for an admin-only dashboard banner), §7.36 (demo mode) and §7.11
(admin categories) first.

**Prerequisites:** none. Independent of Phases 45 and 46.

---

## Goals

1. Logbook records every completed backup: the time, how it was made
   (*Downloaded*, *Scheduled*, *Command line*) and its size.
2. Settings → Backup leads with the status: "Last backup 43 days ago
   (downloaded)". It also shows the schedule, and a plain note about
   where scheduled files live.
3. An admin-only dashboard banner when the last backup is older than the
   threshold (default 30 days), or when there has never been one and
   the install is older than that.
4. *I back up another way*, which turns the warning off and says so on
   the Backup page.
5. Release.

## Not in scope

- Off-site copies, cloud uploads or encryption of backups.
- Checking that a downloaded file was kept. Logbook can't know.
- Backup age on `/health`, which is public (#399; the admin-only API read is in scope).
- Members. Backups are an admin's job (§5 `Backup`).

---

## Spec

Written into spec §6, §7.8, §7.11, §7.13, §7.20 and §7.30 in 47.0
(decided 2026-10-13, #396–#402):

- **Recorded:** the global setting `backup.last` holds the time (UTC),
  the kind (`download`, `scheduled`, `cli`) and the size in bytes of the
  newest completed backup.
  - Written when the archive is complete: for a download, once the ZIP
    is built (before it is sent); for a scheduled run, when the job
    succeeds; for `bin/backup.php create`, when the file is written.
  - A **pre-restore** backup doesn't count. It is a safety copy of data
    that is about to be replaced. Nor does downloading an existing
    scheduled file (#402): it was recorded when the job wrote it.
  - **A restore** keeps the record from before it whenever that is newer
    than the restored one (#400).
  - **Seeding** (no migration): the first time the record is read and
    none is stored, the newest `logbook-scheduled-*.zip` or
    `logbook-backup-*.zip` in `BACKUP_PATH` seeds it (#401), its kind
    from the name and its time from the UTC timestamp in it. With none,
    "no backup" is stored, so the folder is read only once.
- **Settings → Backup:** a status line at the top. It reads "Last backup
  43 days ago, 14 Sep 2026 (downloaded, 182 MB)", or "No backup yet".
  Next comes the schedule, with a link to Settings → Jobs. Then a note:
  "Scheduled backups are written to {BACKUP_PATH} on this server. Copy
  them somewhere else too." Next is *Warn me after* (*7*, *14*, *30*
  (default, #396) or *90 days*, or *Never*), then *I back up another
  way*. Both are stored in the global `backup.warning`.
- **The banner** (§7.30's admin notice area, admins only, under the
  update banner): "Your last backup was 43 days ago." or "Logbook has
  never been backed up.", with *Back up now* (the download) and, while
  the schedule is off, *Turn on scheduled backups* (Settings → Jobs).
  - The age is the calendar days between the backup's date and today in
    the admin's time zone. It shows once that is **more than** the
    threshold; with no backup, it counts from the first account's
    creation.
  - *Dismiss* hides it for 7 days, per admin (`notices.dismissed`).
  - It goes away with the next backup.
  - It never shows in demo mode, or with *Never* or *I back up another
    way* set. The Backup page then reads "Backups are handled outside
    Logbook (set by {admin}, {date})".
- **New installs** (#397): first-run setup stores scheduled backups as
  weekly, keep 7. Existing installs keep what they have.
- **Digest** (#398): one line in an admin's monthly digest, after *Due
  this month*, while the banner would show for them (a dismissal doesn't
  hide it). It counts as content, so it sends a digest on its own. The
  webhook JSON gains `backup`; `WEBHOOK_URL`'s copy carries it too.
- **API** (#399): `GET /api/v1/instance/backup` (any scope) returns the
  record, the age in the key's user's time zone, the threshold, whether
  backups are handled elsewhere and whether the warning would show. A
  key whose user isn't an admin gets 403 `forbidden`. Not on `/health`.
- **No migration**: settings only.

---

## Tasks

### 47.0 Spec first
- [x] Open questions A–D decided by the owner and logged, with E–G
      found while starting; §6, §7.8, §7.11, §7.13, §7.20, §7.30, §13;
      `ROADMAP.md` row.

### 47.1 Recording
- [x] `BackupRecorder` called by the download action, the `backup` job
      and `bin/backup.php create`, never by pre-restore or a scheduled
      file's download.
- [x] A restore keeps the newer record.
- [x] Seeding from `BACKUP_PATH` once.
- [x] First-run setup turns weekly scheduled backups on.

### 47.2 Pages
- [x] Backup page status, note, threshold and *I back up another way*.
- [x] The dashboard banner, *Dismiss* per admin for 7 days, its actions;
      demo mode.
- [x] The digest line (text and webhook JSON).
- [x] `GET /api/v1/instance/backup`, `openapi.json`, `docs/api.md`.
- [x] Translations (every shipped locale).

### 47.3 Tests and release
- [ ] Each kind recorded; pre-restore, a scheduled file's download and
      failed runs not; a restore keeping the newer record; seeding.
- [ ] The threshold's edges in the admin's time zone; never backed up;
      *Never*; *I back up another way*; *Dismiss* expiring; members never
      see it; demo mode never shows it.
- [ ] The API read: admin, member (403), never backed up.
- [ ] The dashboard stays within its query budget (§8).
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; subpath).
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅; tag once
      merged.

---

## Acceptance criteria

1. An admin can see when the last backup was made, and how.
2. An install that hasn't been backed up within the threshold says so to
   its admins, and stops once it has been.
3. An owner who backs up another way can say so, and isn't nagged.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Default threshold.**
  - (1) 30 days.
  - (2) 7 days.
  - (3) 14 days.

  *Recommendation:* (1). Weekly would nag anyone without a schedule.
  *Decided 2026-10-13:* (1). (#396)

- **B. Turn scheduled backups on by default for new installs** (weekly,
  keep 7)?
  - (1) No. Keep §7.30's default and let the banner prompt.
  - (2) Yes, for new installs only.

  *Recommendation:* (2). It is cheap, and the first backup happens
  without anyone remembering. The disk use is a few copies of the data,
  and the Backup page names where they go. *Decided 2026-10-13:* (2):
  first-run setup stores weekly, keep 7; existing installs keep what
  they have. (#397)

- **C. Also in the monthly digest?**
  - (1) Yes, one line for admins.
  - (2) Dashboard and Backup page only.

  *Recommendation:* (1). Admins who rarely open the dashboard still read
  the digest. *Decided 2026-10-13:* (1). (#398)

- **D. Expose the backup age for monitoring?**
  - (1) No.
  - (2) An admin-only API read (`GET /api/v1/instance/backup`), so Uptime
    Kuma or Home Assistant can alert.
  - (3) On `/health`.

  *Recommendation:* (1) now, with (2) parked in §12. `/health` is
  public, and should not tell strangers how stale your backups are.
  *Decided 2026-10-13:* (2), built in this phase:
  `GET /api/v1/instance/backup` for a key whose user is an admin
  (`Backup`), else 403 `forbidden`. Nothing on `/health`. (#399)

- **Found while starting it (2026-10-13):**
  - **E. A restore and the record.** A restore replaces the settings
    table, so the record would go back to the archive's, and the age
    would look older than it is. *Decided:* the record from before the
    restore is kept whenever it is newer than the restored one. (#400)
  - **F. Seeding from command-line files.** `bin/backup.php create`
    with no file writes `logbook-backup-*.zip` to `BACKUP_PATH`, not
    `logbook-scheduled-*`. *Decided:* seeding takes the newest of either
    name, the kind from the name (*Scheduled* or *Command line*), the
    time from the UTC timestamp in it; `pre-restore-*` files never
    count. (#401)
  - **G. Downloading a scheduled file.** *Decided:* it doesn't update
    the record; that backup was recorded when the job wrote it. (#402)
  - Settled from the spec, not asked: the setting keys follow §6's
    dotted names (`backup.last`, `backup.warning`); *Dismiss* uses
    §7.30's `notices.dismissed` with a 7-day hold for this notice; the
    digest line also goes to `WEBHOOK_URL`'s copy (an admin's endpoint,
    and the line has no amounts); a day count is the calendar days
    between the backup's date and today in the viewer's time zone, and
    the banner shows once it is **more than** the threshold.
