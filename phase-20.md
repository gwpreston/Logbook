# Phase 20 — Phase files into `docs/phases/`, open-questions review

*A tidier repository, and nothing left undecided by accident.*

Status: 📋 planned · no app release (repository and docs only; noted in
the 2.1.0 changelog)

There are now more than twenty `phase-*.md` files in the root of the
project. This phase moves them into `docs/phases/` and fixes every link to
and from them. A test stops links breaking again.

It also adds a standing rule to `CLAUDE.md`: before building a phase,
review every earlier phase's *Open questions*, check whether the app
already answers them, and ask the owner about the ones that need a
decision. This phase runs that review once across Phases 1–19. Nothing in
this phase changes the app's behaviour: decisions that need work become
tasks in a later phase, spec first.

Do this phase **before** Phases 21.1 and 21.2: the move includes their
files, and the review may add small tasks to them.

---

## Goals

1. Every phase file lives in `docs/phases/`, and the root keeps only
   `README.md`, `CLAUDE.md`, `spec.md`, `ROADMAP.md` and `CHANGELOG.md`.
2. Every Markdown link in the repository resolves, and a test keeps it that
   way.
3. `CLAUDE.md` has a standing rule for phases and open questions.
4. A one-off review of every *Open questions* section, recorded in a
   decision log. The questions that need the owner are asked, not assumed.

## Not in scope

- Changing any phase file's content beyond its links (and a *Decided*
  note once the owner answers).
- Acting on a review finding in this phase. Findings become tasks later.
- Moving the user guides in `docs/`, or `spec.md` and `ROADMAP.md`.

---

## Part 1: Move the phase files

### Target layout

```
docs/
  phases/
    phase-1.md … phase-21.2.md
    open-questions.md       # decision log (Part 3)
  deployment.md, configuration.md, …   # unchanged
```

### Tasks
- [ ] `git mv phase-*.md docs/phases/` so history follows each file.
- [ ] Fix links **inside** the moved files with a small, reviewed script
      (committed as `bin/tools/relink-phases.php`, or run once and not
      committed). Every relative link that is not `http(s):`, `mailto:`, `#…`
      or another `phase-*.md` gets `../../` in front: `](CLAUDE.md)` →
      `](../../CLAUDE.md)`, `](spec.md)` → `](../../spec.md)`,
      `](db/seeds/DemoDataSeeder.php)` → `](../../db/seeds/…)`. Links into
      `docs/` become `](../deployment.md)`. Links between phase files stay
      as they are, since they are now siblings.
- [ ] Fix links **to** the phase files:
  - `ROADMAP.md`: `](phase-N.md)` → `](docs/phases/phase-N.md)`, in the
    table and every `→` line.
  - `spec.md`: the intro line (`the phase-*.md files (build order)` →
    `the files in docs/phases/ (build order)`) and §13's heading
    (`each becomes a docs/phases/phase-*.md`).
  - `CLAUDE.md` §3 layout: add `/docs/phases  # one file per build phase,
    plus the open-questions log`.
  - `README.md` layout block: `docs/` line becomes "deployment,
    configuration, import, notification and translation guides; build
    phases in docs/phases/".
  - `CHANGELOG.md`: any phase links (search; none known today).
- [ ] Search the whole repository (templates, tests, scripts, CI config,
      `.github/`) for `phase-` and update anything that refers to a file
      path.

### Link test
- [ ] `tests/Unit/Docs/MarkdownLinksTest.php`: finds every `*.md` outside
      `vendor/` and `node_modules/`, extracts inline links, and asserts that
      each relative target exists. Fragments are checked against the
      target's headings for Markdown targets; external URLs are not
      fetched. It fails with file, line and link.
- [ ] It runs in `composer test`, so CI catches a broken link on every
      engine run.

---

## Part 2: Standing rule in `CLAUDE.md`

Add as a new section after §11, renumbering *Do not* to §13:

> ## 12. Phases and open questions
>
> - Phase files live in `docs/phases/` (`phase-<n>.md`). `ROADMAP.md` lists
>   them; `spec.md` §13 summarises them. A new phase gets its file there
>   before any code.
> - **Before starting a phase**, read `docs/phases/open-questions.md` and the
>   *Open questions* of every earlier phase file. For each one still open:
>   1. Check whether the app already answers it (spec, code, tests). If it
>      does, record that in the log with where, and move on.
>   2. If it doesn't and the answer would change behaviour, data, UI or
>      configuration, **ask the owner** before acting. Give the options and a
>      recommendation, then wait for the decision.
>   3. Once decided, update `spec.md` first, add the work to the current or a
>      new phase, mark the question *Decided* in its phase file (with the
>      date and the decision) and in the log.
> - Do not guess an answer to an open question, and do not silently drop
>   one. A question that no longer applies is marked *Obsolete* with the
>   reason.
> - When writing a phase file, anything not yet decided goes under *Open
>   questions* rather than into the tasks.

And in §11 *Definition of done*, add: "8. The phase's open questions are
decided, or carried into `docs/phases/open-questions.md`."

---

## Part 3: One-off open-questions review (Phases 1–19)

### Tasks
- [ ] Collect every *Open questions* section (and any "TBD", "to decide" or
      "later?" notes) from Phases 1–19, with phase and question.
- [ ] For each, check the spec, code and tests, and classify it:
  - **Answered:** the app already does something definite. Record what
    and where (spec section, class or test).
  - **Needs a decision:** still open and would change the app.
  - **Obsolete:** overtaken by a later phase or no longer relevant. Record
    why.
- [ ] Write `docs/phases/open-questions.md`:

  | # | Phase | Question | Status | Decision or where answered | Date |
  |---|---|---|---|---|---|

- [ ] **Ask the owner** about every *Needs a decision* item in one message,
      grouped by area, each with options and a recommendation. Do not act
      until answered.
- [ ] Record each answer in the log and in its phase file. Where work is
      needed, update `spec.md` and add tasks to Phase 21.1 or 21.2 if they
      are small and fit, otherwise draft a new phase.

### Known items to seed the log

These are already known and resolved or scheduled, so the review starts
from them:

| Phase | Question | Status |
|---|---|---|
| 17.1 | Vehicle photo in the sale pack? | Decided: optional, off by default, on a cover page before the summary (Phase 21.1) |
| 17.1 | 31-day tolerance between purchase and first reading | Needs a decision |
| 16 | Reference grade: most used, or `default_grade`? | Check what shipped |
| 16 | Diesel price-pair rule | Check what shipped |
| 16 | Minimum months before *Economy by month* appears | Check what shipped |
| 18.2 | `API_ENABLED` default | Check what shipped |
| 18.2 | Per-vehicle API key restriction | Check what shipped |
| 19 | Admins see all vehicles? | Check what shipped |
| 19 | Log users see documents? | Check what shipped |
| 19 | Self-service password reset | Check what shipped |

---

## Acceptance criteria

1. The project root has no `phase-*.md` files, and `docs/phases/` holds
   them all with their Git history.
2. Every Markdown link in the repository resolves; `MarkdownLinksTest`
   passes and fails on a deliberately broken link.
3. `CLAUDE.md` contains the phases and open-questions rule, and the
   definition of done includes it.
4. `docs/phases/open-questions.md` lists every open question from Phases
   1–19 with a status. The owner has been asked about every *Needs a
   decision* item, and nothing was acted on without an answer.
5. No application code, template, migration or configuration changes.
