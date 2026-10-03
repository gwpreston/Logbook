# Fuel stations

A fill-up's station used to be a line of text. From 2.13 it is a
**station**: a record with a name, and optionally a brand, an address, a
position, the grades sold and opening hours. Each fill-up links to one.
Logbook can then tell you:

- where you usually fill up, and how often;
- what you paid at each station, per grade, over time: the average
  (weighted by how much you bought), the cheapest and the last price;
- how far each station is from your home or work, in a straight line;
- what you paid there last time, as you log a fill-up.

All of it comes from your own fill-ups. **Nothing is fetched from outside**:
no map, no address lookup. The one exception is [Fuel prices](#fuel-prices)
(from 2.14): listed prices from an official open-data feed, **off until an
admin switches them on**.

- [Switching it on or off](#switching-it-on-or-off)
- [Upgrading: your station names become stations](#upgrading-your-station-names-become-stations)
- [Choosing a station on a fill-up](#choosing-a-station-on-a-fill-up)
- [The stations list and a station's page](#the-stations-list-and-a-stations-page)
- [Favourites](#favourites)
- [Places and distances](#places-and-distances)
- [Positions and your current location](#positions-and-your-current-location)
- [Duplicates and merging](#duplicates-and-merging)
- [Chargers](#chargers)
- [Who sees and changes what](#who-sees-and-changes-what)
- [Import, the API and Ask](#import-the-api-and-ask)
- [Backups](#backups)
- [Fuel prices](#fuel-prices)

## Switching it on or off

Stations are **on by default**. They are part of fuel: an admin can switch
them off on **Settings → Modules** (or with `FEATURES_STATIONS=false`, see
[configuration.md](configuration.md)), and with fuel off they are off too.
While off, the fill-up form has its plain *Station* text field again and
the stations pages are gone. Nothing is deleted: a fill-up keeps its link
while its text is unchanged, and switching back on brings everything back.

## Upgrading: your station names become stations

The upgrade turns the station names already on your fill-ups into
stations. Names that differ only in capitals or spacing ("Tesco Antrim",
" tesco  antrim") become **one** station, named with the spelling you used
most. Each fill-up is linked to its station; the text on it is kept as you
typed it.

Different spellings are **not** guessed to be the same place: "Tesco
Antrim" and "Tesco, Antrim Rd" become two stations. **Stations →
Duplicates** lists the likely pairs (see [Duplicates and
merging](#duplicates-and-merging)); for any others, use *Merge* on the
station's page. Once merged, typing or importing the old spelling again
links the station you kept.

Home charging is never turned into a station (see [Chargers](#chargers)).

## Choosing a station on a fill-up

The *Station* field (under *Station and notes* on the fill-up form) is a
search box. Type a few letters of the name, brand or postcode: your
favourites come first, then stations you used recently, then the rest.

- Pick one, and under the field you see **"Last time here: £1.389/L E10
  95, 12 Sep"**: what you paid there last time. It is a hint; the price is
  never filled in for you, because prices change daily.
- Nothing matches? Choose **Add "…"** and the station is created when you
  save the fill-up.
- Without JavaScript, the field is a list of your favourites and recent
  stations, plus *Other…* with a box for a new station's name.

Typing the exact name of a station that exists (ignoring capitals and
spacing) always links that station rather than making a second one.

## The stations list and a station's page

**Stations** in the menu lists every station: favourites first, then by your
last visit. Each shows its brand and postcode, how far it is from each of
your places, your visits and last visit, and the average you paid in the
last 12 months for the grade you buy most there. Search by name, brand or
postcode.

A station's page has its details, *Favourite*, *Open in maps* (when it has
a position), and **what you paid there**: per grade, your visits, spend,
the average (weighted by volume) and the cheapest, with a chart of every
price you paid. Below that is the list of your fill-ups there, newest first.

The Fuel tab of each vehicle has a **By station** card: that vehicle's top
five stations by spend over the last 12 months.

## Favourites

*Favourite* on a station's page puts it first on the fill-up form and the
stations list. Favourites are yours: other people on the install have their
own.

## Places and distances

**Settings → Places** holds your own places: *Home*, *Work* and any others.
Set one by typing its coordinates, copying a station's position, or *Use my
current location*.

Distances from your places appear on the stations list and on each
station's page. They are **straight-line** (great-circle) distances, in your
distance unit, and labelled so: the road distance needs a routing service,
which Logbook doesn't use.

**Your places are private.** Only you see them. They are never in the API,
Ask Logbook, the MCP server, print views, the sale pack or anyone else's
pages.

## Positions and your current location

A station's position is two numbers, typed with a point: latitude
(`54.715400`) and longitude (`-6.216400`). You can copy them from any map.

Standing at the station? **Use my current location** fills them in from
your phone or browser. Your browser asks for permission first; the position
goes only to your Logbook, and is kept only when you save the form. Logbook
never keeps a history of where you have been.

*Open in maps* opens the position in your phone's map app (a `geo:` link)
or on OpenStreetMap on a computer. Nothing is loaded until you press it.

## Duplicates and merging

**Stations → Duplicates** lists pairs that may be the same forecourt:

- the same brand and the same name once the brand is taken out ("Tesco
  Antrim" and "Antrim", both Tesco);
- names one letter apart ("Maxol Ballymena", "Maxol Balymena");
- positions within 150 m of each other.

**Merge** keeps one station and folds the other into it. Every fill-up and
favourite moves to the station you keep. Where both have a detail (a
postcode, a position), you choose which to keep; the grades sold are
combined. The merged station's old page and links open the one you kept,
and its spelling, typed again, links the kept station rather than making a
new one.

## Chargers

Public chargers are stations like any other, with their charging types
(AC, DC, rapid, ultra-rapid) as the grades sold, so an EV gets the same
history.

**Home charging is never a station.** A charge with the grade *Home* keeps
whatever you typed as its place, is never linked or shared, and the form
offers no station list for it.

## Who sees and changes what

- **Stations are shared** by everyone on the install: one list, no
  duplicates per person. Anyone can add a station and favourite any.
- **Only the station's creator or an admin** can edit or merge it.
  Stations made by the upgrade belong to the owner of the vehicle with the
  first fill-up there.
- **What you paid** counts only fill-ups on vehicles you can see, and
  amounts only where you may see costs (see
  [users-and-sharing.md](users-and-sharing.md)).
- **Favourites and places** are yours alone.

## Import, the API and Ask

- **CSV import** ([import.md](import.md)): the *Station* column links a
  station with that name or creates one. The preview says which ("new
  station: Tesco Antrim").
- **Receipts and drafts** ([ai.md](ai.md)): a scanned receipt's station is
  chosen when it exists, or named as a new one; Ask Logbook's draft fill-up
  card says "New station: …" when it would add one.
- **API** ([api.md](api.md)): fill-ups carry `station` (the name) and
  `station_id`. Writes take either. `GET /stations` and
  `GET /stations/{station}` give stations with what the key's user paid
  there. Never places.
- **Ask Logbook and MCP**: the `stations` tool answers "Where do I usually
  fill up?" and "What's the cheapest I've paid at Tesco?".
- **Fuel prices** add `GET /fuel-prices/near`, a `listed` field on station
  responses and the `cheapest_fuel` tool (see [The API, Ask and
  MCP](#the-api-ask-and-mcp)).

## Backups

Backups carry stations, favourites and places. `bin/export-user.php`
carries the person's places and favourites and the stations their fill-ups
use.

With fuel prices, backups also carry each station's link to the price
feed, *Keep my details*, the listed price history and price alerts. The
feed's own copy (its stations and current prices) and the provider's
credentials are **never** backed up; see [What is downloaded and
stored](#what-is-downloaded-and-stored). `bin/export-user.php` also carries
the person's price alerts and the listed price history of the linked
stations in the export.

---

## Fuel prices

From 2.14, Logbook can show the prices stations **list** today, next to
what you paid. It gets them from an official open-data feed, starting with
the UK's **Fuel Finder** scheme. With listed prices it can also:

- rank the stations near you by what the trip really costs (*Cheapest near
  me*);
- show whether driving to a cheaper station was worth it, before you go
  and after a fill-up;
- offer the listed price on the fill-up form, in one tap;
- tell you when a favourite station's price drops below one you choose;
- show the cheapest three near a place on the dashboard.

It is part of stations: with stations (or fuel) off, none of it appears or
runs. It calls a third party, so it is **off until an admin chooses a
provider**. Until then nothing is fetched, no listed price, link offer or
alert appears, and the API route answers `404`.

- [Switching on UK Fuel Finder](#switching-on-uk-fuel-finder)
- [What is downloaded and stored](#what-is-downloaded-and-stored)
- [Grades and closures](#grades-and-closures)
- [How fresh a price is](#how-fresh-a-price-is)
- [Linking a station to the feed](#linking-a-station-to-the-feed)
- [Listed prices on a station's page](#listed-prices-on-a-stations-page)
- [Cheapest near me](#cheapest-near-me)
- [Was it worth it?](#was-it-worth-it)
- [The listed price on the fill-up form](#the-listed-price-on-the-fill-up-form)
- [Price alerts](#price-alerts)
- [The dashboard widget](#the-dashboard-widget)
- [The API, Ask and MCP](#the-api-ask-and-mcp)
- [Sample prices for the demo](#sample-prices-for-the-demo)
- [Adding a provider adapter](#adding-a-provider-adapter)

### Switching on UK Fuel Finder

The feed is free, but it needs a **client ID** and **client secret**:

1. Sign in to the Fuel Finder developer portal with **GOV.UK One Login**.
2. Register an **Information Recipient** application. The portal gives
   you its client ID and secret.
3. In Logbook, as an admin, open **Settings → Fuel prices**.
4. Under *Provider*, choose **UK Fuel Finder**.
5. Type the client ID and secret, and save.

Each credential can be typed in full, or as `env:NAME` to read it from the
environment variable `NAME` (any name) each time a sync runs. A value typed
in full is stored encrypted with a key derived from `SESSION_SECRET`:
without a `SESSION_SECRET`, only `env:` values can be saved, and changing
it means typing them again. Neither is ever shown back: the page says
*Saved*, *Not set*, or which variable it reads. A provider can't be
enabled without both.

The same page has:

- **Refresh:** every 30, 60 (default) or 120 minutes.
- **E5 is sold as:** see [Grades and closures](#grades-and-closures).
- **Last sync:** when prices were last saved, how many stations and
  prices are stored, when the next sync is due, and the last run's
  summary or error. *Sync now* runs it straight away (the Jobs page's *Run
  now* for the `fuel_prices` job).

Choosing *Off* again stops the syncs and hides everything, but keeps what
was downloaded. Choosing a different provider starts with a full sync.

### What is downloaded and stored

UK Fuel Finder publishes the **whole country**, so Logbook downloads the
national list and answers every search on your own server. **Your
position, your places and your searches are never sent.** What goes to
`www.fuel-finder.service.gov.uk` is the client ID and secret (for an
access token that lasts the run and is never stored) and requests for the
pages of the list.

- **Stations:** name, brand, address, postcode, position, opening hours,
  amenities, the grades sold and whether it is closed. Names in capitals
  ("TESCO ANTRIM") are shown as "Tesco Antrim".
- **Prices:** per grade, with the time the station reported it.

Each run of the `fuel_prices` job fetches **only what changed** since the
last good sync (less 10 minutes, to be safe). A **full** sync of every
station and price runs on the first run, whenever no stations are stored
(after a restore, say), after the provider changes, and once a day. Only a
full sync marks stations that have left the feed as removed, and drops the
grades a station no longer lists.

The feed allows 30 requests a minute, so Logbook sends one at a time,
about 2.5 seconds apart, and waits when it is asked to slow down. Each
answer may take up to 30 seconds and be up to 16 MiB. Every page is saved
as it arrives. A run that fails part-way (bad credentials, the network, an
answer that can't be read) keeps what it saved, **never deletes current
prices**, shows as failed on the Jobs page with the reason, and the next
run starts again from the same point.

Prices arrive in pence per litre. A value under 2.0 was typed in pounds
and is multiplied by 100. One outside 50p–500p after that is skipped and
counted ("3 implausible prices skipped"). Prices are kept in pounds per
litre to three places.

**What is kept, and for how long:**

- The feed's stations and current prices are a copy, refreshed by every
  sync. They are **never backed up**: after a restore, the next run is a
  full sync.
- **Listed price history** is kept only for stations that matter to
  someone: those linked to a Logbook station that someone has used (any
  fill-up) or favourited. Each sync records the price such a station
  lists, once per time it was reported. A price that changes and changes
  back between two syncs is never seen.
- History older than `PRICE_HISTORY_DAYS` (default 1,095 days, three
  years; at least 30) is deleted by the hourly `cleanup` job. See
  [configuration.md](configuration.md#fuel-prices).

The provider's licence is shown wherever its data appears. For UK Fuel
Finder: "Contains public sector information licensed under the Open
Government Licence v3.0."

### Grades and closures

The feed's grades become Logbook's:

| Fuel Finder | Logbook |
|---|---|
| E10 | E10 95 |
| E5 | E5 97 (or E5 98 / E5 99+, see below) |
| B7 standard | B7 |
| B7 premium (super diesel) | B7 premium |
| B10 | B10 |
| HVO | XTL (HVO / XTL, paraffinic) |

The feed has **one** E5 price. Most UK super unleaded is 97 RON, so it is
E5 97 unless an admin chooses E5 98 or E5 99+ under *E5 is sold as*. The
choice applies to the whole install. A grade code the map doesn't know is
skipped and counted.

A station the feed marks **permanently closed** is treated as removed. One
marked **temporarily closed** stays, says *Temporarily closed* on its page,
and is left out of *Cheapest near me*, the widget and alerts.

### How fresh a price is

A listed price always shows when it was reported: "Listed £1.379/L at
14:20", with the date too when it wasn't today. A price reported **more
than 48 hours ago** is marked *May be out of date* and is left out of
*Cheapest near me*, the widget, the fill-up form and alerts, unless you
tick *Include older prices* on *Cheapest near me*.

### Linking a station to the feed

A Logbook station shows listed prices once it is **linked** to the feed's
record of it. The station's creator or an admin links it on the station's
page, under *Prices from UK Fuel Finder*:

- *Is this the same station?* offers the feed's stations within **150 m**
  of the station's position, the best name match first (the brand counts
  as part of the name), each with its address and distance. A station
  without a position is offered matches by postcode, then by name; add its
  position to search nearby instead.
- **Link** saves it; **Unlink** removes it, and removes every price alert
  on the station.
- Once linked, the station's address, postcode, position, opening hours
  and grades sold are kept up to date from the feed (on linking and after
  each sync). Its **name and brand are never changed**. Tick **Keep my
  details** to stop the updates and keep what you typed.
- One feed station can be linked to one Logbook station only.
- **Merging** keeps the kept station's link, or takes the other's when the
  kept one has none. Price alerts move as favourites do.

A link is stored as the feed's own id, so it survives re-syncs and
restores.

A feed station without a Logbook station can be added from *Cheapest near
me* with **Add station**: anyone can. Its name, brand, address, postcode,
position and grades are copied, and it is linked.

### Listed prices on a station's page

A linked station's page has a **Listed prices** card: per grade, the price
*Listed now* with its time, beside what *You paid on average* there in the
last 12 months (when you may see costs). *Temporarily closed* shows when
the feed says so.

The chart of what you paid gains a second series per grade, **listed**:
the station's listed price over time (each day's close, from the low, high
and close of that day's prices; the table under it lists them). The
history starts when the station is linked and used or favourited.

### Cheapest near me

**Cheapest near me** (on the stations list, and the *By station* card of a
vehicle's Fuel tab) finds the stations with a listed price near a point.
It is a plain form and works without JavaScript.

- **From:** *My current location*, one of your places, or a favourite or
  recent station with a position. *My current location* needs JavaScript:
  your browser asks for permission, and the position is used for that
  search only. It is never stored, logged or sent anywhere.
- **Vehicle:** your petrol and diesel vehicles (electric ones are left
  out); the one filled most recently first.
- **Grade:** the grades the feed lists for the vehicle's fuel; by default
  the grade it usually takes.
- **Within:** 2, 5 (default), 10 or 20, in your distance unit.
- **Order:** effective cost (default), price, or distance.

Distances are **straight-line**. Closed and removed stations, and prices
older than 48 hours (unless *Include older prices* is ticked), are left
out. Up to 50 stations are shown, with the time of the latest sync and the
provider's attribution below.

**Effective cost** is what filling up there costs once the drive there
and back is counted:

- *usual fill*: the median volume of the vehicle's last 10 full fills of
  petrol or diesel, or **40 L** ("assumed") when it has none;
- *detour*: 2 × the straight-line distance × **1.3**, a fixed road factor
  for the bends a straight line misses;
- *detour fuel*: the detour at the vehicle's economy over its full fills
  of the last 12 months (all time when there are none; a plug-in hybrid
  uses its petrol or diesel). With no economy at all, the drive is not
  counted and the page says so;
- *effective cost* = price × usual fill + detour fuel × price.

Each row shows the station (linking to its Logbook station, or *Add
station*), its distance, the listed price and time, the effective cost
("£61.98 for your usual 45 L") and, against the **nearest** station
selling the grade, what it saves or costs ("saves £0.42", "costs £0.31
more"; *Nearest* on that one).

### Was it worth it?

**Before going:** open a row's saving to see the sum against the nearest
station:

- fuel saving = (nearest's price − this price) × usual fill;
- extra distance = 2 × (this distance − nearest's), and about 1.3 times
  that by road;
- fuel for that = this station's detour fuel cost − the nearest's;
- **actual saving** = the nearest's effective cost − this one's: *worth
  the trip* above zero, *not worth the trip* otherwise.

For example, the nearest station is where you are and lists £1.399, and
one **7 mi further away** lists £1.359, **4p cheaper**. The car's usual fill is **50 L** and
it does **48 mpg** (UK):

| | |
|---|---|
| Fuel saving | 50 L × £0.04 = **£2.00** |
| Extra distance | 14 mi there and back, about 18.2 mi by road |
| Fuel for that | about 1.72 L for the 18.2 mi at £1.359 = **£2.34** (the nearest needs no driving) |
| Actual saving | £2.00 − £2.34 = **−£0.34: not worth the trip** |

**After a fill-up:** a fill-up of petrol or diesel (with a volume and a
price) at a linked station is compared with your **usual station** for
that vehicle: the one you used most in the 12 months before it (ties go
to the latest visit), when that is another linked station. Both need a
listed price for the grade **in effect at the fill-up's time**, reported
no more than 48 hours before it, from their history. Then:

- fuel saving = (the usual station's listed price − what you paid) ×
  volume;
- extra driving = the difference between the two stations' distances from
  your **Home** place (a place named *Home*), × 2 × 1.3, costed at what
  you paid and the vehicle's 12-month economy. It is negative when this
  station is nearer home.

The fill-up's page shows, for example, "Compared with your usual Tesco
Antrim (£1.400/L): saved £2.00 on fuel, about £0.50 for the extra 4 mi,
£1.50 better off". Without a Home place, a position on either station, or
an economy, it shows the fuel saving only, "before the extra driving". At
your usual station, or without both listed prices, it shows nothing.

**Shopping around:** the Fuel tab adds these up for the vehicle's last 12
months: "Shopping around: about £18.40 better off from 23 fill-ups away
from your usual station in the last 12 months." It shows only once at
least 3 fill-ups were compared, and only to people who may see costs.

All of these are worked out when shown and never stored. They are
estimates: listed prices, straight-line distances × 1.3 and your usual
economy.

### The listed price on the fill-up form

At a linked station with a fresh price for the chosen grade, the hint
under *Station* becomes "Listed £1.379/L E10 95 at 14:20 · Last time you
paid £1.389/L". **Use listed price** puts it in the price per unit (in
your volume unit), and works out the total if the volume is typed. The
price is **never filled in on its own**: a listed price can be minutes out
of date, and what you type is the record.

### Price alerts

On a **favourite** linked station's page, *Alert me below* takes a price
per unit for each grade it lists. After each sync, when the station's
fresh listed price is **below** yours, you get one notification through
your channels ("E10 95 at Tesco Antrim is £1.359, below your £1.369"),
naming the time it was listed and linking to the station.

- It is then *Sent* and sends nothing more until the price goes back to
  or above yours, which turns it on again.
- If every channel fails, it stays on and the next sync tries again. With
  no channel set up, it counts as sent, so nothing arrives: set up a
  channel in Settings → Reminders.
- Closed stations and prices older than 48 hours never send an alert.
- Up to 20 alerts each. Removing the favourite or the link removes the
  station's alerts.

### The dashboard widget

**Cheapest fuel** (listed in the dashboard's widgets only while a provider
is enabled) shows the three cheapest by effective cost within 5 miles or
5 km (your distance unit) of your first place (normally *Home*), or
another place you choose in the widget. It is for the dashboard's selected vehicle, else the
one filled most recently, at its usual grade, with the latest sync time
and the attribution. Without a place it says "Add a place to see the
cheapest fuel near it".

### The API, Ask and MCP

- **API** ([api.md](api.md#fuel-prices)): `GET /fuel-prices/near` gives
  the rows of *Cheapest near me*, and station responses gain `listed`, the
  station's listed prices.
- **Ask Logbook and MCP**: the `cheapest_fuel` tool answers "Where's the
  cheapest E10 near work?". It searches near one of your places by name, a
  station, or (from an MCP client that sends one) a position; by default
  your first place.

Both only while a provider is enabled. A position sent to either is used
for that answer only, never stored or logged.

### Sample prices for the demo

The sample data (`bin/dev-setup.sh --with-sample-data`) enables a provider
called **Sample prices (demo)**: eleven made-up stations around the demo
places in Co. Antrim and Belfast, with prices that move a penny or so each
hour. One is temporarily closed and one has prices three days old, so both
cases can be seen. Nothing is fetched from anywhere. It is registered only
outside production (`APP_ENV` other than `production`), so a real install
never offers it.

### Adding a provider adapter

For developers. A provider is one class in `src/Service/FuelPrices/`
registered in `ProviderRegistry`; everything else (the job, *Cheapest near
me*, links, alerts, the API) works from the interface.

**The interfaces.** Every provider implements `PriceProvider`:

- `code()`: a short code stored in settings, links and history. Never
  change it once released.
- `kind()`: `ProviderKind::Bulk` or `ProviderKind::Area`.
- `nameKey()`, `descriptionKey()`, `sendsKey()`: translation keys for its
  name, a description, and what it sends ("Logbook downloads the national
  price list from {host}. Your location is never sent.").
- `licence()`: a `ProviderLicence` with the licence's name, the
  attribution's translation key and a link to the licence.
- `credentials()`: the credential slots it needs, each with its label's
  translation key (`['client_id' => …, 'client_secret' => …]`), or `[]`
  for an open feed. Settings → Fuel prices shows a field per slot, stores
  them sealed or as `env:NAME`, and opens them for each run as
  `ProviderCredentials`; read them with `get('client_id')`, and never log
  them.
- `host()`, `minimumRefreshMinutes()`, `currency()` (ISO 4217).
- `gradeMap()`: the feed's grade codes to Logbook's `FuelGrade`s.
  `gradeChoices()` lists codes an admin may map differently; only UK Fuel
  Finder's `E5` is wired to Settings today.

A **bulk** provider (the whole list, searched locally) also implements
`BulkPriceProvider::sync()`. It is given the credentials, the grade map,
`$since` (null for a full sync) and a `FeedSink`, and writes each page to
the sink as it reads it: `stations()` with a list of `FeedStation`,
`prices()` with a list of `FeedPrice` (price per litre in pounds, or the
provider's currency, as a decimal string; reported time in UTC). It
returns a `FeedReport` of what it read and skipped, checks `$cancelled()`
between pages, and throws `FeedFailure` with a `FeedErrorCode` when the
feed can't be read. Saving, removals, history, linked stations and alerts
are done by the job.

An **area** provider (asked per search) implements
`AreaPriceProvider::search()`. The interface is there for later adapters,
but nothing uses one yet: *Cheapest near me* answers only from a bulk
provider, and the job reports "nothing to sync".

**Registering.** Add the class to the list in the `ProviderRegistry`
entry of `config/dependencies.php`. Add its strings (name, description,
what it sends, attribution, credential labels) to every catalogue in
`translations/`, English first.

**Testing.** Parse the feed in a class of its own, like
`Uk\FuelFinderParser`, and test it against a fixture of the feed's pages
in `tests/Fixtures/`. UK Fuel Finder's fixture
(`tests/Fixtures/fuel-finder/`) is synthetic, written to the published
schema; `bin/record-fuel-finder.php` records and trims a real download
(the first page of stations and their prices) beside it, and
`FuelFinderRecordedTest` checks every record parses. The recorder is
specific to UK Fuel Finder: a new feed needs its own. Use a recorded HTTP
client in the provider's tests, so the suite never calls out.

Check the feed's terms, licence and attribution before starting, and
record its endpoints in `spec.md` §4.
