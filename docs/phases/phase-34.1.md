# Phase 34.1 — Registration plates

*A registration that looks like one.*

Status: 📋 planned · no release of its own (ships with Phase 34.3 as
**v3.1.0**) · file lives in `docs/phases/`

Logbook shows a vehicle's registration as plain text beside its name (the
dashboard tiles lay it over the photo's lower-left corner). Tracktor, the
app this feature list was compared with, draws it as a number-plate badge.
This phase draws it as a plate wherever it *identifies a vehicle*: UK
registrations in the UK style, everything else on a neutral plate.

It is cosmetic. It stores nothing, adds no setting, and changes no figure,
export, API response or printout.

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
>   `md` (cards, the vehicle header). The characters use the app's
>   monospace stack, bold, with letter-spacing. The plate never wraps; type
>   scales down with `clamp()` and a registration longer than the stored
>   limit is cut with an ellipsis and carries the full text in a `title`.
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
> - **No JavaScript, no image, no remote font.** On print the registration
>   stays plain text.

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
- **Plain text in print.** A service history or sale pack is a formal
  document. A yellow badge adds nothing to it and costs ink.
- **No plate font.** The UK plate typeface is not freely licensed, and
  bundling a lookalike is more maintenance than the cosmetic is worth. A
  bold monospace is close enough at badge size.

---

## Tasks

### 34.1.0 Spec first
- [ ] `spec.md` §8 *Registration plate*, the §7.1 and §7.8 sentences, §13
      entry; `ROADMAP.md` row and section.

### 34.1.1 Audit
- [ ] List every template that prints a registration (`vehicle.registration`
      and the places that build it into a line) and sort each into *badge*
      (this phase), *text* (stays) or *print/export* (stays). Record the
      list under *Audit* below.
- [ ] Open `design-import/` and note how the prototype draws a
      registration. If it draws a plate, follow its shape and placement
      with the app's tokens (never its CSS values) and record the
      differences under *Prototype notes*; anything it shows that the app
      has no data for goes to *Open questions*.

### 34.1.2 Code
- [ ] `Support\PlateStyle` enum and `PlateStyleResolver` (owner's locale
      region in, style out).
- [ ] `ui.plate()` macro and its CSS (plate tokens, `sm` and `md`,
      forced-colours rule). Tokens are added beside the existing ones at
      the top of `assets/css/app.css`; `composer build-assets` and the
      committed output.
- [ ] Replace the registration in the audited templates. Pass the owner's
      style once per vehicle, not per row, so a list of vehicles does not
      resolve locales repeatedly.

### 34.1.3 Translations
- [ ] No new visible strings. The band's "UK" is a literal on the plate,
      not translated. Check the English and German catalogues are still in
      step (the existing key tests).

### 34.1.4 Tests
- [ ] Unit: display text (case, whitespace, blank gives nothing, a
      registration with `<script>` is escaped).
- [ ] Unit: the resolver for `en_GB`, `en_US`, `de_DE`, `en`, `de`, a
      lower-case region and an unknown locale.
- [ ] Unit: contrast. The token values for each plate meet 4.5:1 (a small
      WCAG helper in the test, not a dependency).
- [ ] Integration: each audited page renders the plate; the band is
      `aria-hidden`; the neutral plate has no band; a vehicle without a
      registration renders none and the layout holds; a shared vehicle
      shows its owner's style to the other user.
- [ ] Integration: the print views, CSV exports, API and Ask output are
      byte-for-byte unchanged (existing tests stay green; add one for the
      history print header).

### 34.1.5 Checks
- [ ] `design-reviewer` agent at 375, 768 and 1280 px, light and dark, all
      four accents, 200% zoom, and forced-colours mode.

### Sample data
- [ ] `DemoDataSeeder`: confirm the demo shows a vehicle with no
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

*(Filled in by 34.1.1: the list of templates and how each was sorted.)*

## Prototype notes

*(Filled in by 34.1.1.)*

## Open questions

- **Which UK plate?** Yellow (a rear plate, drafted: it stands out on a
  photo and is the one people picture) or white (a front plate).
- **"UK" or "GB" on the band?** Drafted "UK", which is valid on current
  UK plates.
- **Another region's style** (Germany's white plate with a blue EU band
  and "D" is the obvious next one): not now, or in this phase?
- **Plate on the sale pack cover?** Drafted no (plain text, like every
  print view).
