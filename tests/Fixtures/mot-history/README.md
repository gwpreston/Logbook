# DVSA MOT history fixtures

Answers of DVSA's MOT history API (spec.md §4, §7.38), used by
`DvsaParserTest` and `DvsaProviderTest`:

- `vehicle-with-tests.json` — `GET /v1/trade/vehicles/registration/{registration}`
  for a vehicle with tests: passes and fails, miles and kilometres, an
  unreadable odometer, every defect type (and a null, an unknown and a
  textless one), a Northern Ireland test with no number, and a heavy-vehicle
  test with no completed date.
- `new-vehicle.json` — a new vehicle: no tests, a first MOT due date.
- `bulk-download.json` — `GET /v1/trade/vehicles/bulk-download` (*Test* and
  the keep-alive, #327), with a placeholder link.
- `not-found.json` — the body of a `404`.
- `token.json` — the Microsoft token endpoint's answer.

They are **synthetic**, written to DVSA's OpenAPI specification
(`mot_history_open_api_specification.yml`, fetched 2026-10-08) because the
API needs approved credentials. The registration, VIN and test numbers are
made up.

To check the parser against the real API as well, record real answers once
you have credentials:

    DVSA_CLIENT_ID=… DVSA_CLIENT_SECRET=… DVSA_API_KEY=… DVSA_TOKEN_URL=… \
        php bin/record-mot-history.php AB12CDE [MORE PLATES…]

It writes `recorded-*.json` here with the registration, VIN and test
numbers replaced by placeholders, and the bulk-download links dropped
(they are signed). `DvsaRecordedTest` reads them when they exist and is
skipped otherwise. The data is published under the Open Government
Licence v3.0.
