# Phase 47 — How old is the last backup? + release

*Data that lives on your own box is only as safe as your last backup.*

Status: 📋 planned · file lives in `docs/phases/`

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
- Backup age on `/health`, which is public (open question D).
- Members. Backups are an admin's job (§5 `Backup`).

---

## Spec (proposed)

Added to §7.13 and §7.8 in 47.0:

- **Recorded:** the instance settings `backup_last` hold the time (UTC),
  the kind (`download`, `scheduled`, `cli`) and the size of the newest
  completed backup.
  - Written when the archive is complete: for a download, once the ZIP
    is built and sent; for a scheduled run, when the job succeeds; for
    `bin/backup.php create`, when it exits.
  - A **pre-restore** backup doesn't count. It is a safety copy of data
    that is about to be replaced.
  - On upgrade, the newest `logbook-scheduled-*.zip` in `BACKUP_PATH`
    seeds the record, if one exists.
- **Settings → Backup:** a status line at the top. It reads "Last backup
  43 days ago, 14 Sep 2026 (downloaded, 182 MB)", or "No backup yet".
  Next comes the schedule, with a link to Settings → Jobs. Then a note:
  "Scheduled backups are written to {BACKUP_PATH} on this server. Copy
  them somewhere else too." Next is *Warn me after* (*7*, *14*, *30*
  (default) or *90 days*, or *Never*), then *I back up another way*.
- **The banner** (admins only, dashboard top, under the update banner):
  "Your last backup was 43 days ago." or "Logbook has never been backed
  up.", with *Back up now* (a download) and *Turn on scheduled backups*.
  - It shows when the last backup (or, with none, the install's first
    user) is older than the threshold.
  - *Dismiss* hides it for 7 days, per admin.
  - It goes away with the next backup.
  - It never shows in demo mode, or with *Never* or *I back up another
    way* set. The Backup page then reads "Backups are handled outside
    Logbook (set by {admin}, {date})".
- **Digest** (open question C): one line in admins' monthly digest
  while the banner would show.
- **No migration**: settings only.

---

## Tasks

### 47.0 Spec first
- [ ] Open questions A–D decided by the owner and logged; §7.13, §7.8,
      §7.11 if C, §13; `ROADMAP.md` row.

### 47.1 Recording
- [ ] `BackupRecorder` called by the download action, the `backup` job
      and `bin/backup.php create`, never by pre-restore.
- [ ] Seeding from `BACKUP_PATH` once, on upgrade.

### 47.2 Pages
- [ ] Backup page status, note, threshold and *I back up another way*.
- [ ] The dashboard banner, *Dismiss* per admin, its actions; demo mode.
- [ ] The digest line, if C.
- [ ] Translations (every shipped locale).

### 47.3 Tests and release
- [ ] Each kind recorded; pre-restore and failed runs not; seeding.
- [ ] The threshold's edges in the admin's time zone; never backed up;
      *Never*; *I back up another way*; *Dismiss* expiring; members never
      see it; demo mode never shows it.
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

- **B. Turn scheduled backups on by default for new installs** (weekly,
  keep 7)?
  - (1) No. Keep §7.30's default and let the banner prompt.
  - (2) Yes, for new installs only.

  *Recommendation:* (2). It is cheap, and the first backup happens
  without anyone remembering. The disk use is a few copies of the data,
  and the Backup page names where they go.

- **C. Also in the monthly digest?**
  - (1) Yes, one line for admins.
  - (2) Dashboard and Backup page only.

  *Recommendation:* (1). Admins who rarely open the dashboard still read
  the digest.

- **D. Expose the backup age for monitoring?**
  - (1) No.
  - (2) An admin-only API read (`GET /api/v1/instance/backup`), so Uptime
    Kuma or Home Assistant can alert.
  - (3) On `/health`.

  *Recommendation:* (1) now, with (2) parked in §12. `/health` is
  public, and should not tell strangers how stale your backups are.
