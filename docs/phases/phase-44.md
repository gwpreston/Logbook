# Phase 44 — A *Next 3 months* total on *Coming up* + release

*What the next three months will probably cost, where the items already
are.*

Status: 📋 planned · file lives in `docs/phases/`

The prototype's insight "about £x due in the next 3 months" was handed
to the model in Phase 33.4 (#174). Phase 42 decided it is not an insight
(#355): §7.8 says insights never repeat *Coming up* ("amounts due"), and
the outlook is a sum of *Coming up*'s own items. So the total goes on
*Coming up* itself, the page and the widget, beside the items it adds up.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.8
(*Coming up* widget, *Insights*), §7.18 (*Coming up*, its costs), §7.21
(`ViewCosts`) and [Phase 42](phase-42.md) first.

**Prerequisites:** [Phase 42](phase-42.md) complete and green.

---

## Goals

1. A *Next 3 months* total on the *Coming up* page and widget, from the
   costs *Coming up* already shows.
2. Ask's `coming_up` tool returns the same total, so no model adds it up.
3. Release.

## Not in scope

- An insight for it (#355).
- New cost estimates: only the costs *Coming up* already shows are summed.

---

## Tasks

### 44.0 Spec first
- [ ] §7.18 and §7.8 (*Coming up* widget), §7.26 (`coming_up`), §13;
      open questions below decided; `ROADMAP.md` row.

### 44.1 The total
- [ ] The sum over the items due in the next 3 months that have a cost,
      per currency (never converted), only for vehicles the viewer may
      see costs of; on the page and the widget; translations.
- [ ] `coming_up` returns it as raw values and display strings.

### 44.2 Tests and release
- [ ] The sum to the penny; currencies kept apart; `ViewCosts`; items
      without a cost; the window's edges in the owner's time zone.
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅. Tag once
      merged.

---

## Acceptance criteria

1. *Coming up* shows what the next 3 months will probably cost, from its
   own items.
2. Ask answers "what's due in the next 3 months" with that figure.
3. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Which items count?** Reminders with a last-time cost only, or
  also renewals with a known premium and services with an estimate?
- **B. Items without a cost.** Leave them out and say how many ("3 items
  with no cost yet"), or show no total while any item lacks one?
- **C. Window.** The next 3 calendar months, or 90 days from today?
- **D. Where on the widget.** A footer line, or a figure in its header?
