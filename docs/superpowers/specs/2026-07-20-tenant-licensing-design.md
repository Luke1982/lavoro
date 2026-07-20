# Tenant Licensing — Design

**Status:** approved design, not yet planned or built
**Depends on:** `docs/superpowers/plans/2026-06-09-multi-database-tenancy.md` — none of which is implemented yet

## What this is

Every customer company (a "tenant") buys a package. The package decides how many users they get and what they pay. They can buy extra users on top, and they can subscribe to paid modules.

Each tenant also gets 50 GB of file storage, raisable per tenant, with the extra above 50 GB billed per gigabyte.

This design covers the data model, how the limits are enforced, how the monthly price is calculated, and the Artisan commands to manage it all.

A **landlord admin sub-app** on its own subdomain gives the same control in a browser. It is a thin visual layer over the same commands and the same pricing service — no business logic of its own.

## What this is not

- **Not quoting or invoicing.** `quotes` and `invoices` become subscribable modules, but neither feature exists in the app. You are building the switch, not the light.
- **Not billing.** No payment provider, no invoices sent to customers, no payment tracking. The system computes what a tenant owes each month; you bill them outside the app.
- **Not customer-facing.** The landlord sub-app has no public pages, no pricing page, no signup. It is an internal admin tool behind a login. Self-service signup and a public price list are explicitly out of scope and would each need their own spec.

## Packages

Four packages. "Field" is buitendienst, "office" is kantoor.

| Package | Field seats | Office seats | Price / month | Extra field | Extra office |
| --- | --- | --- | --- | --- | --- |
| Starter | 1 | 1 | €27,50 | €12,00 | €8,00 |
| Team | 5 | 2 | €87,50 | €11,00 | €7,50 |
| Business | 10 | 4 | €160,00 | €10,00 | €7,00 |
| Enterprise | 15 | 6 | €230,00 | €9,50 | €6,50 |

Add-on seats get cheaper as the package grows, and so does the price per included user:

| Package | Included users | Price | Per user |
| --- | --- | --- | --- |
| Starter | 2 | €27,50 | €13,75 |
| Team | 7 | €87,50 | €12,50 |
| Business | 14 | €160,00 | €11,43 |
| Enterprise | 21 | €230,00 | €10,95 |

Modules:

| Module | Price / month |
| --- | --- |
| Offertes (`quotes`) | €27,50 |
| Facturen (`invoices`) | €27,50 |
| Both together | €40,00 |

The existing feature toggles — `snelstart`, `google_calendar`, `projects`, `tickets`, `location_tracking` — live in the same module list at €0. That keeps one list and one `hasModule()` check rather than splitting paid and free.

Storage:

| | Amount | Price / month |
| --- | --- | --- |
| Included with every package | 50 GB | — |
| Extra, per GB above 50 | per GB | a configurable per-GB rate |

The included amount (50 GB) and the per-GB rate are two global values, editable like package prices. Billing is on the tenant's *allowance* above 50 GB, not on live usage — the same principle as seats (you pay for what you are allotted, not for the bytes currently on disk). This avoids surprise usage-based bills.

### Why Business and Enterprise cost more than first proposed

Two rules constrain these numbers, and the originally supplied figures could not satisfy both:

1. **Expanding is always cheaper than upgrading to the next package with the same coverage.** "Same coverage" means adding exactly enough seats to match the next package's seat counts — for Team, 5 extra field and 2 extra office to reach Business's 10/4.
2. **Add-on seats get cheaper as the package grows.** A Business customer should never pay more per extra seat than a Team customer.

The figures first supplied (Business €150,00, Enterprise €225,00) broke rule 1 at Team:

```
Team + 5 field + 2 office = 87,50 + 55,00 + 15,00 = €157,50
Business (10 field, 4 office)                     = €150,00   ← cheaper
```

That cannot be fixed by lowering Team's add-on rates. Team's `5 × field + 2 × office` would have to come in under €62,50, and even at Business's own rates (€10,00 / €7,00) it is €64,00 — so any rate that satisfies rule 1 is below Business's and breaks rule 2.

The cause was the package prices, not the rates: Business gave 5 field and 2 office seats more than Team for €62,50, about €8,93 per seat blended, cheaper than any add-on rate in the table. The upgrade was underpriced relative to the seats it bought. Raising Business to €160,00 and Enterprise to €230,00 satisfies both rules with every original rate intact:

```
starter + seats   €83,50   vs team         €87,50    margin  €4,00
team + seats     €157,50   vs business    €160,00    margin  €2,50
business + seats €224,00   vs enterprise  €230,00    margin  €6,00
```

The Team → Business margin is only €2,50. The rule holds, but the two routes are near enough to identical that a customer at that point may as well upgrade — which is the preferable outcome anyway. Business €165,00 / Enterprise €235,00 widens it to €7,50 if a clearer gap is wanted later.

This change also fixes a flat spot in the original list, where Business and Enterprise were both €10,71 per included user. The price per user now falls at every step.

A test asserts both rules for every package, so a future price or rate change that breaks either fails the suite instead of silently mispricing.

## Data model

All naming is English. Dutch appears only in user-facing strings.

### Catalogue — central database

Three tables, seeded by migration, edited by Artisan command.

```
packages
  key                  string, unique      starter | team | business | enterprise
  name                 string              "Starter"
  field_seats          unsigned int        1
  office_seats         unsigned int        1
  price_cents          unsigned int        2750
  extra_field_cents    unsigned int        1200
  extra_office_cents   unsigned int         800
  sort_order           unsigned int
  timestamps

modules
  key                  string, unique      quotes | invoices | snelstart | ...
  name                 string              "Offertes"
  price_cents          unsigned int        2750   (0 for free feature toggles)
  sort_order           unsigned int
  timestamps

module_bundles
  name                 string              "Offertes + Facturen"
  module_keys          json                ["quotes","invoices"]
  price_cents          unsigned int        4000
  timestamps

pricing_settings                           key-value, for scalars that are not packages/modules
  key                  string, unique      included_storage_gb | storage_extra_per_gb_cents
  value                unsigned int        50 | (the per-GB rate in cents)
  timestamps
```

`module_bundles.module_keys` is JSON rather than a pivot table. There is one bundle, it is managed by CLI, and a pivot buys nothing here.

`pricing_settings` holds the two storage scalars that do not belong to any package or module row. Seeded with `included_storage_gb = 50` and a starting `storage_extra_per_gb_cents`, edited with `pricing:set`.

### Tenant record — central database

Replaces `license_type` from the tenancy plan's Task 16. Basic/Premium/Enterprise does not survive contact with the real packages and is deleted, not migrated.

```
tenants
  package_key           string      → packages.key
  extra_field_seats     unsigned int, default 0
  extra_office_seats    unsigned int, default 0
  modules               json        ["quotes","invoices"]  — module keys
  price_override_cents  unsigned int, nullable
  storage_limit_gb      unsigned int, default 50
```

`package_key` and `modules` hold **keys, not foreign keys**. The tenant row is read on every request; keeping it a single row read with no join is worth more than referential integrity here. The cost is that a catalogue row could be deleted out from under a tenant, so `package:delete` and `module:delete` refuse while any tenant still references them and print which tenants those are.

`storage_limit_gb` is the tenant's total allowance, default 50, raisable per tenant. The billable portion is `storage_limit_gb − included_storage_gb` (see pricing).

### Storage usage counter — tenant database

Current usage is a single running total, kept in the tenant's own `general_settings` table under the key `storage_used_bytes` (the existing `GeneralSetting::get`/`set` helper — no new table). It lives in the tenant DB, not central, because uploads happen in tenant context and incrementing a counter in the same database avoids a cross-database write on every upload. A nightly job recomputes it from disk to correct drift (see Enforcement).

### User record — tenant database

```
users
  seat_type   string   field | office
```

`seat_type` has no default. It is a required field when creating a user, chosen explicitly in the form — the person adding a user is the person who knows which kind it is, and a default would quietly bill the wrong bucket. The only place a value is assigned automatically is the backfill for existing users (see below).

## Seat types mean something

A seat type is not a billing label. If it were, a customer on 5 field seats could mark three field workers as "office", keep scheduling them, and pay for five while using eight.

So the seat type controls capability:

| | `field` | `office` |
| --- | --- | --- |
| Appears in the planner | yes | no |
| Can be set `plannable` | yes | no |
| Assignable as executing user on an event | yes | no |
| Everything else (admin, customers, service orders, …) | yes | yes |

Marking a field worker as office removes them from the planner and makes them unassignable. The dodge costs the customer the exact thing they wanted, so it is not worth attempting.

`plannable` stays as a separate column, now gated: it can only be true for a field seat, and switching a user to office forces it false. This preserves the current ability to have a field worker who is temporarily not scheduled, without letting office users into the planner.

## Enforcement

### Seat limits

A seat limit is a validation concern — "is there room" — not an authorization concern. It goes in `rules()` as a `SeatAvailable` rule, and the frontend displays it through `form.errors` like any other validation error.

```
limit  = packages.field_seats + tenants.extra_field_seats
in use = users where seat_type = 'field' and deleted_at is null
```

Applied in:

| Request | Case |
| --- | --- |
| `UserStoreRequest` | creating a user |
| `UserUpdateRequest` | switching a user from office to field |
| `UserRestoreRequest` | restoring a soft-deleted user takes a seat back |

Soft-deleted users do not consume a seat — they cannot log in.

The limit lives in the central database and the count lives in the tenant database, but both are available during a tenant request: the limit comes from the already-loaded `tenancy()->tenant`, the count from a query on the tenant's own `users` table. No cross-database query.

Message shown to the user:

> Uw licentie staat 5 buitendienstgebruikers toe. Neem contact op om uit te breiden.

### Over-limit tenants

A tenant can exceed its limits — when an existing customer is imported, or if you downgrade their package. When that happens:

- Every existing user keeps working and keeps logging in. Nothing is ever taken away.
- Only *new* seats are refused.
- `tenant:overview` flags the tenant as over limit so you can have the sales conversation.

### Capability enforcement

| Request | Rule |
| --- | --- |
| `UpdateUserPlannableRequest` | reject `plannable = true` for an office seat |
| `EventStoreRequest` | reject office users in the executing-user list |
| `EventUpdateRequest` | same |

### Storage limits

A `StorageQuota` service is the single point of truth:

```
usedBytes()      GeneralSetting::get('storage_used_bytes', 0)  — tenant DB
limitBytes()     tenancy()->tenant->storage_limit_gb × 1024³   — central, already loaded
remainingBytes() limitBytes() − usedBytes()
assertRoomFor(n) throws / returns false when usedBytes() + n > limitBytes()
add(n) / subtract(n)   adjust the counter
```

Enforcement is a `WithinStorageQuota` validation rule on the file-upload requests — image upload, avatar, document, company logo — so an over-quota upload fails as a normal validation error the frontend shows through `form.errors`, in Dutch:

> Uw opslaglimiet van 50 GB is bereikt. Neem contact op om uit te breiden.

After a file is stored, the upload path calls `add(bytes)`; on delete, `subtract(bytes)`. Consistent with the seat philosophy: **new uploads are blocked, existing files are never deleted.** A tenant whose limit was lowered below current usage keeps every file and simply cannot add more.

Like seats, the check needs no cross-database query during a tenant request: the limit is on the already-loaded central `tenancy()->tenant`, the counter is in the tenant's own `general_settings`.

### Nightly reconcile

The running counter can drift — a file written through a path that forgot to call `add()`, a manual deletion on disk, a failed upload that stored bytes but errored before accounting. A nightly per-tenant job recomputes the true size of `storage/tenant-<id>/public` plus `.../local` and overwrites `storage_used_bytes`. This is the authoritative correction; the counter between reconciles is a fast, slightly-approximate cache. The job follows the per-tenant dispatch pattern used by the other scheduled work (tenancy plan Task 20).

## Price calculation

One `TenantSubscription` service. **Integer cents throughout** — no floats anywhere near money.

```
  package price       price_override_cents ?? packages.price_cents
+ extra field seats   extra_field_seats  × packages.extra_field_cents
+ extra office seats  extra_office_seats × packages.extra_office_cents
+ extra storage       max(0, storage_limit_gb − included_storage_gb) × storage_extra_per_gb_cents
+ modules             see below
= monthly total
```

`price_override_cents` replaces **only the package price**. Extra seats, extra storage and modules still compute on top, so a negotiated discount on the package does not accidentally make them free.

Modules: if a tenant has every module in a bundle, that bundle's price replaces those modules' individual prices. So a tenant with Offertes and Facturen pays €40,00, not €55,00. Each module is charged once. If more than one bundle ever exists, apply the one with the largest saving first.

Worked example:

```
Acme — Business, +5 field, +2 office, quotes + invoices

  base                       €160,00
  5 extra field × €10,00     € 50,00
  2 extra office × €7,00     € 14,00
  quotes + invoices (bundle) € 40,00
  -------------------------------
  total / month              €264,00
```

Acme is on the default 50 GB, so there is no storage line. A tenant raised to 120 GB, with a €0,50/GB rate, would add `(120 − 50) × €0,50 = €35,00`.

## Landlord sub-app

A small internal admin, on its own subdomain (`beheer.lavorofsm.nl`), for managing the price catalogue and every tenant's subscription in a browser. It is the visual counterpart to the commands below and shares all of their logic through the same `TenantSubscription` service and catalogue models — the controllers hold no pricing or limit logic of their own.

### The constraint that shapes it

The rest of the app is *always inside a tenant*. The landlord sub-app is the mirror image: it is **always central, never inside a tenant.** A landlord operates on all tenants at once and belongs to none, so:

- Landlord accounts live in a new central `landlord_users` table, not in any tenant database.
- The landlord routes run in their own group that **does not** carry the `InitializeTenancyBySession` middleware (tenancy plan Task 12). Nothing ever switches to a tenant connection; every query is central.
- Its own auth guard (`landlord`), its own login, its own layout. A landlord is not a tenant user and a tenant user is not a landlord — the two account systems never mix.

### Subdomain, not path prefix

The sub-app is served from a dedicated host (`beheer.lavorofsm.nl`), separate from the tenant app (`app.lavorofsm.nl`). This keeps landlord traffic physically apart from tenant traffic, scopes the tenancy middleware away cleanly by host, avoids a reserved `/landlord` path on the tenant app, and separates the cookie scope. Routing is by subdomain in `routes/landlord.php`, loaded in `bootstrap/app.php` with its own middleware group.

### Stack

Inertia + Vue, like the rest of the app, reusing the shared UI primitives (`ComboBox`, `TextInput`, `ModalDialog`, …). It is a second Inertia root with a distinct landlord layout — no tenant branding, no tenant navigation, no `company` share.

### Screens

All behind the `landlord` guard.

| Route | Purpose |
| --- | --- |
| `GET /login`, `POST /login`, `POST /logout` | landlord authentication |
| `GET /` | all tenants: package, seat usage (in-use / limit), modules, monthly total, over-limit flag |
| `GET /tenants/{tenant}` | one tenant: change package, adjust extra seats, set storage limit, toggle modules, set or clear price override |
| `GET /packages` | list + create/edit/delete packages |
| `GET /modules` | list + create/edit/delete modules |
| `GET /bundles` | list + create/edit/delete bundles |
| `GET /pricing` | edit the storage scalars (`included_storage_gb`, `storage_extra_per_gb_cents`) |

### Price-change confirmation

Editing a catalogue price re-prices every tenant on it, exactly as the CLI does. The UI shows the same blast radius — which tenants change and from what to what — and requires an explicit confirm step before writing. The computation behind both the CLI preview and this screen is the one `TenantSubscription` service; there is no second implementation to drift.

### Seat usage is read cross-tenant

The tenant list needs each tenant's *actual* field/office user counts, which live in each tenant database. The landlord runs central, so this is the one place a per-tenant read is unavoidable: the overview initializes tenancy for each tenant in turn, counts users, and ends tenancy — the same pattern the scheduler uses (tenancy plan Task 20). For a handful of tenants this is fine inline; if the tenant count grows, this becomes a cached nightly roll-up rather than a per-page-load loop. Noted as a scaling lever, not built now.

### Landlord accounts

```
landlord_users              central database
  id
  name
  email        unique
  password
  timestamps
```

Managed by CLI only — there is no landlord self-registration and no UI to create other landlords (you are a small number of internal operators). One command:

```
landlord:create <name> <email> [--password=]
```

## Commands

Catalogue CRUD. `set` creates or updates, which keeps this to three commands per entity and makes them scriptable.

```
package:list
package:set <key> [--name= --field-seats= --office-seats= --price= --extra-field= --extra-office=]
package:delete <key>

module:list
module:set <key> [--name= --price=]
module:delete <key>

bundle:list
bundle:set <name> --modules=quotes,invoices --price=4000
bundle:delete <name>
```

Storage pricing scalars:

```
pricing:list
pricing:set included_storage_gb 50
pricing:set storage_extra_per_gb_cents 50
```

Tenant subscription management:

```
tenant:package <tenant-id> <package-key>
tenant:seats <tenant-id> [--field=+5] [--office=+2]
tenant:modules <tenant-id> [--add=quotes] [--remove=invoices]
tenant:storage <tenant-id> [--limit=120]
tenant:override <tenant-id> [--price=14000|--clear]
tenant:overview
```

`tenant:package` replaces the tenancy plan's `tenant:license`.

A new tenant created with `tenant:create` gets `starter` unless `--package=` says otherwise, and no modules. Starter is the safe default: it is the smallest thing that works, and an under-provisioned tenant complains immediately while an over-provisioned one silently costs you money.

### Price changes show their blast radius

Editing a catalogue price re-prices every tenant on it immediately. That is inherent to storing prices centrally and is the most dangerous thing these commands can do, so the change is shown and confirmed before it is written:

```
$ php artisan package:set business --price=17000

  Business: €160,00 → €170,00
  Affects 2 tenants:
    Acme  €264,00 → €274,00
    Vos   €160,00 → €170,00

  Continue? (yes/no)
```

Use `tenant:override` to hold individual customers at the old price.

### tenant:overview

```
NAAM   PAKKET      FIELD    OFFICE   OPSLAG        MODULES         /MND
Acme   Business    12/15     4/6     31/50 GB      quotes,invoices  €264,00
Spee   Team         12/5 ⚠   3/2 ⚠   48/50 GB      —                €87,50
Vos    Starter       1/1      1/1     3/50 GB       quotes           €55,00
                                                    totaal          €406,50
```

The storage column shows used / limit; the used figure is read from each tenant's own database (see the landlord cross-tenant read note).

## Migration and backfill

**Existing users all become `office`.** Office is the cheaper seat, so this cannot over-bill anyone on day one. You then mark the field staff by hand.

This means that immediately after migration nobody is plannable, which would empty the planner. So the backfill is two steps, and the second is not optional:

1. Migration sets every user to `office` and `plannable = false`.
2. Before going live, set the field staff to `field` and restore their `plannable` flag.

`tenant:setup-existing` prints the user counts by seat type after import so you can see what needs correcting.

## Where this lands in the tenancy plan

| Plan task | Change |
| --- | --- |
| Task 4 — `Tenant` model | new columns (incl. `storage_limit_gb`); `hasModule()` unchanged; add package + subscription relations |
| Task 6 — central migrations | four catalogue tables (`packages`, `modules`, `module_bundles`, `pricing_settings`), seeded; new tenant columns; `license_type` removed |
| Task 8 — tenant migrations | new migration adding `users.seat_type` |
| Task 16 — license and modules | rewritten around packages, modules, pricing catalogue, the `TenantSubscription` service and the CRUD commands; `TenantLicenseType` deleted |
| Task 19 — user requests | `SeatAvailable` rule + capability enforcement |
| Task 21 — `tenant:create` | `--package` instead of `--license` |
| Task 26 — `tenant:setup-existing` | set package, report seat usage after import |
| Task 30 — tests | the pricing invariant test, seat enforcement tests, storage tests |
| new | seat type field in the user form; seat and storage usage shown to the customer |
| new | storage quota: `StorageQuota` service, `WithinStorageQuota` rule wired into the upload paths, the nightly reconcile job, `tenant:storage` and `pricing:*` commands |
| new | the landlord sub-app: `landlord_users` table, `landlord` guard, `routes/landlord.php` on its own subdomain, Inertia landlord layout, the screens above, and `landlord:create` |

Storage enforcement slots in after the per-tenant storage roots exist (tenancy plan Task 14) and reuses the scheduled per-tenant dispatch pattern (Task 20). The landlord sub-app depends on the tenancy plan being far enough along that the central connection, the `tenants` table and the catalogue tables exist. It is built last, after the CLI commands prove the model.

## Testing

- Every package satisfies "expanding is cheaper than upgrading to equivalent coverage". Passes with the rates in this document; it exists to catch a future rate change that breaks it. Load the originally supplied Team rates (€11,00 / €7,50) to confirm the test actually fails.
- Price calculation: each package, with and without extra seats, with and without modules, with and without an override.
- The bundle rule: quotes alone €27,50; invoices alone €27,50; both €40,00.
- Seat limits: at the limit creation fails; under it succeeds; a soft-deleted user frees a seat; restoring consumes one.
- Over-limit tenants: existing users keep working, new ones are refused.
- Capability: an office user cannot be made plannable and cannot be assigned to an event.
- `package:delete` and `module:delete` refuse while a tenant references them.

Storage:

- `StorageQuota::limitBytes()` reflects `storage_limit_gb`; a 50 GB tenant is `50 × 1024³`.
- An upload that fits succeeds and increments the counter; one that would exceed the limit fails validation and does not store.
- Deleting a file decrements the counter.
- A tenant whose limit is below current usage keeps its files but cannot upload.
- The nightly reconcile overwrites a drifted counter with the true on-disk size.
- Extra-storage pricing: at €0,50/GB, a 50 GB tenant adds €0,00; a 120 GB tenant adds €35,00; the monthly total reflects it.

Landlord sub-app:

- A landlord route never initializes tenancy — assert the default connection stays central through a landlord request.
- A tenant user cannot authenticate against the `landlord` guard, and a landlord cannot authenticate against the tenant guard.
- Editing a catalogue price from the UI produces the same total as the CLI for the same change (both go through `TenantSubscription`).
- The tenant overview reports each tenant's real field/office counts, read from that tenant's own database.

## Decisions on record

| Decision | Choice |
| --- | --- |
| Seat classification | new explicit `seat_type` column, set in the app |
| Pricing scope | store prices, compute monthly total; no payment provider |
| Where prices live | central database, managed by Artisan CRUD commands |
| Control surface | CLI, plus a landlord admin sub-app |
| Landlord location | own subdomain (`beheer.lavorofsm.nl`), central context, own `landlord` guard and `landlord_users` table |
| Landlord scope | internal admin only — no public pricing page, no self-service signup |
| Over limit | block new seats, never lock out existing users |
| Storage limit | 50 GB included per tenant, raisable; extra billed per GB above 50 on the allowance, not live usage |
| Storage measurement | running counter in the tenant DB (`storage_used_bytes` general setting), corrected by a nightly per-tenant reconcile |
| Storage over limit | block new uploads, never delete existing files |
| Override scope | package price only |
| Backfill | everyone to `office`, corrected by hand |
| Anti-circumvention | office users cannot be scheduled |
| Naming | English in the backend, Dutch only in UI strings |
| Pricing correction | all original add-on rates kept; Business raised to €160,00 and Enterprise to €230,00 so that expanding stays cheaper than upgrading *and* seats get cheaper as packages grow |
