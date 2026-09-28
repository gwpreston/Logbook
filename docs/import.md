# Importing from CSV

Each vehicle tab (Mileage, Fuel, Maintenance, Documents, Expenses) has an
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
| **Comes from an entry** | (Mileage only.) A reading whose source is a fill-up or service. Fill-ups and maintenance with an odometer create their own readings, so import the fuel and maintenance files instead — readings are never doubled. |

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
- **Yes/no** columns accept yes/no, true/false, 1/0, y/n, x (and your
  language's yes/no).
- A fill-up row's **Unit** column (`UK gallons`, `L`, `kWh`, …) overrides the
  file's volume unit for that row. Charging is always in kWh.

## Fields per list

| List | Fields (required in bold) |
|---|---|
| Fuel | **Date and time**, **Odometer**, Fuel, Volume, Unit, Price per unit, Total, Currency, Partial, Missed previous, Station, Notes — any two of volume, price and total |
| Mileage | **Date and time**, **Odometer**, Source, Note |
| Maintenance | **Date**, Category, **Title**, Odometer, Cost, Currency, Garage, Details |
| Documents | **Type**, Title (required for *Other*), Provider, Reference, Start, Expiry, Cost, Currency, Notes |
| Expenses | **Date**, Category, Amount, Currency, Note |

Not imported: attachments, links between maintenance and schedules, and
archived vehicles (restore the vehicle first).
