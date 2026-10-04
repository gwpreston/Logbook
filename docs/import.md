# Importing from CSV and from Fuelio

Each vehicle tab (Mileage, Fuel, Maintenance, Documents, Expenses, and Trips
when the trips module is on) has an
**Import CSV** button next to **Export CSV**. Use it to move records in from a
spreadsheet, another app, or another Logbook.

## How it works

1. **Choose a file.** A `.csv` with a header row: comma-, semicolon- or
   tab-separated, UTF-8 (Excel's "CSV UTF-8") or Windows-1252, up to
   `MAX_UPLOAD_MB` and 5,000 rows.
2. **Match the columns.** Each field gets a drop-down of the file's columns.
   Columns named like Logbook's export (in your language or English) or with a
   common name ("Mileage", "Cost", "Notes") are matched already, and so is an
   unnamed column that holds dates. Then say how the file is written: the date
   order, the odometer unit, the volume unit (fuel) and the time zone (fuel and
   mileage). Units and zone default to what the header says ("Odometer
   (Miles)", "Date and time (Europe/London)"), else your own settings.
3. **Preview.** Every row is listed with what will happen to it — nothing has
   been saved yet. Change the matching and preview again as often as you like.
4. **Import.** The importable rows are saved together; the result lists each
   row that was not imported, with its row number and why.

## What happens to each row

| Outcome | Why |
|---|---|
| **Import** | Valid and new. |
| **Problem** | Something the form would also refuse: a missing date, a negative number, an unknown category, an amount in another currency. The reason is shown per field. Rows with problems are only skipped if you tick *Skip the rows with problems*. |
| **Already there** | The same entry is already logged for the vehicle, or appears earlier in the file. Importing the same file twice therefore changes nothing. |
| **Comes from an entry** | (Mileage only.) A reading whose source is a fill-up, service or document. Fill-ups, maintenance and documents with an odometer create their own readings, so import the fuel, maintenance and documents files instead — readings are never doubled. |

What counts as "the same entry": a fill-up with the same time and odometer; a
reading with the same time and odometer (whatever created it); maintenance on
the same date with the same category, title and cost; a document of the same
type with the same reference and dates; an expense on the same date with the
same category, amount and note.

## Values

- **Numbers:** `1234.5` always works; your own format (`1.234,5` in German)
  too. Quantities are read in the units chosen for the file and converted
  exactly, so an exported file imports back to the very same values.
- **Money** is in the vehicle's currency and never converted. A *Currency*
  column is optional; a row in another currency is refused. `0` is a valid cost.
- **Dates:** `2026-09-27`, or day-first / month-first as chosen. **Times** are
  local to the chosen time zone; a fill-up or reading with only a date is
  taken as noon.
- **Choices** (fuel, category, document type, reading source) accept
  Logbook's code (`petrol`), or the label in your language or English
  (`Petrol`, `Benzin`). A missing category means *Other*; a missing fuel means
  the vehicle's usual fuel.
- **Grade** (fuel, optional) accepts Logbook's code (`e10_95`, `b7`,
  `dc_rapid`, …: see the *Grade code* column of a fuel export), the grade's
  name (`E10 unleaded, 95 RON`, `Super E10, 95 ROZ`) or its short name
  (`E10 95`, `B7`, `Rapid`, `Home`) in your language or English, plus a few
  common shorthands (`E10`, `E5`, `HVO`). Words that could mean several
  grades — `Unleaded`, `Super`, `Diesel`, `Premium` — are not grades and the
  row is refused, as is a grade of another fuel (a `B7` on a petrol row).
  An empty cell, or no Grade column at all, means *not recorded*. The grade
  does not count towards "the same entry". A fuel export has both *Grade*
  (the name) and *Grade code*; the code column is matched first.
- **Yes/no** columns accept yes/no, true/false, 1/0, y/n, x (and your
  language's yes/no).
- A document's **Odometer** (optional; files without the column import as
  before) is the reading shown on it, such as an MOT certificate's. It adds
  a reading to the mileage log at noon on the start date, just as the form
  does, so it needs a start date.
- A fill-up row's **Unit** column (`UK gallons`, `L`, `kWh`, …) overrides the
  file's volume unit for that row. Charging is always in kWh.

## Fields per list

| List | Fields (required in bold) |
|---|---|
| Fuel | **Date and time**, **Odometer**, Fuel, Grade, Volume, Unit, Price per unit, Total, Currency, Partial, Missed previous, Station, Notes — any two of volume, price and total |
| Mileage | **Date and time**, **Odometer**, Source, Note |
| Maintenance | **Date**, Category, **Title**, Odometer, Cost, Currency, Garage, Details |
| Documents | **Type**, Title (required for *Other*), Provider, Reference, Start, Expiry, Odometer (needs a start date), Cost, Currency, Notes |
| Expenses | **Date**, Category, Amount, Currency, Note |
| Trips | **Date**, **From**, **To**, Return, Distance, Odometer start, Odometer end, Business, Purpose (required for business trips), Passengers, Notes — a distance, or both odometers |

Not imported: attachments, links between maintenance and schedules, and
archived vehicles (restore the vehicle first).

### Stations

A fill-up's *Station* (Phase 30.1, [stations.md](stations.md)) links the
station with that name, ignoring capitals and spacing, or creates one when
there is none. The preview says which on each row ("station: Tesco Antrim",
"new station: Maxol Ballymena"). Home charging keeps its text and is never
a station. With the stations module off, the text is imported as it is.

### Trips

Trips (Phase 22, [trips.md](trips.md)) import the columns their export
writes. The **distance is the whole trip**, even on a return: unlike the
trip form, the import never doubles it, so a file exported from Logbook
imports back unchanged. *Return* and *Business* are yes or no; a missing
*Business* column means every trip is business. A trip with the same date,
places and distance as one already on the vehicle is a duplicate and is
skipped. Imported trips are the importing user's own trips, and count in
their claim. Trips never write odometer readings.

## From Fuelio

Fuelio's exports aren't one table per file, so they have their own import:
**Settings → Import from another app** (also linked as *Coming from
Fuelio?* on a vehicle's fill-up import). It needs the fuel module on.
Logbook isn't affiliated with Fuelio.

### Exporting from Fuelio

- **CSV export**: one file per vehicle. In Fuelio, open the menu and export
  the vehicle as CSV (under *Backup & sync* or *Export*; the menus change
  between app versions). Upload it on the web page.
- **Backup ZIP** (`backup-….fuelio.zip`): every vehicle, plus the photos
  you took of fill-ups. These are often hundreds of megabytes, so they are
  imported on the command line instead:

  ```bash
  php bin/import-app.php backup.fuelio.zip --dry-run
  php bin/import-app.php backup.fuelio.zip
  ```

  With Docker: `docker compose exec -u www-data app php bin/import-app.php
  /data/backup.fuelio.zip` (copy the file into the volume first). Add
  `--as <username>` when the install has more than one user, `--vehicle
  <id>` or `--create` to choose where a one-vehicle export goes, and
  `--schedules` to turn repeating costs into service schedules.
  `--dry-run` prints the preview and writes nothing. Like backups, it
  needs PHP's `zip` extension (the Docker image has it).

### What the import asks

1. **Vehicle**: one of your active vehicles you can manage, or a new one
   filled in from the export (name, make, model, year, registration, VIN,
   fuel type, tank size). A later export of the same car proposes the
   vehicle that already holds its rows.
2. **Units and dates**: read from the export's headers ("Odo (mi)",
   "Fuel (litres)"). The preview shows the economy they give, next to
   Fuelio's own figures: *"With miles and litres, these fill-ups average
   22.6 mpg (12.5 L/100 km). Fuelio's own figures agree."* If they
   disagree, the units are probably wrong.
3. **Cost categories**: each goes to a Maintenance category, an Expense
   category, or nowhere. Fuelio's own categories have defaults (Service →
   Maintenance *Service*; Parking, Tolls, Wash, Fines, Registration →
   Expenses); your own categories match by name, else *Other*.
4. **Fuel types**: Fuelio's fuel codes map to a fuel (and a grade if you
   like), or *Don't import*. Petrol is recognised; check any other.
5. **Service schedules** (optional): a cost that repeats every so many
   miles or months becomes a schedule.

### What comes across, and what doesn't

| From Fuelio | In Logbook |
|---|---|
| Fill-ups | Fill-ups: date, odometer, volume, total and price per unit, full or partial, missed, notes, station. Each writes its odometer reading. |
| Costs | Maintenance records (with their reading) or expenses, by the category mapping. |
| Favourite stations | Stations with their position, as your favourites. A fill-up's station is matched by Fuelio's id, then by name, then within 150 m of a station you have; otherwise a new one is added by name. |
| Photos (backup only) | Attachments on their fill-up, checked and stripped of their metadata like any upload. |
| Income, cost templates | Not imported (listed with the reason). |
| GPS trips | Not imported yet. |

A fill-up's own GPS position is used only to find its station, and is
never stored.

The preview lists every row with its outcome: *import*, *invalid* (with
the reason), *duplicate* (already on the vehicle), *already imported* and
*not imported*. Everything is written in one transaction, so either the
whole file lands or nothing does.

### Importing a newer export later

Logbook remembers each imported row by Fuelio's own id. Importing a newer
export of the same car adds only what's new, even if you've edited the
imported entries since. Deleted ones aren't brought back.
