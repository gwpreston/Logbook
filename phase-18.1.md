# Phase 18.1 — Access policy

*One place that decides who may do what, before anyone else can sign in.*

Status: 📋 planned · no release of its own; ships with Phase 18.2 as
**v1.10.0**

Logbook has one owner, so nothing today asks "may this user see this
vehicle?". The REST API (Phase 18.2) and multiple users (Phase 19) both
need that question answered everywhere. If the API were built first, every
endpoint would have to be revisited when users arrive. This phase adds the
question now, answers it with "yes, it's yours" for the single owner, and
proves every route asks it. Behaviour does not change; no screen or figure
moves.

Read [`CLAUDE.md`](CLAUDE.md) §5 and [`spec.md`](spec.md) §5 first.

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
- [ ] `Domain\Access\VehicleAbility`, `VehicleScope`, `InstanceAbility`
      enums.
- [ ] `Service\Access\VehicleAccess` and `InstanceAccess` interfaces;
      `SingleOwnerVehicleAccess` and `SingleOwnerInstanceAccess`
      implementations, bound in PHP-DI.
- [ ] `visibleVehicleIds()` with one indexed query (`vehicles.user_id`,
      `status`), memoised per request.

### Routing and middleware
- [ ] `Middleware\VehicleAccessMiddleware` on the vehicle route groups:
      reads the route's `ability` argument, loads the vehicle, sets the
      attribute, answers 404 or 403.
- [ ] Declare an ability on every `{id}` route in `config/routes.php`.
      Reads get `View`, cost pages `ViewCosts`, log forms `Log`, edit and
      import `Manage`, archive, delete and restore `Own`.
- [ ] Refactor Actions to read `vehicle` from the request. Remove their own
      vehicle loading.
- [ ] Instance settings Actions check `InstanceAccess`.

### Cross-vehicle reads
- [ ] Garage, dashboard widgets, fleet history (`ActivityFeed`), Reports,
      Ownership report, `ComingUp`, calendar feed and reminder sync take ids
      from the policy. Change repository signatures from "all active" to
      "these ids".
- [ ] Backup stays instance-wide behind `InstanceAbility::Backup`. It is
      not filtered.

### Costs
- [ ] `can_see_costs()` Twig function. Wrap every amount in list, card,
      report and widget templates. Inventory them with a grep test for the
      money filter outside a `can_see_costs` block, with allowed exceptions
      listed in the test.
- [ ] Report, ownership and forecast services skip vehicles without
      `ViewCosts`.

### Tests
- [ ] **Route inventory test:** loads the Slim route collector. Every route
      is either public (`/health`, setup, sign-in, the calendar feed by
      token, assets), signed-in-only (personal settings), an instance
      ability, or a vehicle route with a declared ability. An unclassified
      route fails the build and names itself.
- [ ] **Policy test double:** a `ConfigurableVehicleAccess` for tests. With
      it, each vehicle route is exercised with no access (404), view only
      (403 on writes), no costs (no amounts in the HTML, no vehicle in
      report totals), and full access (unchanged).
- [ ] A second owner row inserted directly in the test DB never appears in
      any list, report, feed, widget or search result, and their vehicle and
      attachment ids answer 404.
- [ ] The whole existing suite passes unchanged: no behaviour change for the
      single owner.
- [ ] Integration suite green on every engine.

---

## Acceptance criteria

1. Every route is classified by the inventory test, and adding a route
   without an ability fails CI.
2. With the single-owner policy, nothing visible changes.
3. With the test policy, access and cost visibility are enforced on every
   route and every cross-vehicle read, which proves Phase 19 only needs a
   new policy and the data behind it.
4. No migrations. Definition of done (CLAUDE.md §11) holds.
