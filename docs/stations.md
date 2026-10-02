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
no map, no address lookup, no price feed.

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

## Backups

Backups carry stations, favourites and places. `bin/export-user.php`
carries the person's places and favourites and the stations their fill-ups
use.
