# Product

<!-- impeccable:product-schema 1 -->

The product record Impeccable (and anyone designing for Logbook) works from.
`spec.md` is the source of truth for what the app does; this file says who
it is for and what must not change. Confirmed with the owner 2026-11-06.

## Platform

web

## Users

A self-hoster looking after a household's cars and bikes, usually one to ten
vehicles. Two situations matter most:

- **At the pump or the garage, on a phone:** logging a fill-up, a charge or
  a reading quickly, often one-handed, sometimes with poor signal (the PWA).
- **At a desk:** checking what's coming up (MOT, tax, insurance, services),
  what each vehicle has cost, and its full history, for example when selling.

Each person in the household has their own account; a vehicle can be shared
with others at *View*, *Log* or *Manage*, with costs shared or not.

Not for fleet operators (drivers, dispatch, cost centres) or anyone who
wants a hosted service.

## Product Purpose

One place for every record about the household's vehicles: fuel and
charging, mileage, servicing, tyres, documents and renewals, reminders,
expenses, reports and history.

Promise: *never miss a renewal or a service, and always know what each
vehicle costs.* Success is reminders that actually reach the owner and
numbers they can trust: correct units, currency, dates and totals.

## Positioning

Self-hosted first: the data stays on the owner's own server (Docker with one
volume, or an ordinary PHP 8.4 server, down to a Raspberry Pi). It covers the
whole life of a vehicle, not only fuel, and the true cost per mile.

## Operating Context

- Server-rendered pages that work with JavaScript off for the core flows
  (add, edit, list); JS only enhances (charts, drag-to-arrange dashboard).
- Installable PWA with a fast *add fill-up* path for phones.
- Often run behind a reverse proxy at a subpath.
- Light and dark themes; metric or imperial; any currency; English and
  German catalogues today, with every user-facing string translatable.
- Receipts, invoices and certificates are uploaded against entries.

## Capabilities and Constraints

- Feature list: [`docs/features.md`](docs/features.md); full behaviour:
  [`spec.md`](spec.md) §7; design rules: `spec.md` §8.
- No SPA framework and no runtime Node dependency (CLAUDE.md §13).
- A cost of 0, a partial fill and a first-ever odometer reading are valid
  and must never be rejected by the UI.

## Brand Commitments

- **Name and mark are fixed:** "Logbook" and `assets/images/logbook-mark.png`
  (with the app icons beside it) are not to be changed.
- **Voice:** plain, UK English. Short, concrete words; no jargon, class names
  or internal terms in anything a user reads.
- **Visual source:** the design prototype in `design-import/` and `spec.md`
  §8 are the authority for the look. Design work refines that incumbent
  system; it does not redesign it unless a phase file says so.

## Evidence on Hand

- Screenshots of the real app, light and dark, phone and desktop:
  `docs/images/`.
- Sample data from the seeds (`bin/dev-setup.sh --with-sample-data`).
- No testimonials, user counts or press exist; don't invent any.

## Product Principles

1. Trustworthy numbers come first: a figure that might be wrong is worse
   than no figure.
2. The quick path is quick: logging a fill-up on a phone takes seconds.
3. Say it plainly: every label, message and empty state is in the owner's
   words.
4. Works everywhere it's installed: without JS, at a subpath, on modest
   hardware.

## Accessibility & Inclusion

WCAG 2.2 AA: keyboard navigation, labelled inputs, visible focus, contrast
in both themes, reflow at 320 px, and targets usable on a phone.
