---
name: Logbook
description: A self-hosted logbook for your cars and bikes
colors:
  bg: "#f3f5f8"
  surface: "#ffffff"
  surface-2: "#eef1f5"
  stripe: "#e5e9ef"
  text: "#0e1c2e"
  muted: "#5c6b7e"
  border: "#e1e6ed"
  wordmark: "#0f2e4f"
  accent: "#1667d9"
  on-accent: "#ffffff"
  green: "#087552"
  amber: "#945700"
  red: "#c02c2c"
  on-red: "#ffffff"
  c-maint: "#12946c"
  c-ins: "#6d5bd0"
  c-tax: "#b07800"
  c-other: "#788496"
  c-build: "#b5541c"
  plate-bg-gb: "#f6cf3a"
  plate-fg: "#111111"
  plate-band: "#00309a"
  avatar-default: "#4a5568"
  avatar-0: "#1f5fbf"
  avatar-1: "#0a6f74"
  avatar-2: "#4b4fd6"
  avatar-3: "#8a3fb8"
  avatar-4: "#a3461e"
  avatar-5: "#3f6b1f"
typography:
  display:
    fontFamily: "Outfit, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Outfit, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  title:
    fontFamily: "Outfit, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 600
    lineHeight: 1.2
  body:
    fontFamily: "Plus Jakarta Sans, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 700
    letterSpacing: "0.08em"
  figure:
    fontFamily: "Outfit, Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 600
  meta:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.78125rem"
    fontWeight: 400
rounded:
  2xs: "3px"
  xs: "4px"
  sm: "10px"
  icon: "11px"
  md: "12px"
  lg: "16px"
  xl: "20px"
  sheet: "22px"
  pill: "999px"
spacing:
  "1": "4px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  card: "18px"
  "5": "20px"
  "6": "24px"
  "8": "32px"
  "10": "40px"
  "14": "56px"
components:
  button:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "0 16px"
    height: "44px"
  button-hover:
    backgroundColor: "{colors.surface-2}"
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.md}"
    padding: "0 16px"
    height: "44px"
  button-ghost:
    textColor: "{colors.accent}"
    rounded: "{rounded.md}"
    padding: "0 12px"
    height: "44px"
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.xl}"
    padding: "{spacing.card}"
  input:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "48px"
  chip:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.pill}"
    padding: "0 16px"
    height: "44px"
  chip-selected:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
  pill:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.muted}"
    rounded: "{rounded.pill}"
    height: "24px"
  plate-gb:
    backgroundColor: "{colors.plate-bg-gb}"
    textColor: "{colors.plate-fg}"
    rounded: "{rounded.xs}"
    height: "24px"
---

# Design System: Logbook

This file records the design system as built. The tokens' source of truth is
`assets/css/app.css` (`:root`), drawn from the prototype in
`design-import/Logbook.dc.html`; the rules are `spec.md` §8. Where they
differ from this file, they win. Values above are the light theme's blue
accent; dark values and the other accents are in the CSS.

## Overview

**Creative North Star: "The Glovebox Ledger"**

Logbook is the tidy, trustworthy record you'd keep in the glovebox: quiet
surfaces, exact figures, and the state of each vehicle readable at a glance.
The page is cool paper grey with white cards on it, deep navy text, and a
single accent that marks what you can press and where you are. Colour
otherwise speaks only for status (green fine, amber due soon, red overdue)
and for the cost categories in charts.

It is an app people operate, often one-handed at a pump, so it is sturdy and
friendly rather than showy: big touch targets, soft corners, bold short
labels, and nothing that moves without a reason. The one piece of character
is the number plate, drawn the way it looks on the car.

**Key Characteristics:**
- Calm cool-grey canvas, white cards, navy text; one accent.
- Status by colour **and** words or icons, never colour alone.
- Flat surfaces with hairline borders; shadows only on floating layers.
- 44 px minimum targets, 48 px inputs; soft 12–20 px corners.
- Outfit for headings and figures, Plus Jakarta Sans for everything else.
- Light and dark themes and four accents, all from tokens.

## Colors

A cool, low-saturation neutral base with one blue accent and a strict
three-colour status set.

### Primary
- **Logbook Blue** (accent): links, primary buttons, selected chips, the
  current nav item, focus rings and the first chart series. The owner can
  switch it to *Teal*, *Indigo* or *Purple* (Profile → Appearance); only
  the accent tokens change.

### Neutral
- **Cool Paper** (bg): the page behind everything.
- **Card White** (surface): cards, buttons at rest, the chip at rest.
- **Mist** (surface-2): inputs, hover fills, quiet pills.
- **Hairline** (border): the 1 px edge on cards, buttons and inputs.
- **Deep Navy Ink** (text): body text and headings.
- **Slate** (muted): field labels, hints, secondary text.
- **Wordmark Navy** (wordmark): the Logbook wordmark only.

### Status
- **OK Green** (green), **Due-soon Amber** (amber), **Overdue Red** (red):
  each with a `-soft` tint for the background it sits on, at 4.5:1 or
  better. Never used for decoration.

### Chart categories
- Fuel takes the accent; **Maintenance Green** (c-maint), **Insurance
  Violet** (c-ins, rose with a violet accent), **Tax Ochre** (c-tax),
  **Other Slate** (c-other), **Build Rust** (c-build). Graphic contrast
  3:1 or better.

### Named Rules
**The Status Is Sacred Rule.** Red, amber and green mean overdue, due soon
and OK. They never change with the accent, and they are never used just to
add colour.

**The Not Colour Alone Rule.** Every state shown in colour is also shown in
words, an icon or a tick (a chosen chip has a tick as well as its fill).

## Typography

**Display Font:** Outfit (falls back to Plus Jakarta Sans, then system)
**Body Font:** Plus Jakarta Sans (with system-ui, -apple-system, Segoe UI, Roboto)
**Mono:** ui-monospace, SFMono-Regular, Menlo, Consolas

**Character:** Outfit's geometric shapes give headings, figures and the
plate a confident, engineered look; Plus Jakarta Sans keeps the UI text
warm and easy to read at 13–14 px. Both are self-hosted.

### Hierarchy
- **Display** (600, 30px, 1.2): hero figures such as a total or an economy
  figure.
- **Headline** (600, 28px, 1.2, -0.02em): page titles (h1).
- **Figure** (600, 22px, display face): stat and figure values in cards
  (`--fs-figure`), one step below the 30px hero figures.
- **Title** (600, 17–20px, 1.2): card titles (17px), and dialog titles and
  vehicle card names (20px).
- **Body** (400–500, 14px, 1.5): all UI text, alerts included; 16px in
  inputs so iOS doesn't zoom, and for long text.
- **Label** (700, 11–13px): field labels (13px, muted) and section labels
  (11px, upper-case, 0.08em tracking).
- **Meta** (400, 12.5px): field hints and errors, row details in lists.

Every text size comes from this scale, with three exceptions that belong to
a drawn object rather than to the text: the wordmark (21px, 24px in the
sidebar) is part of the logo lock-up; the plate's "UK" band (10px, 9px on a
small plate) follows the plate's proportions; avatar initials (10px, 13px,
18px) scale with their 20, 32 and 48px circles. Print styles use points.

### Line breaks and measure
- Headings and titles (h1–h3, card, empty-state, dialog, legend and widget
  titles) use `text-wrap: balance`, so a long name or a German title
  splits evenly rather than leaving one word behind.
- Paragraphs use `text-wrap: pretty`. Both are progressive: older browsers
  wrap as before.
- Ask answers, the one place people read paragraphs, stop at 70ch inside
  their 45rem column.
- A figure never parts from its unit: the unit filters (`distance`,
  `volume`, `economy` and the rest) join number and unit with a no-break
  space, and a figure a template builds itself goes through
  `keep_together`. A grid of figures (`.figures`) drops to fewer columns on
  a phone (each at least 6.5rem) rather than shrinking the type.

### Named Rules
**The Figures In Outfit Rule.** Numbers that matter (totals, economy,
mileage) and the plate use the display font; running text never does.

## Layout

Mobile-first. Below 60rem the app has a sticky 60px top bar and a 72px
bottom tab bar with a central *add* button; from 60rem a 252px sidebar
replaces both. Content is capped at 1140px. Breakpoints in use: 48rem,
60rem and 72rem. While the bars show, `html` carries scroll padding the
height of each bar plus 8px, so a focused control or an anchor target
is never hidden under one (WCAG 2.4.11); it drops to 0 with the sidebar.

Spacing runs on a 4px base (4, 8, 12, 16, 20, 24, 32, 40, 56px), with 18px
inside cards and about 14px between a card's parts. Cards sit in a
responsive grid that collapses to one column on a phone. Pages must reflow
at 320px with no sideways scroll, and work at a subpath.

## Elevation & Depth

Flat by default. Surfaces are separated by tone (white on cool grey) and a
1px hairline border, not by shadow. One soft two-part shadow exists for
layers that float above the page: dialogs and sheets, menus, combobox
lists, the sign-in card, and the badges laid over a vehicle photo.

### Shadow Vocabulary
- **Float** (`box-shadow: 0 1px 2px rgba(14,28,46,.05), 0 10px 30px rgba(14,28,46,.08)`;
  darker in the dark theme): anything that floats over other content.
- **Raised** (`--shadow-raised`, `0 1px 3px rgba(0,0,0,.12)`): only the
  selected thumb of a segmented control, lifted off its grey track.

### Named Rules
**The Flat Until It Floats Rule.** A card resting on the page has a border
and no shadow. A shadow means "this is on top of something".

## Shapes

Soft, friendly corners that grow with the size of the thing: 4px on number
plates, 10px on nav items, 12px on buttons and inputs, 16px on tiles, 20px
on cards and 22px on dialogs and bottom sheets. Icon tiles and thumbnails
in lists take 11px, and small marks (swatches, inline tags) 3px. A shape
nested inside a padded one takes the outer radius minus the padding (the
segmented control's 9px thumb in its 12px track). Chips, pills and badges
are fully rounded. Borders are always 1px hairlines in the border colour,
tinted towards a status colour when the state needs showing (a waiting
draft card in Ask), never a thick stripe down one side.

## Components

Sturdy and friendly: generous targets, bold short labels, soft corners.

### Buttons
- **Shape:** gently rounded (12px), at least 44px tall; 48px for the large
  size.
- **Default:** white with a hairline border and bold 14px navy text; hover
  fills with Mist.
- **Primary:** accent fill, white text; hover mixes 12% of the text colour
  into the accent.
- **Ghost:** no border or fill, accent text; hover takes the soft accent
  tint. **Danger** is the default button with red text.
- **Danger, solid:** the final confirm on a delete, revoke or restore page.
  Red fill with `on-red` text: white in the light theme, near-black
  (`#081020`) in the dark theme, where the red is lighter and white would
  fall to 2.8:1. The bottom-nav count badge uses the same pair.
- **Icon button:** 44px square, transparent, Mist on hover.
- **Focus:** a 2px accent outline offset 2px, on every focusable element.

### Chips
- **Style:** fully rounded, 44px tall, white with a hairline border, bold
  13px text.
- **State:** chosen (checked or `aria-pressed`) fills with the accent and
  shows a tick; a chip marking where you are (`aria-current`) fills but has
  no tick.
- **Vehicle filter row:** on a phone the chips stay on one line that
  scrolls sideways edge to edge, like the vehicle tabs: edge shadows and a
  thin scrollbar say it scrolls, and the current chip is brought into view.
  From the sidebar breakpoint (60rem) they wrap.

### Pills (status)
- 24px, fully rounded, bold 12px: quiet (Mist/Slate), valid (green on green
  tint), soon (amber on amber tint), overdue (red on red tint).

### Cards / Containers
- **Corner Style:** 20px.
- **Background:** white (surface) on the cool-grey page.
- **Shadow Strategy:** none (see Elevation).
- **Border:** 1px hairline.
- **Internal Padding:** 18px; header row with a 17px title and an optional
  bold 13px link on the right.

### Draft card (Ask)
- **Shape:** a 16px-corner tile, 12px padding, its parts spaced only by the
  card's 8px gap (paragraph margins are off inside it).
- **Fields:** a grid of label-over-value pairs, at least 8.5rem wide, so a
  fill-up's fields sit two to a row on a 360px phone.
- **States by border and status line, never by fading:**
  - *Waiting:* accent-tinted hairline; Add, Edit and Discard.
  - *Added:* green-tinted hairline, a green tick and "Added".
  - *Already logged:* nothing was added, so plain hairline, a muted info icon
    and the message. It never borrows *Added*'s green.
  - *Undone, discarded, expired:* plain hairline and a muted status line.
    No reduced opacity: it took muted text below AA.

### Inputs / Fields
- **Style:** Mist fill, hairline border, 12px corners, 48px tall, 16px
  text; the label above in bold 13px Slate, a hint below in muted 12.5px.
- **Placeholder:** full muted Slate (accent for a value Logbook worked
  out), never faded: placeholder text is text and needs 4.5:1 on the fill.
- **Focus:** 2px accent outline and an accent border.
- **Error:** red border (`aria-invalid`) and a bold red message below.

### Navigation
- **Sidebar (wide):** 42px rows with a 21px icon and bold 14px label; hover
  Mist, current page in the soft accent tint with accent text; a red count
  badge for things that need attention.
- **Bottom tab bar (narrow):** icon-over-label tabs with a raised central
  *add* button; the current tab in the accent.

### Number plate (signature)
- Drawn as on the car: UK style is yellow with black characters, a thin
  black border and a blue "UK" band on the left; other regions get a white
  plate, no band. Display font, bold, letter-spaced, upper-case, 4px
  corners, never wraps (ellipsis instead). Never changes with the accent or
  the theme. Rules in `spec.md` §8 *Registration plate*.

## Do's and Don'ts

### Do:
- **Do** use tokens (`var(--…)`) for every colour, size, radius and space;
  never a raw colour in a component.
- **Do** give every control a hit area of at least 44 × 44px on a phone,
  growing it with padding or a pseudo-element if it must look smaller.
- **Do** check both themes and all four accents; each must keep 4.5:1 text
  and 3:1 graphic contrast.
- **Do** keep core add, edit and list flows working with JavaScript off.
- **Do** respect `prefers-reduced-motion`; motion is short (about 150ms)
  and explains a change.

### Don't:
- **Don't** add a second accent or use status colours for decoration.
- **Don't** put a shadow on a resting card.
- **Don't** show state by colour alone.
- **Don't** restyle the number plate, the Logbook mark or the wordmark.
- **Don't** redesign: refine this system unless a phase file says otherwise.
