# Phase 3 — Maintenance + Compliance

**Goal:** full service history with recurring schedules that compute the next due
point, plus compliance documents you can create **and edit**, with file
attachments — the attachment handler generalised from Phase 1's photo handler.

Read `spec.md` (§6, §7.4, §7.5, §7.12) and `CLAUDE.md` (§8, §9) before starting.

**Prerequisites:** Phase 2 complete and green.

---

## Scope

**In:** maintenance entry CRUD (cost 0 valid), extensible categories, recurring
schedules with next-due computation, compliance document CRUD (create + edit),
general attachments on fuel / maintenance / compliance.

**Out:** reminder surfacing + delivery (Phase 4 consumes `next_due` and expiry
dates), reports (Phase 5).

---

## Tasks

### 3.1 Maintenance domain + migration
- [x] `MaintenanceEntry` (date, odometer_km, category, title, description,
      cost DECIMAL **0 allowed**, vendor). Saving links an `OdometerReading`
      (source `maintenance`).
- [x] `MaintenanceSchedule` (category, interval_km optional, interval_months
      optional, last_done_at/odometer, next_due computed).
- [x] Migrations apply + roll back on both DBs.

### 3.2 Next-due computation service
- [x] Compute `next_due` from last-done + interval (km and/or months), choosing
      the sooner when both are set.
- [x] Recompute when a maintenance entry closes/advances a schedule.

### 3.3 Compliance domain + migration
- [x] `ComplianceDocument` (type `insurance|pollution/PUCC|registration|
      inspection|other`, provider, number, start_date, expiry_date, cost).
- [x] **UPDATE must work** — explicit regression test guarding the known
      "can't edit compliance entry" bug.

### 3.4 Attachments (generalise the Phase 1 photo handler)
- [x] `Attachment` entity + migration (owner_type, owner_id, filename, mime,
      size, stored_path, uploaded_at).
- [x] Upload / list / delete against fuel, maintenance, compliance.
- [x] Stored outside web root; authenticated serving; mime + size validation
      (`MAX_UPLOAD_MB`). Reuse the single handler — do not fork a second path.

### 3.5 UI
- [x] Maintenance history per vehicle, categorised; add / edit / delete.
- [x] Schedule management with next-due display.
- [x] Compliance list with expiry; add / **edit** / delete; attachment upload.

### 3.6 Vehicle detail integration
- [x] Wire maintenance + compliance sections into the vehicle-detail page.

### 3.7 i18n
- [x] All new strings translatable; English catalogue updated.

### 3.8 Tests
- [x] Unit: next-due for km-only, months-only, and both; zero-cost entry.
- [x] Integration: maintenance CRUD, **compliance create AND edit**, attachment
      upload + authenticated serving + validation rejection.
- [x] Pass on **both** MySQL and Postgres.

---

## Deliverables
Categorised service history with recurring schedules that compute next-due, and
compliance documents with working create/edit and file attachments.

## Acceptance criteria
- [x] A maintenance entry with cost 0 saves.
- [x] Compliance create **and** edit both work (regression-tested).
- [x] Schedule next-due is correct for km-only, months-only, and combined.
- [x] Attachments stored outside web root, served only when authenticated, and
      validated for type/size.
- [x] Suite green on both DBs; translatable; works behind a subpath.

## Gotchas
- `next_due` feeds Phase 4 — keep it a clean, queryable field/service.
- Make category extensible (enum + `other` / free text) so new categories don't
  need a migration.
- Reuse the authenticated file handler seeded in Phase 1; one upload path only.
