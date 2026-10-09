# MOT history

Bring a UK vehicle's official MOT record into Logbook from DVSA: every past
test with its date, result, expiry and mileage, its advisories and defects,
and whether a manufacturer recall is outstanding. The mileages become
readings, passes can become MOT documents, and advisories can become
issues to watch.

It is **off until an admin enables it**, because it calls a third party.
Then each owner chooses, per vehicle, whether to fetch: the registration
identifies their car.

- [Getting credentials from DVSA](#getting-credentials-from-dvsa)
- [Enabling it](#enabling-it)
- [What is sent](#what-is-sent)
- [Fetching a vehicle's history](#fetching-a-vehicles-history)
- [The review card](#the-review-card)
- [Mileage](#mileage)
- [Recalls](#recalls)
- [Look up when adding a vehicle](#look-up-when-adding-a-vehicle)
- [Keeping it up to date](#keeping-it-up-to-date)
- [Elsewhere in Logbook](#elsewhere-in-logbook)
- [Licence and attribution](#licence-and-attribution)
- [Trying it in development](#trying-it-in-development)
- [Troubleshooting](#troubleshooting)

## Getting credentials from DVSA

The MOT history API is free, and individuals can apply as well as
businesses. Register on
[DVSA's MOT history API page](https://documentation.history.mot.api.gov.uk/mot-history-api/register)
with your name, email and postal address. DVSA usually answers in about
5 working days with four things:

- a **client ID** and a **client secret**;
- an **API key**;
- a **token URL**, of the form
  `https://login.microsoftonline.com/<tenant>/oauth2/v2.0/token`.

Two things to know:

- **DVSA revokes a key unused for 90 days.** Logbook's daily job makes one
  call that sends no vehicle when nothing has worked for 80 days, so an
  enabled install keeps its key.
- **The client secret expires every 2 years.** DVSA emails the holder;
  type the new one into Settings when it arrives.

## Enabling it

Needs the **Documents** module (`compliance`) on. In **Settings → MOT
history** (admins):

1. Type the four credentials. Each shows *Saved* or *Not set* and is never
   shown back; it is stored encrypted with the install's `SESSION_SECRET`.
   Instead of a value you can type `env:NAME` to read it from the
   environment variable `NAME`. The token URL must be DVSA's Microsoft
   sign-in address above; anything else is refused, because it receives
   the client secret.
2. Choose **DVSA (UK)** as the provider and save.
3. Press **Test**. It signs in and makes one call that sends no vehicle,
   so both the client credentials and the API key are checked.

The page shows the last call: when, whether it worked and its error, with
no secret in it. Switching the provider *Off* stops every call; vehicles'
stored tests stay. In demo mode MOT history is blocked.

## What is sent

To DVSA: the **registration** of a vehicle whose owner fetches its history
(or, when DVSA has no record for it, its **VIN**), and nothing else. *Test*
and the keep-alive send no vehicle. *Look up* on the add-vehicle form sends
the registration typed there, when it is pressed.

## Fetching a vehicle's history

Only the vehicle's **owner** can fetch, since it sends their registration.
Anyone the vehicle is shared with can see what was fetched.

On the vehicle's **Documents** tab, or the overview's Documents card, choose
**Fetch MOT history**. The first time, confirm "Sends this vehicle's
registration (or VIN) to DVSA". After that the page has **Refresh** and
**Stop and remove**.

- When DVSA's record is for a different make (a typing slip in the
  registration), nothing is stored and the page says what DVSA has. A
  different model is only noted: owners write "3 Series" where DVSA writes
  "320D M SPORT".
- When the record comes from the VIN under another registration (a private
  plate), the page says "DVSA knows this vehicle as …".
- Refreshing never duplicates a test: tests are matched by their number.
- **Stop and remove** deletes the stored tests, their readings and the
  recall state. Issues and documents you made from them stay, as your own
  entries.

The **MOT history** page lists every test, newest first: result, expiry,
mileage (in your unit, with the unit it was tested in when different),
each defect with its type in words and an icon, and links to what you made
from it.

## The review card

After a fetch that brings something new, Logbook opens the review card
(anyone who can log entries can use it). Nothing is added on its own:

- **Passed tests** can become **MOT documents** (start = the test date,
  expiry, reference = the test number, provider "DVSA MOT"). *Add all*
  adds them oldest first, so the latest pass drives the MOT reminder. A
  pass already logged (same number, or a document starting that day) is
  not offered. Adding a pass this way closes the reminder of the MOT
  document it replaces as **done**.
- A **new car** with no tests yet: DVSA's first MOT due date is offered for
  the vehicle's *First MOT due*.
- **Defects** can become **issues** (issues module on): fails, major and
  dangerous ones as *open*, advisories, minor defects and testers' notes as
  *watching*, with a look-again date 30 days before the MOT expires.
  Dangerous and major ones are marked *Affects safety*.
- An advisory that comes back at the next MOT adds a note to the issue it
  already is, rather than a second issue.
- **Not now** puts an offer off for good; **Done** puts off everything left
  on a test.

The overview shows "New MOT result: passed 14 Feb 2026" while the newest
test still has something on the card.

## Mileage

Every test with a read odometer, passes and fails alike, becomes a mileage
reading (source *MOT*), so Logbook's usual checks apply: a reading of yours
that disagrees with an MOT is flagged in *Needs attention*, saying which is
which, and *Fix* opens your reading, never the MOT's. An MOT reading changes
only by refreshing.

## Recalls

The MOT history page states DVSA's recall status: an outstanding recall,
recalls all fixed, none found, or unavailable. An outstanding recall is a
*Now* item in *Needs attention* until a later fetch says otherwise. Check it
with the manufacturer or a dealer.

## Look up when adding a vehicle

While MOT history is on, the add-vehicle form has **Look up** beside the
registration ("Sends this registration to DVSA"). It fills only the fields
you left blank: make, model, fuel, first registration and, for a car with
no MOT yet, *First MOT due*. Nothing is stored until you save, and it
doesn't turn on MOT history for the new vehicle. It works without
JavaScript too.

## Keeping it up to date

The `mot_history` job runs daily while a provider is enabled (see
*Settings → Jobs*). It refreshes each vehicle with MOT history on, not
archived, whose MOT falls due between 14 days ahead and 60 days ago (a new
car by its first MOT due date), and not fetched in the last 7 days, so a
new MOT shows within a week without asking DVSA about every car every day.
It signs in once per run, stops when DVSA says it is busy (the rest wait
for the next day), and makes the keep-alive call when needed.

## Elsewhere in Logbook

- **History**: each test is a line under *Documents* (passed or failed,
  mileage, defects), unless it became a document, whose line carries it.
  Not in *Recent activity* or the printable service history.
- **Sale pack**: a summary of the tests (date, result, mileage), beside the
  printed link to DVSA's own MOT history checker.
- **CSV**: *Export CSV* on the MOT history page gives
  `mot-tests.csv`, one row per defect (a test with none is one row).
- **API**: `GET /api/v1/vehicles/{id}/mot-tests` ([api.md](api.md)).
- **Ask and MCP**: the `mot_history` tool reads the stored tests
  ([ai.md](ai.md), [mcp.md](mcp.md)). Neither ever fetches.
- **Backups** include the tests and their defects; the credentials are
  never in a backup.

## Licence and attribution

DVSA's MOT data is published under the
[Open Government Licence v3.0](https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/).
Logbook shows "Contains public sector information licensed under the Open
Government Licence v3.0." wherever it shows the data: the MOT history page,
the review card, History, the sale pack, the API and Ask. The CSV is plain
data; if you publish it, credit it the same way.

## Trying it in development

`bin/dev-setup.sh --with-sample-data` enables a **Sample MOT history**
provider that sends nothing and answers for the sample vehicles only, and
stores their history: passes, a fail, advisories that became issues, a new
car with only a first MOT due date and one outstanding recall. It is never
offered in production or in demo mode.

`bin/record-mot-history.php` (maintainers only; the app never runs it)
checks real credentials and records scrubbed DVSA answers for the test
fixtures. It reads `DVSA_CLIENT_ID`, `DVSA_CLIENT_SECRET`, `DVSA_API_KEY`
and `DVSA_TOKEN_URL` from the environment and takes the registrations to
record as arguments; it exits 0 when recorded, 1 when DVSA couldn't be
read and 3 on wrong usage. See `tests/Fixtures/mot-history/README.md`.

## Troubleshooting

| Message | What to do |
|---|---|
| "DVSA refused Logbook's credentials" | Check the client ID, secret and API key in Settings and press *Test*. The secret expires every 2 years. |
| "DVSA is busy; try again later" | DVSA's limits were reached. Fetch again later; the job carries on the next day. |
| "You've asked DVSA a lot in a short time" | Each person may look up or fetch 20 times in 10 minutes and 200 times a day, so the install's shared quota lasts. Wait a few minutes. |
| "No DVSA record for AB12 CDE" | Check the registration. Vehicles first registered recently, or not tested in Great Britain since 2005 or Northern Ireland since 2017, may have none. |
| "MOT history isn't available right now" (owners) | An admin should look at Settings → MOT history. |
