# UK Fuel Finder feed fixture

`pfs-page-1.json` (stations, `GET /api/v1/pfs`) and `fuel-prices-page-1.json`
(prices, `GET /api/v1/pfs/fuel-prices`) are one page each of the UK Fuel
Finder Information Recipient API (spec.md §4, §7.34).

They are **synthetic** (decided 2026-10-03, `docs/phases/open-questions.md`
#139): the feed needs GOV.UK One Login credentials, so they were written to
the published schema rather than downloaded. They cover every case the
parser handles: upper-case names, a full address on line 1, a position of
0,0, 24-hour and closed days, temporary and permanent closures, every grade
code, an unknown code, a price in pounds, implausible prices, an unpriced
grade, a stale price and a record without a valid id.

To check the parser against the real feed as well, record a trimmed real
download (credentials from the Fuel Finder developer portal):

    FUEL_FINDER_CLIENT_ID=… FUEL_FINDER_CLIENT_SECRET=… php bin/record-fuel-finder.php --stations=20

It writes `recorded-pfs.json` and `recorded-fuel-prices.json` here (the
first stations of page 1, and their prices). `FuelFinderRecordedTest` reads
them when they exist and checks that every record parses, and is skipped
otherwise. The data is published under the Open Government Licence v3.0.
