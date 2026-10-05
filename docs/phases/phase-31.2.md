# Phase 31.2 — The Fuel stations module shows its icon + v2.15.1

*Settings → Modules shows the Fuel stations icon again.*

Status: ✅ complete · released as **v2.15.1** · file lives in `docs/phases/`

**Goal:** on Settings → Modules, every module's title has an icon except
**Fuel stations**, which shows a blank space where its icon should be. It
has been missing since v2.13.0 (Phase 30.1), when the module was added.
It should show its pin icon like the others. Fix that, make sure no other
icon is missing, then cut **v2.15.1**.

Read `spec.md` (§7.10) and `CLAUDE.md` (§7, §11) before starting.

**Prerequisites:** [Phase 31](phase-31.md) released as v2.15.0.

---

## Scope

**In:** the missing icon in the bundled icon sprite, a check of every
other icon the templates and enums name, a test that keeps them in step,
and the v2.15.1 release.

**Out:** any change to the modules page, its wording or which icons are
used.

---

## Cause

Icons are drawn from one SVG sprite, `assets/vendor/icons.svg`, built by
`bin/vendor-assets.mjs` from the list of Material Symbols names in that
script (and copied to `public/assets/vendor/icons.svg` by
`bin/build-assets.php`). `Feature::icon()` returns `pin_drop` for
`Feature::Stations`, but Phase 30.1 never added `pin_drop` to the list, so
the sprite has no `#pin_drop` symbol and `<use>` draws nothing. Every other
icon name in the templates and in the enums' `icon()` methods is in the
sprite.

---

## Tasks

### 31.2.0 Spec first
- [x] `spec.md` §13 entry; `ROADMAP.md` row and section. No §7 rule
      changes: §7.10 already lists the module.

### 31.2.1 Fix
- [x] Add `pin_drop` to the icon list in `bin/vendor-assets.mjs` and
      re-run `npm run vendor`, committing the rebuilt sprite and asset
      manifest.
- [x] Check every icon named in `templates/` and in each `icon()` method
      against the sprite. *(Only `pin_drop` was missing.)*

### 31.2.2 Tests
- [x] Unit: `tests/Unit/Assets/IconSpriteTest.php` checks that every case
      of every enum with an `icon()` method names a symbol present in both
      the source and the built sprite. It fails on `Feature::Stations`
      without the fix and passes with it.

### Release
- [x] `CHANGELOG.md` **2.15.1**: *Fixed* — the Fuel stations module's
      icon; no migrations, no config changes, no backup format change.
- [x] Bump `VERSION`, rebuild assets, update the README status.
- [x] Tag `v2.15.1` once merged.

---

## Definition of done

- `composer lint`, `composer analyse` and `composer test` pass; CI green
  on SQLite, PostgreSQL, MySQL and MariaDB.
- Settings → Modules shows an icon beside every module, Fuel stations
  included.
- Works at a subpath; the Docker image builds; the bare-PHP path needs no
  extra step (the rebuilt sprite is committed).

## Open questions

None.
