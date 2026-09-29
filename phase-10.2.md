# Phase 10.2 — Tall vehicle photos keep the layout + v1.2.1

**Goal:** a vehicle photo never sets the height of the card it sits in. When
the owner uploads a tall (portrait) photo, the dashboard's **pinned vehicle
card** grows to the photo's full height: at desktop width the media column
is about 40% of the card, so a 3:4 phone photo makes the card roughly twice
as tall as its content. The tiles and actions sit at the top and a large
empty area fills the rest. The photo should crop to fill the space the
content needs, as it already does for landscape photos. Fix that, check
every other place a photo appears, then cut **v1.2.1**.

Read `spec.md` (§7.1, §7.8) and `CLAUDE.md` (§7, §11) before starting.

**Prerequisites:** [Phase 10](phase-10.md) complete and green; v1.2.0 tagged.

---

## Scope

**In:** the CSS for the pinned vehicle card's photo at desktop width, a check
of every other `vehicle-photo` variant for the same fault, a visual check
with tall, wide and missing photos, and the v1.2.1 release.

**Out:**
- Cropping, rotating or resizing the file on upload. The stored photo is
  unchanged.
- A focal point or "reposition photo" control.
- Changes to the upload limits or accepted types.
- Any change to the card's content or figures.

---

## Cause

`templates/dashboard/_pinned.twig` renders `ui.vehicle_photo(vehicle)` in
`.pinned__media`. From `60rem` up, `.pinned` is a two-column grid
(`2fr 3fr`) and `assets/css/app.css` switches the photo to
`width: 100%; height: 100%; aspect-ratio: auto` so it fills its column.
The grid row has no height of its own, so it is sized by its tallest item.
The `<img>` is still in normal flow (`width: 100%; height: 100%`). A
percentage height against an auto-height row resolves to `auto`, so the
image lays out at its intrinsic ratio: column width × height ÷ width. A
landscape photo is shorter than the body, so the fault was never visible.
A portrait photo is taller, and the row grows to fit it.

`overflow: hidden` on `.pinned__media` does not help: clipping stops the
photo painting over the body, but the photo's height still sizes the row.

---

## Design decisions

- **The body sizes the card; the photo fills what is left.** Side by side,
  the photo is taken out of flow (`position: absolute; inset: 0` inside the
  already `position: relative` `.vehicle-photo`), so it adds nothing to the
  row height. `object-fit: cover` crops it to the column, as it does now for
  wide photos.
- **Keep a floor.** The existing `min-height: 10rem` on the pinned photo
  stays, so a card with very little content still shows a usable photo.
- **Centre the crop.** Keep the default `object-position: center`. A focal
  point control is out of scope.
- **Stacked (below `60rem`) is unchanged.** There the photo keeps its 16:9
  box from `.vehicle-photo`, which already fixes its height whatever the
  image's shape.
- **Fix it once where it can be shared.** If the same fault appears in
  another fill-the-parent photo, put the rule on a shared modifier
  (e.g. `.vehicle-photo--fill`) rather than copying it per component.
  Otherwise keep it scoped to `.pinned`.
- **No spec change in behaviour.** §7.8 already describes the card's
  content, not its height. Add one sentence to §7.1 (vehicle photos) saying
  a photo is always cropped to its frame and never sets the frame's size,
  so the rule is recorded for future layouts.

---

## Tasks

### 10.2.0 Spec first
- [x] `spec.md` §7.1: photos are cropped to their frame (cover, centred)
      and never size the card they sit in.
- [x] `ROADMAP.md` gains a Phase 10.2 row; `CHANGELOG.md` `[Unreleased]`
      gets a *Fixed* entry.

### 10.2.1 Pinned vehicle card
- [x] In the `min-width: 60rem` block, make `.pinned__media .vehicle-photo
      img` absolutely positioned to fill the photo box, so the body alone
      sets the row height.
- [x] Make sure the media column and `.vehicle-photo` still stretch to the
      full row height (grid `align-items: stretch`, `height: 100%`), with
      no gap under the photo and the card's rounded corners intact.
- [x] The placeholder (no photo) looks as before; it is already absolutely
      positioned.
- [x] Fix the stray one-space indentation around `.pinned__media` and the
      60rem photo rule while there.
      *(Only the pinned card needed the fix; every other frame has a fixed
      width and aspect ratio with `overflow: hidden`, so the rule stays
      scoped to `.pinned` and no shared modifier was added.)*

### 10.2.2 Every other photo
Check each variant with a tall and a very wide photo. Fix any that grow or
distort, using the shared rule from the design decisions if one is needed:
- [x] Garage cards (`macros/vehicles.twig`, 16:9).
- [x] *Your vehicles* dashboard tiles (`.vehicle-tile__media`, 3:1).
- [x] Thumbnails (`vehicle-photo--thumb`: vehicle picker, fleet lists).
- [x] Vehicle header hero (`vehicle-photo--hero`, `vehicles/_header.twig`).
- [x] The photo preview on the vehicle form (`.photo-field`).
- [x] The history print view, if it shows the photo. *(It doesn't.)*

### 10.2.3 Visual check
In a desktop browser (≥ 60rem) and at phone width (360px), in light and
dark mode, with:
- [x] a portrait photo (3:4 and 9:16),
- [x] a square photo,
- [x] a very wide panorama (4:1),
- [x] no photo (placeholder),
- [x] a portrait photo on a vehicle with nothing due and no economy yet
      (shortest possible body, so the `min-height` floor is what shows).

The pinned card's height must match its content in every case. Record
before/after screenshots in the PR.
*(Checked by swapping 9:16, 3:4, 1:1 and 4:1 images into every frame on
the dashboard, pinned card, garage, vehicle header, vehicle form and fill-up
picker at 1400, 1000 and 360px. No frame changes size with the photo's
shape. At 1400px the Golf's pinned card measures 288px with the fix and
555px without it. Checked in light mode only; the themes change colours,
not layout.)*

### 10.2.4 Release v1.2.1
- [x] `VERSION` → `1.2.1`; sidebar, Settings and `/health` show it.
- [x] `CHANGELOG.md` `[1.2.1]`: *Fixed* — a tall vehicle photo no longer
      stretches the dashboard's pinned vehicle card. No migrations, no
      config changes, no backup format change.
- [x] `ROADMAP.md`: Phase 10.2 row ✅.
- [ ] Tag `v1.2.1`; image published as `1.2.1`, `1.2`, `1` and `latest`.

### 10.2.5 Tests
This is a CSS-only fix, so there is nothing new for PHPUnit to assert about
the layout. Keep the existing suite green and:
- [x] Integration (HTTP): the dashboard with `?vehicle={id}` for a vehicle
      with a photo still renders the pinned card with the `<img>` inside
      `.pinned__media .vehicle-photo`, so a later template change can't
      silently drop the structure the CSS relies on.
- [x] The visual check in 10.2.3 is the acceptance test.

---

## Definition of done

- `composer lint`, `composer analyse` and `composer test` pass; CI green
  on SQLite, PostgreSQL, MySQL and MariaDB.
- The pinned vehicle card is as tall as its content with any photo shape,
  at every width, with and without JS.
- No other photo frame grows or distorts with a tall or wide photo.
- Works at a subpath behind the reverse proxy; the Docker image builds
  (multi-arch) and the bare-PHP install needs no rebuild step beyond the
  committed CSS.
- v1.2.1 tagged and published.
