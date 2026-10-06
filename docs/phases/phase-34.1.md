# Phase 34.1 — Registration plates

*A registration that looks like one.*

Status: ✅ complete (the design review of the signed-in pages is still
open, task 34.1.5) · no release of its own (ships with Phase 34.3 as
**v3.1.0**) · file lives in `docs/phases/`

This phase was drafted on the belief that Logbook shows a registration as
plain text. In fact the Phase 33 design import had already brought in the
prototype's plate: a plain yellow chip, the same for everyone, on the
garage cards, tiles, pinned card, vehicle header and a few other places
(found while starting; see *Audit*). Tracktor, the app this feature list
was compared with, draws a registration as a number-plate badge. This
phase turns that chip into a real plate wherever it *identifies a
vehicle*: the UK style for an owner in GB, a neutral plate for everyone
else.

It is cosmetic. It stores nothing, adds no setting, and changes no figure,
export, API response or printout, except the sale pack cover: it already
drew the yellow chip, and now draws the owner's plate (#201).

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.1
(garage cards, descriptive line, first-MOT suggestion by region), §7.8
(tiles and pinned card) and §8 (design system, tokens, accessibility,
printing), [Phase 7](phase-7.md) (the first design import) and
[Phase 33.3](phase-33.3.md) (the vehicle pages) first.

**Prerequisites:** [Phase 33.4](phase-33.4.md) released as v3.0.0.

---

## Goals

1. One Twig macro, `ui.plate()`, draws a registration as a plate in two
   sizes.
2. It is used wherever a registration identifies a vehicle: garage cards,
   the dashboard's *Your vehicles* tiles and pinned vehicle card, the
   vehicle header, and the vehicle pickers that show one today.
3. The style follows the **vehicle owner's** locale region: `GB` gets the
   UK plate, anything else (or no region) the neutral plate.
4. Plates keep their own colours in both themes and all four accents, and
   stay readable in forced-colours mode.

## Not in scope

- Plate styles for other countries (parked, spec §12).
- A plate typeface, national flags or any image. No new font is shipped
  (fonts are self-hosted and the app makes no third-party requests).
- A setting to turn plates off, or to choose a style per vehicle.
- Validating or re-spacing a registration. Registrations are shown as
  typed.
- Tables, CSV, the API, Ask, the sale pack and every print view. They keep
  the registration as text.
- Registration lookup (parked with VIN decode, spec §12).

---

## Spec additions

### §8 Registration plate (new)

> - **Macro** `ui.plate(registration, size, style)` in
>   `templates/macros/ui.twig`. A blank registration renders **nothing**:
>   a registration is optional (§6 Vehicle), so there is no placeholder
>   dash.
> - **Display text:** trimmed, upper-cased, and any run of whitespace
>   collapsed to one space. It is never re-spaced or validated, so
>   `AB12CDE` stays `AB12CDE` and a personalised plate shows as typed. The
>   stored value is untouched.
> - **Styles** (`Support\PlateStyle`, an enum):
>   - `gb`: yellow plate, black characters, thin black border, and a blue
>     band at the left carrying "UK" in white, in the manner of a UK rear
>     plate;
>   - `neutral`: white plate, black characters, black border, no band.
> - **Which style:** from the region of the **vehicle owner's** locale
>   (`GB` → `gb`; any other region, or a locale with no region such as
>   `en` or `de` → `neutral`), worked out in one place
>   (`PlateStyleResolver`), the way the first-MOT suggestion keys on the
>   owner's region (§7.1). Everyone sees the same plate for the same car,
>   whoever is looking.
> - **Sizes:** `sm` (a chip: lists, pickers, the overlay on a photo) and
>   `md` (cards, the vehicle header, the pinned card, the sale pack
>   cover). The characters use the display font, bold and letter-spaced,
>   as the prototype draws them (#202). The plate never wraps: a plate
>   wider than its box is cut off with an ellipsis, and a registration
>   over ten characters carries the full text in a `title`.
> - **Colours** are tokens (`--plate-bg-gb`, `--plate-bg-neutral`,
>   `--plate-fg`, `--plate-border`, `--plate-band`, `--plate-band-fg`) that
>   are **the same in light and dark**: a plate is an object, not a
>   surface. They do not follow the accent colour. Characters on each
>   plate, and "UK" on the band, meet 4.5:1 (WCAG AA), checked by a test
>   from the token values.
> - **Accessibility:** one element. The band is `aria-hidden` and
>   `user-select: none`, so a screen reader and copy-and-paste get the
>   registration only. Yellow carries no meaning. Under
>   `@media (forced-colors: active)` the plate keeps a visible border in a
>   system colour.
> - **No JavaScript, no image, no remote font.** In print only the sale
>   pack cover draws a plate (#201); every other print view keeps the
>   text.

### §7.1 and §7.8 (changed)

> The registration on garage cards, the *Your vehicles* tiles, the pinned
> vehicle card and the vehicle header is drawn as a plate (§8). Its place
> in each layout does not change; the tiles' plate still sits over the
> photo's lower-left corner, as a `sm` plate.

---

## Decisions (and why)

- **Owner's region, not the viewer's.** A car shared with a German user
  should not look different to them than to its UK owner. The first-MOT
  suggestion already uses the owner's region (§7.1).
- **No new setting.** The style follows data the app already has. A
  per-vehicle choice would add a field for a cosmetic.
- **Plain text in print, except the sale pack cover.** A service history
  is a formal document; a yellow badge adds nothing to it and costs ink.
  The cover already drew the prototype's plate, and the owner kept it
  (#201).
- **No plate font.** The UK plate typeface is not freely licensed, and
  bundling a lookalike is more maintenance than the cosmetic is worth.
  The plate uses the display font, bold, as the prototype and the existing
  chip do (#202).

---

## Tasks

### 34.1.0 Spec first
- [x] `spec.md` §8 *Registration plate*, the §7.1 and §7.8 sentences, §13
      entry; `ROADMAP.md` row and section.

### 34.1.1 Audit
- [x] List every template that prints a registration (`vehicle.registration`
      and the places that build it into a line) and sort each into *badge*
      (this phase), *text* (stays) or *print/export* (stays). Record the
      list under *Audit* below.
- [x] Open `design-import/` and note how the prototype draws a
      registration. If it draws a plate, follow its shape and placement
      with the app's tokens (never its CSS values) and record the
      differences under *Prototype notes*; anything it shows that the app
      has no data for goes to *Open questions*.

### 34.1.2 Code
- [x] `Support\PlateStyle` enum and `PlateStyleResolver` (owner's locale
      region in, style out).
- [x] `ui.plate()` macro and its CSS (plate tokens, `sm` and `md`,
      forced-colours rule). Tokens are added beside the existing ones at
      the top of `assets/css/app.css`; `composer build-assets` and the
      committed output.
- [x] Replace the registration in the audited templates. Pass the owner's
      style once per vehicle, not per row, so a list of vehicles does not
      resolve locales repeatedly.

### 34.1.3 Translations
- [x] No new visible strings. The band's "UK" is a literal on the plate,
      not translated. Check the English and German catalogues are still in
      step (the existing key tests).

### 34.1.4 Tests
- [x] Unit: display text (case, whitespace, blank gives nothing, a
      registration with `<script>` is escaped).
- [x] Unit: the resolver for `en_GB`, `en_US`, `de_DE`, `en`, `de`, a
      lower-case region and an unknown locale.
- [x] Unit: contrast. The token values for each plate meet 4.5:1 (a small
      WCAG helper in the test, not a dependency).
- [x] Integration: each audited page renders the plate; the band is
      `aria-hidden`; the neutral plate has no band; a vehicle without a
      registration renders none and the layout holds; a shared vehicle
      shows its owner's style to the other user.
- [x] Integration: the print views, CSV exports, API and Ask output are
      byte-for-byte unchanged (existing tests stay green; add one for the
      history print header).

### 34.1.5 Checks
- [ ] `design-reviewer` agent at 375, 768 and 1280 px, light and dark, all
      four accents, 200% zoom, and forced-colours mode. *Partly done
      2026-10-06:* the built CSS and the macro's markup were checked in a
      browser at 375 and 1280 px, light and dark, blue and purple accents:
      the plates keep their colours, are 24 px (`md`) and 22 px (`sm`)
      tall as in the prototype, a narrow box ends in an ellipsis, and
      selecting a GB plate copies "AB12 CDE" only. Still open: the real
      pages signed in, 768 px, 200% zoom and forced-colours mode (which
      the browser used could not emulate).

### Sample data
- [x] `DemoDataSeeder`: confirm the demo shows a vehicle with no
      registration and one with a personalised-looking registration, so
      both layouts can be seen. Add them if missing.

### Release
- [ ] Ships with Phase 34.3 as **v3.1.0**. Nothing to tag here.

---

## Acceptance criteria

- A UK-locale owner's vehicles show yellow UK plates on the garage, the
  dashboard and the vehicle header. A US-locale owner's show neutral
  plates.
- A vehicle with no registration shows no plate and no dash.
- Copying a plate gives only the registration.
- Prints, exports, the API and Ask are unchanged.
- No new request, font, image or JavaScript.

## Audit

Every template that prints a vehicle's registration, 2026-10-06.

**Badge** (`ui.plate()`, in the owner's style). All eight already drew the
prototype's yellow `.plate` chip:

| Template | Where | Size |
|---|---|---|
| `macros/vehicles.twig` `card` | garage cards | `md` |
| `macros/vehicles.twig` `tile` | *Your vehicles* tiles, over the photo | `sm` |
| `macros/vehicles.twig` `pick_list` | one-tap pickers (`/fuel/new`, `/log/new`) | `sm` |
| `dashboard/_pinned.twig` | pinned vehicle card | `md` |
| `vehicles/_header.twig` | vehicle header, every tab | `md` |
| `macros/ownership.twig` | *Cost of ownership* cards | `sm` |
| `scan/pick.twig` | the scan's vehicle picker | `sm` |
| `vehicles/delete.twig` | the delete page's description line | `sm` |

**Print, drawn as a badge** (by decision #201): `sale_pack/_cover.twig`,
`md`.

**Text** (stays): the page eyebrows of `import/map`, `import/upload`,
`incidents/show`, `scan/reminders`, `scan/vehicle`, `valuations/index`
and `vehicles/archive` (and its modal line); the `<select>` options in
`import_app/map` and `incidents/history`, which can hold no markup; the
rows of `incidents/history`, the trips table in `macros/trips.twig` and
`trips/claim`; the *Registration* field in `vehicles/show`; an incident's
other-party registration (not one of the owner's vehicles); and
`ask/_draft_card` (Ask output).

**Print / export** (stays text): `history/print`, `print/_header`,
`sale_pack/_running` and `sale_pack/_summary`. CSV, the API and Ask
never use a template.

## Prototype notes

`design-import/Logbook.dc.html` draws a registration as a plate in four
places: the vehicle header (beside the fuel pill), the garage card, the
dashboard tile (over the photo's lower-left corner) and the vehicle
picker. Its sale pack cover draws one too. The plate is a yellow chip
(`--plate: #F6CF3A`, `#111` text), 24 px tall (22 px over a photo), with
a 4 px radius and Outfit 700 at 13 px (12 px) with 0.06em letter-spacing,
the same for every locale. The app had already copied it in Phase 33.

What this phase changes, by its decisions: a 1 px black border, a blue
band reading "UK" on the GB plate (#198, #199), a white neutral plate for
every other region, and colours as fixed tokens. The shape, sizes, font
and placement follow the prototype. The prototype shows nothing the app
has no data for.

## Open questions

All decided by the owner on 2026-10-06, before the phase was built
([`open-questions.md`](open-questions.md) #198–#202).

- **Which UK plate?** *Decided 2026-10-06 (#198):* yellow (the rear
  plate), with a thin black border and a blue band at the left.
- **"UK" or "GB" on the band?** *Decided 2026-10-06 (#199):* "UK".
- **Another region's style** (Germany first): *Parked 2026-10-06 (#200):*
  not now; recorded in spec §12. Every region except GB gets the neutral
  plate.
- **Plate on the sale pack cover?** (found while starting: the cover
  already drew the prototype's yellow plate) *Decided 2026-10-06 (#201):*
  kept, in the owner's style. The running heads, the summary and every
  other print view keep the text.
- **Plate typeface** (found while starting: the draft says monospace,
  while the prototype and the existing chip use the display font):
  *Decided 2026-10-06 (#202):* the display font, bold and letter-spaced.
