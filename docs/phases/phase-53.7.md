# Phase 53.7 — API, webhook and MCP edges from the known-issues log + patch release

*Four open rows of the known-issues log closed: editing a modification's
title no longer announces its parts as changed, a modification's ETag
follows what its body shows, an MCP tool call checks only the tool it
runs, and decimal arithmetic stays exact past a PHP int.*

Status: ✅ complete · releases **v3.18.3**, a patch release after Phase
53.6's (#506) · file lives in `docs/phases/`

This is the next batch after [Phase 53.6](phase-53.6.md). Like 52.1,
52.2, 53.5 and 53.6, it takes a few small open rows, the four that 53.6
named as candidates for a later batch: K24, K25, K69 and K62, all LOW.
Three are about what the integrations see (webhooks, the REST API, MCP);
K62 is in the decimal helper behind the figures they report. K25 needed a
choice, which the owner made when the batch was planned (#507). It is
numbered 53.7 because no phase is in progress, so it is the next phase
not yet defined, after 53.6 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§2 *Finance arithmetic*),
[`spec.md`](../../spec.md) §7.20 (*Parts and modifications* in the API,
*Read one* and its `ETag`), §7.28 (*MCP server*), §7.39 (*Parts and
modifications*: webhooks, #442), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.6](phase-53.6.md) merged, and its release
tagged.

---

## Goals

1. **K24.** Editing a modification's title, notes, cost or category, with
   its date, odometer and parts lists unchanged, sends one
   `entry.updated` webhook for the modification and none for its parts.
   That holds for the parts it fitted and the parts it took off. A part
   is still announced as updated when the edit moves its fitting or
   removal (a new date or odometer), changes a removal's outcome, price
   or replacement, or fits or removes it, as #442 says.
2. **K25.** `GET /vehicles/{id}/modifications/{mod}` answers the same
   `ETag` after an edit to one of its parts' notes, price or warranty,
   and a new one when the modification, its revert, the fittings it
   made, or a part's name (which its body shows) changes (#507).
3. **K69.** An MCP `tools/call` checks whether the tool it was asked for
   is on, not every Ask tool: a `parts` call sends about as many queries
   as Ask's `parts` does, and an unknown or switched-off tool still
   answers the JSON-RPC error `Unknown tool` it does today.
4. **K62.** `Decimal::multiply()` and `Decimal::divide()` give the exact
   result rounded half away from zero when the scaled-integer product
   would overflow, through `brick/math`, instead of a float that is 1–3
   off in the last place.

## Not in scope

- Rows that wait on an open question: K1 (#484), K3 (#485), K29 (#448),
  K39, K40 (#449), K42 (#450), K43 (#451), K44 (#452), K45 (#461), K47
  and K59 (#454), K48 (#455), K54 (#456), K55 (#457), K56 (#458), K57
  (#459), K67 (#483), K71 (#487), K72 (#488), K87 and K93 (#500), K88
  (#501).
- K94 (a hung `tesseract` makes the Scan page and Settings → AI take
  20 s): small, and the next candidate; left for a batch after 53.3 and
  53.4, which change the OCR code around it.
- K19 (warranty reminder queries and inserts) and K89 (OCR evidence
  matching cost): performance passes, not one-place fixes. K74 may
  reopen #492. K85 needs an eval of the enlarge rule first.
- K79 (the optional PaddleOCR image's install and user): Docker
  hardening across the image and compose file; a batch of its own.
- K63 (on SQLite a table rebuild resets its id counter, so a deleted
  id is reused): a migration and upgrade matter across many tables, not
  small.
- K28 (a distance warranty's reminder reopens when the part is stored):
  it shares `ReminderGenerator::fromPart()` with K29, which waits on
  #448.
- The rows 53.5 and 53.6 left out (K13, K49, K51, K90, K95–K98, K41,
  K16, K35) for the reasons they give.

---

## Decisions (and why)

- **K24 announces a part only when its fitting changed.**
  `PartService::applyRecord()` counts every fitting and removal the
  record made as touched, and `settle()` sends `entry.updated` for each,
  even when `moveFitted()` or `setRemoved()` writes what was already
  stored. Spec §7.39 (#442) makes "a fitting or removal" an update of
  the part, and a title is neither. A fitting is moved, or a removal
  rewritten, and the part announced, only when what it stores differs
  (date and odometer; for a removal also outcome, sold price and
  replaced-by). New fittings, new removals and cleared ones are
  unchanged, and so is the modification's own webhook. The same code
  serves maintenance records, which gain the same fix.
- **K25 hashes what the modification's body shows** (#507). The tag is
  `EntityTag::of()` over the whole `ModificationState`, which carries
  each fitted part's every column, so a part's notes change the tag
  though the body doesn't. It becomes a hash of the modification, the
  fittings it made, and each part's id and name, so the tag changes
  exactly when the body can. Spec §7.20's sentence on this `ETag` says
  so (updated when the batch was planned).
- **K69 looks the tool up by name first, then checks that one.**
  `McpToolbox::call()` builds `readTools()` (every tool's
  `isAvailable()`) to find one name, and `ToolRegistry::run()` checks it
  again. `call()` instead finds the read tool (not a `DraftTool`) by
  name and calls `isAvailable()` on that one; if it is off, it falls
  through to the write tools and throws `McpError::invalidParams('Unknown
  tool: …')` as today, not the tool-result error `run()` would give.
  `run()` may keep its own check: two checks instead of 37 fixes the
  row. `tools/list` still lists every tool on. No spec change, and no
  change to the MCP contract.
- **K62 uses `brick/math` for the overflow branch only.** CLAUDE.md §2
  names `BigDecimal` "where the scaled-int `Support\Number\Decimal`
  helper would overflow". The fast integer path stays for every value a
  real entry has; each of the three float fallbacks (`multiply()`'s
  overflow check and its `rescale()` fallback, `divide()`'s) becomes
  `BigDecimal` with `RoundingMode::HALF_UP` (away from zero), as the
  integer path rounds.
- **Release:** a patch on its own after 53.6's release (#506, decided
  2026-10-18).

---

## Tasks

### 53.7.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). *2026-10-20:* every question
      still *Needs a decision* is about a row listed under *Not in
      scope*; #506 and #507 were decided when the batch was planned, and
      spec §7.20 already carries #507. No open row besides K24, K25, K62
      and K69 is in the code this phase changes.

### 53.7.1 Integration edges

- [x] `PartService::applyRecord()` moves a fitting, or rewrites a
      removal, and counts its part as touched only when what it stores
      would change (K24). A test (`PartsTest` or `ModificationsTest`):
      a title-only edit of a modification that fitted one part and took
      another off queues one `modification` update and no `part`
      update; changing its date queues both parts; changing the
      removal's outcome queues that part. *Done:* the test is in
      `WebhooksTest`, beside #442's; it also checks that the same lists
      sent again, or the same mileage written as `37000` for
      `37000.000`, tell nothing, and that clearing a removal tells its
      part. Mileages and sale prices are compared as decimals.
      `followRevert()` moves fittings only to undo a revert, so it
      needed no change.
- [x] `ApiModifications::tag()` hashes the modification, its fittings,
      and each part's id and label instead of the whole state (K25,
      #507). `ApiPartsTest`: editing a fitted part's notes keeps the
      modification's `ETag` (an `If-Match` with the old one still
      succeeds); renaming the part changes it. *Done:* `ApiEditor`'s
      `If-Match` check, which hashed the stored state itself, now asks
      `ApiModifications::tag()` for a modification, so the read and the
      `PATCH` agree. The test also edits the part's price and warranty,
      and checks that a stale tag after a rename is a 412 and that a
      new brand (part of the name shown) changes the tag.
- [x] `McpToolbox::call()` finds the read tool by name and checks
      `isAvailable()` on that one only (K69). A test: an MCP `parts`
      call runs within a query budget close to Ask's, and a call to a
      switched-off read tool still throws the JSON-RPC `Unknown tool`
      error. *Done:* `ToolRegistry::find()` gives the tool by name; a
      write or draft is looked up by name too (`writeTool()`), so only
      its own `canDraft()` runs. `PartsToolsTest`: an MCP `parts` call
      sends 9 queries against Ask's 5 (54 before), on a read and a
      read-and-write key; a switched-off read tool, a write on a read
      key or with its module off, an Ask draft tool's name and an
      unknown name are each `Unknown tool: …`.
- [x] `Decimal::multiply()` and `Decimal::divide()` compute every
      overflow fallback with `BigDecimal`, leaving no `fromFloat()` call
      in either (K62). `DecimalTest`: `-27094.791536 ×
      30815.924647` at scale 6 gives the exact `…299549`, and a divide
      whose scaled operand overflows matches `BigDecimal`'s result.
      *Done:* the tests are in `DecimalArithmeticTest` (beside the other
      multiply and divide cases), with a tie past an int rounding away
      from zero; an operand of more than 18 digits is still refused by
      `toScaledInt()`, as before.

### 53.7.2 Finish

- [x] Known-issues rows K24, K25, K62 and K69 *Fixed*.
- [x] CHANGELOG; spec §13; ROADMAP status; `docs/api.md` if it
      describes the modification `ETag` (#507; spec §7.20 already
      says it). *Done:* `docs/api.md`'s modification read row now
      names the parts' names and says a part's other fields leave the
      tag alone.
- [ ] Merge review, then merge.

### Release

- [x] `VERSION` 3.18.3, CHANGELOG 3.18.3, README status: the patch
      after 53.6's release (#506).
- [ ] Tag `v3.18.3` once merged.

## Acceptance criteria

- Each row (K24, K25, K69, K62) has a regression test that fails
  without its fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. Any new or changed strings are in every catalogue in
  `translations/`.

## Open questions

- **#506. Release.** *Decided 2026-10-18:* released on its own as a
  patch, after Phase 53.6's release, like 52.1 (#481), 52.2 (#486),
  53.5 (#502) and 53.6 (#503). None of the rows touch the Scan code that
  53.3 and 53.4 change. Not chosen: with the next phase that releases.
- **#507. K25: what a modification's `ETag` covers.** *Decided
  2026-10-18:* what its body shows: the modification, its revert, the
  fittings it made, and each of those parts' id and name. A part's
  other fields don't change it; spec §7.20 says so. Not chosen: the
  fittings only, as the spec read before, so a part rename would change
  the body but not the tag.
