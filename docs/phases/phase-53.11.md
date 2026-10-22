# Phase 53.11 — Warranty reminders, Ask's figures and the sign-in log from the known-issues log + patch release

*Five open rows of the known-issues log closed: a warranty reminder the
owner checked stays checked when its part is stored or refitted, a
stored part's page says its distance warranty is paused, a line break
in a sign-in username can't forge a log line, and Ask's grounding check
reads an answer's numbers the way the user's language writes them.*

Status: 🚧 in progress · file lives in `docs/phases/` · releases the patch
after Phase 53.10's release, a patch release

This is the next batch after [Phase 53.10](phase-53.10.md). Like 52.1,
52.2 and 53.5–53.10, it takes a few small open rows: K28 and K29
(MEDIUM, Parts and Reminders), K45 (MEDIUM, sign-in log), K47 (MEDIUM)
and K59 (LOW), both Ask's grounding check. Each waited on an open
question until the owner decided it when the batch was planned: #448
(K28, K29), #461 (K45) and #454 (K47, K59). The owner decided #457 (tap
targets) on the same day; its rows K16, K35 and K55 are left for the
next batch (*Not in scope*). It is numbered 53.11 because no phase is in
progress: Phases 53.9 and 53.10 are planned but not started, so the
batch is the next phase not yet defined, after 53.10 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§11, §12),
[`spec.md`](../../spec.md) §7.6 (*Part warranty reminders*), §7.9
(*Failed sign-ins*), §7.26 (*Grounding check*), §7.39 (*Warranty*), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.10](phase-53.10.md) merged, and its release
tagged.

---

## Goals

### Part warranties

1. **K28.** A "Warranty ends" reminder the owner marked *Checked it*
   stays checked, with its notification state, when the part is taken
   off and kept as a spare and when it is refitted. It re-opens only
   when the warranty or the first fitting's date is edited, as spec
   §7.6 says. While the part is stored the reminder is judged by its
   date alone.
2. **K29.** A stored part's distance warranty is paused while it is off
   the vehicle, and its page says so: "In warranty until 3 Mar 2028,
   distance paused while off the vehicle", or for a distance-only
   warranty "In warranty, paused while off the vehicle". A stored part
   with only a distance limit has no reminder; one with a time limit
   reminds by date (#448). Spec §7.39 no longer says a stored spare
   always reminds.

### Sign-in log and Ask

3. **K45.** A sign-in username (or any other outside text) with a line
   break in it is logged on one line, so it can't write a line fail2ban
   would read as a failed sign-in from another address. `docs/deployment.md`
   gives a fail2ban filter that is tested against Logbook's log line
   (#461).
4. **K47.** An English-locale answer of `£1,459 per litre` or
   `45,123 litres` is marked "Logbook didn't provide this figure" when
   the tool returned 1.459 or 45.123. A raw value the model copied as it
   was (`1.459` in a German answer) still matches (#454).
5. **K59.** An answer that groups thousands with a plain space
   (`1 234,56 €`, `Cost 1 234.56`) is matched as 1234.56, as the
   no-break spaces and `'` already are (spec §7.26).

## Not in scope

- K16 (insurer page: a blank *Mark all* date, row buttons and toolbar
  at 375 px), K35 (tap targets on the Parts pages) and K55 (compact
  controls under 44 px on a phone): the owner decided #457 when this
  batch was planned (a 44 px hit area at narrow widths, the controls'
  look kept; spec §8). They are CSS across many pages, a batch of their
  own, and the next candidates.
- An in-app sign-in throttle: #461 kept fail2ban as the control.
- Showing *paused* in the API, MCP and Ask `parts` results: they give
  the warranty's end date and mileage as now; a new field is an API
  change, not this fix.
- Rows that wait on an open question: K1 (#484), K3 (#485), K39, K40
  (#449), K42 (#450), K43 (#451), K44 (#452), K48 (#455), K54 (#456),
  K56 (#458), K57 (#459), K67 (#483), K71 (#487), K72 (#488).
- K132 (a scan with a Claude model on `json_schema`): a batch of its own
  once a request is recorded (53.10). K105 and K114: need measuring
  (53.10).
- K41 and K40–K43, K49: the larger rows, as 53.5 said. K129: a
  streaming reader (53.9).
- The rows 53.5–53.10 left out (K13, K19, K51, K63, K74, K79) for the
  reasons they give.

---

## Decisions (and why)

- **K28 keeps the end mileage in the reminder's occurrence.**
  `ReminderGenerator::fromPart()` builds the occurrence from the
  warranty's `untilKm` whatever the part's status, and passes the
  fitted-only `untilKm` to `ReminderRules::manual()` and `dueKm` only.
  Storing or refitting a part doesn't change its warranty's end date or
  end mileage, so the occurrence stays the same and the owner's
  *Checked it* is kept, as spec §7.6 already says (re-open only on
  editing the warranty or the first fitting's date).
- **K29: a distance warranty counts only while the part is fitted, and
  the page says when it is paused** (#448, decided 2026-10-21).
  `PartWarranty` already measures a stored part's distance at the
  mileage it came off, so a stored part never reaches its limit; it
  gets a `distancePaused` flag (a distance limit, the part not fitted,
  the warranty active) and the `parts.warranty` macro shows the paused
  wording. The reminder is unchanged: a stored part with only a
  distance limit has none (the current code). The distance limit still
  counts from the first fitting's mileage (spec §7.39). Spec §7.39 and
  §7.6 now say so (updated when the batch was planned).
- **K45 logs outside text on one line** (#461, decided 2026-10-21). A
  processor on the logger in `config/dependencies.php`, run before
  `PsrLogMessageProcessor`, replaces CR and LF in the message and in
  every string context value with a space. Turning off the
  `LineFormatter`'s inline line breaks isn't enough: Monolog's
  `includeStacktraces()` turns them back on in debug, and stack traces
  are still written there. The sign-in actions are
  unchanged: the fix is where every log line is made, so the login
  link, OIDC and header sign-in lines (`LoginLinkAction`, `OidcSignIn`,
  `ExternalUsers`) are covered too. `docs/deployment.md` gets a fail2ban
  filter and jail, and a test reads the filter's regex and checks it
  matches a real failed sign-in line and not a forged one. No in-app
  throttle (#461 options 2 and 3 not chosen). Spec §7.9 says so.
- **K47 reads an answer's numbers in the user's locale, and the other
  way only for a raw value** (#454, decided 2026-10-21).
  `GroundingCheck::numbers()` gives the other reading only for a token
  that is plain digits with at most one `.` and no grouping (`1.459`,
  `45.123`), the form tool values take; `1,459` in English is read as
  1459 only. Tool results and the context keep both readings, so a
  German display string still supplies its value.
- **K59 adds the plain space to the group separators.** A plain space
  is a group separator in `GroundingCheck::NUMBER` and `read()` only
  between a 1–3 digit group and exact 3-digit groups (`1 234`,
  `12 345 678`). So that two figures written side by side ("3 400 km"
  meaning 3 and 400 is rare, but a list of small counts isn't) aren't
  flagged wrongly, a space-grouped number is grounded when its whole
  value matches or when each of its parts does. This makes the code do
  what spec §7.26 already says ("normalised for separators").
- **Release:** a patch on its own after 53.10's release (#545, decided
  2026-10-21).

---

## Tasks

### 53.11.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). *2026-10-22:* #448, #454, #461
      and #545 were decided when the batch was planned, and spec §7.6,
      §7.9, §7.26 and §7.39 already carry them. Every question still
      *Needs a decision* is about a row listed under *Not in scope* or
      code this phase doesn't change. No open row besides K28, K29,
      K45, K47 and K59 is in the code this phase changes.

### 53.11.1 Part warranties

- [x] `ReminderGenerator::fromPart()`: the occurrence uses the
      warranty's end mileage whatever the part's status (K28).
      `PartWarrantyReminderTest`: a time-and-distance warranty's
      reminder marked done stays done, with its notification state,
      after the part is stored and again after it is refitted; editing
      the warranty still re-opens it.
- [x] `PartWarranty::distancePaused`; the `parts.warranty` macro shows
      `part.warranty.paused_both` and `part.warranty.paused` in every
      catalogue in `translations/` (K29, #448). `PartsTest`: a stored
      part's page shows the paused wording for a time-and-distance and
      a distance-only warranty, a fitted part's page doesn't; a stored
      distance-only part with the tick has no reminder
      (`PartWarrantyReminderTest`).

### 53.11.2 Sign-in log and Ask

- [ ] A processor in `src/Support/` that puts CR and LF in the message
      and string context values on one line, added to the logger in
      `config/dependencies.php` (K45, #461). A test: a sign-in with a
      username holding `\n` and a forged
      `Failed sign-in for "x" from 203.0.113.77` line writes one log
      line, with debug on and off, and the fail2ban filter in
      `docs/deployment.md` matches the real client address only; a
      debug stack trace is still logged.
- [ ] `docs/deployment.md`: the fail2ban filter and jail, beside the
      failed sign-in paragraph (#461).
- [ ] `GroundingCheck::numbers()`: the other reading only for a raw
      value (K47, #454); the plain space as a group separator, with a
      space-grouped number grounded by its whole value or each part
      (K59). `GroundingCheckTest`: `£1,459 per litre` and `45,123
      litres` in English are flagged against 1.459 and 45.123; `1.459`
      in German still matches; `1 234,56 €` and `Cost 1 234.56` match
      1234.56; "3 400" with 3 and 400 in the results isn't flagged.
- [ ] The Ask evals (`bin/ai-eval.php`, the ai-evaluator agent): no
      answer that was grounded on master is flagged now.

### 53.11.3 Finish

- [ ] Known-issues rows K28, K29, K45, K47 and K59 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review, then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.10's
      release (#545).

## Acceptance criteria

- K28, K29, K45, K47 and K59 each have a regression test that fails
  without the fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. The new strings are in every catalogue in
  `translations/`.

## Open questions

- **#448. K29: a part's distance warranty while it is stored.**
  *Decided 2026-10-21:* (3) only distance while fitted counts; a stored
  part with a distance limit shows that it is paused while off the
  vehicle, and one with only a distance limit has no reminder. Not
  chosen: (1) paused with nothing shown, spec reworded only; (2) the
  vehicle's odometer counts whether or not the part is fitted. spec
  §7.39, §7.6.
- **#454. K47: which readings of an answer's number the grounding check
  accepts.** *Decided 2026-10-21:* (1) the user's-locale reading, plus
  the other reading only for a raw value (plain digits, dot decimal, no
  grouping). Not chosen: (2) the locale reading only; (3) both readings,
  as now. spec §7.26.
- **#457. Tap targets at narrow widths** (K16, K35, K55; not built in
  this phase). *Decided 2026-10-21:* (3) compact controls keep their
  look and get a 44 × 44 px hit area at narrow widths. Not chosen: (1)
  every control drawn at least 44 px tall; (2) fix only targets under
  WCAG 2.5.8's 24 px. spec §8.
- **#461. K45: an in-app sign-in throttle, or fail2ban.** *Decided
  2026-10-21:* (1) fail2ban stays the control: the log forgery is fixed
  and `docs/deployment.md` gives a tested filter. Not chosen: (2) an
  in-app limit per client address; (3) limits per address and per
  account. spec §7.9.
- **#545. Release.** *Decided 2026-10-21:* released on its own as a
  patch, after Phase 53.10's release, like 52.1 (#481), 52.2 (#486),
  53.5 (#502), 53.6 (#503), 53.7 (#506), 53.9 (#527) and 53.10 (#543).
  None of the rows touch code 53.9 or 53.10 change. Not chosen: with the
  next phase that releases.
