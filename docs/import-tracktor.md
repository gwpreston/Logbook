# Importing from Tracktor

Bringing a whole Tracktor garage into Logbook.

**For:** anyone moving from Tracktor.

[Tracktor](https://github.com/javedh-dev/tracktor) is a self-hosted
vehicle tracker. Logbook reads its export and brings your whole garage
across in one go: every vehicle, fill-up, service record, document and
reminder, with their attachments and vehicle photos when you give it
Tracktor's uploads folder. Logbook isn't affiliated with Tracktor.

The import is on **Settings → Import from another app**, the same page as
Fuelio's ([import.md](import.md#from-fuelio)). It needs the fuel module on,
and `Manage` on every vehicle it writes to.

- [Exporting from Tracktor](#exporting-from-tracktor)
- [On the web page](#on-the-web-page)
- [On the command line](#on-the-command-line)
- [What comes across, and what doesn't](#what-comes-across-and-what-doesnt)
- [Importing a newer export later](#importing-a-newer-export-later)

## Exporting from Tracktor

1. In Tracktor, open **Settings** and use **Export**. Leave *Encrypt* off:
   Logbook doesn't read password-protected exports, and says so if you
   give it one. The file is called `tracktor-export-<date>.json`.
2. For the attachments and vehicle photos, take Tracktor's uploads
   folder too. In Tracktor's Docker image it is `/data/uploads`:

   ```bash
   docker cp tracktor-app:/data/uploads ./uploads
   ```

   Zip that folder for the web page (`uploads.zip`), or keep it as a
   folder for the command line.

Exports from Tracktor 1.x, from before August 2026 when insurance and
pollution certificates were separate pages, are read too.

## On the web page

Upload the JSON export, and the uploads ZIP in the second field if you
have it (each up to the install's upload limit, `MAX_UPLOAD_MB`). Then:

1. **Vehicles**: each Tracktor vehicle goes to one of your active vehicles
   you can manage, a new vehicle filled in from Tracktor's, or nowhere.
   A vehicle already imported is proposed again, found by Tracktor's own
   ids; otherwise one with the same registration. If you choose another
   vehicle for rows that are already in Logbook, the preview warns you,
   since they would come across a second time.
2. **Units**: distance and volume come from Tracktor's settings. Tracktor
   doesn't say which gallon it means, so for gallons Logbook proposes
   yours, or the UK gallon in the United Kingdom and Ireland and the US
   gallon elsewhere. Each vehicle's economy in the preview tells you if
   it's wrong: miles read as kilometres, or the wrong gallon, give a
   figure no car does.
3. **Service records**: Tracktor's have no category, so pick one for all
   of them (*Service* by default). The title is the first line of the
   notes, or *Service*.
4. **Documents**: each Tracktor type goes to a Logbook one: insurance,
   emissions (a pollution certificate), roadworthiness (an inspection),
   registration and other.
5. **Reminders** (on by default) and **Make schedules** (off by default),
   below.

The preview shows a card per vehicle, every row with its outcome. The
import is one transaction: every vehicle lands, or nothing does.

## On the command line

For a large export, or to give the uploads as a folder:

```bash
php bin/import-app.php tracktor-export.json --files ./uploads --dry-run
php bin/import-app.php tracktor-export.json --files ./uploads
```

`--files` takes the folder or a ZIP of it. Add `--as <username>` when the
install has more than one user, `--create` to make every vehicle new,
`--vehicle <id>` for an export with one vehicle, and `--schedules` for
*Make schedules*. With Docker, copy the files into the volume first, then
`docker compose exec -u www-data app php bin/import-app.php
/data/tracktor-export.json --files /data/uploads`.

## What comes across, and what doesn't

| From Tracktor | In Logbook |
|---|---|
| Vehicles | Make, model, year, registration, VIN and fuel type. Motorcycles and scooters are bikes; every other type is a car (a bus, farm vehicle or yacht is marked so you can check it). A new vehicle takes Tracktor's currency if it isn't yours. |
| The vehicle's odometer | A reading dated when you exported, if it's higher than everything else. |
| Fuel logs | Fill-ups: date, odometer, amount, price, cost, full or partial, missed previous, notes. Any two of amount, price and cost give the third. A log whose three don't agree to within a cent is marked and kept as Tracktor has it. Each writes its odometer reading. |
| LPG and CNG | LPG in litres or gallons, CNG in kilograms (or pounds, converted). LPG by weight and CNG by volume aren't imported: Logbook doesn't record them that way. |
| Maintenance logs | Service records with the category you chose; the service centre is the garage. A free service (cost 0) is fine. |
| Compliance documents (1.x: insurance and PUCC) | Documents: type, number, issuer, start and end dates, cost and notes. |
| Reminders | Manual reminders, due on the same day with the same notice (a week before, and so on). The ones completed in Tracktor are marked done. |
| Attachments and the vehicle photo | Files on their entry, and the vehicle's photo (for a new vehicle or one without a photo), checked and stripped like any upload. Only with the uploads folder. |
| Colour and custom fields | Not imported: a Logbook vehicle has nowhere to put them. The preview lists them. |
| Notifications, notification providers, settings | Never read. A provider's password or token stays in Tracktor. |

Amounts are never converted between currencies: an existing vehicle in
another currency gets a warning in the preview.

Dates are the dates you picked in Tracktor. Tracktor stores them as
midnight where your browser was, so Logbook reads them in your time zone.

### Make schedules

Tracktor repeats documents and reminders; Logbook repeats service
schedules and reminds you before a document expires. With **Make
schedules** ticked:

- an open *maintenance* reminder that repeats monthly or yearly becomes a
  service schedule (every so many months) instead of a one-off reminder;
- a document that repeats yearly or monthly with no end date takes the
  next due date Tracktor shows as its expiry, so Logbook reminds you
  before it.

Without it, documents keep their dates as written and reminders happen
once.

## Importing a newer export later

Logbook remembers each imported row by Tracktor's own id, and each
vehicle too. Importing a newer export adds only what's new, into the same
vehicles, even if you've edited the imported entries since. Deleted ones
aren't brought back.

---

[Back to the docs index](README.md)
