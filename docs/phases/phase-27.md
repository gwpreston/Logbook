# Phase 27 — Incidents, damage and insurance claims + v2.10 release

*What happened, what was fixed, what the insurer did, and the five-year
answer your next quote will ask for.*

Status: 📋 planned · releases **v2.10.0** · file lives in `docs/phases/`

Today a scrape, a break-in or a pothole leaves only its repair behind: a
`bodywork` or `repair` maintenance record, perhaps a `damaged` tyre. The
event itself is not recorded: when and where it happened, whose fault it
was, the claim, the excess, the payout, the photos. This phase adds
**incidents**. An incident is an event that **links** the records Logbook
already keeps (repairs, expenses, tyre changes, attachments, a reading)
rather than copying their costs, so nothing is counted twice.

It also adds a **Claims history** across every vehicle, including sold
ones. That is the question UK insurers ask at every quote: claims and
incidents in the last five years, fault or not. The sale pack gains an
optional incident summary that shows the repairs, never the claim details.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6, §7.2,
§7.4, §7.5, §7.7, §7.12, §7.16, §7.17, §7.19, §7.21 and §7.24 first.

---

## Goals

1. An `incidents` module with an *Incidents* tab per vehicle and *Log
   incident* in the *Log entry* chooser.
2. Incidents with type, fault, location, damage, driver, the other party,
   a police reference, an insurance claim (number, status, excess, payout,
   no-claims effect), a write-off category, a status, and photos.
3. **Links** from maintenance records, expenses and tyre changes to an
   incident; the incident's cost is what is linked, less payouts.
4. **Claims history** (all vehicles, any period, printable and CSV).
5. History, sale pack, Reports, ownership, Needs attention, the API and
   Ask Logbook all know about incidents.

## Not in scope

- Contacting insurers, or exporting in an insurer's claim-form format.
- Map lookups for the location.
- Recording other people's injuries, or witness statements beyond notes.
- Automatic vehicle history checks (HPI and similar; third-party).
- Reading insurer letters or estimates with AI (see *Open questions*).

---

## Spec additions

### §6 Data model

> **Incident** (Phase 27): id, vehicle_id (`ON DELETE CASCADE`),
> created_by (user), occurred_on (calendar date), occurred_at_time
> (optional local time), location (optional free text, up to 200), type
> (`collision` | `parked_damage` | `theft` | `break_in` | `vandalism` |
> `weather` | `glass` | `pothole` | `animal` | `fire` | `other`), fault
> (`at_fault` | `not_at_fault` | `split` | `unknown`, default `unknown`),
> description (up to 2,000), damage_areas (JSON list of `front` | `rear` |
> `left` | `right` | `roof` | `underside` | `glass` | `wheels` |
> `interior`), severity (`cosmetic` | `minor` | `major`), driver_user_id
> (optional, Phase 19 user), driver_name (optional free text, for someone
> without an account), other_party_name, other_party_registration,
> other_party_insurer (optional, up to 100 each), police_reference
> (optional), status (`open` | `closed`), closed_on (optional),
> write_off_category (`none` | `cat_n` | `cat_s` | `cat_b` | `cat_a`,
> default `none`), notes, created/updated (UTC). Index `(vehicle_id,
> occurred_on)`.
>
> **Claim** fields on the incident: claim_status (`not_claimed` |
> `notified` | `open` | `settled` | `declined` | `withdrawn`, default
> `not_claimed`), insurer (optional, defaulting in the form to the
> provider of the `insurance` document current on occurred_on), insurance
> document id (optional, `ON DELETE SET NULL`), claim_number (optional),
> excess (`DECIMAL`, optional, ≥ 0), payout (`DECIMAL`, optional, ≥ 0:
> money the owner received), ncd_affected (`yes` | `no` | `unknown`),
> claim_updated_on (optional date of the latest news). Amounts are in the
> vehicle's currency, and 0 is valid.
>
> **Links:** `incident_id` (nullable, `ON DELETE SET NULL`, indexed) on
> `maintenance_entries`, `expense_entries` and `tyre_changes`. A record
> belongs to at most one incident. Deleting an incident unlinks its records
> and never deletes them.
>
> **Reading:** an optional odometer on the incident writes an
> OdometerReading with source `incident` (a new source, §6), like documents
> do.
>
> **Attachments** gain owner type `incident`. Incident photos **keep** their
> EXIF (time and place are evidence for an insurer). EXIF is stripped
> whenever a photo leaves through the sale pack's ZIP.
>
> Backups carry incidents, links and `incident` attachments. The schema
> version moves.

### §7.29 Incidents (new)

> - **Module** `incidents`, switchable (§7.10), on by default.
> - **Incidents tab** (`/vehicles/{id}/incidents`): newest first, each
>   with date, type, a fault badge, a claim-status badge, a *Cat S* badge
>   when written off, the net cost, and a paperclip. Open incidents come
>   first. The toolbar has *Log incident*.
> - **Form** (page and desktop modal, §5), in four sections: *What
>   happened* (date, time, location, type, description, odometer, driver),
>   *Damage* (areas, severity, photos and files, write-off category), *Other
>   party* (folded away by default; name, registration, insurer, police
>   reference), and *Insurance* (status, insurer and policy, claim number,
>   excess, payout, no-claims effect, latest update). Validation: the date
>   is not in the future; amounts ≥ 0; *closed* needs no fields but sets
>   closed_on to today unless given.
> - **Incident page:** the details, the photos as a grid, and **Linked
>   records**: the repairs, expenses and tyre changes linked to it, each
>   with its cost and link. It has *Link a record* (a picker of the
>   vehicle's unlinked records within 180 days after the incident) and
>   buttons to *Add a repair*, *Add an expense* (excess, hire car,
>   recovery) and *Add a tyre change*. Each opens the normal form with the
>   incident preselected.
>   - **Costs:** *Linked costs* (the sum of linked records' costs), *Payouts
>     received*, and *Net cost to you* (linked minus payouts, never shown
>     below 0 without the label "you received more than it cost").
>   - With reminders on, *Add reminder* opens a manual reminder prefilled
>     "Chase claim {number}".
> - **Maintenance, expense and tyre change forms** gain *Part of an
>   incident* (optional select of the vehicle's incidents, open ones first).
>   It is preselected when opened from an incident.
> - **Claims history** (`/incidents/history`, module on): every incident on
>   every vehicle the user can see, **including archived and sold ones**.
>   Filters: last 3, 5 (default) or 10 years or a date range; vehicle;
>   driver; *Claims only* or *All incidents*; fault. Columns: date, vehicle
>   and registration, type, fault, driver, claim status, insurer, claim
>   number, payout, no-claims effect. The hint reads "Insurers usually ask
>   about the last 5 years, including incidents that were not your fault
>   and ones on vehicles you no longer own." Printable (Phase 17.2
>   conventions) and CSV. The other party is never included.
> - **History** (§7.16): an *Incidents* chip. Incident rows show under
>   *Everything* with their linked records nested beneath them, as linked
>   tyre changes are under service records. The print view leaves incidents
>   out unless *Include incidents* is ticked (off by default), and shows only
>   date, type, damage and the linked repairs.
> - **Sale pack** (§7.19): *Include incidents* (off by default). With it on,
>   an *Incidents* group lists each incident's date, type, damage areas,
>   severity and repairs (vendor and date), with their paperwork offered in
>   the ZIP. Fault, claim details, payouts, the driver and the other party
>   are **never** shown.
>   - **Write-off:** when any incident has a write-off category, the summary
>     shows "Recorded as Cat S (14 Mar 2025)" whenever incidents are
>     included. When they are not, a screen-only notice tells the seller:
>     "This vehicle has a Cat S record. A buyer's vehicle history check will
>     show it."
> - **Overview:** a written-off vehicle shows a *Cat S* badge in its header.
>   The *Recent activity* card includes incidents.
> - **Reports** (§7.7): spend is unchanged, because linked costs are
>   already counted in their own groups. A *Incidents* section gives, for
>   the period, the number of incidents, *Incident-related spend* (linked
>   costs) and *Payouts received*.
> - **Ownership** (Phase 14.2): running cost is shown **net of payouts**,
>   with a line *Insurance payouts* so the figure is explained.
> - **Needs attention** (§7.24), *Check*: an incident with claim status
>   `notified` or `open` and no claim update for **30 days**: "Claim 4417 with
>   Aviva: no update for 34 days". It links to the incident and can be hidden
>   (the fingerprint is claim_updated_on and status).
> - **Access** (Phase 19): logging needs `Log`. Fault, the other party,
>   police reference, claim number, payout and driver are visible to `Manage`
>   and `Own` and to the incident's creator. Others with `View` see the date,
>   type, damage and linked repairs. Amounts follow `ViewCosts`.
> - **API** (§7.20): `GET/POST /api/v1/vehicles/{id}/incidents`, `GET
>   /api/v1/incidents/history`, with the access rules above.
> - **Ask Logbook** (§7.26): an `incidents(vehicles?, period?, claims_only?)`
>   tool, so questions like "Have I had any claims in the last five years?"
>   are answered with the claims history's figures. A `draft_incident` draft
>   tool (Phase 26.3 rules: nothing saves without *Add*).
> - **Export:** incidents join the CSV export. There is no CSV import.

---

## Decisions (and why)

- **Link, don't copy.** Repairs and costs already live in maintenance
  records and expenses, which feed Reports, ownership and the sale pack.
  An incident that stored its own cost would double-count, and an edit to
  one would leave the other wrong.
- **Payouts reduce ownership, not spend.** Spend is money that went out.
  Ownership answers "what has it cost me", which a payout does change.
  Each figure stays true to its label.
- **Claims history includes sold vehicles.** Insurers ask per driver over
  years, not per current car. It is the most practical feature in this
  phase.
- **The sale pack shows repairs, not claims.** Good repairs with invoices
  help a sale. Fault, payouts and third parties are nobody else's business.
- **The write-off category isn't hidden in a way that looks deliberate.**
  Any history check shows it. The app tells the seller rather than forcing
  their hand, and shows it whenever incidents are included.
- **Photos keep EXIF here.** Unlike receipts (Phase 26.4), time and place
  are the point of a damage photo, until it is shared with a buyer.

---

## Tasks

### Spec and docs
- [ ] §6, §7.29 and the touched sections (§7.7, §7.16, §7.19, §7.20, §7.24,
      §7.26, Phase 14.2's ownership) in `spec.md`; the Phase 27 line in §13.
- [ ] `docs/incidents.md`: logging an incident, linking records, claims
      history for insurance quotes, what the sale pack shows.

### Migrations (every engine, each reversible)
- [ ] `incidents`; `incident_id` on `maintenance_entries`, `expense_entries`
      and `tyre_changes`; reading source `incident`; attachment owner type
      `incident`. Rollback unlinks and drops (photo files stay under
      `UPLOAD_PATH`, as earlier rollbacks do).

### Code
- [ ] `Domain\Incident\*` (entity, enums), `Repository\IncidentRepository`.
- [ ] `Service\Incident\IncidentService` (create and edit, reading, insurer
      default from the current policy, closing), `IncidentCosts` (linked,
      payouts, net), `ClaimsHistory`.
- [ ] Links: the *Part of an incident* select on the three forms, and the
      *Link a record* picker.
- [ ] Tab, form, incident page, claims history page (print and CSV); the
      History chip and nesting; the sale pack group, ZIP EXIF stripping and
      write-off line; the overview badge; Reports section; ownership line;
      the Needs attention kind.
- [ ] Access rules in the Phase 18.1 policy (a `ViewIncidentDetails`
      ability).
- [ ] API endpoints; the Ask tool and draft tool in `ToolRegistry`.
- [ ] Translations (en, de), with the write-off categories named as in the
      UK and explained in German as *Versicherungs-Totalschaden-Kategorie*.

### Tests
- [ ] **No double counting:** a £1,400 repair linked to an incident counts
      once in Reports, ownership and the sale pack; unlinking changes
      nothing in Reports.
- [ ] Net cost with and without payouts; a payout above the linked costs
      shows the label; 0 excess valid.
- [ ] Ownership net of payouts, with the line.
- [ ] Claims history: includes archived and sold vehicles; the 5-year
      default by calendar date; *Claims only*; driver filter; the other party
      never in print or CSV.
- [ ] Sale pack: off by default; on shows repairs and never fault, claim,
      payout, driver or other party; the write-off line; the seller notice
      when off; ZIP photos without EXIF; stored photos keep EXIF.
- [ ] History nesting and the print view option.
- [ ] Needs attention: raised at 31 days without an update, not at 29; gone
      when settled or updated; hide fingerprint.
- [ ] The insurer default comes from the policy current on the incident
      date.
- [ ] Access matrix: detail fields for Manage, Own and the creator only;
      View sees the summary; `ViewCosts` gates amounts; API and Ask follow
      the same rules.
- [ ] Deleting an incident unlinks and keeps records; deleting a linked
      record leaves the incident.
- [ ] Module off: tab, chooser, form selects, history chip, sale pack
      option, Reports section, attention kind and API routes all gone;
      data kept.
- [ ] Integration suite green on every engine; migrations roll back on every
      engine.

### Sample data
- [ ] `DemoDataSeeder`: on the Golf, a 2024 non-fault parked-damage
      incident, claim settled, a bumper repair linked, photos, and the other
      party's insurer. On the motorbike, link the existing damaged-tyre
      replacement to a pothole incident (not claimed). On the archived car,
      an old at-fault collision with a claim, so the claims history shows a
      sold vehicle.

### Release
- [ ] `CHANGELOG.md` **2.10.0**: incidents, damage and claims history.
      Upgrade notes: migrations; the module is on by default and can be
      switched off.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
      documentation table.

---

## Acceptance criteria

1. Logging a parked-damage incident with photos, then adding its repair
   from the incident page, links the two. The repair's cost is counted
   once everywhere.
2. *Claims history* lists five years of incidents across every vehicle,
   sold ones included, ready to read out to an insurer, without anyone
   else's details.
3. With *Include incidents* on, the sale pack shows what was damaged and
   who repaired it, and never the claim, fault or other party.
4. A claim waiting for news shows in *Needs attention* after 30 days.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Write-off in the sale pack:** show it only when incidents are included
  (drafted, with a notice to the seller otherwise), or always on the summary
  whenever a category is recorded?
- **Total loss:** when the vehicle is written off and settled, should
  archiving offer *Written off*, with the settlement as the sale price and
  the incident linked?
- **Module default:** on for everyone (drafted), or off like trips?
- **AI reading:** add insurer letters and repair estimates to Phase 26.4's
  scanning (claim number, status, excess, estimate), or leave incidents
  typed?
