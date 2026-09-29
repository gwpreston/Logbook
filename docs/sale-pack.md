# The sale pack

*Prepare for sale* (in every vehicle's header, and *Sale pack* in the
History toolbar) builds what a buyer wants to see: a one-page summary, a
mileage record they can check, the history by type, and the invoices and
certificates as a ZIP. It works for active and archived vehicles, and
without JavaScript. Nothing is stored: the pack is worked out from your
records every time you open it.

## What is in it

- **Summary** (the first printed page): the vehicle (make, model, variant,
  model year, fuel, registration, VIN, first registered and its age); how
  long you have owned it and how far it has gone since ("Owned since March
  2021 · 29,515 mi covered", shown only when there is a reading within 31
  days of the purchase date); the current odometer and the average per year;
  how many service and repair records there are, the last service and how
  many have paperwork; when the MOT (or TÜV, or emissions certificate) runs
  out; the tyres fitted with their last *measured* tread; what is due next;
  and how many invoices and certificates there are.
- **For UK owners** with a registration on the vehicle, a line pointing the
  buyer to `gov.uk/check-mot-history`. Logbook never fetches anything from
  it.
- **Mileage record:** readings taken when someone else was there (services,
  inspections, tyre changes) and manual readings with a photo attached, each
  with where it came from ("Service invoice, Kwik Fit", "MOT certificate",
  "Dashboard photo"). Fill-up readings are left out: there are hundreds and
  they are your own typing. With JavaScript, a chart sits above the table.
- **History by type:** service and repairs, inspections and certificates,
  and tyres, newest first, with descriptions and the names of the files.
  Optionally, the full timeline after them.

## What is never in it

Whatever the options say: the purchase and sale prices, fuel, expenses,
valuations, depreciation and cost of ownership. Insurance, registration and
*other* documents are not listed in the history, because they are about you,
not the car.

*Show the cost of work* adds the cost of each service, repair and inspection
and one total ("£3,240 spent on servicing and repairs since March 2021"). It
is off by default.

## The paperwork ZIP

Tick which kinds go in: service and repair invoices, MOT and emissions
certificates and dashboard photos are ticked to start with; purchase
paperwork and insurance are not. Registration documents (the V5C), sale
paperwork, valuations, fill-up receipts and expenses are **never** offered:
the V5C's reference can be used for fraud.

*Choose files* lists every file with a box each, so you can leave one out.
Files are named so a buyer can find them (`2024-03-12 Service - Kwik Fit.pdf`)
and `contents.txt` lists each one with its entry, date and odometer.

**Invoices often show your name and address.** Open them and check before you
send them.

The ZIP is streamed straight from your uploads (nothing is copied to a
temporary file) and needs no PHP extension.

## Saving it as a PDF

The pack is a web page laid out for paper: the options and notices are left
off, the summary gets a page of its own and everything prints black on white.

- **Desktop (Chrome, Edge, Firefox, Safari):** *Print* (or Ctrl/⌘ + P), then
  choose *Save as PDF* (Chrome, Edge), *Microsoft Print to PDF* (Windows) or
  *PDF → Save as PDF* (Safari on macOS). Turn off headers and footers for a
  cleaner page.
- **iPhone and iPad:** *Share → Print*, pinch outwards on the preview, then
  *Share → Save to Files*.
- **Android (Chrome):** *⋮ → Share → Print*, then choose *Save as PDF* as the
  printer.

Send the PDF and the ZIP together.

## Before you share it

If a reading in the mileage record looks wrong (it goes backwards, or jumps
by more than anyone could drive), the pack says so above the sheet with a
link to the entry. The warning is never printed; fix the entry, or leave it
if it is right (a replaced odometer, say).
