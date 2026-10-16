# Tracktor fixtures

Real exports (*Settings → Export*) made on 2026-10-16 with Tracktor's own
Docker images, filled through Tracktor's API with invented vehicles and
entries (Phase 51.1). Nothing in them belongs to a real person.

| File | Tracktor | What it holds |
|---|---|---|
| `export-metric.json` | 2.1.0 | km, litres, INR. A car (photo, colour, custom fields; a partial fill, a missed previous, fills with two of the three amounts; a free service; insurance, emissions and an `other` document; an open yearly maintenance reminder and a completed one) and a motorcycle. A Gotify notification provider, its token encrypted with the server's secret. |
| `export-imperial.json` | 2.1.0 | miles, gallons (UK figures), GBP. A diesel car with an MOT and road tax, a Nissan Leaf (kWh) and a 1979 camper as `bus` with no odometer. |
| `export-encrypted.json` | 2.1.0 | The metric garage before its provider was added, exported with the password `correct horse`. |
| `export-1.4.1.json` | 1.4.1 | Before the compliance merge: `insurances` and `puccs`, no reminders, no vehicle type; `users` and `sessions` (empty here: auth was off). |
| `uploads.zip` | 2.1.0 | The metric instance's `/data/uploads` folder, zipped as `uploads/<file>`. |
