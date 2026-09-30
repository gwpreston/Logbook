# Phase 21.1 — Tyre modals, drag-and-drop files, digest on by default, sale pack cover

*Small things that make everyday use smoother.*

Status: 🚧 in progress · ships with Phase 21.2 as **v2.1.0**

Four owner requests, and one small item from the Phase 20 review (§5):

1. Editing a tyre opens in a modal on desktop, like every other entry
   form. Today it opens a new page.
2. The monthly digest is **on** by default.
3. Files can be dragged onto a form, not only chosen with the file browser.
4. The sale pack can include the vehicle photo, on a cover page before the
   summary. It is off by default. This resolves Phase 17.1's open question.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §5, §7.11,
§7.12, §7.17 and §7.19 first. Check `docs/phases/open-questions.md` for
anything touching these areas (CLAUDE.md §12).

---

## 1. Tyre forms in a modal

### Why it happens

§5's modal forms list names fill-ups, readings, service records, service
intervals, documents, expenses and the *Log entry* chooser. The tyre
*change* forms (*Fit tyres*, *Swap set* and the others) got modals in Phase
11.1, but §7.17 says *Editing a tyre* is "its own page". Its template has no
`modal_body` block, and its links carry no `data-modal`.

### Spec change (§7.17 and §5)

- **Editing a tyre** opens as a desktop modal (§5), as do *Edit change*,
  *Edit set*, and the delete confirmations for a tyre, a change and a set.
  Each is still its own page with its own URL, so it works without JS, on
  narrow screens, and by deep link. Links from the Tyres tab, History and
  *Recent activity* carry `data-modal` and, from History, `return`
  (§5).

Add "tyres, tyre changes and tyre sets" to §5's list.

### Tasks
- [x] Add `modal_body` and `heading` blocks to the tyre edit, change edit,
      set edit and the three delete-confirmation templates.
- [x] Add `data-modal` to their links (Tyres tab cards and lists, History
      rows, *Recent activity*); `return` from History.
- [x] Their Actions keep working unchanged under `ModalMiddleware`: 422
      re-renders inside the dialog, redirect → 204 with
      `X-Logbook-Location`.
- [x] **Audit:** list every form template without `modal_body` (settings
      pages excepted). Add each missing entry form here, or record why it
      is a page (for example CSV import's multi-step flow).
- [x] Tests: each route answers `X-Logbook-Modal: 1` with only the form;
      a validation error in the modal returns 422 with the form; a save
      returns 204 with the location; without the header, the full page
      renders as before. The History `return` round-trip works.

The request named *editing a tyre* only. The change and set forms are
included for consistency. Say so if you'd rather keep this to the tyre
edit form.

---

## 2. Monthly digest on by default

### Spec change (§7.11)

> - **Digest** (on by default from 2.1.0): … as today.

### Existing users

Switching the default would otherwise start monthly emails for every
existing user who never touched the setting. This is drafted as: **new
users** (setup and invitations) get it on. **Existing users** keep what they
have, because the migration writes an explicit "off" for anyone without a
stored choice. See *Open questions*.

### Tasks
- [x] Change the default in the settings service and the Settings →
      Reminders and notifications → *Notifications* card.
- [x] Setup and invitation (Phase 19) create users with the digest on.
- [x] ~~Migration: store `digest = false` for every existing user with no
      stored value. Rollback leaves those rows alone: an explicit "off"
      behaves exactly like the old default, so nothing changes on the way
      back.~~ Not needed; see *Changed while building it*.

- [x] The card's hint says the digest is only sent when a channel is set up
      and something is due, as today.
- [x] Tests: a new user has it on; an upgraded user without a choice keeps
      it off; an explicit choice is never changed; a user with the digest on
      but no configured channel gets nothing and no error.

---

## 3. Drag-and-drop file inputs

### Spec change (§7.12)

- **Dropping files** (Phase 21.1): the shared attachment input is wrapped
  in a drop zone ("Drag files here or choose files"). It is progressive
  enhancement: without JS it is the plain `<input type="file" multiple>`,
  and the native input stays in the page, focusable and clickable.
  - Dropped files are **added to** the input's current selection (built
    with `DataTransfer` and assigned to `input.files`). The form, the modal
    `FormData` submit and the one parser are unchanged, so there is still
    no second upload path.
  - The zone lists the chosen files (name and size), each with a *Remove*
    button. It highlights while files are dragged over it and announces
    changes through an `aria-live` region ("3 files added"; "receipt.heic:
    not a PDF, JPEG, PNG or WebP file").
  - The client applies the same limits as today (count, `MAX_UPLOAD_MB`,
    the four types) before submitting. The server is still the authority,
    and its all-or-nothing check is unchanged.
  - A drop anywhere else on a page that has a drop zone is ignored, so the
    browser never navigates away and loses the form. Pages without a zone
    are untouched.
  - The same macro serves the vehicle form's purchase and sale inputs, the
    vehicle photo (single file: a drop replaces it), CSV import's upload
    and backup restore's upload (single file each).
  - Touch devices keep the file picker. The zone shows only "Choose
    files" where dragging is unsupported.

### Tasks
- [ ] `ui.file_drop()` macro in `templates/macros/ui.twig` wrapping the
      existing input (name, `multiple`, accepted types, hint), used by the
      shared attachment partial and the single-file inputs listed.
- [ ] `assets/js/file-drop.js`: enhancement, merge, remove, limits,
      announcements, and the page-level drop guard. No new library.
- [ ] Styles from the design tokens (light and dark), a visible focus ring,
      and a highlight that doesn't depend on colour alone (dashed border
      plus text).
- [ ] Translations (en, de).
- [ ] Tests: server behaviour unchanged (the existing upload tests pass);
      templates render the plain input inside the zone; a JS unit test (or
      a Playwright smoke test if the project gains one) covers merge,
      remove and the limit. Manual check list in the PR for Chrome, Firefox,
      Safari and the modal path.

---

## 4. Sale pack cover page with the vehicle photo

### Spec change (§7.19)

- **Options** gain *Include the vehicle photo*, **off by default**. Only
  `photo=1` turns it on. It is disabled with the hint "Add a photo on the
  vehicle's edit page" (a link) when the vehicle has none.
- **Cover page** (only with the photo on): printed first, before the
  summary, and shown the same on screen. It holds the photo (as large as
  fits the page, `object-fit: contain`, never cropped or stretched); the
  vehicle's name, and make, model and variant; the model year; the
  registration; the title "Vehicle history"; and "Prepared {date}" in the
  owner's date format. A page break follows, so the summary starts on page
  two. The summary is unchanged.
- The photo is served by the existing authenticated photo route, and only
  to users who can see the sale pack (`Manage`, Phase 19). It is never
  added to the paperwork ZIP.
- Screen-only notice with the option on: "The photo may show your
  number plate, house or street. Check it before you share the pack."

### Tasks
- [ ] Option parsing (`photo=1` only), with the disabled state when there
      is no photo.
- [ ] `templates/sale_pack/_cover.twig` and print CSS: a full-page layout,
      `break-after: page`, image sized to fit A4 and Letter with the text
      block. A tall photo shrinks and never pushes the text onto another
      page.
- [ ] Translations (en, de).
- [ ] Tests: off by default (no cover, no image request in the HTML);
      `photo=1` with a photo → the cover comes before the summary; `photo=1`
      without a photo → no cover and the option disabled; the ZIP never
      contains the photo; values other than `1` → off.
- [ ] Mark Phase 17.1's photo question *Decided* in its file and in
      `open-questions.md`.

---

## 5. Where the registration document goes

Phase 12 asked whether owners would file the V5C under *Bought* instead of
as a *Registration* document. Decided 2026-09-30 (Phase 20 review): a hint.

### Spec change (§7.1)

- The *Purchase paperwork* hint adds: "Keep the registration certificate
  (V5C) as a *Registration* document instead, so it shows with the
  vehicle's documents and reminders." German names the
  *Zulassungsbescheinigung*. The sale paperwork hint is unchanged.

### Tasks
- [ ] The purchase input's hint, in en and de.
- [ ] Test: the vehicle form shows it under *Purchase paperwork* only.

---

## Acceptance criteria

1. On desktop, editing a tyre (and a tyre change or set) opens a modal. On
   a phone or without JS it opens the page, and both save the same way.
2. A new user's digest is on. No existing user starts getting a digest they
   didn't choose.
3. Files can be dropped onto every file input and added to what was
   already chosen, with the same limits and messages. Without JS nothing
   changes.
4. With *Include the vehicle photo* ticked, the sale pack starts with a
   cover page holding the photo and vehicle details, and the summary moves
   to page two. Unticked, the pack is as before.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Digest for existing users:** keep them as they are (drafted), or
  switch it on for everyone who never made a choice, with a line in the
  upgrade notes?
  *Decided 2026-09-30: keep them as they are (drafted). Anyone who has saved the
  Notifications card already has an explicit `digest: false`, so a
  switch would have reached only some users anyway.*
- **Scope of the modal change:** tyre edit only, or every tyre form
  (drafted)?
  *Decided 2026-09-30: every tyre form (drafted).*
- **Drop zones on CSV import and restore:** wanted, or attachments and the
  vehicle form only?
  *Decided 2026-09-30: yes, the same macro on both.*

## Changed while building it

- **The digest default has no migration.** Setup and invitations store
  `digest: true` with each new account, and a missing row still reads as
  "off". Existing users are untouched by construction, rollback has
  nothing to undo, and restoring a pre-2.1 backup can't switch anyone's
  digest on (a migration that wrote "off" wouldn't have covered that).
  Tests that count messages start from a pre-2.1 owner
  (`ReminderTestCase::ownerFromBefore21()`).
- **The audit added the manual reminder form** to the modal forms (§5).
  Every other form without `modal_body` stays a page, with the reason in
  spec §5.
