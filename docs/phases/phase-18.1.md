# Phase 18.1 — Access policy

*One place that decides who may do what, before anyone else can sign in.*

Status: ✅ complete · no release of its own; ships with Phase 18.2 as
**v1.10.0**

Logbook has one owner, so nothing today asks "may this user see this
vehicle?". The REST API (Phase 18.2) and multiple users (Phase 19) both
need that question answered everywhere. If the API were built first, every
endpoint would have to be revisited when users arrive. This phase adds the
question now, answers it with "yes, it's yours" for the single owner, and
proves every route asks it. Behaviour does not change; no screen or figure
moves.

Read [`CLAUDE.md`](../../CLAUDE.md) §5 and [`spec.md`](../../spec.md) §5 first.

---

## Goals

1. A **vehicle access policy** consulted by every vehicle-scoped Action and
   by every query that lists vehicles across the garage.
2. An **instance policy** for install-wide settings (modules, backup and
   restore, notification channels, and later users and API keys).
3. A **cost visibility** check wherever amounts are shown, so a later
   policy can hide costs without touching templates again.
4. A **route inventory test**: every route declares what it needs, or the
   build fails.

## Not in scope

- New users, roles, sharing, invitations, or any UI. That is Phase 19.
- Schema changes. `vehicles.user_id` already names the owner, and that is
  all the single-owner policy reads.
- The API itself (Phase 18.2).

---

## Spec addition (§5 Architecture, after *Current user*)

> - **Access policy** (Phase 18.1). Actions and services never decide
>   access themselves. They ask `Service\Access\VehicleAccess`:
>   - `can(User $user, VehicleAbility $ability, Vehicle $vehicle): bool`
>   - `visibleVehicleIds(User $user, VehicleScope $scope): list<int>`,
>     where the scope is `Active`, `Archived` or `All`.
>
>   `VehicleAbility` is an enum. The Phase 19 levels map onto it:
>
>   | Ability | Covers |
>   |---|---|
>   | `View` | reading the vehicle, its entries, history and files |
>   | `ViewCosts` | amounts, prices, reports, cost of ownership, valuations |
>   | `Log` | adding fill-ups, readings, service records, documents, expenses and tyre changes; editing or deleting one's own |
>   | `Manage` | editing the vehicle and any entry, schedules, valuations, import, sale pack |
>   | `Own` | archive, restore, delete, transfer, sharing |
>
>   `Service\Access\InstanceAccess::can(User, InstanceAbility)` covers
>   `ManageModules`, `Backup`, `Restore`, `ManageNotifications` and later
>   `ManageUsers`.
>
>   **Phase 18.1 policy:** a user has every vehicle ability on the vehicles
>   whose `user_id` is theirs and none on any other, and every instance
>   ability. With one owner, that is today's behaviour exactly.
> - **Vehicle routes.** Each route with `{id}` declares its ability (a route
>   argument read by `Middleware\VehicleAccessMiddleware`). The middleware
>   loads the vehicle once, checks the ability, and puts the vehicle on the
>   request as the `vehicle` attribute. Actions take it from there and never
>   reload it by id. A vehicle the user cannot `View`, or that does not
>   exist, answers **404**, so its existence is never revealed. One the user
>   can view but lacks the ability for answers **403** with a friendly page.
>   Entry routes (`/vehicles/{id}/fuel/{entry}`) also check that the entry
>   belongs to that vehicle (404 otherwise), as they do today.
> - **Cross-vehicle reads** (garage, dashboard widgets, fleet history,
>   Reports, the Ownership report, *Coming up*, the calendar feed, the
>   scheduler's reminder sync) take their vehicle ids from
>   `visibleVehicleIds()`. No repository lists "all vehicles". The scheduler
>   runs per user, as it already notifies per owner.
> - **Costs.** Templates show an amount only through `can_see_costs(vehicle)`
>   (a Twig function backed by `ViewCosts`). Report services drop vehicles
>   without `ViewCosts` from their figures. Under the Phase 18.1 policy this
>   is always true.
> - **Attachments** are served after a `View` check on their vehicle. Their
>   lookup is already scoped by `vehicle_id` (§7.12).
> - Instance pages (Settings → Modules, Backup, Reminders' channel part)
>   check `InstanceAccess`. Personal settings (units, language, password,
>   calendar feed) need only a signed-in user.

---

## Tasks

### Policy
- [x] `Domain\Access\VehicleAbility`, `VehicleScope`, `InstanceAbility`
      enums.
- [x] `Service\Access\VehicleAccess` and `InstanceAccess` interfaces;
      `SingleOwnerVehicleAccess` and `SingleOwnerInstanceAccess`
      implementations, bound in PHP-DI.
- [x] `visibleVehicleIds()` with one indexed query (`vehicles.user_id`,
      `status`), memoised per request.

### Routing and middleware
- [x] `Middleware\VehicleAccessMiddleware` on the vehicle route groups:
      reads the route's `ability` argument, loads the vehicle, sets the
      attribute, answers 404 or 403.
- [x] Declare an ability on every `{id}` route in `config/routes.php`.
      Reads get `View`, cost pages `ViewCosts`, log forms `Log`, edit and
      import `Manage`, archive, delete and restore `Own`.
- [x] Refactor Actions to read `vehicle` from the request. Remove their own
      vehicle loading.
- [x] Instance settings Actions check `InstanceAccess`.

### Cross-vehicle reads
- [x] Garage, dashboard widgets, fleet history (`ActivityFeed`), Reports,
      Ownership report, `ComingUp`, calendar feed and reminder sync take ids
      from the policy. Change repository signatures from "all active" to
      "these ids".
- [x] Backup stays instance-wide behind `InstanceAbility::Backup`. It is
      not filtered.

### Costs
- [x] `can_see_costs()` Twig function. Wrap every amount in list, card,
      report and widget templates. Inventory them with a grep test for the
      money filter outside a `can_see_costs` block, with allowed exceptions
      listed in the test.
- [x] Report, ownership and forecast services skip vehicles without
      `ViewCosts`.

### Tests
- [x] **Route inventory test:** loads the Slim route collector. Every route
      is either public (`/health`, setup, sign-in, the calendar feed by
      token, assets), signed-in-only (personal settings), an instance
      ability, or a vehicle route with a declared ability. An unclassified
      route fails the build and names itself.
- [x] **Policy test double:** a `ConfigurableVehicleAccess` for tests. With
      it, each vehicle route is exercised with no access (404), view only
      (403 on writes), no costs (no amounts in the HTML, no vehicle in
      report totals), and full access (unchanged).
- [x] A second owner row inserted directly in the test DB never appears in
      any list, report, feed, widget or search result, and their vehicle and
      attachment ids answer 404.
- [x] The whole existing suite passes unchanged: no behaviour change for the
      single owner.
- [x] Integration suite green on every engine.

---

## Acceptance criteria

1. Every route is classified by the inventory test, and adding a route
   without an ability fails CI.
2. With the single-owner policy, nothing visible changes.
3. With the test policy, access and cost visibility are enforced on every
   route and every cross-vehicle read, which proves Phase 19 only needs a
   new policy and the data behind it.
4. No migrations. Definition of done (CLAUDE.md §11) holds.

---

## Changed while building it

spec.md §5 *Access policy* and what follows it is the current text.

- **Entry edits and deletes need `Manage`,** not `Log`. `Log` was to cover
  editing one's own entries, but nothing records who logged an entry yet
  (no migrations in this phase), so every entry edit and delete asks for
  `Manage` until Phase 19 records the author.
- **The middleware sits on the whole signed-in group,** not the
  `/vehicles/{id}` group: the vehicle page, its edit, archive and photo
  routes, the odometer and fuel routes, History and the sale pack are
  outside that group. It acts on any route whose pattern has `{id:`, loads
  the vehicle by id alone (`VehicleRepository::findById()`) and asks the
  policy, so a policy can grant someone else's vehicle; a route with `{id}`
  and no ability is a 500, never an open door. It is added by class name,
  so a test can swap the policy before the first request.
- **Instance pages declare their ability like vehicle routes** (the route
  argument `instance`, read by `InstanceAccessMiddleware`), instead of each
  Action checking, so the route inventory sees them.
- **Settings → Reminders stays personal.** The plan put its channel part
  under the instance policy, but the channels one is notified on, the email
  address, the digest and the test message are all stored per user; the
  channels' servers are environment variables with no page.
  `ManageNotifications` exists and guards nothing yet.
- **Reminder routes declare an ability too** (`Log` for done, dismiss and
  reopen; `Manage` to edit or delete), read from the route by
  `ReminderRoute`, and the inventory checks them with the vehicle routes.
  Manual reminders can be created only for vehicles one may `Manage`.
- **A fifth inventory class, *fleet*:** the garage, dashboard, History,
  *Coming up*, Reports, the reminder list and the log pickers are none of
  public, personal, instance or vehicle; they list the policy's vehicles.
  The pickers in front of a log form list only vehicles with `Log` (or
  `Manage` for a service interval).
- **No repository lists a user's vehicles.** `VehicleRepository` has
  `idsOwnedBy()` (behind the single-owner policy only), `listByIds()` and
  `findById()`; `ReminderRepository` takes vehicle ids. Writes to a vehicle
  are keyed by the vehicle's own owner, so a granted user's save lands.
  Every list short-circuits an empty id list (no `IN ()`).
- **Memoised per request and after writes.** The visible ids are
  remembered per user and scope; `CurrentUserMiddleware` and the scheduler
  drop them at the start of a request or run, and `VehicleService` after
  adding, archiving, restoring or deleting a vehicle.
- **`can_see_costs()` takes a vehicle only** and reads the signed-in user
  from a request-scoped `AccessContext` (like `DisplayContext`), so macros
  can call it. Fleet figures are filtered in the services instead, and the
  template scan lists them as exceptions with the reason. Beyond `|money`,
  the scan covers `unit_price`, `per_distance` and `per_thousand_distance`;
  charts of amounts (the fuel cost trend and price chart, the value chart)
  are behind the same checks, and the policy-double test looks for the
  amounts in the whole HTML and CSVs, so a chart's data would be caught.
- **`can_instance()`** hides the Modules and Backup links on Settings and
  the restore form without the ability.
- **The 403 page says what happened** ("You can open this, but you are not
  allowed to make this change"), en + de, via `AccessDeniedException`.
- **Existing tests changed only where they called removed repository
  methods** (`listForUser()`, `find($userId, $id)`): a helper,
  `ownedVehicles()`, reads the same rows. No assertion changed.
