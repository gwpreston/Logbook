# Phase 6 — Feature toggles + Import/backup + polish

**Goal:** finish the self-host story and harden the app — module toggles UI, CSV
import, whole-dataset backup/restore, PWA, an accessibility pass, translation
completeness, and documentation.

Read `spec.md` (§7.10, §7.13, §7.14, §11) and `CLAUDE.md` (§7, §8, §11) before
starting.

**Prerequisites:** Phase 5 complete and green.

---

## Scope

**In:** feature-toggle management UI, CSV import per module (validation +
preview), backup/restore of the full dataset (DB + uploads), PWA (manifest +
service worker + offline quick-add), accessibility pass, translation-catalogue
completeness plus a second locale to prove the framework, and docs.

**Out:** everything in `spec.md` §12 (REST API, multi-user/roles, OIDC/SSO, PDF
reports, tyre-life, trip log, VIN decode, OBD-II import).

---

## Tasks

### 6.1 Feature toggles UI
- [x] Global settings to enable/disable modules; a disabled module is removed
      from nav, routes, and dashboard (gating exists — this is the management UI).

### 6.2 CSV import
- [x] Per module; column mapping, validation, and a dry-run preview.
- [x] Values interpreted per the user's units/currency; bad rows reported, not
      silently dropped.

### 6.3 Backup / restore
- [x] One-click export of the whole dataset (DB dump + uploads) as a downloadable
      archive.
- [x] Restore with explicit confirmation; document what/where to back up.

### 6.4 PWA
- [x] Web manifest + service worker; installable on a phone; offline "add
      fill-up" quick path.

### 6.5 Accessibility pass
- [x] Keyboard navigation, labelled inputs, sufficient contrast, focus states,
      `aria` where needed; audit the core flows.

### 6.6 i18n completeness
- [x] Extract any remaining strings; ship a second locale (even if partial) to
      prove the pipeline; document how to add a translation.

### 6.7 Docs
- [x] README, `.env` reference, Docker + bare-PHP deploy guides, reverse-proxy /
      subpath notes, upgrade guide + changelog, and backup guidance.

### 6.8 Tests
- [x] Import validation, backup/restore round-trip, toggle gating; accessibility
      smoke checks where feasible.
- [x] Pass on **both** MySQL and Postgres.

---

## Deliverables
A polished, fully self-hostable release: modules can be toggled, data imports and
exports, the whole dataset backs up and restores, the app installs as a PWA, core
flows are accessible, strings are translatable with a second locale shipped, and
the docs cover both deployment paths.

## Acceptance criteria
- [x] Toggling a module off removes it from nav, routes, and dashboard; on
      restores it.
- [x] CSV import validates, previews, and imports with correct units/currency;
      bad rows are reported.
- [x] Backup produces a restorable archive; restore reproduces data + uploads.
- [x] PWA installs; offline quick fuel entry works.
- [x] Core flows are keyboard-navigable and labelled.
- [x] A second locale renders; no hard-coded strings; docs complete.
- [x] Suite green on both DBs; works behind a subpath; Docker and bare-PHP paths
      both work.

## Gotchas
- Restore is destructive — require explicit confirmation and take a pre-restore
  auto-backup.
- Import must not double-create odometer readings that imported fuel/maintenance
  rows already imply.
- Service worker + base path: cache paths must respect `APP_BASE_PATH`, or
  subpath installs break offline.
