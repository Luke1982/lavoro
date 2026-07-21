# Multi-Database Multi-Tenancy Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Add multi-database multi-tenancy to Lavoro using `stancl/tenancy` v3, where a single domain serves all client companies and the correct database is chosen based on who logs in. The central ("landlord") database also holds the licensing model: each tenant's package, extra seats, module subscriptions and storage allowance, a price catalogue that computes each tenant's monthly total, and a landlord admin sub-app (on `beheer.lavorofsm.nl`) to manage it all. Licensing is designed in `docs/superpowers/specs/2026-07-20-tenant-licensing-design.md`; Tasks 4, 6, 16, 19, 21, 26 and 33–37 implement it.

> **Revised 2026-07-03** against the current codebase. Changes from the original 2026-06-09 version:
>
> - **Task 24 (API) simplified.** The app uses `$middleware->statefulApi()` and there is no `createToken()` call anywhere — every API client (SPA and the Android app) authenticates with stateful Sanctum session cookies. The tenant therefore comes from the session on API requests too; the `X-Tenant-ID` header is kept only as a fallback for future bearer-token clients. The Inertia-prop + axios-default-header frontend steps are dropped.
> - **Task 25 (Google webhook) fixed.** `RenewWatchChannelsJob` *and* `BackfillCalendarJob` already generate a random `watch_channel_token` that `GoogleWebhookController` validates with `hash_equals`. The original plan overwrote that token with a bare tenant id, silently removing the security check and missing the second creation site. Now the token is `"<tenant_key>|<random>"` — the webhook parses the prefix to pick the tenant and the full-token validation stays intact.
> - **Remember-me preserved.** Login currently calls `Auth::attempt(..., true)`. The original plan dropped remember-me; this version stores the tenant id in a long-lived encrypted cookie so the recaller keeps working (Tasks 12 & 15).
> - **Task 16 repurposed.** `LoginPage.vue` no longer renders company branding (it uses the static `/img/logo-neg.svg`), so the old "null-guard the login page" task is obsolete. The `company` prop passed by `AuthController::create()` is dead code and is removed in Task 15. Task 16 now implements the landlord license type + module subscriptions.
> - **Task 19 made concrete.** The user routes use `UserStoreRequest` and `UserUpdateRequest` (see `UserController.php:48,61,97`); `UserUpdateRequest` is also used for `me.update` where no route user exists — the ignore logic accounts for that.
> - **Task 23 seeder updated** for the stage flags added since June (`is_invoiced_state`, 2026-06-17; `is_incomplete_state`, 2026-06-19).
> - **Service worker excluded from caching `/files/`** (new step in Task 14). `public/service-worker.js` caches same-origin GETs cache-first; `/files/images/{id}` ids are per-tenant auto-increments, so the same URL means different files in different tenants — cached responses could leak across tenants on a shared browser.
> - **Central migration filenames re-dated** to `2026_07_03_*` (a real tenant migration dated `2026_06_09` now exists), and migration counts refreshed (176 files today: 2 stay central, 174 move to `tenant/`). *(Superseded by the 2026-07-20 revision below — those counts and dates are no longer current.)*
> - **New code checked and covered:** FCM push notifications (`device_tokens`, `NewServiceOrderAssigned` — queued, so the queue bootstrapper carries tenant context; Firebase credentials are global env, see Known impact), event executions, plan groups, freeform materials, user roles (all ordinary tenant tables), the APK download / assetlinks routes (read `storage_path('app/releases/...')` directly, which the filesystem bootstrapper does not touch — they stay global by design).
> - **Task 29 added:** a repeatable runbook for migrating customers who run their own dedicated-subdomain install (e.g. `spee.lavorofsm.nl`) into the multi-tenant app at `app.lavorofsm.nl`.
>
> **Revised again 2026-07-10** to close out four items previously left as "Known impact" follow-up work:
> - **Task 20 (scheduler) reworked.** Two of the three scheduled ticks used to run a query or delete inline, per tenant, inside the scheduler process. They now only dispatch a queued job per tenant — the tick's cost no longer scales with data volume, addressing the old Known impact point 6.
> - **Task 30 added.** Tests move off SQLite (incompatible with multi-database tenancy) onto a dedicated, clearly-named MySQL test database, with a hard runtime assertion and a narrow-grant MySQL user as independent layers guaranteeing a test run can never reach a live database. Closes the old Known impact point 1.
> - **Task 31 added.** Module subscriptions are now actually enforced — a `tenant.module` route middleware on the SnelStart, Google Calendar, Tickets, and Projects routes, plus matching frontend gating. Closes the old Known impact point 5.
> - **Task 32 added.** Microsoft Graph mail credentials move from a single global env-configured mailbox to per-tenant `general_settings`, with a fallback to the global credentials and a fix for `MailManager`'s mailer caching across tenants on long-running queue workers. Narrows the old Known impact point 4 to just SnelStart and Firebase, which remain global.
>
> **Revised again 2026-07-20** after re-verifying every claim against the codebase. Four of these are correctness bugs that would have broken the implementation, not drift:
>
> - **Task 12 fixed — middleware ordering was wrong (critical).** `$middleware->web(append: [...])` puts the tenancy middleware *after* `SubstituteBindings`, which is the last entry of Laravel 12's default web group (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:485-491`). Route-model binding would then have resolved every `{serviceorder}`, `{customer}`, … against the **central** database and 404'd the entire app. The middleware is now placed with `$middleware->priority([...])`, between `StartSession` and `AuthenticatesRequests`, which is the only way to guarantee ordering across group + route middleware. Task 24 gets the same treatment.
> - **Task 30 fixed — the test transaction wrapped the wrong connection.** stancl's `DatabaseManager::connectToTenant()` hardcodes the tenant connection name to `tenant` (`setDefaultConnection('tenant')`), so `DB::connection('mysql')->beginTransaction()` began a transaction on a *non-tenant* connection: tenant writes would never have rolled back and tests would have polluted each other. Now `DB::connection('tenant')`. Also: `tenant_connection_name` is not a real v3 config key and has been dropped from Task 3.
> - **Task 14 widened — six `storage_path('app/public/…')` call sites bypass the disk abstraction.** The old claim "upload code needs no changes" holds only for `Storage::disk()` calls. `ServiceOrderController.php:850`, `ImageController.php:54` and `:258`, `Company.php:52`, and `resources/views/pdf/servicejob.blade.php:232` all build absolute paths by hand and would silently read/write the *central* storage tree under tenancy — PDFs would render without photos and without a logo. `resources/views/emails/event/appointment_confirmation.blade.php:102` uses `asset('storage/…')`, which becomes a dead link once files leave the public symlink; e-mail clients cannot authenticate, so the logo must be embedded. All six are now explicit steps.
> - **Task 18 fixed — `User` uses `SoftDeletes` since 2026-07-07.** The observer's `deleted` hook fires on *soft* delete, which would have released the email in the central lookup while the row still occupied `users.email` in the tenant — the user could then neither log in nor be recreated. Now: soft delete keeps the lookup, `forceDeleted` removes it, `restored` re-adds it.
> - **Task 20 — a fourth schedule exists.** `maintenancecontracts:generate-serviceorders` (hourly, added 2026-07-10) runs `MaintenanceContractServiceOrderGenerator::generateAllDue()` inline in the tick. It is now a per-tenant queued job like the others. Also corrected: `google-renew-watches` is a `Schedule::job(...)`, not a `dispatch()` call.
> - **Counts and locations refreshed.** 211 migrations today (5 stay central, 209 move to `tenant/`); central migrations re-dated `2026_07_21_*` because `2026_07_03` and later dates are now taken, up to `2026_07_20`. 35 test files use `RefreshDatabase`, not 16. `/storage/` appears in **12** Vue/JS files, not 10 (`Components/ServiceOrders/CloseServiceOrderModal.vue` and `Utilities/Utilities.js:273` are new). Sidebar navigation moved out of `MainLayout.vue` into `resources/js/Composables/useSidebarNav.js`. `snelStartEnabled` now exists in exactly one place (`ServiceOrderController.php:326`) and the SnelStart *customer* import route no longer exists — only `imports/snelstart/materials` and `serviceorders/{serviceorder}/send-snelstart`.
> - **Task 26 fixed for soft deletes.** `User::query()->pluck('email')` silently skipped trashed users, whose emails still occupy `users.email` (Laravel's `unique` rule does not know about soft deletes). Now `withTrashed()`, so a trashed user's email stays reserved centrally and the two checks agree.
> - **Task 27 hardened.** Added the missing `php artisan down`, queue-worker stop, `optimize:clear`, and `queue:restart` steps — Step 1 drops the database the running app is connected to.
> - **Package compatibility verified.** `stancl/tenancy` v3.10.0 requires `illuminate/support ^10.0|^11.0|^12.0|^13.0` — Laravel 12 is supported, no version hunting needed.
> - **Custom bootstrapper renamed** from `FilesystemTenancyBootstrapper` to `TenantStorageBootstrapper`, because the package ships a class with the identical basename and the two behave differently (ours deliberately does not suffix `storage_path()`).
>
> **Revised again 2026-07-20 (later that day)** with two decisions from the project owner, plus one bug they surfaced:
>
> - **Central database is `lavoro_tenants`, and no single account reaches everything — never `root`.** Task 2 sets up three identities: `lavoro_app` (web/queue, confined to the central database), `lavoro_provisioner` (passwordless `auth_socket` account used only from the CLI to create databases and users), and one MySQL user per tenant confined to its own database. Cross-tenant isolation is enforced by MySQL, not only by the application.
> - **Task 27 is materially less destructive as a result.** Because central takes a *new* name rather than reusing `lavoro`, the old "drop and recreate the live database to free its name" step is gone. The production database is copied to the tenant name and then left completely untouched, so rollback becomes a code-and-config revert with no database restore. Tasks 21 and 26 also gain a guard refusing to point a tenant at the central database name (`tenant:create "Tenants"` would otherwise derive `lavoro_tenants` and migrate over the tenant registry).
> - **Tasks 12 and 24 fixed — the middleware ended tenancy it did not start.** Both ended tenancy unconditionally after the response. In the test suite the `TestCase` establishes tenancy in `setUp()` and holds an open transaction; the first HTTP request in a test would tear that down, sending every later assertion to the central database. 22 of the 35 test files make requests, so this would have read as inexplicable mass failure. Both middlewares now track `$initialized_here` and end only what they began — which also stops them from ending the tenancy `GoogleWebhookController` establishes for itself.
> - **Task 30 turned into an actual gate for "all tests still pass".** Added Step 0 (record the pre-tenancy baseline — **209 passed, 542 assertions, ~5.9s** on 2026-07-20), a baseline diff in Step 7, a grant-isolation check, a ranked triage list for expected failures (Step 7a), and a regression test for the Task 12 ordering bug that the existing suite structurally cannot catch (Step 7b).

**How it works, in plain terms:**

Today there is one database for everything. After this change there is one small *central* database plus one full database per client company (a "tenant"). When someone logs in, we look up their email in the central database to find which company they belong to, switch every database query to that company's database for the rest of the request, and remember the company in their session (and a long-lived cookie, for remember-me) for later requests.

**What lives where:**

Central database (small, shared infrastructure):
- `tenants` — one row per client company, including its `package_key`, extra seat counts, `storage_limit_gb`, `price_override_cents` and subscribed `modules`
- `packages`, `modules`, `module_bundles`, `pricing_settings` — the price catalogue (seeded), read to compute each tenant's monthly total
- `user_tenant_lookups` — maps an email to a tenant, used only when logging in
- `jobs`, `job_batches`, `failed_jobs` — the queue, so the worker can read jobs without first knowing the tenant
- `cache`, `cache_locks` — shared store; entries are isolated per tenant by a key prefix
- `sessions` — must be readable before we know the tenant, so it stays central

Tenant database (one per client company, fully isolated):
- `users`, `password_reset_tokens`
- Every business table (customers, assets, service orders, tickets, events, projects, materials, device tokens, location pings, plan groups, etc.)
- `roles`, `permissions`, `user_roles`, `companies`, `general_settings`, Google integration tables — everything else

Uploaded files are fully separated on disk too: each tenant's files live under their own root, `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`, and are served only through authenticated controllers (never a public URL). See Task 14. The Android APK under `storage/app/releases/` is global and intentionally unaffected.

**Three hard constraints this design imposes (read before starting):**

1. **Email must be globally unique across all tenants.** Because we find the tenant *from* the email at login, the same email cannot belong to two different companies. This is enforced at user creation (Task 19) and the central lookup table (Task 6).
2. **Sessions stay on the database driver but pinned to the central connection** (`SESSION_CONNECTION=central`, Task 7). The session is read before tenancy is initialized, so it cannot live in a tenant database.
3. **Cache stays on the database driver but is isolated by a per-tenant key prefix** (Task 10). We do *not* use the package's tag-based cache bootstrapper because the `database` cache store does not support tagging. The prefix approach is **cache-driver-agnostic** — it works identically on `file`, `database`, and `redis`, so adopting Redis later is just a `CACHE_STORE=redis` change with no tenancy code touched.

**Current environment (verified):** `DB_CONNECTION=mysql`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`. This plan keeps all of those drivers — no Redis required.

**Database naming and credentials (decided 2026-07-20):**

| | Name |
| --- | --- |
| Central database | `lavoro_tenants` |
| Tenant databases | `lavoro_<slug-or-ulid>` (prefix `lavoro_`, Task 3) |
| Web/queue MySQL user | `lavoro_app`, granted only on `lavoro_tenants` |
| Provisioning MySQL user | `lavoro_provisioner`, no password (`auth_socket`), granted on `` `lavoro\_%` `` + `CREATE USER` |
| Per-tenant MySQL users | `lavoro_<tenant>`, granted only on that tenant's own database |
| Test MySQL user | `lavoro_test`, granted only on `` `lavoro\_test\_%` `` (Task 30) |

The application **never connects as `root`**, and no single credential reaches every database. `lavoro_app` is confined to the central database — it cannot read customer data at all. Each tenant's queries authenticate as that tenant's own user, so cross-tenant access is blocked by MySQL rather than only by the application switching connections correctly. `root` is used only for the one-time account creation in Task 2 and for the dump/restore steps in Tasks 27 and 29, where you are acting as an operator rather than as the app.

Two consequences of naming the central database `lavoro_tenants` worth knowing up front:

- It sits *inside* the `lavoro_%` pattern that the provisioner is granted on, so the provisioner can manage both central and tenant databases. `lavoro_app` is scoped to the central database by name, so it is unaffected.
- A tenant whose name slugs to exactly `tenants` would produce the database name `lavoro_tenants` and collide with central. Tasks 21 and 26 add an explicit guard against this.

**Database isolation is enforced by MySQL, not only by the application.** Each tenant gets its own MySQL user that can reach only its own database (Task 2/3). The web app's own credentials reach only the central database. So a bug that fails to switch tenant context produces a permission error rather than another customer's data. The account that can create databases and users authenticates by Unix socket as a dedicated Linux user (`auth_socket`) and has **no password stored anywhere** — provisioning is a deliberate CLI action, impossible from a web request.

**Prerequisites:**
- MySQL (multi-database tenancy does not work on SQLite)
- MySQL running on the same host as the app, reachable over its Unix socket — required for the passwordless provisioner account (Task 2)
- `root` (or another admin account) available once, to create the `lavoro_app` and `lavoro_provisioner` accounts in Task 2
- A full database backup before running the one-time deployment (Task 27)

---

## Task 1: Install the stancl/tenancy package

**Files:** `composer.json`, `config/tenancy.php` (published, replaced in Task 3), `app/Providers/TenancyServiceProvider.php` (published, replaced in Task 11)

- [ ] **Step 1: Add the package**

```bash
composer require stancl/tenancy:"^3.0"
```

If composer reports a Laravel 12 conflict, check the package's GitHub releases for the newest compatible tag and require that instead.

- [ ] **Step 2: Run the installer**

```bash
php artisan tenancy:install
```

This publishes `config/tenancy.php`, `app/Providers/TenancyServiceProvider.php`, and a stub tenants migration. We replace all three.

- [ ] **Step 3: Delete the stub tenants migration (we write our own in Task 6)**

```bash
rm database/migrations/*_create_tenants_table.php 2>/dev/null; true
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/tenancy.php app/Providers/TenancyServiceProvider.php
git commit -m "chore: install stancl/tenancy package"
```

---

## Task 2: Database connections and the three MySQL identities

The default `mysql` connection gets switched to the tenant's database on every tenant request. We add a `central` connection that is never switched, and a `provisioner` connection used only for creating and destroying tenants.

**Three separate MySQL identities, each with the least privilege it can do its job with:**

| Identity | Authenticates by | Can reach | Used by |
| --- | --- | --- | --- |
| `lavoro_app@127.0.0.1` | password in `.env` | **only** `lavoro_tenants` (central) | the web app and queue workers |
| `lavoro_provisioner@localhost` | **no password** — Unix socket | all `lavoro_%` databases, plus `CREATE USER` | `tenant:create` / `tenant:delete`, run from the CLI as a specific Linux user |
| `lavoro_<tenant-id>@%` | generated password, stored encrypted on the tenant row | **only that tenant's own database** | tenant requests, via the per-tenant connection |

Why this shape:

- **The web app can no longer reach any tenant database with its own credentials.** Tenant queries authenticate as that tenant's user. A bug that fails to switch tenant context now hits a MySQL permission error instead of returning another customer's data — the isolation stops depending on the application being correct.
- **The privileged account has no password to steal.** MySQL's `auth_socket` plugin authenticates by *operating-system user identity* over the local socket. Only the Linux user named `lavoro_provisioner` can use it. There is no secret in `.env`, no secret on disk. The web server runs as `www-data`, so even a fully compromised app cannot create or drop databases or users.
- **Per-tenant credentials are low value.** Each opens exactly one customer's database and (per the package's default grant list) cannot create, drop, or grant anything.

**Requires MySQL on the same machine as the app** — `auth_socket` works over the Unix socket only. Verified present at `/var/run/mysqld/mysqld.sock`. If the database ever moves to its own host, this task needs revisiting (client certificates or a root-only credentials file).

**Files:** `config/database.php`, `.env`, `.env.example`

- [ ] **Step 1: Create the app user, restricted to the central database (run once as root, per environment)**

```sql
CREATE USER IF NOT EXISTS 'lavoro_app'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON `lavoro_tenants`.* TO 'lavoro_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Note this is **not** a wildcard grant. `lavoro_app` gets the central database and nothing else.

- [ ] **Step 2: Create the provisioner — Linux user first, then a passwordless MySQL user bound to it**

```bash
sudo adduser --system --group --no-create-home lavoro_provisioner
```

```sql
CREATE USER IF NOT EXISTS 'lavoro_provisioner'@'localhost' IDENTIFIED WITH auth_socket;
GRANT ALL PRIVILEGES ON `lavoro\_%`.* TO 'lavoro_provisioner'@'localhost' WITH GRANT OPTION;
GRANT CREATE USER ON *.* TO 'lavoro_provisioner'@'localhost';
FLUSH PRIVILEGES;
```

The escaped underscores in `` `lavoro\_%` `` are load-bearing: an unescaped `_` is a MySQL single-character wildcard, so `lavoro_%` would also match `lavoroX`, widening the grant beyond the namespace.

`WITH GRANT OPTION` is required because this account grants each new tenant user rights on its own database. `CREATE USER` must be granted at `*.*` — MySQL does not accept it scoped to a database pattern.

If `auth_socket` is unavailable, install it once: `INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';` (on MySQL 8 the plugin may be named `auth_socket` or `unix_socket` depending on the build).

- [ ] **Step 3: Verify the identities behave as intended**

```bash
# Provisioner works only as its own Linux user, and needs no password:
sudo -u lavoro_provisioner mysql -e "SELECT current_user();"        # → lavoro_provisioner@localhost
mysql -u lavoro_provisioner -e "SELECT 1;"                          # → must FAIL (wrong OS user)

# The app user is confined to central:
mysql -u lavoro_app -p -e "SHOW DATABASES;"                         # → only lavoro_tenants
mysql -u lavoro_app -p -e "CREATE DATABASE lavoro_nope;"            # → must FAIL
```

The second command failing is the whole point: it proves the privilege is tied to OS identity, not to a string anyone can copy.

- [ ] **Step 4: Point `.env` at the central database and the app user**

```
DB_CONNECTION=mysql
DB_DATABASE=lavoro_tenants
DB_USERNAME=lavoro_app
DB_PASSWORD=<strong-password>
DB_SOCKET=/var/run/mysqld/mysqld.sock
```

Mirror the non-secret keys into `.env.example`. `DB_DATABASE` now names the **central** database. `DB_SOCKET` is needed by the provisioner connection below; the `mysql`/`central` connections keep using TCP via `DB_HOST`.

- [ ] **Step 5: Add the `central` and `provisioner` connections after the `mysql` block**

```php
'central' => [
    'driver'      => 'mysql',
    'url'         => env('DB_URL'),
    'host'        => env('DB_HOST', '127.0.0.1'),
    'port'        => env('DB_PORT', '3306'),
    'database'    => env('DB_DATABASE', 'lavoro_tenants'),
    'username'    => env('DB_USERNAME', 'lavoro_app'),
    'password'    => env('DB_PASSWORD', ''),
    'unix_socket' => '',
    'charset'     => env('DB_CHARSET', 'utf8mb4'),
    'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'      => '',
    'prefix_indexes' => true,
    'strict'      => true,
    'engine'      => null,
    'options'     => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
],

// Used only by tenant:create / tenant:delete / tenant:provision-user, which must be
// run as: sudo -u lavoro_provisioner php artisan <command>
// Authenticates by OS user over the Unix socket — deliberately has no password.
'provisioner' => [
    'driver'      => 'mysql',
    'host'        => null,
    'port'        => null,
    'database'    => env('DB_DATABASE', 'lavoro_tenants'),
    'username'    => 'lavoro_provisioner',
    'password'    => '',
    'unix_socket' => env('DB_SOCKET', '/var/run/mysqld/mysqld.sock'),
    'charset'     => env('DB_CHARSET', 'utf8mb4'),
    'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'      => '',
    'prefix_indexes' => true,
    'strict'      => true,
    'engine'      => null,
    'options'     => [],
],
```

`host` and `port` are `null` so PDO uses the socket rather than TCP — a TCP connection would be `lavoro_provisioner@127.0.0.1`, a *different* MySQL account that does not exist, and would fail.

- [ ] **Step 6: Commit**

```bash
git add config/database.php .env.example
git commit -m "feat(tenancy): add central and provisioner database connections"
```

---

## Task 3: Replace `config/tenancy.php`

The bootstrappers list is deliberate: the package's `DatabaseTenancyBootstrapper` and `QueueTenancyBootstrapper` are used as-is, but instead of the package's tag-based cache bootstrapper we register our own prefix-based one (built in Task 10), and we do not use the package filesystem bootstrapper (file isolation is done by disk-root repointing in Task 14 — our class is named `TenantStorageBootstrapper` precisely so it is never confused with the package's `FilesystemTenancyBootstrapper`, which additionally suffixes `storage_path()` and which we deliberately avoid).

**The `mysql` manager is `PermissionControlledMySQLDatabaseManager`** (namespace `Stancl\Tenancy\TenantDatabaseManagers\`, verified against the v3.10 source — note it is *not* under `Database\Drivers`, which does not exist). It extends the plain `MySQLDatabaseManager` and additionally implements `ManagesDatabaseUsers`, so creating a tenant also creates a MySQL user scoped to that tenant's database, and deleting a tenant drops it. Its default grant list is data-manipulation only:

```
ALTER, ALTER ROUTINE, CREATE, CREATE ROUTINE, CREATE TEMPORARY TABLES, CREATE VIEW,
DELETE, DROP, EVENT, EXECUTE, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT,
SHOW VIEW, TRIGGER, UPDATE
```

Those apply *within the tenant's own database only* — no `CREATE USER`, no `GRANT OPTION`, no ability to reach another database. `DROP` here means dropping tables inside its own database, which migrations need; it does not permit `DROP DATABASE`. Override `PermissionControlledMySQLDatabaseManager::$grants` in a service provider if you ever want to narrow it further.

The `env('TENANCY_MYSQL_MANAGER', ...)` indirection exists so the test suite can fall back to the plain `MySQLDatabaseManager` (Task 30) — creating a MySQL user per test run would require granting the test account `CREATE USER`, which would widen exactly the privilege that task works to keep narrow.

Two things about the tenant connection, verified against the v3.10 source, that the rest of the plan depends on:

- `DatabaseManager::connectToTenant()` calls `setDefaultConnection('tenant')` — the tenant connection name is **hardcoded to `tenant`** in v3 and is not configurable. Anywhere you need the tenant connection by name (notably the test transactions in Task 30), it is `'tenant'`, never `'mysql'`.
- `RevertToCentralContext` sets the default connection back to `tenancy.database.central_connection`, i.e. `central`. So outside tenancy the default connection is `central`, not `mysql`; both point at the same database (Task 2), so this is invisible in practice.

Note there is **no** `queue.connections.database.central` flag set in Task 9. That is intentional: `QueueTenancyBootstrapper::getPayload()` returns an empty payload for connections marked `central`, which would strip the `tenant_id` from every job and break tenant-aware queued work. Pinning the queue *tables* to the central database (Task 9) is a different thing from marking the queue connection `central`.

**Files:** `config/tenancy.php`

- [ ] **Step 1: Replace the entire file**

```php
<?php

use App\Tenancy\PrefixCacheBootstrapper;
use App\Tenancy\TenantStorageBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

return [
    'tenant_model' => App\Models\Tenant::class,

    'central_domains' => [],

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        PrefixCacheBootstrapper::class,
        TenantStorageBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => env('DB_CONNECTION', 'mysql'),
        'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_'),
        'suffix' => '',
        'managers' => [
            'mysql'   => env('TENANCY_MYSQL_MANAGER', Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class),
            'mariadb' => env('TENANCY_MYSQL_MANAGER', Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class),
            'pgsql'   => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
        ],
    ],

    'features' => [],

    'migration_parameters' => [
        '--force'    => true,
        '--path'     => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\Seeders\TenantDatabaseSeeder',
    ],
];
```

- [ ] **Step 2: Commit**

```bash
git add config/tenancy.php
git commit -m "feat(tenancy): configure bootstrappers and migration path"
```

---

## Task 4: Create the `Tenant` model

Represents one client company; lives in the central database. The MySQL database name is stored in the JSON `data` column under `tenancy_db_name`. Its subscription — `package_key`, `extra_field_seats`, `extra_office_seats`, `modules`, `price_override_cents`, `storage_limit_gb` — is stored as real columns (declared as custom columns so stancl does not fold them into `data`). Module gating on the backend is `tenancy()->tenant->hasModule('...')`. Price and seat *computation* live in the `TenantSubscription` service (Task 16), not on the model — the model only holds the raw subscription data.

**The tenant's own MySQL credentials are also real columns.** `PermissionControlledMySQLDatabaseManager` (Task 3) generates a username and password per tenant and stores them via stancl's internal keys, which map to the attributes `tenancy_db_username` and `tenancy_db_password`. Declaring them in `getCustomColumns()` promotes them from the `data` JSON blob to real columns, which is what makes the `encrypted` cast usable — the password is then written to disk as ciphertext and decrypted transparently by `getPassword()` when the tenant connection is built. Anyone reading the central database directly sees ciphertext, not a working credential.

Note the cast requires `APP_KEY` to be stable: rotating it without re-encrypting makes every tenant's stored password unreadable and every tenant database unreachable. Treat `APP_KEY` as a backup-critical secret from here on.

**Files:** `app/Models/Tenant.php`, `tests/Feature/TenantModelTest.php`

**Interfaces:**
- Produces: `Tenant` (central-connection Eloquent model) with integer-cast columns `extra_field_seats`, `extra_office_seats`, `price_override_cents` (nullable), `storage_limit_gb`, array-cast `modules`, and `hasModule(string): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\TestCase;

class TenantModelTest extends TestCase
{
    public function test_has_module_reads_the_modules_array(): void
    {
        $tenant = new Tenant(['modules' => ['quotes', 'snelstart']]);

        $this->assertTrue($tenant->hasModule('quotes'));
        $this->assertFalse($tenant->hasModule('invoices'));
    }

    public function test_has_module_is_false_when_modules_is_null(): void
    {
        $tenant = new Tenant();

        $this->assertFalse($tenant->hasModule('quotes'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TenantModelTest`
Expected: FAIL — `Class "App\Models\Tenant" not found`.

- [ ] **Step 3: Create the model**

```php
<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $connection = 'central';

    protected $casts = [
        'data'                 => 'array',
        'modules'              => 'array',
        'extra_field_seats'    => 'integer',
        'extra_office_seats'   => 'integer',
        'price_override_cents' => 'integer',
        'storage_limit_gb'     => 'integer',
        'tenancy_db_password'  => 'encrypted',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'package_key',
            'extra_field_seats',
            'extra_office_seats',
            'modules',
            'price_override_cents',
            'storage_limit_gb',
            'tenancy_db_username',
            'tenancy_db_password',
        ];
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TenantModelTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Tenant.php tests/Feature/TenantModelTest.php
git commit -m "feat(tenancy): add Tenant model with package, seats, modules and storage"
```

---

## Task 5: Create the `UserTenantLookup` model

A small central table: given an email, which tenant owns it. Queried only at login and password reset.

**Files:** `app/Models/Central/UserTenantLookup.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class UserTenantLookup extends Model
{
    protected $connection = 'central';
    protected $table = 'user_tenant_lookups';
    protected $primaryKey = 'email';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['email', 'tenant_id'];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Central/UserTenantLookup.php
git commit -m "feat(tenancy): add UserTenantLookup model"
```

---

## Task 6: Create central database migrations

All target the `central` connection. The `user_tenant_lookups.email` primary key is what enforces global email uniqueness across tenants. The catalogue tables (`packages`, `modules`, `module_bundles`, `pricing_settings`) hold the price list and are seeded in the same migration that creates them. Dated `2026_07_21` so they sort after every existing migration — the newest tenant migrations today are `2026_07_20_*`. **Re-check `ls database/migrations/ | tail -1` at implementation time and bump the date past it if newer migrations have landed since**; the split in Task 8 excludes these central migrations by exact filename, so a stale date here means the wrong files move.

**Files:**
- `database/migrations/2026_07_21_000001_create_tenants_table.php`
- `database/migrations/2026_07_21_000002_create_user_tenant_lookups_table.php`
- `database/migrations/2026_07_21_000004_create_licensing_catalogue_tables.php`

**Interfaces:**
- Produces: central tables `tenants` (columns `id`, `name`, `package_key`, `extra_field_seats`, `extra_office_seats`, `modules`, `price_override_cents`, `storage_limit_gb`, `data`, timestamps), `packages`, `modules`, `module_bundles`, `pricing_settings` — all seeded with the price list below.

- [ ] **Step 1: Create the tenants migration**

`storage_limit_gb` defaults to 50 (the included allowance). `package_key` is nullable at the column level so `tenant:setup-existing` (Task 26) can insert a row before the package is assigned; `tenant:create` (Task 21) always sets it.

`tenancy_db_username` and `tenancy_db_password` hold the tenant's own MySQL credentials (Task 4). They are nullable because a tenant registered from an existing database (Task 26) has none until `tenant:provision-user` (Task 38) creates one. `tenancy_db_password` is `text` rather than `string` because the `encrypted` cast stores ciphertext, which is substantially longer than the plaintext password.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('package_key')->nullable();
            $table->unsignedInteger('extra_field_seats')->default(0);
            $table->unsignedInteger('extra_office_seats')->default(0);
            $table->json('modules')->nullable();
            $table->unsignedInteger('price_override_cents')->nullable();
            $table->unsignedInteger('storage_limit_gb')->default(50);
            $table->string('tenancy_db_username')->nullable();
            $table->text('tenancy_db_password')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
```

- [ ] **Step 2: Create the user_tenant_lookups migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('user_tenant_lookups', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('user_tenant_lookups');
    }
};
```

- [ ] **Step 3: Create the licensing-catalogue migration (create + seed)**

Four tables, seeded inline with the price list. All money is integer cents. The free feature toggles are `modules` rows at `price_cents = 0`, so there is one module list and one `hasModule()` check. `included_storage_gb` and `storage_extra_per_gb_cents` are the two storage scalars.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('field_seats');
            $table->unsignedInteger('office_seats');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('extra_field_cents');
            $table->unsignedInteger('extra_office_cents');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('module_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('module_keys');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        Schema::connection('central')->create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('value');
            $table->timestamps();
        });

        $now = now();

        DB::connection('central')->table('packages')->insert([
            ['key' => 'starter',    'name' => 'Starter',    'field_seats' => 1,  'office_seats' => 1, 'price_cents' => 2750,  'extra_field_cents' => 1200, 'extra_office_cents' => 800, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'team',       'name' => 'Team',       'field_seats' => 5,  'office_seats' => 2, 'price_cents' => 8750,  'extra_field_cents' => 1100, 'extra_office_cents' => 750, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'business',   'name' => 'Business',   'field_seats' => 10, 'office_seats' => 4, 'price_cents' => 16000, 'extra_field_cents' => 1000, 'extra_office_cents' => 700, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'field_seats' => 15, 'office_seats' => 6, 'price_cents' => 23000, 'extra_field_cents' => 950,  'extra_office_cents' => 650, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('modules')->insert([
            ['key' => 'quotes',            'name' => 'Offertes',          'price_cents' => 2750, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoices',          'name' => 'Facturen',          'price_cents' => 2750, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'snelstart',         'name' => 'SnelStart',         'price_cents' => 0,    'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'google_calendar',   'name' => 'Google Agenda',     'price_cents' => 0,    'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'projects',          'name' => 'Projecten',         'price_cents' => 0,    'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'tickets',           'name' => 'Storingen',         'price_cents' => 0,    'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'location_tracking', 'name' => 'Locatie volgen',    'price_cents' => 0,    'sort_order' => 7, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('module_bundles')->insert([
            ['name' => 'Offertes + Facturen', 'module_keys' => json_encode(['quotes', 'invoices']), 'price_cents' => 4000, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('pricing_settings')->insert([
            ['key' => 'included_storage_gb',        'value' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'storage_extra_per_gb_cents', 'value' => 50, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('pricing_settings');
        Schema::connection('central')->dropIfExists('module_bundles');
        Schema::connection('central')->dropIfExists('modules');
        Schema::connection('central')->dropIfExists('packages');
    }
};
```

- [ ] **Step 4: Run the central migrations and confirm the catalogue seeded**

Run: `php artisan migrate --database=central --path=database/migrations --realpath` (or the full `php artisan migrate` once the split in Task 8 is done).
Expected: `packages` has 4 rows, `modules` has 7, `module_bundles` has 1, `pricing_settings` has 2.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_21_000001_create_tenants_table.php \
        database/migrations/2026_07_21_000002_create_user_tenant_lookups_table.php \
        database/migrations/2026_07_21_000004_create_licensing_catalogue_tables.php
git commit -m "feat(tenancy): add central DB migrations and licensing catalogue"
```

---

## Task 7: Move the `sessions` table into the central database

The session is read at the very start of every request, before we know the tenant. So the `sessions` table must be in the central database, and the session driver must be pointed at the `central` connection.

The `sessions` table is currently created inside the framework users migration. We remove it from there and create it as its own central migration. Note the central migration keeps `user_id` as a plain indexed column with **no foreign key** — users live in tenant databases.

**Files:**
- `database/migrations/0001_01_01_000000_create_users_table.php` (remove the sessions block)
- `database/migrations/2026_07_21_000003_create_sessions_table.php` (new, central)
- `.env` / `.env.example`

- [ ] **Step 1: Remove the `sessions` block from the users migration**

Open `database/migrations/0001_01_01_000000_create_users_table.php`. It creates three tables: `users`, `password_reset_tokens`, and `sessions`. Delete the entire `Schema::create('sessions', function (Blueprint $table) { ... });` block (and the matching `Schema::dropIfExists('sessions');` in `down()`). Leave `users` and `password_reset_tokens` intact — those belong to the tenant database.

- [ ] **Step 2: Create the central sessions migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sessions');
    }
};
```

- [ ] **Step 3: Point the session driver at the central connection**

Add to `.env` and `.env.example`:

```
SESSION_CONNECTION=central
```

`config/session.php` already reads `'connection' => env('SESSION_CONNECTION')` (verified, line 76), so no config edit is needed. `SESSION_DRIVER=database` stays as-is.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/0001_01_01_000000_create_users_table.php \
        database/migrations/2026_07_21_000003_create_sessions_table.php \
        .env.example
git commit -m "feat(tenancy): move sessions table to central connection"
```

---

## Task 8: Split migrations into central and tenant directories

After this:
- `database/migrations/` holds only central migrations: cache, jobs, tenants, user_tenant_lookups, sessions, and the licensing catalogue. `php artisan migrate` runs these against the central database.
- `database/migrations/tenant/` holds everything else (209 files as of 2026-07-20: the users migration plus 208 dated ones). `php artisan tenants:migrate` runs these against each tenant database. Plain `migrate` does not descend into subdirectories, so these are correctly excluded from the central run.

`0001_01_01_000000_create_users_table.php` (now just users + password_reset_tokens after Task 7) moves to tenant. The cache and jobs framework migrations, and the four `2026_07_21_00000{1,2,3,4}` central migrations from Tasks 6–7, stay central.

**Files:** move ~209 migration files (212 total after Task 6 − 3 framework/catalogue migrations, but the exclusion below keeps 6 central in all).

- [ ] **Step 1: Move the files**

The `000004` catalogue migration (Task 6) is central — it must be in the exclusion list, or `packages`/`modules`/`module_bundles`/`pricing_settings` would be created per-tenant instead of once centrally.

```bash
mkdir -p database/migrations/tenant

git mv database/migrations/0001_01_01_000000_create_users_table.php database/migrations/tenant/

for f in database/migrations/2025_*.php; do
  git mv "$f" database/migrations/tenant/
done

for f in database/migrations/2026_*.php; do
  base=$(basename "$f")
  if [[ "$base" != "2026_07_21_000001_create_tenants_table.php" && \
        "$base" != "2026_07_21_000002_create_user_tenant_lookups_table.php" && \
        "$base" != "2026_07_21_000003_create_sessions_table.php" && \
        "$base" != "2026_07_21_000004_create_licensing_catalogue_tables.php" ]]; then
    git mv "$f" database/migrations/tenant/
  fi
done
```

- [ ] **Step 2: Verify**

```bash
ls database/migrations/*.php
# Expected exactly these 6:
# 0001_01_01_000001_create_cache_table.php
# 0001_01_01_000002_create_jobs_table.php
# 2026_07_21_000001_create_tenants_table.php
# 2026_07_21_000002_create_user_tenant_lookups_table.php
# 2026_07_21_000003_create_sessions_table.php
# 2026_07_21_000004_create_licensing_catalogue_tables.php

ls database/migrations/tenant/ | wc -l   # ~209
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "feat(tenancy): split migrations into central and tenant directories"
```

---

## Task 9: Pin the queue to the central database

Jobs must always be stored centrally so the worker finds them regardless of tenant context. The `QueueTenancyBootstrapper` records the active tenant in each job payload and re-initializes it on the worker, so queued jobs (Google sync, FCM notifications) still run in the right tenant.

**Files:** `config/queue.php`

- [ ] **Step 1: Set all three database references to `central`**

```php
'database' => [
    'driver'       => 'database',
    'connection'   => 'central',
    'table'        => env('DB_QUEUE_TABLE', 'jobs'),
    'queue'        => env('DB_QUEUE', 'default'),
    'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => true,
],
```

```php
'batching' => [
    'database' => 'central',
    'table'    => 'job_batches',
],
```

```php
'failed' => [
    'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => 'central',
    'table'    => 'failed_jobs',
],
```

- [ ] **Step 2: Commit**

```bash
git add config/queue.php
git commit -m "feat(tenancy): pin queue tables to central connection"
```

---

## Task 10: Custom prefix-based cache bootstrapper

The `database` cache store does not support tags, so the package's tag-based `CacheTenancyBootstrapper` cannot be used. Instead we set a per-tenant cache key prefix when a tenant is initialized and restore it when tenancy ends. The shared central `cache` table then holds all tenants' entries, isolated by prefix.

**Files:** `app/Tenancy/PrefixCacheBootstrapper.php`

- [ ] **Step 1: Create the bootstrapper**

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheBootstrapper implements TenancyBootstrapper
{
    protected ?string $original_prefix = null;

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->original_prefix = $this->app['config']->get('cache.prefix');
        $this->app['config']->set('cache.prefix', 'tenant_' . $tenant->getTenantKey());
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
    }

    public function revert(): void
    {
        $this->app['config']->set('cache.prefix', $this->original_prefix);
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
        $this->original_prefix = null;
    }
}
```

`forgetDriver` discards the resolved cache store so it is rebuilt with the new prefix on next use. The prefix is applied to every key by the store regardless of driver — this is what makes the approach driver-agnostic: it works unchanged on the `database` store today and on `redis` if you switch later.

- [ ] **Step 2: Commit**

```bash
git add app/Tenancy/PrefixCacheBootstrapper.php
git commit -m "feat(tenancy): add prefix-based cache bootstrapper for database cache"
```

---

## Task 11: Write the `TenancyServiceProvider`

When a `Tenant` is created, the `TenantCreated` event triggers a job pipeline that creates the database, runs tenant migrations, then seeds. `BootstrapTenancy` / `RevertToCentralContext` are the package listeners that switch the connection in and out of tenant context.

**Files:** `app/Providers/TenancyServiceProvider.php`, `bootstrap/providers.php`

- [ ] **Step 1: Replace `app/Providers/TenancyServiceProvider.php`**

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Support\JobPipeline;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class, MigrateDatabase::class, SeedDatabase::class])
                ->send(fn (TenantCreated $event) => $event->tenant)
                ->shouldBeQueued(false)
                ->toListener()
        );
    }
}
```

- [ ] **Step 2: Register the provider in `bootstrap/providers.php`**

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
];
```

- [ ] **Step 3: Commit**

```bash
git add app/Providers/TenancyServiceProvider.php bootstrap/providers.php
git commit -m "feat(tenancy): register TenancyServiceProvider with tenant-creation pipeline"
```

---

## Task 12: Session-based tenancy middleware (with remember-me cookie fallback)

On every web request, after the session is read, switch to the tenant stored in the session. If the session has no tenant (fresh session revived by the remember-me recaller), fall back to the long-lived `tenant_id` cookie set at login (Task 15) — this is what keeps `Auth::attempt(..., remember: true)` working, because the auth guard resolves the recaller *after* this middleware has already switched the database. Always end tenancy after the response so the connection is not left switched (matters for long-running workers like Octane).

The cookie is encrypted/decrypted automatically by the web group's `EncryptCookies` middleware.

**Files:** `app/Http/Middleware/InitializeTenancyBySession.php`, `bootstrap/app.php`

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyBySession
{
    public function handle(Request $request, Closure $next): mixed
    {
        $initialized_here = false;
        $tenant_id = session('tenant_id') ?: $request->cookie('tenant_id');

        if ($tenant_id && !tenancy()->initialized) {
            $tenant = Tenant::on('central')->find($tenant_id);
            if ($tenant) {
                tenancy()->initialize($tenant);
                $initialized_here = true;
                if (!session('tenant_id')) {
                    session(['tenant_id' => $tenant->id]);
                }
            } else {
                session()->forget('tenant_id');
                cookie()->queue(cookie()->forget('tenant_id'));
            }
        }

        $response = $next($request);

        if ($initialized_here && tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
```

**`$initialized_here` is not defensive padding — without it the test suite breaks.** The naive version ends tenancy unconditionally after the response, including tenancy that something *else* established. In tests (Task 30) the `TestCase` initializes tenancy once in `setUp()` and holds an open transaction on the `tenant` connection; the first `$this->get(...)` in a test would then tear that down on the way out, and every assertion after it — `assertDatabaseHas`, a second request, the rollback in `tearDown` — would run against the central database instead. 22 of the current test files make HTTP requests, so this would have looked like a mass, baffling failure. The same guard keeps the middleware from ending tenancy that `GoogleWebhookController` established for itself (Task 25), since that route lives in the web group too.

- [ ] **Step 2: Add to the web stack in `bootstrap/app.php` — with an explicit priority, not `append`**

This is the single most order-sensitive change in the plan. The middleware must run:

- **after** `StartSession` (it reads `session('tenant_id')`) and after `EncryptCookies` (it reads the encrypted `tenant_id` cookie);
- **before** `SubstituteBindings`, or every route-model binding (`{serviceorder}`, `{customer}`, …) resolves against the **central** database and 404s;
- **before** the `auth` middleware and `HandleInertiaRequests`, both of which touch `Auth::user()` and therefore query the tenant database.

`$middleware->web(append: [...])` does **not** achieve this. Laravel 12's default web group is `EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:485-491`) — appending lands the middleware *after* `SubstituteBindings`, which is exactly the failure above. And relative order between group and route middleware is decided by the framework's priority list anyway, so appending is not even deterministic against `auth`.

Register it in the group **and** pin its position in the priority list:

```php
$middleware->web(append: [
    \App\Http\Middleware\InitializeTenancyBySession::class,
    HandleInertiaRequests::class,
]);

$middleware->priority([
    \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \App\Http\Middleware\InitializeTenancyBySession::class,
    \App\Http\Middleware\InitializeTenancyForApi::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
    \Illuminate\Routing\Middleware\ThrottleRequests::class,
    \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
    \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\EnsureTenantHasModule::class,
    \Illuminate\Auth\Middleware\Authorize::class,
]);
```

This is Laravel 12's stock priority array (`Illuminate\Foundation\Http\Kernel::$middlewarePriority`, lines 103–115) with three insertions: the two tenancy initializers right after `StartSession`, and `EnsureTenantHasModule` (Task 31) after `SubstituteBindings` so a module check can rely on a resolved tenant. `InitializeTenancyForApi` (Task 24) and `EnsureTenantHasModule` (Task 31) do not exist yet — either add this block once both classes exist, or add the entries incrementally as each task lands.

- [ ] **Step 3: Verify the resulting order before moving on**

Do not take this on faith — a wrong order here fails as mass 404s that look like a routing problem, not a tenancy problem:

```bash
php artisan route:list --path=serviceorders -v | head -40
```

`InitializeTenancyBySession` must appear before `SubstituteBindings` and before `auth` in the listed middleware for the route. Then log in and open a detail page (`/serviceorders/{id}`) — a 404 on a record that exists means the order is still wrong.

Note the Google webhook route lives in the web group too; it carries no session or cookie, so this middleware is a no-op there (the webhook resolves its tenant itself, Task 25).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/InitializeTenancyBySession.php bootstrap/app.php
git commit -m "feat(tenancy): initialize tenant from session or remember-cookie on web requests"
```

---

## Task 13: Guard the company Inertia share

`AppServiceProvider` shares company data on every Inertia response, including the login page where no tenant is active. Querying `Company` then hits the central database, which has no `companies` table, and crashes. Return `null` when tenancy is not initialized. The logo URL points at the authenticated file route from Task 14 (not a public `/storage` path), so it resolves per tenant.

**Files:** `app/Providers/AppServiceProvider.php` (the share is at the bottom of `boot()`)

- [ ] **Step 1: Replace the `Inertia::share('company', ...)` closure**

```php
Inertia::share('company', function () {
    if (!tenancy()->initialized) {
        return null;
    }
    $company = Company::where('is_main', true)->first();
    if (!$company) {
        return null;
    }
    $logo_url = $company->logo_path ? url("/files/companies/{$company->id}/logo") : null;
    return [
        'name' => $company->name,
        'logo_url' => $logo_url,
    ];
});
```

- [ ] **Step 2: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "fix: guard company Inertia share when tenancy not initialized"
```

---

## Task 14: Per-tenant storage isolation + authenticated file serving

Each tenant gets a completely separate storage root: `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`. A custom filesystem bootstrapper repoints the `public` and `local` disk roots into the active tenant's folder whenever tenancy is initialized, so **code that goes through `Storage::disk(...)` needs no changes** — `->store('uploaded/...', 'public')` automatically lands inside the tenant's folder, and the stored `path` stays relative (no tenant prefix in the database).

**Code that does *not* go through a disk does need changing, and it is easy to miss.** Six call sites build absolute paths by hand with `storage_path('app/public/…')` or `asset('storage/…')`. These bypass the disk root entirely, so after this task they would keep pointing at the shared central storage tree — silently, with no error: PDFs would render with missing photos and no logo, and imported images would be written where nothing can read them. Step 6 fixes all six. Re-run the grep in Step 6 before implementing, in case more have appeared.

Because files now live outside the web-served `public/storage` symlink, they are no longer reachable by URL. Instead, three small authenticated routes stream them through controllers. Tenant isolation is automatic: a file id from another tenant does not exist in this tenant's database, so route-model binding returns 404. (Documents already download through `DocumentController::download`, which uses `Storage::disk('public')` and therefore works unchanged — no document route is added here. The APK download route reads `storage_path('app/releases/lavoro.apk')` directly, not through a disk, so it intentionally stays global.)

**Files:**
- `app/Tenancy/TenantStorageBootstrapper.php` (new)
- `app/Http/Controllers/FileController.php` (new)
- `routes/web.php`
- `app/Models/User.php` (avatar accessor, `getAvatarAttribute`)
- `public/service-worker.js` (exclude `/files/` from caching)
- The 12 Vue/JS files that hardcode `/storage/${...}` (images and company logos)
- The 6 non-disk path builders: `app/Http/Controllers/ServiceOrderController.php:850`, `app/Http/Controllers/ImageController.php:54` and `:258`, `app/Models/Company.php:52`, `resources/views/pdf/servicejob.blade.php:232`, `resources/views/emails/event/appointment_confirmation.blade.php:102`

- [ ] **Step 1: Create the storage bootstrapper**

This repoints the disk roots only — it deliberately does **not** call `useStoragePath`, so framework storage (logs, compiled views, framework cache, `app/releases`) stays in the normal location; only uploaded-file disks move per tenant. That is also why it is not the package's `FilesystemTenancyBootstrapper`, and why it carries a different name.

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class TenantStorageBootstrapper implements TenancyBootstrapper
{
    protected array $original_roots = [];

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = 'tenant-' . $tenant->getTenantKey();

        foreach (['local', 'public'] as $disk) {
            $this->original_roots[$disk] = $this->app['config']["filesystems.disks.{$disk}.root"];
            $this->app['config']->set(
                "filesystems.disks.{$disk}.root",
                storage_path("{$suffix}/{$disk}")
            );
            Storage::forgetDisk($disk);
        }
    }

    public function revert(): void
    {
        foreach ($this->original_roots as $disk => $root) {
            $this->app['config']->set("filesystems.disks.{$disk}.root", $root);
            Storage::forgetDisk($disk);
        }
        $this->original_roots = [];
    }
}
```

- [ ] **Step 2: Create the file-serving controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function image(Image $image)
    {
        abort_unless(Storage::disk('public')->exists($image->path), 404);

        return Storage::disk('public')->response($image->path);
    }

    public function avatar(User $user)
    {
        $directory = "users/{$user->id}/avatar";
        $files = Storage::disk('public')->files($directory);
        abort_if(empty($files), 404);

        return Storage::disk('public')->response($files[0]);
    }

    public function companyLogo(Company $company, string $variant = 'main')
    {
        $path = $variant === 'negative' ? $company->logo_negative_path : $company->logo_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
```

- [ ] **Step 3: Register the routes inside the authenticated web group in `routes/web.php`**

Add inside the `auth` middleware group (tenancy is already initialized for these requests by the session middleware):

```php
Route::get('files/images/{image}', [\App\Http\Controllers\FileController::class, 'image'])->name('files.image');
Route::get('files/avatars/{user}', [\App\Http\Controllers\FileController::class, 'avatar'])->name('files.avatar');
Route::get('files/companies/{company}/logo/{variant?}', [\App\Http\Controllers\FileController::class, 'companyLogo'])->name('files.companyLogo');
```

- [ ] **Step 4: Change the `User` avatar accessor to return the route URL**

In `app/Models/User.php`, `getAvatarAttribute()` currently ends with `return Storage::url($files[0]);`. Keep the existence checks (so it still returns `null` when no avatar exists and the UI shows initials), but return the authenticated route instead:

```php
$directory = "users/{$this->id}/avatar";

if (!Storage::disk('public')->exists($directory)) {
    return null;
}

$files = Storage::disk('public')->files($directory);

if (empty($files)) {
    return null;
}

return url("/files/avatars/{$this->id}");
```

- [ ] **Step 5: Exclude `/files/` from service-worker caching**

`public/service-worker.js` serves same-origin GETs cache-first. Image/avatar ids are per-tenant auto-increments, so `/files/images/5` is a *different file* in each tenant; a cached copy could be shown to a user of another tenant on a shared browser, and stale copies would survive image replacement. In the `fetch` listener, add `/files/` to the early-return list:

```js
// Let the browser handle assets & API/Inertia calls
if (
    url.pathname.startsWith("/build/") ||
    url.pathname.startsWith("/files/") ||
    event.request.headers.get("X-Inertia") ||
    url.pathname.startsWith("/api/")
) {
    return;
}
```

- [ ] **Step 6: Update the frontend to use the file routes instead of `/storage/`**

Find every hardcoded reference:

```bash
grep -rn "/storage/" resources/js/
```

Apply these conversions across the matching files (verified list as of 2026-07-20 — 12 files, 18 occurrences; re-run the grep in case more were added):

| File | Occurrences |
| --- | --- |
| `Pages/Assets/IndexPage.vue` | 1 |
| `Pages/Assets/ShowPage.vue` | 1 |
| `Pages/Products/IndexPage.vue` | 1 |
| `Pages/Products/ShowPage.vue` | 1 |
| `Pages/ServiceOrders/ShowPage.vue` | 1 |
| `Pages/Companies/IndexPage.vue` | 2 |
| `Pages/Companies/Partials/EditCompanyModal.vue` | 2 |
| `Components/Timeline/TimelineComponent.vue` | 1 |
| `Components/CustomerUpcomingActivity.vue` | 2 |
| `Components/ImageUploadComponent.vue` | 3 |
| `Components/ServiceOrders/CloseServiceOrderModal.vue` (new since last revision) | 2 |
| `Utilities/Utilities.js:273` (new since last revision) | 1 |

Two of these need more than a mechanical swap:

- `Components/Timeline/TimelineComponent.vue` binds `event.thumbnailPath`, a *path* with no accompanying image id. Check what the timeline payload contains; if it carries no id, the backend that builds it must expose the `Image` id alongside (or instead of) the path.
- `Utilities/Utilities.js:273` builds `thumbnail_url` from `asset.product.images[0].path` inside a shared mapper — switch it to `.id` and confirm every consumer of `thumbnail_url` still works.


- Image displays bound to an `Image` model — replace the path build with the id route:

```vue
<!-- before -->
<img :src="`/storage/${image.path}`" />
<!-- after -->
<img :src="`/files/images/${image.id}`" />
```

  Apply the same to `asset.product.images[...]`, `product.main_image[0]`, and any other `Image` model: use its `.id`, not `.path`.

- Company logos — use the company route, with the `negative` variant for the negative logo:

```vue
<!-- before -->
<img :src="`/storage/${company.logo_path}`" />
<img :src="`/storage/${company.logo_negative_path}`" />
<!-- after -->
<img :src="`/files/companies/${company.id}/logo`" />
<img :src="`/files/companies/${company.id}/logo/negative`" />
```

- In `ImageUploadComponent.vue`, a *freshly uploaded* preview may use a local object URL or a path returned from the upload response before an `Image` id exists. Leave object-URL previews as-is; for previews of already-saved images, use `/files/images/${image.id}`. Check each usage in this file specifically. Note the line-383 occurrence feeds an image *editor* (`loadImage: { path, name }`), not an `<img>` — verify the editor accepts the route URL.

- [ ] **Step 7: Fix the six backend path builders that bypass the disk**

Each of these constructs an absolute path or a public URL by hand and therefore ignores the per-tenant disk root set in Step 1. All of them fail *silently* — a missing file is treated as "no image" — so they will not surface in a smoke test unless you look at a rendered PDF and an appointment e-mail specifically.

```bash
grep -rn "storage_path('app/\|asset('storage/" app/ resources/views/
```

1. **`app/Http/Controllers/ServiceOrderController.php:850`** — builds `storage_path('app/public/' . $image->path)` to base64-embed werkbon photos in the PDF. Read through the disk instead, which respects the tenant root:

```php
if (!Storage::disk('public')->exists($image->path)) {
    return null;
}
$contents = Storage::disk('public')->get($image->path);
$mime = Storage::disk('public')->mimeType($image->path);
[$width, $height] = @getimagesizefromstring($contents) ?: [1, 1];
// ...
'data' => 'data:' . $mime . ';base64,' . base64_encode($contents),
```

2. **`app/Models/Company.php:52` (`pdfLogo`)** — same pattern for the company logo on every PDF. Replace `storage_path('app/public/' . $company->logo_path)` with `Storage::disk('public')->exists(...)` / `->get(...)` / `->size(...)`, keeping the existing empty-file and extension checks.

3. **`resources/views/pdf/servicejob.blade.php:232`** — `<img src="{{ storage_path('app/public/' . $img['path']) }}">`. Dompdf reads this straight off disk, so it points at the central tree. Resolve the absolute path in the controller that renders this view (via `Storage::disk('public')->path($img['path'])`, which *is* tenant-aware) and pass it into the view, or pass a data URI like the other PDFs already do.

4. **`app/Http/Controllers/ImageController.php:258`** — `file_put_contents(storage_path('app/public/' . $path) . $filename, ...)` for imported images. Replace the manual `mkdir` + `file_put_contents` with `Storage::disk('public')->put($path . $filename, $image_data)`, which creates directories itself.

5. **`app/Http/Controllers/ImageController.php:54`** — `mkdir(storage_path('app/' . $path))`. **This one is already a latent bug today, independent of tenancy**: it creates `storage/app/uploaded/…` while the very next line stores into the `public` disk at `storage/app/public/uploaded/…`. The `mkdir` has never been doing anything useful — `storePubliclyAs` creates the directory itself. Delete the `$real_path` / `mkdir` block outright rather than porting it.

6. **`resources/views/emails/event/appointment_confirmation.blade.php:102`** — `asset('storage/' . $company->logo_path)`. Once files leave the `public/storage` symlink this URL 404s, and the `/files/` routes are behind `auth`, so an e-mail client can never fetch it. Embed the logo instead, reusing the accessor that already exists for PDFs:

```blade
@php($logo = \App\Models\Company::pdfLogo($company))
@if($logo['data'])
    <img src="{{ $logo['data'] }}" alt="{{ $company->name }}">
@endif
```

The `asset('storage/logo.png')` / `public_path('storage/logo.png')` references in the three PDF blades are a different case: that is a single static fallback logo, not tenant data. It resolves through the untouched `public/storage` symlink and stays global by design — leave it, but be aware it is the same image for every tenant.

- [ ] **Step 8: Commit**

```bash
git add app/Tenancy/TenantStorageBootstrapper.php \
        app/Http/Controllers/FileController.php \
        app/Http/Controllers/ImageController.php \
        app/Http/Controllers/ServiceOrderController.php \
        app/Models/Company.php \
        routes/web.php \
        app/Models/User.php \
        public/service-worker.js \
        resources/views/ \
        resources/js/
git commit -m "feat(tenancy): per-tenant storage roots with authenticated file serving"
```

---

## Task 15: Update the login controller

Look up the tenant from the email, switch to its database, then authenticate. Keep `remember: true` (current behavior) and pair it with a forever `tenant_id` cookie so the recaller can find its database on a fresh session (Task 12 reads it). Also remove the `exists:users,email` rule from the form request — it runs before tenancy is initialized and would query the central database, which has no `users` table. Finally, drop the `company` prop from `create()`: `LoginPage.vue` renders the static `/img/logo-neg.svg` and never reads it, and the query would crash against the central database.

**Files:** `app/Http/Controllers/AuthController.php`, `app/Http/Requests/StoreUpdateAuthRequest.php`

- [ ] **Step 1: Remove the `exists` rule**

```php
public function rules(): array
{
    return [
        'email'    => 'required|string|email',
        'password' => 'required|string',
    ];
}
```

- [ ] **Step 2: Rewrite `AuthController`**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateAuthRequest;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create()
    {
        return inertia('Auth/LoginPage');
    }

    public function store(StoreUpdateAuthRequest $request)
    {
        $lookup = UserTenantLookup::where('email', $request->email)->first();

        if (!$lookup) {
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        $tenant = Tenant::on('central')->findOrFail($lookup->tenant_id);
        tenancy()->initialize($tenant);

        if (!Auth::attempt($request->only('email', 'password'), true)) {
            tenancy()->end();
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        session(['tenant_id' => $tenant->id]);
        cookie()->queue(cookie()->forever('tenant_id', $tenant->id));
        $request->session()->regenerate();

        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        cookie()->queue(cookie()->forget('tenant_id'));

        return redirect()->route('login');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/AuthController.php app/Http/Requests/StoreUpdateAuthRequest.php
git commit -m "feat(tenancy): resolve tenant before authenticating on login"
```

---

## Task 16: Licensing catalogue models and the pricing service

The catalogue tables exist and are seeded (Task 6). This task adds the Eloquent models over them, the `TenantSubscription` service that computes a tenant's monthly total, and the frontend exposure of the tenant's package and modules. The CRUD commands that edit the catalogue and the per-tenant subscription commands come later (Tasks 33–34); nothing before those needs them.

**Files:**
- `app/Models/Central/Package.php`, `Module.php`, `ModuleBundle.php`, `PricingSetting.php` (new)
- `app/Services/TenantSubscription.php` (new)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/Utilities/Utilities.js`
- `tests/Feature/TenantSubscriptionTest.php`, `tests/Feature/PricingCatalogueTest.php` (new)

**Interfaces:**
- Consumes: seeded `packages`, `modules`, `module_bundles`, `pricing_settings` (Task 6); `Tenant` (Task 4).
- Produces:
  - `App\Models\Central\Package` — columns `key`, `name`, `field_seats`, `office_seats`, `price_cents`, `extra_field_cents`, `extra_office_cents`, `sort_order`; central connection; fillable.
  - `App\Models\Central\Module` — `key`, `name`, `price_cents`, `sort_order`; central; fillable.
  - `App\Models\Central\ModuleBundle` — `name`, `module_keys` (array cast), `price_cents`; central; fillable.
  - `App\Models\Central\PricingSetting` — `key`, `value`; central; static `value(string $key, int $default = 0): int`.
  - `App\Services\TenantSubscription` — `__construct(Tenant $tenant)`; `monthlyTotalCents(): int`.

- [ ] **Step 1: Create the four catalogue models**

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $connection = 'central';
    protected $fillable = ['key', 'name', 'field_seats', 'office_seats', 'price_cents', 'extra_field_cents', 'extra_office_cents', 'sort_order'];
}
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $connection = 'central';
    protected $fillable = ['key', 'name', 'price_cents', 'sort_order'];
}
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class ModuleBundle extends Model
{
    protected $connection = 'central';
    protected $fillable = ['name', 'module_keys', 'price_cents'];
    protected $casts = ['module_keys' => 'array'];
}
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $connection = 'central';
    protected $fillable = ['key', 'value'];

    public static function value(string $key, int $default = 0): int
    {
        $row = static::on('central')->where('key', $key)->first();
        return $row ? (int) $row->value : $default;
    }
}
```

- [ ] **Step 2: Write the failing pricing-catalogue invariant tests**

These read the seeded catalogue. They encode the two rules the price list must satisfy — a future price change that breaks either turns the suite red.

```php
<?php

namespace Tests\Feature;

use App\Models\Central\Package;
use Tests\TestCase;

class PricingCatalogueTest extends TestCase
{
    public function test_expanding_is_cheaper_than_upgrading_to_equivalent_coverage(): void
    {
        $packages = Package::on('central')->orderBy('sort_order')->get();

        for ($i = 0; $i < $packages->count() - 1; $i++) {
            $lower = $packages[$i];
            $upper = $packages[$i + 1];

            $expand_cost = $lower->price_cents
                + ($upper->field_seats - $lower->field_seats) * $lower->extra_field_cents
                + ($upper->office_seats - $lower->office_seats) * $lower->extra_office_cents;

            $this->assertLessThan(
                $upper->price_cents,
                $expand_cost,
                "Expanding {$lower->key} to {$upper->key}'s coverage must cost less than upgrading."
            );
        }
    }

    public function test_add_on_seats_get_cheaper_as_packages_grow(): void
    {
        $packages = Package::on('central')->orderBy('sort_order')->get();

        for ($i = 0; $i < $packages->count() - 1; $i++) {
            $this->assertGreaterThan($packages[$i + 1]->extra_field_cents, $packages[$i]->extra_field_cents);
            $this->assertGreaterThan($packages[$i + 1]->extra_office_cents, $packages[$i]->extra_office_cents);
        }
    }
}
```

- [ ] **Step 3: Run to verify they fail, then pass**

Run: `php artisan test --filter=PricingCatalogueTest`
Expected first: FAIL — `Class "App\Models\Central\Package" not found`. After Step 1 is in place and the catalogue migration (Task 6) has run against the test central database, both tests PASS with the seeded numbers.

- [ ] **Step 4: Write the failing `TenantSubscription` tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantSubscription;
use Tests\TestCase;

class TenantSubscriptionTest extends TestCase
{
    private function subscription(array $attributes): TenantSubscription
    {
        return new TenantSubscription(new Tenant(array_merge([
            'package_key'        => 'starter',
            'extra_field_seats'  => 0,
            'extra_office_seats' => 0,
            'modules'            => [],
            'storage_limit_gb'   => 50,
        ], $attributes)));
    }

    public function test_bare_package_is_its_base_price(): void
    {
        $this->assertSame(2750, $this->subscription(['package_key' => 'starter'])->monthlyTotalCents());
        $this->assertSame(16000, $this->subscription(['package_key' => 'business'])->monthlyTotalCents());
    }

    public function test_extra_seats_add_at_the_package_rate(): void
    {
        $total = $this->subscription([
            'package_key' => 'business', 'extra_field_seats' => 5, 'extra_office_seats' => 2,
        ])->monthlyTotalCents();

        $this->assertSame(16000 + 5 * 1000 + 2 * 700, $total); // 22400
    }

    public function test_a_module_bundle_replaces_its_members_individual_prices(): void
    {
        $this->assertSame(16000 + 2750, $this->subscription(['package_key' => 'business', 'modules' => ['quotes']])->monthlyTotalCents());
        $this->assertSame(16000 + 4000, $this->subscription(['package_key' => 'business', 'modules' => ['quotes', 'invoices']])->monthlyTotalCents());
    }

    public function test_free_modules_add_nothing(): void
    {
        $this->assertSame(16000, $this->subscription(['package_key' => 'business', 'modules' => ['snelstart', 'projects']])->monthlyTotalCents());
    }

    public function test_extra_storage_bills_the_allowance_above_the_included_amount(): void
    {
        // 120 GB limit, 50 GB included, 50 cents/GB → (120-50)*50 = 3500
        $this->assertSame(16000 + 3500, $this->subscription(['package_key' => 'business', 'storage_limit_gb' => 120])->monthlyTotalCents());
        $this->assertSame(16000, $this->subscription(['package_key' => 'business', 'storage_limit_gb' => 50])->monthlyTotalCents());
    }

    public function test_price_override_replaces_only_the_package_price(): void
    {
        $total = $this->subscription([
            'package_key' => 'business', 'price_override_cents' => 14000,
            'extra_field_seats' => 5, 'modules' => ['quotes', 'invoices'],
        ])->monthlyTotalCents();

        $this->assertSame(14000 + 5 * 1000 + 4000, $total); // 23000
    }
}
```

- [ ] **Step 5: Run to verify they fail**

Run: `php artisan test --filter=TenantSubscriptionTest`
Expected: FAIL — `Class "App\Services\TenantSubscription" not found`.

- [ ] **Step 6: Implement the `TenantSubscription` service**

```php
<?php

namespace App\Services;

use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Tenant;

class TenantSubscription
{
    public function __construct(private Tenant $tenant)
    {
    }

    public function monthlyTotalCents(): int
    {
        $package = Package::on('central')->where('key', $this->tenant->package_key)->firstOrFail();

        $base = $this->tenant->price_override_cents ?? $package->price_cents;

        $seats = $this->tenant->extra_field_seats * $package->extra_field_cents
            + $this->tenant->extra_office_seats * $package->extra_office_cents;

        return $base + $seats + $this->storageCents() + $this->modulesCents();
    }

    private function storageCents(): int
    {
        $included = PricingSetting::value('included_storage_gb', 50);
        $per_gb   = PricingSetting::value('storage_extra_per_gb_cents', 0);
        $extra_gb = max(0, $this->tenant->storage_limit_gb - $included);

        return $extra_gb * $per_gb;
    }

    private function modulesCents(): int
    {
        $held = $this->tenant->modules ?? [];
        if (empty($held)) {
            return 0;
        }

        $remaining = array_values($held);
        $total = 0;

        $bundles = ModuleBundle::on('central')->get()
            ->map(function (ModuleBundle $bundle) {
                $individual = Module::on('central')->whereIn('key', $bundle->module_keys)->sum('price_cents');

                return ['keys' => $bundle->module_keys, 'price' => $bundle->price_cents, 'saving' => $individual - $bundle->price_cents];
            })
            ->sortByDesc('saving');

        foreach ($bundles as $bundle) {
            $all_held = collect($bundle['keys'])->every(fn ($key) => in_array($key, $remaining, true));
            if ($all_held) {
                $total += $bundle['price'];
                $remaining = array_values(array_diff($remaining, $bundle['keys']));
            }
        }

        return $total + Module::on('central')->whereIn('key', $remaining)->sum('price_cents');
    }
}
```

- [ ] **Step 7: Run the subscription tests to verify they pass**

Run: `php artisan test --filter=TenantSubscriptionTest`
Expected: PASS.

- [ ] **Step 8: Share the tenant's package and modules with the frontend**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the array returned by `share()` (next to the existing `auth` key). Seat and storage usage are added to this same prop by their own tasks (Tasks 34 and 35) — keep it to package and modules here.

```php
'tenant' => tenancy()->initialized ? [
    'package' => tenancy()->tenant->package_key,
    'modules' => tenancy()->tenant->modules ?? [],
] : null,
```

- [ ] **Step 9: Add a `hasModule` helper to `resources/js/Utilities/Utilities.js`**

Follow the same pattern as the existing `hasPermission` helper (which reads `usePage().props.auth.permissions`):

```js
export const hasModule = (name) => {
    const page = usePage();
    const modules = page?.props?.tenant?.modules;
    return Array.isArray(modules) && modules.includes(name);
}
```

Two deliberate differences from `hasPermission`: it is **not** bypassed for admins (a module is a subscription boundary, not a permission), and it returns `false` rather than throwing when `tenant` is absent — which is the case on the login page, where no tenancy is initialized.

- [ ] **Step 10: Commit**

```bash
git add app/Models/Central/Package.php app/Models/Central/Module.php \
        app/Models/Central/ModuleBundle.php app/Models/Central/PricingSetting.php \
        app/Services/TenantSubscription.php \
        app/Http/Middleware/HandleInertiaRequests.php resources/js/Utilities/Utilities.js \
        tests/Feature/TenantSubscriptionTest.php tests/Feature/PricingCatalogueTest.php
git commit -m "feat(tenancy): licensing catalogue models and pricing service"
```

---

## Task 17: Update the password reset flow

Password reset runs before login, so do the same tenant lookup before calling the `Password` facade (which queries `password_reset_tokens` and `users` in the tenant database). Do **not** wrap the new password in `Hash::make()` — the `User` model has a `hashed` cast, so `forceFill` hashes it automatically; wrapping it would double-hash and break login.

**Files:** `app/Http/Controllers/PasswordResetController.php`

- [ ] **Step 1: Rewrite the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function create()
    {
        return inertia('Auth/ForgotPasswordPage');
    }

    public function store(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $this->switchToTenantForEmail($request->email);

        $status = Password::sendResetLink($request->only('email'));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    public function edit(string $token, Request $request)
    {
        return inertia('Auth/ResetPasswordPage', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->switchToTenantForEmail($request->email);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                event(new PasswordReset($user));
            }
        );

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    private function switchToTenantForEmail(string $email): void
    {
        $lookup = UserTenantLookup::where('email', $email)->first();
        if (!$lookup) {
            return;
        }
        $tenant = Tenant::on('central')->find($lookup->tenant_id);
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/PasswordResetController.php
git commit -m "feat(tenancy): switch to tenant DB in password reset flow"
```

---

## Task 18: Keep the central lookup in sync via a `User` observer

When a user is created, changes email, or is deleted in a tenant, mirror it into the central lookup. The `created` hook refuses to hijack an email already registered to a *different* tenant (defense in depth behind the validation in Task 19).

**`User` uses `SoftDeletes`** (added 2026-07-07, `2026_07_07_080819_add_softdeletes_to_users_table.php`; see `UserController::restore` and `UserRestoreRequest`). This matters more than it looks:

- Eloquent's `deleted` event fires on a **soft** delete. Dropping the lookup row there would free the email centrally while the row still occupies `users.email` in the tenant — and Laravel's `unique:users,email` rule does **not** exclude trashed rows. The email would then be un-loggable-in, un-recreatable in this tenant, and claimable by another tenant, which is exactly the invariant this table exists to protect.
- The rule is therefore: the central lookup tracks whether the email is **taken in the tenant's `users` table**, trashed or not. Soft delete keeps the row, `forceDeleted` removes it, `restored` re-asserts it.

A soft-deleted user cannot log in (the `SoftDeletingScope` hides them from the auth guard's query), so keeping the lookup row costs nothing.

**Files:** `app/Observers/UserObserver.php`, `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create the observer**

```php
<?php

namespace App\Observers;

use App\Models\Central\UserTenantLookup;
use App\Models\User;
use RuntimeException;

class UserObserver
{
    public function created(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        $existing = UserTenantLookup::on('central')->find($user->email);
        if ($existing && $existing->tenant_id !== $tenant_id) {
            throw new RuntimeException("E-mailadres {$user->email} is al in gebruik bij een andere tenant.");
        }

        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function updated(User $user): void
    {
        if (!$user->isDirty('email')) {
            return;
        }

        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->where('email', $user->getOriginal('email'))->delete();
        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function restored(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function forceDeleted(User $user): void
    {
        UserTenantLookup::on('central')->where('email', $user->email)->delete();
    }
}
```

There is deliberately **no `deleted` hook**. On a soft-deleting model `deleted` fires for soft deletes, and the lookup must survive those — see the explanation above. `forceDeleted` fires only on a true `forceDelete()`, which is when the email genuinely becomes free again.

Also note `updated` fires on the soft-delete write (`deleted_at` changes), but its `isDirty('email')` guard makes that a no-op.

- [ ] **Step 2: Register it in `AppServiceProvider::boot()`** (next to the existing `EventModel::observe` / `Ticket::observe` calls)

```php
\App\Models\User::observe(\App\Observers\UserObserver::class);
```

- [ ] **Step 3: Commit**

```bash
git add app/Observers/UserObserver.php app/Providers/AppServiceProvider.php
git commit -m "feat(tenancy): sync central user lookup on user changes"
```

---

## Task 19: Enforce global email uniqueness at user creation/update

The lookup table's email primary key would throw a raw SQL error if an admin created a user whose email already exists in another tenant. Validate it cleanly instead. The routes use `UserStoreRequest` (store) and `UserUpdateRequest` (update **and** `me.update` via `updateSelf`, where there is no `{user}` route parameter — verified at `UserController.php:55,68,120`). `StoreUserRequest`/`UpdateUserRequest` also exist in `app/Http/Requests/` but are not referenced by the user routes; leave those untouched.

Both requests already have exactly the shape this task assumes: `UserStoreRequest::rules()` has the flat `'email' => 'required|email|unique:users,email'` string, and `UserUpdateRequest::rules()` already computes `$route_user` / `$route_user_id` / `$current_user_id` / `$ignore_id` in that order, so the `$ignore_email` snippet below drops in directly after them.

Consistency note tying this to Task 18: `unique:users,email` counts soft-deleted users, and the central lookup keeps a row for soft-deleted users. The two checks therefore agree — an email belonging to a trashed user is rejected by both, not one.

The `central.` prefix on the unique rule tells the validator to query the central connection.

**Files:** `app/Http/Requests/UserStoreRequest.php`, `app/Http/Requests/UserUpdateRequest.php`

- [ ] **Step 1: Add a global-uniqueness rule to `UserStoreRequest::rules()`**

Replace the current `'email' => 'required|email|unique:users,email',` line:

```php
use Illuminate\Validation\Rule;

// inside rules():
'email' => [
    'required', 'email',
    'unique:users,email',
    Rule::unique('central.user_tenant_lookups', 'email'),
],
```

- [ ] **Step 2: Add the same rule to `UserUpdateRequest`, ignoring the user's current email**

The request already derives `$ignore_id` from the route user or the authenticated user; mirror that logic for the email. Inside `rules()`, after the existing `$ignore_id` computation, add:

```php
$ignore_user = is_object($route_user)
    ? $route_user
    : ($route_user ? \App\Models\User::find($route_user) : null);
$ignore_email = $ignore_user?->email ?? optional(request()->user())->email;
```

and extend the email rules:

```php
'email' => [
    'required',
    'email',
    Rule::unique('users', 'email')->ignore($ignore_id),
    Rule::unique('central.user_tenant_lookups', 'email')->ignore($ignore_email, 'email'),
],
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/UserStoreRequest.php app/Http/Requests/UserUpdateRequest.php
git commit -m "feat(tenancy): validate email is globally unique across tenants"
```

---

## Task 20: Make scheduled tasks run per tenant, without per-tenant work blocking the scheduler tick

Loop over all tenants, switch into each, and dispatch **one queued job per tenant per schedule** — the scheduler tick itself must never run a data query or delete inline, only cheap config-swap + single-row `INSERT INTO jobs` work. The jobs are dispatched from tenant context (not `dispatchSync`) — the `QueueTenancyBootstrapper` records the active tenant in the job payload and re-initializes it automatically when the worker picks it up, so the job body needs no manual `tenancy()->initialize()` call of its own.

`routes/console.php` has **four** schedules as of 2026-07-20 (the plan previously said three). If more exist by implementation time, wrap them in the same per-tenant dispatch-only pattern:

| Schedule | Today | Change |
| --- | --- | --- |
| `google-pull-changes` | `Schedule::call` running a `whereHas` query inline | → per-tenant dispatch of `DispatchTenantCalendarPullsJob` |
| `google-renew-watches` | `Schedule::job(new RenewWatchChannelsJob())` | → per-tenant `RenewWatchChannelsJob::dispatch()` inside the loop (a bare `Schedule::job` has no tenant context and would run against the central database) |
| `prune-location-pings` | `Schedule::call` running a synchronous `DELETE` inline | → per-tenant dispatch of `PruneLocationPingsJob` |
| `maintenancecontracts-generate-serviceorders` (**new since last revision**, 2026-07-10) | `Schedule::command('maintenancecontracts:generate-serviceorders')` calling `MaintenanceContractServiceOrderGenerator::generateAllDue()` inline | → per-tenant dispatch of `GenerateMaintenanceContractServiceOrdersJob` |

The maintenance-contract one is the most important of the four to convert: `generateAllDue()` scans every asset on every active contract and **creates service orders**. Left as a plain `Schedule::command`, it would run exactly once per tick against whatever the default connection happens to be — the central database, which has no `maintenance_contracts` table — so contract generation would break outright for every tenant. The existing Artisan command stays (it is useful for manual runs against a chosen tenant); the schedule stops invoking it directly.

**Files:**
- `app/Jobs/Google/DispatchTenantCalendarPullsJob.php` (new)
- `app/Jobs/PruneLocationPingsJob.php` (new)
- `app/Jobs/GenerateMaintenanceContractServiceOrdersJob.php` (new)
- `routes/console.php`

> **Precondition, easy to forget:** none of this runs at all until a server-level cron invokes `php artisan schedule:run` — there is currently no crontab entry wired up, and `app/Console/Kernel.php`'s `schedule()` is dead code under Laravel 12's `routes/console.php` setup. Confirm the cron exists on the target server before treating any scheduled behaviour as working.

- [ ] **Step 1: Create the calendar-pull dispatch job**

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTenantCalendarPullsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        GoogleSyncedCalendar::query()
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->pluck('id')
            ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));
    }
}
```

This runs on the worker with tenancy already initialized (tagged by `QueueTenancyBootstrapper` at dispatch time, same as any other tenant-context job), so the `whereHas` query and the `PullCalendarChangesJob` dispatches it makes both land against the correct tenant database automatically.

- [ ] **Step 2: Create the location-ping pruning job**

```php
<?php

namespace App\Jobs;

use App\Models\LocationPing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneLocationPingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        LocationPing::where('recorded_at', '<', now()->subDay())->delete();
    }
}
```

- [ ] **Step 3: Create the maintenance-contract generation job**

```php
<?php

namespace App\Jobs;

use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMaintenanceContractServiceOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MaintenanceContractServiceOrderGenerator $generator): void
    {
        $generator->generateAllDue();
    }
}
```

Leave `App\Console\Commands\GenerateMaintenanceContractServiceOrders` in place for manual runs; only the schedule changes.

- [ ] **Step 4: Rewrite `routes/console.php` so every tick only dispatches**

`cursor()` replaces `get()` so the central tenant list is streamed rather than loaded into memory in one array — cheap either way at today's tenant count, but it's the free half of the "chunking" mitigation Known impact point 6 calls for.

```php
<?php

use App\Jobs\GenerateMaintenanceContractServiceOrdersJob;
use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Jobs\PruneLocationPingsJob;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        DispatchTenantCalendarPullsJob::dispatch();
        tenancy()->end();
    });
})->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        RenewWatchChannelsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('google-renew-watches')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        PruneLocationPingsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('prune-location-pings')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        GenerateMaintenanceContractServiceOrdersJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('maintenancecontracts-generate-serviceorders')->withoutOverlapping();
```

Every tick body is now: swap config, one `INSERT` into the central `jobs` table, revert config — no query, no delete, nothing whose cost scales with a tenant's data volume. The remaining linear cost is strictly "number of tenants × one INSERT", which is the part `withoutOverlapping` can comfortably absorb even at a few hundred tenants.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Google/DispatchTenantCalendarPullsJob.php app/Jobs/PruneLocationPingsJob.php \
        app/Jobs/GenerateMaintenanceContractServiceOrdersJob.php routes/console.php
git commit -m "feat(tenancy): keep per-tenant scheduler ticks dispatch-only"
```

---

## Task 21: `tenant:create` command (with initial admin)

Creates the tenant record (which fires the create→migrate→seed pipeline), then creates an initial admin user inside the new tenant so the company can actually log in.

The package is validated against the seeded `packages` catalogue (Task 6/16) and defaults to `starter` — the smallest thing that works, so an under-provisioned tenant complains immediately rather than silently costing money. Modules default to none. The guard against `$database` equalling the central database name matters because central is `lavoro_tenants` and the default derived name is `'lavoro_' . Str::slug($name, '_')` — so `tenant:create "Tenants"` would otherwise point a tenant at the central database and run the tenant migrations straight over the tenant registry. Cheap check, unrecoverable failure without it. Creating the user fires the observer, which writes the central lookup row. The admin user is created without an explicit `seat_type`, so it takes the column default `office` (Task 33); the operator can promote it to `field` afterwards.

**This command must run as the provisioner Linux user** (Task 2), because creating a database and a MySQL user is the one thing the web app's credentials deliberately cannot do:

```bash
sudo -u lavoro_provisioner php artisan tenant:create "Klant BV" admin@klant.nl
```

**Files:** `app/Console/Commands/Concerns/RunsAsProvisioner.php` (new), `app/Console/Commands/CreateTenant.php`

- [ ] **Step 1: Create the `RunsAsProvisioner` trait**

Provisioning writes the tenant row *and* issues `CREATE DATABASE` / `CREATE USER`, so both must happen on the `provisioner` connection. This trait repoints `central` at the provisioner connection for the life of the command, and fails with a clear message when the command is run as the wrong Linux user — otherwise the failure surfaces as an opaque MySQL access-denied error.

```php
<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

trait RunsAsProvisioner
{
    protected function useProvisionerConnection(): bool
    {
        config(['database.connections.central' => config('database.connections.provisioner')]);
        DB::purge('central');

        try {
            DB::connection('central')->select('select 1');
        } catch (\Throwable $e) {
            $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
                : 'unknown';

            $this->error("Could not connect as the provisioner (running as Linux user '{$user}').");
            $this->error('Run this command as: sudo -u lavoro_provisioner php artisan ' . $this->getName() . ' ...');

            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\Module;
use App\Models\Central\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:create {name} {admin_email} {--database=} {--admin-password=} {--package=starter} {--modules=*}';
    protected $description = 'Create a tenant, its database, its MySQL user, and an initial admin user';

    public function handle(): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        $name     = $this->argument('name');
        $email    = $this->argument('admin_email');
        $database = $this->option('database') ?: 'lavoro_' . Str::slug($name, '_');
        $password = $this->option('admin-password') ?: Str::password(16);
        $package  = $this->option('package');
        $modules  = $this->option('modules');

        if (!Package::on('central')->where('key', $package)->exists()) {
            $valid = Package::on('central')->orderBy('sort_order')->pluck('key')->implode(', ');
            $this->error("Unknown package '{$package}'. Valid packages: {$valid}");
            return self::FAILURE;
        }

        $valid_modules = Module::on('central')->pluck('key')->all();
        foreach ($modules as $module) {
            if (!in_array($module, $valid_modules, true)) {
                $this->error('Unknown module: ' . $module);
                return self::FAILURE;
            }
        }

        if ($database === config('database.connections.central.database')) {
            $this->error("Refusing to use '{$database}' — that is the central database.");
            return self::FAILURE;
        }

        $this->info("Creating tenant '{$name}' (database {$database})...");

        $tenant = Tenant::create([
            'id'              => (string) Str::ulid(),
            'name'            => $name,
            'package_key'     => $package,
            'modules'         => array_values(array_unique($modules)),
            'tenancy_db_name' => $database,
        ]);

        tenancy()->initialize($tenant);

        $user = User::create([
            'name'     => 'Beheerder',
            'email'    => $email,
            'password' => $password,
        ]);

        $admin_role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->syncWithoutDetaching([$admin_role->id]);

        tenancy()->end();

        $this->info("Tenant ID: {$tenant->id}");
        $this->info("Package: {$package}");
        $this->info("Database user: {$tenant->tenancy_db_username}");
        $this->info("Admin: {$email}");
        $this->info("Password: {$password}");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Verify the tenant got its own confined MySQL user**

```bash
sudo -u lavoro_provisioner php artisan tenant:create "Test BV" test@test.nl --package=starter

# The generated user exists and reaches only its own database:
mysql -u lavoro_provisioner --protocol=socket -e "SHOW GRANTS FOR '<printed-username>'@'%';"
```

Expected: a single `GRANT ... ON \`lavoro_<id>\`.*` line — no `*.*` grant, no `CREATE USER`, no `GRANT OPTION`. Then confirm the stored password is not readable in the clear:

```bash
mysql -u lavoro_app -p -e "SELECT tenancy_db_username, LEFT(tenancy_db_password, 24) FROM lavoro_tenants.tenants;"
```

Expected: the password column shows base64-looking ciphertext (`eyJpdiI6...`), not a usable password.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/Concerns/RunsAsProvisioner.php app/Console/Commands/CreateTenant.php
git commit -m "feat(tenancy): add tenant:create with per-tenant database user"
```

---

## Task 22: `tenant:delete` command (cleanup / failed-creation recovery)

If tenant creation fails partway, or a tenant is offboarded, this drops the database, **the tenant's MySQL user**, the central lookup rows, and the tenant record. Deleting the `Tenant` fires the package's `DeleteDatabase` job if wired; to be explicit and safe we drop both directly.

Dropping the user matters: leaving it behind accumulates orphaned MySQL accounts that still have grants on a database name that could later be reused, which is exactly the sort of stale privilege that turns into a cross-tenant hole.

Like `tenant:create`, this runs as the provisioner:

```bash
sudo -u lavoro_provisioner php artisan tenant:delete <id>
```

**Files:** `app/Console/Commands/DeleteTenant.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:delete {id}';
    protected $description = 'Drop a tenant database, its MySQL user, and its central records';

    public function handle(): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $database = $tenant->getDatabaseName();
        $db_user  = $tenant->tenancy_db_username;

        if (!$this->confirm("Permanently drop database '{$database}' and all its data?")) {
            return self::FAILURE;
        }

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

        if ($db_user) {
            DB::connection('central')->statement("DROP USER IF EXISTS `{$db_user}`@`%`");
            $this->info("Dropped MySQL user {$db_user}.");
        }

        UserTenantLookup::on('central')->where('tenant_id', $tenant->id)->delete();
        $tenant->delete();

        $this->info("Deleted tenant {$tenant->id} and database {$database}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify no orphaned user remains**

```bash
mysql -u lavoro_provisioner --protocol=socket -e "SELECT user, host FROM mysql.user WHERE user LIKE 'lavoro%';"
```

Expected: no row for the deleted tenant.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/DeleteTenant.php
git commit -m "feat(tenancy): add tenant:delete for cleanup"
```

---

## Task 23: `TenantDatabaseSeeder`

Runs automatically when a new tenant database is created. It only seeds what the tenant migrations do not: the company record and default service order stages. Roles and permissions are already created by the existing `seed_*_permissions` migrations, so they must not be duplicated here.

The stage rows carry all six semantic flags currently on `service_order_stages` (verified 2026-07-03): `is_plannable_state`, `is_planned_state`, `is_closed_state`, `is_planning_cancelled_state`, `is_invoiced_state`, `is_incomplete_state`.

**Files:** `database/seeders/TenantDatabaseSeeder.php`

- [ ] **Step 1: Create the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ServiceOrderStage;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::firstOrCreate(
            ['is_main' => true],
            ['name' => tenancy()->tenant->name]
        );

        $default_flags = [
            'is_plannable_state'          => false,
            'is_planned_state'            => false,
            'is_closed_state'             => false,
            'is_planning_cancelled_state' => false,
            'is_invoiced_state'           => false,
            'is_incomplete_state'         => false,
        ];

        $stages = [
            ['name' => 'Nieuw',    'order' => 1, 'is_plannable_state' => true],
            ['name' => 'Gepland',  'order' => 2, 'is_planned_state' => true],
            ['name' => 'Gesloten', 'order' => 3, 'is_closed_state' => true],
        ];

        foreach ($stages as $stage) {
            ServiceOrderStage::firstOrCreate(
                ['name' => $stage['name']],
                array_merge($default_flags, $stage)
            );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add database/seeders/TenantDatabaseSeeder.php
git commit -m "feat(tenancy): add TenantDatabaseSeeder for company and stages"
```

---

## Task 24: API routes — tenant from the session (header fallback)

The API uses stateful Sanctum: `bootstrap/app.php` calls `$middleware->statefulApi()`, and there are **no bearer tokens anywhere** (`createToken` is never called) — the SPA and the Android app both authenticate with session cookies. `EnsureFrontendRequestsAreStateful` runs the session middleware for requests from stateful origins *before* route middleware, so by the time our middleware runs, `session('tenant_id')` is available. That means API tenancy works exactly like web tenancy, with **no frontend changes at all**.

An `X-Tenant-ID` header is honored as a fallback so a future bearer-token client can work without touching this middleware again. Applied as a named route middleware (not a global prepend) so any future public API endpoint can skip it.

**Files:** `app/Http/Middleware/InitializeTenancyForApi.php`, `bootstrap/app.php`, `routes/api.php`

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyForApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant_id = $request->hasSession()
            ? $request->session()->get('tenant_id')
            : null;
        $tenant_id = $tenant_id ?: $request->header('X-Tenant-ID');

        if (!$tenant_id) {
            return response()->json(['message' => 'Tenant kon niet worden bepaald.'], 400);
        }

        $tenant = Tenant::on('central')->find($tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'Onbekende tenant.'], 400);
        }

        $initialized_here = false;
        if (!tenancy()->initialized) {
            tenancy()->initialize($tenant);
            $initialized_here = true;
        }

        $response = $next($request);

        if ($initialized_here) {
            tenancy()->end();
        }

        return $response;
    }
}
```

Same `$initialized_here` guard as Task 12, for the same reason — several API tests drive these routes with tenancy already established by the `TestCase`.

- [ ] **Step 2: Register the alias in `bootstrap/app.php`**

```php
$middleware->alias([
    'admin'      => EnsureUserIsAdmin::class,
    'tenant.api' => \App\Http\Middleware\InitializeTenancyForApi::class,
]);
```

- [ ] **Step 3: Apply it to the authenticated API group in `routes/api.php`**

The whole file is currently one `Route::group(['middleware' => 'auth:sanctum'], ...)`; add `tenant.api` before it:

```php
Route::group(['middleware' => ['tenant.api', 'auth:sanctum']], function () {
    // all existing routes unchanged
});
```

**Ordering matters here for the same reason as Task 12.** The `api` group also ends in `SubstituteBindings`, and many of these routes bind models (`events/{event}`, `images/{image}`, `projects/{project}`, `plan-groups/{group}`, `users/{user}`, …). Listing `tenant.api` first in the array is not sufficient on its own, because Laravel priority-sorts the combined group + route middleware list. `InitializeTenancyForApi` is already included in the Task 12 priority array — confirm it is there before relying on this, and verify with:

```bash
php artisan route:list --path=api/events -v | head -20
```

`InitializeTenancyForApi` must appear before both `SubstituteBindings` and `auth:sanctum`.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/InitializeTenancyForApi.php bootstrap/app.php routes/api.php
git commit -m "feat(tenancy): resolve tenant from session on API requests"
```

---

## Task 25: Route the Google webhook to the right tenant

The webhook arrives from Google with no session and no cookie. The existing code already round-trips a random secret: `RenewWatchChannelsJob` and `BackfillCalendarJob` both store a `Str::random(40)` token on the calendar row, and `GoogleWebhookController` rejects notifications whose `X-Goog-Channel-Token` fails `hash_equals` against it. **Keep that check.** We only prepend the tenant key to the token — `"<tenant_key>|<random>"` — so the controller can pick the database before looking the channel up; the stored token is the full prefixed string, so the `hash_equals` comparison still covers the entire value Google echoes back.

Channels created before this change carry unprefixed tokens and cannot be routed; they self-heal on renewal (Task 27 Step 6 forces it), and the 5-minute polling schedule covers the gap.

**Files:** `app/Jobs/Google/RenewWatchChannelsJob.php`, `app/Jobs/Google/BackfillCalendarJob.php`, `app/Http/Controllers/GoogleWebhookController.php`

- [ ] **Step 1: Prefix the token in `RenewWatchChannelsJob`**

In `handle()`, the loop currently builds `$token = Str::random(40);`. Change it to:

```php
$token = tenancy()->tenant->getTenantKey() . '|' . Str::random(40);
```

- [ ] **Step 2: Prefix the token in `BackfillCalendarJob::registerWatch()`**

Same change — replace `$token = \Illuminate\Support\Str::random(40);` with:

```php
$token = tenancy()->tenant->getTenantKey() . '|' . \Illuminate\Support\Str::random(40);
```

Both jobs are always dispatched from tenant context (scheduler loop or a tenant web request), so `tenancy()->tenant` is set when they run on the worker via the `QueueTenancyBootstrapper`.

- [ ] **Step 3: Initialize tenancy from the token prefix in `GoogleWebhookController::handle()`**

After the four `$request->header(...)` reads and the `if (!$channel_id || !$resource_id)` guard, and before the `GoogleSyncedCalendar::where('watch_channel_id', $channel_id)->first()` lookup, add:

```php
$token_parts = explode('|', (string) $channel_token, 2);
if (count($token_parts) !== 2) {
    return response('Unknown channel', 404);
}

$tenant = \App\Models\Tenant::on('central')->find($token_parts[0]);
if (!$tenant) {
    return response('Unknown channel', 404);
}

tenancy()->initialize($tenant);
```

The rest of the method (channel lookup, full-token `hash_equals`, resource-id check, `PullCalendarChangesJob::dispatch`) stays exactly as it is — the dispatch happens inside tenant context, so the queue bootstrapper tags the job with the right tenant.

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/Google/RenewWatchChannelsJob.php \
        app/Jobs/Google/BackfillCalendarJob.php \
        app/Http/Controllers/GoogleWebhookController.php
git commit -m "feat(tenancy): route Google webhook to tenant via prefixed channel token"
```

---

## Task 26: `tenant:setup-existing` — register a pre-tenancy database

Registers an already-existing, already-migrated database as a tenant, **gives it its own MySQL user**, and copies its user emails into the central lookup. Uses a direct insert to skip the `TenantCreated` pipeline, since the database already exists and is already migrated. The package and modules for this tenant are set afterwards with `tenant:package` and `tenant:modules` (Task 34).

**Provisioning the MySQL user is not optional here.** After Task 2, `lavoro_app` can reach only the central database. A tenant registered without its own credentials is therefore unreachable by the web app — every request for it fails with an access-denied error. So this command runs as the provisioner and creates the user in the same breath:

```bash
sudo -u lavoro_provisioner php artisan tenant:setup-existing "Naam" lavoro_acme
```

The standalone `tenant:provision-user` command below exists for the cases this one does not cover: rotating a tenant's password, or repairing a tenant whose user was lost.

Note `User::withTrashed()` — `User` soft-deletes, and a trashed user's email still occupies `users.email` and still blocks `unique:users,email`. Copying only live users would leave those emails free centrally while unusable in the tenant, and let a *later* tenant claim them; the collision check below would then pass and the invariant would already be broken. This matches the Task 18 observer, which keeps the lookup row through a soft delete.

This command is used twice: for the main install's database during deployment (Task 27), and again for every dedicated-subdomain install that gets absorbed later (Task 29).

**Files:** `app/Console/Commands/SetupExistingTenant.php`

- [ ] **Step 1: Create the command**

The tenant is registered on the `starter` package (the safe default; raise it with `tenant:package` once you know the customer's real subscription). `extra_field_seats`, `extra_office_seats` and `storage_limit_gb` fall to their column defaults (0, 0, 50) on the raw insert. Seat-type counts are reported only once the tenant migrations have added `users.seat_type`; on a legacy database that column may not exist yet, so the command detects it and otherwise reminds the operator to migrate first.

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantUserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SetupExistingTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:setup-existing {name} {database}';
    protected $description = 'Register an existing pre-tenancy database as a tenant and give it its own MySQL user';

    public function handle(): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        if ($this->argument('database') === config('database.connections.central.database')) {
            $this->error('Refusing to register the central database as a tenant.');
            return self::FAILURE;
        }

        $id = (string) Str::ulid();

        DB::connection('central')->table('tenants')->insert([
            'id'          => $id,
            'name'        => $this->argument('name'),
            'package_key' => 'starter',
            'modules'     => json_encode([]),
            'data'        => json_encode(['tenancy_db_name' => $this->argument('database')]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $tenant = Tenant::on('central')->findOrFail($id);

        // Give the tenant its own confined MySQL user before anything tries to
        // reach its database — lavoro_app cannot, by design.
        app(TenantUserProvisioner::class)->provision($tenant);
        $this->info("Created MySQL user {$tenant->fresh()->tenancy_db_username} for {$this->argument('database')}.");

        tenancy()->initialize($tenant->fresh());

        $emails = User::withTrashed()->pluck('email');

        $conflicts = UserTenantLookup::on('central')
            ->whereIn('email', $emails)
            ->where('tenant_id', '!=', $id)
            ->pluck('email');

        if ($conflicts->isNotEmpty()) {
            tenancy()->end();
            DB::connection('central')->table('tenants')->where('id', $id)->delete();
            $this->error('Aborted: these emails already belong to another tenant:');
            $conflicts->each(fn ($email) => $this->error("  - {$email}"));
            $this->error('Resolve the duplicates in the source database first, then rerun.');
            return self::FAILURE;
        }

        $emails->each(function (string $email) use ($id) {
            UserTenantLookup::on('central')->updateOrCreate(
                ['email' => $email],
                ['tenant_id' => $id]
            );
        });

        $this->info("Registered '{$this->argument('name')}' as tenant: {$id}");
        $this->info("Populated {$emails->count()} email lookups.");

        if (Schema::connection('tenant')->hasColumn('users', 'seat_type')) {
            $field  = User::withTrashed()->where('seat_type', 'field')->count();
            $office = User::withTrashed()->where('seat_type', 'office')->count();
            $this->info("Seat usage: {$field} field / {$office} office — review against the package limits.");
        } else {
            $this->warn("seat_type not present yet. Run `php artisan tenants:migrate --tenants={$id}` (backfills every user to office), then mark the field staff.");
        }

        tenancy()->end();

        $this->warn("Set the package with: php artisan tenant:package {$id} <key>");
        $this->warn("Now migrate existing files into storage/tenant-{$id}/ — see Task 27 Step 5 / Task 29 Step 6.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Create the `TenantUserProvisioner` service**

This is the one place that creates a tenant's MySQL user, so `tenant:setup-existing`, the standalone command below, and any future rotation all behave identically. It reuses the package's own generators and grant list rather than duplicating them, so a tenant provisioned here is indistinguishable from one created by `tenant:create`.

```php
<?php

namespace App\Services;

use App\Models\Tenant;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

class TenantUserProvisioner
{
    public function provision(Tenant $tenant): void
    {
        $config = $tenant->database();

        $username = (DatabaseConfig::$usernameGenerator)($tenant);
        $password = (DatabaseConfig::$passwordGenerator)($tenant);

        $tenant->tenancy_db_username = $username;
        $tenant->tenancy_db_password = $password;
        $tenant->save();

        $manager = $config->manager();
        if (!$manager instanceof PermissionControlledMySQLDatabaseManager) {
            throw new \RuntimeException('The configured MySQL manager does not manage database users.');
        }

        if ($manager->userExists($username)) {
            $manager->deleteUser($tenant->database());
        }

        $manager->createUser($tenant->database());
    }
}
```

`$tenant->database()` returns a fresh `DatabaseConfig` that reads the credentials just saved, which is what `createUser()` grants against. Saving before creating is deliberate — if the grant fails, the stored credentials and the MySQL state are reconciled by re-running the command rather than left silently diverged.

- [ ] **Step 3: Create the standalone `tenant:provision-user` command**

For the first tenant during deployment (Task 27), for password rotation, and for repairing a tenant whose user was lost.

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Tenant;
use App\Services\TenantUserProvisioner;
use Illuminate\Console\Command;

class ProvisionTenantUser extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:provision-user {id}';
    protected $description = 'Create or rotate the dedicated MySQL user for a tenant';

    public function handle(TenantUserProvisioner $provisioner): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $provisioner->provision($tenant);

        $this->info("Tenant '{$tenant->name}' now uses MySQL user {$tenant->fresh()->tenancy_db_username}.");
        $this->warn('Restart the queue workers so they pick up the new credentials.');
        return self::SUCCESS;
    }
}
```

Rotating a live tenant's password briefly breaks in-flight connections; do it in a quiet window and restart workers afterwards.

- [ ] **Step 4: Verify**

```bash
sudo -u lavoro_provisioner php artisan tenant:provision-user <id>
mysql -u lavoro_provisioner --protocol=socket -e "SHOW GRANTS FOR '<printed-username>'@'%';"
```

Expected: grants on that tenant's database only.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SetupExistingTenant.php app/Console/Commands/ProvisionTenantUser.php \
        app/Services/TenantUserProvisioner.php
git commit -m "feat(tenancy): provision a dedicated MySQL user per tenant"
```

---

## Task 27: One-time deployment

Destructive and irreversible — run on a backup first. Do this in a maintenance window; all users will be logged out and must log in again.

**Prerequisites:** full MySQL dump taken; the `lavoro_app` account exists granted on `lavoro_tenants` only, and the `lavoro_provisioner` Linux user and its `auth_socket` MySQL account exist (Task 2, Steps 1–3); `.env` has `DB_CONNECTION=mysql`, `DB_DATABASE=lavoro_tenants`, `DB_USERNAME=lavoro_app`, `DB_SOCKET=/var/run/mysqld/mysqld.sock`, `SESSION_CONNECTION=central`.

**This is less destructive than it used to be.** Because the central database is now `lavoro_tenants` — a *new* name rather than the existing `lavoro` — nothing has to be dropped and recreated in place. The live `lavoro` database is copied to the tenant name and then simply **left alone** as a rollback artefact until the smoke test passes. An earlier revision of this plan dropped and recreated `lavoro` to free the name for central; that step is gone.

- [ ] **Step 0: Take the app down and stop everything that writes**

Step 1 drops and recreates the database the running application is connected to. Anything still writing during that window loses data silently, and a queue worker holding a booted container will keep serving stale config afterwards.

```bash
php artisan down

# Stop the queue worker and the scheduler cron (adjust to your process manager)
sudo systemctl stop lavoro-worker      # or: supervisorctl stop lavoro-worker:*
sudo crontab -l                        # confirm/comment the schedule:run entry
```

Confirm nothing is connected before continuing:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW PROCESSLIST;"
```

- [ ] **Step 1: Copy the existing database to the tenant name, and create an empty central**

MySQL has no rename-database command, so copy via dump/restore. The existing `lavoro` database is **not** modified — it stays exactly as it is, untouched, as the fastest possible rollback.

Run the dump as an admin account. The creates and the restore run as **`lavoro_provisioner`** — `lavoro_app` is confined to the central database after Task 2 and cannot create databases or write to a tenant database.

```bash
EXISTING=lavoro                  # the current production database, left intact
TENANT_DB=lavoro_acme            # rename to match the customer
CENTRAL_DB=lavoro_tenants

mysqldump -u root -p --single-transaction --routines --triggers "$EXISTING" > /tmp/tenant_backup.sql

sudo -u lavoro_provisioner mysql --protocol=socket -e "CREATE DATABASE $TENANT_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo -u lavoro_provisioner mysql --protocol=socket "$TENANT_DB" < /tmp/tenant_backup.sql
sudo -u lavoro_provisioner mysql --protocol=socket -e "CREATE DATABASE $CENTRAL_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

`--protocol=socket` is required: over TCP the account would be `lavoro_provisioner@127.0.0.1`, which does not exist. `--single-transaction` keeps the dump consistent without locking; `--routines --triggers` are there because a plain `mysqldump` silently omits both, which is an easy way to lose behaviour you did not know the schema had.

Confirm all three databases now exist and the copy is complete before continuing:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW DATABASES LIKE 'lavoro%';"
sudo -u lavoro_provisioner mysql --protocol=socket -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema IN ('$EXISTING','$TENANT_DB') GROUP BY table_schema;"
```

The two table counts must match.

- [ ] **Step 2: Clear every cached config, then run the central migrations**

`.env` gained `SESSION_CONNECTION=central` and `config/database.php`, `config/queue.php`, `config/tenancy.php` all changed. A cached config bundle from before the deploy would quietly override all of it — including pointing sessions and the queue at the wrong database.

```bash
php artisan optimize:clear
php artisan migrate --force
```

Creates `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `tenants`, `user_tenant_lookups`, `migrations` in the central database.

Sanity-check that `migrate` did **not** pick up tenant migrations (Task 8 moved them into a subdirectory that plain `migrate` does not descend into) — the central `migrations` table should hold 5 rows, not 200+:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e "SELECT COUNT(*) FROM lavoro_tenants.migrations;"
```

- [ ] **Step 3: Drop the now-unused `sessions` table from the tenant copy (optional tidy)**

The restored tenant database still contains a `sessions` table from before the split. It is unused (sessions are central now) and harmless; drop it if you want a clean schema:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket "$TENANT_DB" -e "DROP TABLE IF EXISTS sessions;"
```

- [ ] **Step 4: Register the existing database as tenant #1 and set its package/modules**

This both registers the tenant and creates its dedicated MySQL user, so it must run as the provisioner Linux user (Task 2):

```bash
sudo -u lavoro_provisioner php artisan tenant:setup-existing "Naam van het bedrijf" lavoro_acme
```

Confirm the tenant can actually be reached with its own credentials before going further — `lavoro_app` deliberately cannot reach it, so a missing user shows up as a site-wide access-denied error after `php artisan up`:

```bash
mysql -u lavoro_provisioner --protocol=socket \
  -e "SELECT tenancy_db_username FROM lavoro_tenants.tenants;"     # must be non-empty
```

Record the printed tenant ID — call it `TENANT_ID` for the next steps. Then:

```bash
php artisan tenant:package "$TENANT_ID" business
php artisan tenant:seats "$TENANT_ID" --field=+2 --office=+1
php artisan tenant:modules "$TENANT_ID" --add=snelstart --add=google_calendar
php artisan tenant:storage "$TENANT_ID" --limit=100
```

(Pick the package, extra seats, modules and storage limit that match the customer's actual subscription. Seed the seats and storage limit from their real usage so they do not start over limit — `tenant:setup-existing` printed the seat counts, and `du -sh storage/tenant-$TENANT_ID` shows the storage.)

- [ ] **Step 5: Move existing uploaded files into the tenant storage root**

Task 14 puts each tenant's files under `storage/tenant-<id>/public/...`. Existing files currently sit in `storage/app/public/...` (locally: `uploaded/` and `company-logos/`; production may also have `users/` avatar folders). Move them into the new root. **No database path rewrite is needed** — the stored `path` values are relative to the disk root, which is exactly what the per-tenant disk root now resolves against. The `app/releases/` folder (APK) stays where it is.

```bash
TENANT_ID=<paste-from-step-4>
cd storage

mkdir -p "tenant-$TENANT_ID/public"
# Move every existing public upload dir into the tenant's public root
for d in app/public/users app/public/uploaded app/public/company-logos; do
  [ -e "$d" ] && mv "$d" "tenant-$TENANT_ID/public/"
done

# If anything was stored on the private/local disk, move it too:
if [ -d app/private ] && [ -n "$(ls -A app/private 2>/dev/null)" ]; then
  mkdir -p "tenant-$TENANT_ID/local"
  mv app/private/* "tenant-$TENANT_ID/local/"
fi
```

After this, e.g. an image whose stored `path` is `uploaded/customer/5/documents/x.jpg` resolves to `storage/tenant-<id>/public/uploaded/customer/5/documents/x.jpg`, served via `/files/images/<id>`.

- [ ] **Step 6: Force-renew the Google watch channels**

Existing watch channels carry tokens without the tenant prefix (Task 25), so the webhook cannot route them. Expire them so the hourly renewal recreates them with prefixed tokens on the next run (the 5-minute polling schedule keeps sync working meanwhile):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket "$TENANT_DB" \
  -e "UPDATE google_synced_calendars SET watch_expires_at = NOW() WHERE watch_channel_id IS NOT NULL;"
```

- [ ] **Step 7: Build front-end assets, bring everything back up, and verify**

```bash
npm run build

# Restart workers so they pick up the new config, then lift maintenance mode
php artisan queue:restart
sudo systemctl start lavoro-worker     # or: supervisorctl start lavoro-worker:*
sudo crontab -e                        # restore the schedule:run entry
php artisan up

sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW TABLES IN lavoro_tenants;"
sudo -u lavoro_provisioner mysql --protocol=socket -e "SELECT id, name, package_key, storage_limit_gb, modules FROM lavoro_tenants.tenants;"
sudo -u lavoro_provisioner mysql --protocol=socket -e "SELECT COUNT(*) FROM lavoro_tenants.user_tenant_lookups;"
sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW TABLES IN lavoro_acme;" | head
```

- [ ] **Step 8: Smoke test**

Log in as an existing user, then walk this list. The first four are the failure modes most likely to survive to production, because each of them fails *silently* rather than with an error page:

- [ ] The dashboard loads, and a **detail page opens** (`/serviceorders/{id}`, `/customers/{id}`). A 404 on a record you can see in the index means the Task 12 middleware ordering is wrong and route-model binding is hitting the central database.
- [ ] An existing customer's documents and images display, and a previously uploaded avatar shows.
- [ ] **Export a werkbon to PDF** and confirm the photos *and* the company logo appear — this is the check for the Task 14 Step 7 path builders. A PDF that renders with everything except images means one of them was missed.
- [ ] **Send an appointment confirmation e-mail** and confirm the logo renders in the received message (Task 14 Step 7, item 6).
- [ ] Upload a new image and confirm it lands under `storage/tenant-<id>/public/…`, not `storage/app/public/`.
- [ ] Open the Planner and confirm its API calls return this tenant's events (`tenant.api` resolving from the session).
- [ ] Watch the log for a few minutes after the queue worker restarts — confirm no job fails with "table does not exist", which would mean a job ran without tenant context.
- [ ] Close the browser and reopen — remember-me should log you straight back in (session + tenant cookie).

Existing sessions are gone (the central `sessions` table is fresh), so everyone re-logs in once — expected.

- [ ] **Step 9: Rollback plan**

If the smoke test fails in a way you cannot fix inside the maintenance window, roll back rather than debug in production. **No database restore is involved** — `lavoro` was never modified, so rolling back is only a code and config revert plus moving the files back:

```bash
php artisan down

git checkout <pre-tenancy-tag>

# .env: DB_DATABASE back to lavoro, remove SESSION_CONNECTION
# (DB_USERNAME can stay lavoro_app — the grant covers lavoro too)

# Files back out of the tenant root (Step 5 in reverse)
cd storage
mv "tenant-$TENANT_ID/public/"* app/public/ 2>/dev/null
[ -d "tenant-$TENANT_ID/local" ] && mv "tenant-$TENANT_ID/local/"* app/private/ 2>/dev/null
cd ..

php artisan optimize:clear && npm run build && php artisan up
```

Everyone re-logs in again (sessions were in `lavoro_tenants`, which the reverted code no longer reads) — that is the only user-visible cost.

Leave `lavoro_acme`, `lavoro_tenants`, and `/tmp/tenant_backup.sql` in place; they cost nothing and let you retry. Drop the *original* `lavoro` only once the customer has run on the new setup for a week or two — and take a final dump of it before you do.

---

## Task 28: Verify isolation with a second tenant

- [ ] **Step 1: Create a second tenant with an admin**

```bash
php artisan tenant:create "Tweede Klant BV" admin@tweede.nl --admin-password=secret123 --package=team --modules=google_calendar
```

Confirm it prints a tenant ID, package, admin email, and password, and does not error. If it hangs, check the MySQL user has `CREATE DATABASE` and that no queue worker is needed (the pipeline runs inline via `shouldBeQueued(false)`).

- [ ] **Step 2: Confirm web isolation**

Log in as `admin@tweede.nl`. Confirm you see an empty data set (only the seeded stages), not the first tenant's data. Upload an image and confirm it lands under `storage/tenant-<second-id>/public/...` and displays via `/files/images/<id>`. Confirm that requesting another tenant's image id returns 404.

- [ ] **Step 3: Confirm the API resolves the tenant from the session**

While logged in as each tenant's user in the browser, open `/api/events?start=...&end=...` (or watch the Planner's own XHR calls) and confirm each tenant only sees their own events. Then confirm the fallback path:

```bash
# Unauthenticated, no session, no header — 400 from tenant.api:
curl http://localhost/api/events -H "Accept: application/json"
```

- [ ] **Step 4: Confirm a queued job runs in the right tenant**

```bash
php artisan queue:work --once --verbose
```

Trigger a Google sync (or any queued import) from one tenant and confirm the data lands in that tenant's database, not the other's or the central one.

- [ ] **Step 5: Confirm package/module data round-trips**

```bash
php artisan tenant:overview                            # the row shows Team, 1/5 field, 1/2 office, google_calendar
php artisan tenant:modules <second-id>                 # prints: google_calendar
```

And in the browser as the second tenant's user, check the Inertia page props include `tenant: { package: 'team', modules: ['google_calendar'] }`.

---

## Task 29: Migrate a dedicated-subdomain install into the multi-tenant app

Some customers run their own standalone copy of Lavoro on a dedicated subdomain — currently one at `spee.lavorofsm.nl` — each with its own database, storage, and `.env`. This task absorbs such an install into the multi-tenant app at `app.lavorofsm.nl` as a new tenant. It is a **repeatable runbook**: run it once per legacy install, in a maintenance window agreed with that customer. The steps below use the Spee install as the concrete example; substitute names for the next customer.

**Prerequisites:**
- Task 27 is complete: `app.lavorofsm.nl` is live and multi-tenant.
- The legacy install is upgraded to the **latest pre-tenancy release** so its schema matches the files now in `database/migrations/tenant/`. Verify: `php artisan migrate:status` on the legacy host must show every migration Ran and none Pending.
- SSH access to the legacy host, and the central MySQL user has `CREATE DATABASE`.

- [ ] **Step 1: Freeze the legacy install and take a final backup**

On the legacy host:

```bash
php artisan down
mysqldump -u "$OLD_DB_USER" -p"$OLD_DB_PASS" "$OLD_DB_NAME" > /tmp/spee_final.sql
```

Also stop the legacy queue worker and scheduler cron so nothing writes to the database after the dump.

- [ ] **Step 2: Transfer the dump and restore it as a tenant database**

On the central server:

```bash
scp legacy-host:/tmp/spee_final.sql /tmp/spee_final.sql
sudo -u lavoro_provisioner mysql --protocol=socket -e "CREATE DATABASE lavoro_spee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_spee < /tmp/spee_final.sql
```

- [ ] **Step 3: Drop the infrastructure tables from the copy**

Sessions are central now; the queue/cache tables in the copy are unused (jobs live centrally). Only `sessions` matters — the rest is optional tidying:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_spee \
  -e "DROP TABLE IF EXISTS sessions, cache, cache_locks, jobs, job_batches, failed_jobs;"
```

- [ ] **Step 4: Check for email collisions, then register the tenant**

Every user email must be globally unique across tenants. Check up front — and include **soft-deleted** users, because their emails still occupy `users.email` and are still copied into the lookup by `tenant:setup-existing` (see Task 26):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e \
  "SELECT u.email, u.deleted_at FROM lavoro_spee.users u
   JOIN lavoro_tenants.user_tenant_lookups l ON l.email = u.email;"
```

A collision on a *soft-deleted* row on either side is the easy case: force-delete that user in whichever database it is dead in, rather than renaming a live account. A collision between two live accounts (the same person working for two customers, or a shared `info@` address) needs a conversation with the customer — one of the two has to change.

If this returns rows, resolve them with the customer first (change the email in the source database). Then register — the command aborts and rolls back by itself if a collision slipped through:

```bash
sudo -u lavoro_provisioner php artisan tenant:setup-existing "Spee" lavoro_spee
```

(As provisioner — this also creates Spee's own MySQL user, without which the app cannot reach the imported database.)

Record the printed tenant ID as `TENANT_ID`, then set the subscription:

```bash
php artisan tenant:package "$TENANT_ID" business
php artisan tenant:seats "$TENANT_ID" --field=+7 --office=+1
php artisan tenant:modules "$TENANT_ID" --add=google_calendar
php artisan tenant:storage "$TENANT_ID" --limit=100
```

Seed the seats and storage from Spee's real usage so they do not import over limit — `tenant:setup-existing` printed the seat counts (after Step 5 migrates `seat_type`), and `du -sh storage/tenant-$TENANT_ID` shows the storage.

- [ ] **Step 5: Bring the imported schema up to date**

If the central app has gained tenant migrations newer than the legacy release, apply them (already-run migrations are recorded by filename in the imported `migrations` table, so only the new ones execute):

```bash
php artisan tenants:migrate --tenants="$TENANT_ID"
```

This works because Task 8 preserved every migration filename when moving files into `database/migrations/tenant/` — the imported `migrations` table matches on basename, not path. Two things to check before trusting the result:

```bash
# The imported migrations table still lists the three now-central migrations.
# Harmless (their tables are simply unused in the tenant copy) — do not delete the rows,
# or a later `tenants:migrate` will try to recreate sessions/cache/jobs in the tenant DB.
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_spee \
  -e "SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;"
```

Then diff against the first tenant to catch a legacy install that was further behind than `migrate:status` suggested:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -N -e "SHOW TABLES IN lavoro_acme;" | sort > /tmp/a.txt
sudo -u lavoro_provisioner mysql --protocol=socket -N -e "SHOW TABLES IN lavoro_spee;" | sort > /tmp/b.txt
diff /tmp/a.txt /tmp/b.txt
```

Expect the only differences to be the central-infrastructure tables dropped in Step 3.

- [ ] **Step 6: Copy the uploaded files into the tenant storage root**

On the central server, pull the legacy public disk into `storage/tenant-<id>/public/` (paths in the database are relative, so no rewrite is needed — same principle as Task 27 Step 5):

```bash
mkdir -p "storage/tenant-$TENANT_ID/public" "storage/tenant-$TENANT_ID/local"
rsync -av legacy-host:/path/to/lavoro/storage/app/public/ "storage/tenant-$TENANT_ID/public/"
rsync -av legacy-host:/path/to/lavoro/storage/app/private/ "storage/tenant-$TENANT_ID/local/"
```

- [ ] **Step 7: Re-home the Google Calendar integration**

The imported `google_synced_calendars` rows hold watch channels registered against `https://spee.lavorofsm.nl/google/webhook` with unprefixed tokens. Expire them so the hourly renewal re-registers them against the central webhook URL with tenant-prefixed tokens (5-minute polling covers the gap):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_spee \
  -e "UPDATE google_synced_calendars SET watch_expires_at = NOW() WHERE watch_channel_id IS NOT NULL;"
```

The stored OAuth refresh tokens keep working — they are not domain-bound. But if the legacy install used its **own** Google Cloud OAuth client, reconnecting later from `app.lavorofsm.nl` requires the central OAuth client instead; in that case expect the customer to redo the Google connection once (Beheer → Google koppeling) and tell them so up front.

- [ ] **Step 8: Redirect the old subdomain**

Keep `spee.lavorofsm.nl` DNS and its TLS certificate alive, but replace the vhost with a permanent redirect so bookmarks, the installed PWA, and password-reset links in old emails all land correctly:

```nginx
server {
    listen 443 ssl;
    server_name spee.lavorofsm.nl;
    # existing ssl_certificate lines stay

    return 301 https://app.lavorofsm.nl$request_uri;
}
```

- [ ] **Step 9: Verify**

- Log in at `app.lavorofsm.nl` with a Spee user's existing email and password (passwords migrate as-is — same hashes). Confirm their customers, service orders, images, and documents all show.
- Confirm `https://spee.lavorofsm.nl` redirects to `https://app.lavorofsm.nl`.
- Confirm a calendar event created in the Planner still syncs to Google.
- Confirm the first tenant's data is untouched and Spee users see none of it.

- [ ] **Step 10: Decommission the legacy install (after a grace period)**

Once the customer confirms everything works — suggest two weeks — remove the legacy app directory, drop its database on the old host, and remove its cron entries and queue worker. Keep the redirect vhost indefinitely; it costs nothing.

**Client-side caveats to communicate to the customer:**

- Everyone logs in again once — sessions and remember-me cookies do not carry over between domains.
- A PWA installed from `spee.lavorofsm.nl` is bound to that origin. The redirect keeps it functional, but users should remove it and install the PWA fresh from `app.lavorofsm.nl`.
- **Check the Android APK's base URL before migrating.** If the build the customer uses points at `spee.lavorofsm.nl`, plan an app update targeting `app.lavorofsm.nl` and have users reinstall and log in again; do not rely on the HTTP client following the 301 with cookies intact. FCM device tokens themselves are app-instance-bound, not domain-bound, so push notifications resume after re-login.

---

## Task 30: Tenant-aware test suite — MySQL only, and it must be structurally impossible to hit a live database

`phpunit.xml` currently pins tests to SQLite `:memory:`, which is fast, trivially isolated (a throwaway in-process database per run), and — critically — physically cannot be a live database. Multi-database tenancy does not work on SQLite (see Prerequisites), so tests must move to MySQL. Moving to MySQL removes the "physically cannot be live" guarantee SQLite gave us for free, so this task rebuilds that guarantee explicitly, in three independent layers, rather than trusting a correctly-set env var:

1. **Distinct database names.** The central test database is `lavoro_test_central`, never `lavoro` (the dev/prod name from Task 2). Tenant test databases get their own prefix, `lavoro_test_` (Task 3 set `lavoro_`), configured via a new env var so it can differ from the runtime prefix without touching `config/tenancy.php` again per environment.
2. **A hard runtime assertion.** The test bootstrap refuses to run — throws before a single query executes — if the resolved central database name doesn't contain `test`. This is the layer that survives someone fat-fingering `.env` or copy-pasting production values into `phpunit.xml` later.
3. **A distinct MySQL user with narrow grants (operational, done once outside the app).** Create a MySQL user that only has privileges on `lavoro_test_%` — even a fully wrong config in (1) and a bypassed assertion in (2) still cannot reach `lavoro` or any `lavoro_<tenant-id>` database, because the user has no grant on it. Document this as a required local/CI setup step; it is not something the application can enforce in code.

**How the existing `RefreshDatabase` test files change:** one shared test tenant is created once per test run (not once per test — creating a MySQL database per test would make the suite very slow), central and tenant migrations run once, and each individual test is wrapped in a transaction on *both* the `central` and `tenant` connections that rolls back after the test — the same isolation guarantee `RefreshDatabase` gave per-test, just spanning two connections instead of one. This logic moves from the per-file `RefreshDatabase` trait into the shared `TestCase`, so `RefreshDatabase` comes out of every file that used it. **35 test files** use it as of 2026-07-20 (the plan previously said 16) — re-run the grep in Step 5.

Two consequences of transaction-rollback isolation that `RefreshDatabase`'s truncate-and-remigrate did not have, and that may surface as test failures during this task:

- `AUTO_INCREMENT` counters are **not** reset between tests, because a rollback does not reclaim them. Any test asserting a literal id (`assertSame(1, $model->id)`) or relying on a predictable id ordering will start failing. Fix those tests to use the actual model's id.
- Code under test that issues DDL, an explicit `DB::beginTransaction()`, or `DB::unprepared` can implicitly commit and defeat the wrapper. `ProjectFinancialNotesMigrationTest` is worth checking first, since it exercises a data migration.

**Files:**
- `phpunit.xml`
- `tests/Concerns/RefreshesTenantDatabase.php` (new)
- `tests/TestCase.php`
- The 35 existing test files using `RefreshDatabase`

- [ ] **Step 0: Record the baseline before changing anything**

The success criterion for this task is "the same tests pass as before, on MySQL". That is only checkable against a recorded baseline — do this on the pre-tenancy commit, before Task 1:

```bash
git stash list                              # ensure a clean tree
php artisan test 2>&1 | tail -3 > /tmp/test-baseline.txt
cat /tmp/test-baseline.txt
```

Baseline as of 2026-07-20 on the pre-tenancy `master`: **209 passed, 542 assertions, ~5.9s** across 35 files. Expect the MySQL run to be noticeably slower than SQLite `:memory:` — that is normal and not a regression. What must not change is the pass count.

- [ ] **Step 1: Confirm the tenant database prefix is env-overridable**

Already handled — `config/tenancy.php` in Task 3 sets `'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_')`. Nothing to change here; just verify it reads from the env var before continuing, since Step 3's guard depends on it.

- [ ] **Step 2: Point `phpunit.xml` at a dedicated, clearly-named MySQL test database**

Replace the sqlite block:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

with:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_DATABASE" value="lavoro_test_central"/>
<env name="DB_USERNAME" value="lavoro_test"/>
<env name="DB_PASSWORD" value="lavoro_test"/>
<env name="TENANCY_DB_PREFIX" value="lavoro_test_"/>
<env name="SESSION_CONNECTION" value="central"/>
<env name="TENANCY_MYSQL_MANAGER" value="Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager"/>
```

**Tests deliberately use the plain `MySQLDatabaseManager`, not the permission-controlled one** (Task 3 made this env-overridable for exactly this reason). Creating a MySQL user per test run would require granting `lavoro_test` the `CREATE USER` privilege — server-wide, since MySQL will not scope it to a database pattern — which directly undermines the narrow grant this task exists to establish. The test tenant therefore connects with the `lavoro_test` credentials rather than its own.

The cost, stated plainly: the per-tenant credential path is **not** covered by the suite. If `TenantUserProvisioner` or the encrypted-password cast breaks, tests stay green and you find out on the server. The Task 21 Step 3 and Task 26 Step 4 manual verifications are the compensating control — run them after any change to tenant provisioning.

Also remove `SESSION_DRIVER` value `array` is fine to keep — the session table itself is never touched by tests that don't explicitly exercise auth, and `SESSION_CONNECTION=central` only matters when the `database` driver is used. Leave `SESSION_DRIVER=array` as-is.

- [ ] **Step 3: Create the tenancy test-setup trait**

```php
<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait RefreshesTenantDatabase
{
    protected static ?Tenant $testTenant = null;

    protected function setUpTenancy(): void
    {
        $central_db = config('database.connections.central.database');
        if (!str_contains($central_db, 'test')) {
            throw new RuntimeException(
                "Refusing to run tests against central database '{$central_db}' — " .
                "its name must contain 'test'. Check phpunit.xml's DB_DATABASE."
            );
        }

        $prefix = config('tenancy.database.prefix');
        if (!str_contains($prefix, 'test')) {
            throw new RuntimeException(
                "Refusing to run tests with tenant database prefix '{$prefix}' — " .
                "it must contain 'test'. Check phpunit.xml's TENANCY_DB_PREFIX."
            );
        }

        if (!static::$testTenant) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            static::$testTenant = Tenant::create(['id' => 'test-tenant', 'name' => 'Test Tenant', 'package_key' => 'enterprise']);
        }

        tenancy()->initialize(static::$testTenant);

        DB::connection('central')->beginTransaction();
        DB::connection('tenant')->beginTransaction();
    }

    protected function tearDownTenancy(): void
    {
        DB::connection('tenant')->rollBack();
        DB::connection('central')->rollBack();
        tenancy()->end();
    }
}
```

**The connection name is `tenant`, not `mysql`.** stancl's `DatabaseManager::connectToTenant()` creates a connection literally named `tenant` and calls `setDefaultConnection('tenant')`; the name is hardcoded in v3 and not configurable (see Task 3). An earlier revision of this plan used `DB::connection('mysql')` here, which begins a transaction on a *different, non-tenant* connection — tenant writes would commit for real and leak into every subsequent test, while the rollback silently succeeded against an untouched connection. Getting this wrong produces order-dependent test failures that look like flakiness.

The two `str_contains(..., 'test')` checks are layer (2) from the description above — they run before any migration or query, on every single test, and throw rather than silently proceeding. `Tenant::create()` synchronously runs the full `TenantCreated` pipeline from Task 11 (`CreateDatabase`, `MigrateDatabase`, `SeedDatabase` — `shouldBeQueued(false)`), so the first test that runs creates a real `lavoro_test_test-tenant` MySQL database, migrates it with the tenant migrations from Task 8, and seeds it with `TenantDatabaseSeeder` (Task 23). It is left behind after the run — cheap to keep, and `migrate:fresh` on the next run only refreshes the central schema, so a stale tenant database from a previous run is simply reused (its migrations already match, since `MigrateDatabase` is idempotent per the framework's migration tracking). If the tenant migrations change between runs and you want a fully clean slate, drop `lavoro_test_test-tenant` manually — it is a throwaway.

- [ ] **Step 4: Use the trait in the base `TestCase`**

Replace `tests/TestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }
}
```

- [ ] **Step 5: Remove `RefreshDatabase` from the existing test files**

Tenant-database refresh is now handled centrally by `TestCase`, so the per-file trait is redundant (and would try to migrate/refresh the single default connection using Laravel's normal single-connection logic, which doesn't know about the `central` connection at all).

```bash
grep -rl "RefreshDatabase" tests/   # 35 files as of 2026-07-20
```

In each matching file, remove the `use Illuminate\Foundation\Testing\RefreshDatabase;` import and the `use RefreshDatabase;` trait line inside the test class. Leave everything else in those files untouched.

Expect to run the suite iteratively here rather than in one pass — see the two isolation caveats at the top of this task. Convert the files, run `composer test`, and fix fallout in the tests rather than weakening the isolation in `TestCase`.

- [ ] **Step 6: Set up the local/CI test database and user (operational, run once)**

```sql
CREATE DATABASE IF NOT EXISTS lavoro_test_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lavoro_test'@'127.0.0.1' IDENTIFIED BY 'lavoro_test';
GRANT ALL PRIVILEGES ON `lavoro\_test\_%`.* TO 'lavoro_test'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The escaped underscores in `lavoro\_test\_%` matter — an unescaped `_` is a MySQL wildcard that would also match e.g. `lavoroXtest_evil`. This user cannot create, read, or drop `lavoro` or any `lavoro_<tenant-id>` database — that is layer (3) from the description above, and it holds even if every application-level check in Step 3 were somehow bypassed.

- [ ] **Step 7: Run the suite and verify against the baseline**

```bash
php artisan test 2>&1 | tail -3 | tee /tmp/test-after.txt
diff <(grep -o '[0-9]* passed' /tmp/test-baseline.txt) <(grep -o '[0-9]* passed' /tmp/test-after.txt)
```

The pass count must match the Step 0 baseline (209). Duration will be higher on MySQL — ignore it.

Then confirm the isolation layers actually held:

```bash
# No test run ever created or touched a live database
mysql -u lavoro_test -p -e "SHOW DATABASES LIKE 'lavoro%';"
```

You should see only `lavoro_test_central` and `lavoro_test_test-tenant`. `lavoro_tenants` and any `lavoro_<customer>` database must be **absent from this list entirely** — not merely untouched, but invisible, because the `lavoro_test` user has no grant on them. If you can see them here, the grant in Step 6 is too wide; fix it before trusting any of the above.

- [ ] **Step 7a: Work through failures in this order**

Some churn is expected — these are the causes to check first, roughly in likelihood order. Fix the *test*, not the isolation.

1. **Everything after the first HTTP request in a test fails.** The `$initialized_here` guard in the Task 12 / Task 24 middleware is missing, so the request ended the tenancy `TestCase` set up. 22 test files make requests, so this presents as mass failure.
2. **Hardcoded id assertions.** Transaction rollback does not reset `AUTO_INCREMENT`. Assert against `$model->id`, not `1`.
3. **Tests asserting on `users` uniqueness or user deletion.** `UserSoftDeleteTest`, `UserSoftDeleteVisibilityTest`, `UserDeletionAuthorizationTest`, and `UserHistoricalReferenceTest` now also exercise the Task 18 observer, which writes to the central `user_tenant_lookups` table on create/restore/force-delete. 24 test files create users via factory. If a factory generates a duplicate email the observer will throw a `RuntimeException` rather than a validation error — make the factory email unique if this shows up.
4. **`ProjectFinancialNotesMigrationTest`.** Exercises a data migration; DDL implicitly commits in MySQL and escapes the transaction wrapper. May need `RefreshDatabase`-style handling of its own.
5. **MySQL strict-mode differences from SQLite.** SQLite is permissive about types, string lengths, and invalid dates; MySQL is not. A test that passed on SQLite with an over-long string or a zero date will now fail legitimately — that is a real bug the old suite was hiding, so fix the code, not the test.
6. **Timezone assertions.** `StandardEmailRenderingTest` already asserts Amsterdam wall-clock times (commit `17840c9`). Confirm the MySQL session timezone does not shift these.

- [ ] **Step 7b: Add one test that would have caught the ordering bug**

The most expensive failure mode in this plan (Task 12) is invisible to the existing suite, because tests initialize tenancy directly rather than through the middleware. One test closes that gap permanently:

```php
public function test_a_bound_model_route_resolves_against_the_tenant_database(): void
{
    $user = User::factory()->create();
    $order = ServiceOrder::factory()->create();

    $this->actingAs($user)
        ->withSession(['tenant_id' => static::$testTenant->getTenantKey()])
        ->get("/serviceorders/{$order->id}")
        ->assertOk();
}
```

If `InitializeTenancyBySession` ever drifts after `SubstituteBindings` in the priority list, this goes red with a 404 instead of the whole app going quietly broken in production.

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml tests/Concerns/RefreshesTenantDatabase.php tests/TestCase.php tests/
git commit -m "test(tenancy): run the suite against isolated, clearly-named MySQL test databases"
```

> **Do not close out this plan with a red or skipped suite.** Task 30 is the gate for "tests still work after multi-tenancy" — the pass count must equal the Step 0 baseline, with no tests deleted or marked skipped to get there. If a test cannot be made to pass, that is a finding about the implementation, not about the test.

---

## Task 31: Enforce module subscriptions on gated features

Task 16 built the data model (`tenants.modules`), the `Tenant::hasModule()` check, the shared `tenant` Inertia prop, and the `hasModule()` JS helper — but nothing consumed them, so a tenant without e.g. the `tickets` module could still use every ticket route. This task wires the actual gates: a backend route middleware (authoritative — this is what actually blocks access) plus frontend nav/UI hiding using the existing helper (a UX nicety, not the security boundary).

Per CLAUDE.md, authorization belongs in Form Requests/policies, not ad-hoc controller checks — module gating is a tenancy/subscription concern that sits a layer above per-user permissions, so it's implemented as route middleware (the same pattern already used for `tenant.api` in Task 24), applied on top of the existing `auth` group and permission checks, not instead of them.

**Files:**
- `app/Http/Middleware/EnsureTenantHasModule.php` (new)
- `bootstrap/app.php`
- `routes/web.php`, `routes/api.php`
- `app/Http/Controllers/ServiceOrderController.php:326` (the only remaining `snelStartEnabled` flag)
- `resources/js/Composables/useSidebarNav.js`
- `resources/js/Components/GoogleCalendarSection.vue`
- `resources/js/Pages/Admin/GeneralSettingsPage.vue`

> **All line numbers below were re-verified 2026-07-20 and this file moves constantly.** Locate each route by its name/controller rather than by line number.

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Central\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantHasModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Module::on('central')->where('key', $module)->exists(), 500, "Onbekende module '{$module}'.");

        abort_unless(
            tenancy()->initialized && tenancy()->tenant->hasModule($module),
            403,
            'Deze functie is niet beschikbaar in uw abonnement.'
        );

        return $next($request);
    }
}
```

- [ ] **Step 2: Register the alias in `bootstrap/app.php`**, next to `tenant.api` from Task 24

```php
$middleware->alias([
    'admin'        => EnsureUserIsAdmin::class,
    'tenant.api'   => \App\Http\Middleware\InitializeTenancyForApi::class,
    'tenant.module' => \App\Http\Middleware\EnsureTenantHasModule::class,
]);
```

- [ ] **Step 3: Apply it to the module-gated route groups in `routes/web.php`**

Inside the existing `auth` group:

Tickets (lines ~164-168) — wrap the three ticket routes:

```php
Route::middleware('tenant.module:tickets')->group(function () {
    Route::post('tickets/bulk-update', [TicketController::class, 'bulkUpdate'])
        ->name('tickets.bulk-update');
    Route::get('tickets/map', [TicketController::class, 'map'])
        ->name('tickets.map');
    Route::resource('tickets', TicketController::class);
});
```

SnelStart — there are exactly **two** SnelStart routes today (lines ~230 and ~243); the `imports/snelstart/customers` route the previous revision listed no longer exists. They are not adjacent, so either wrap each individually or apply the middleware inline:

```php
Route::post('imports/snelstart/materials', [SnelStartImportController::class, 'importMaterials'])
    ->middleware('tenant.module:snelstart')
    ->name('imports.snelstart.materials');

Route::post('serviceorders/{serviceorder}/send-snelstart', [ServiceOrderController::class, 'sendToSnelStart'])
    ->middleware('tenant.module:snelstart')
    ->name('serviceorders.sendToSnelStart');
```

Note SnelStart *customer* import now happens through the generic Excel import (`CustomerImportController::looksLikeSnelStartExport`, auto-detecting a SnelStart export format from the file header). That is offline file parsing with no SnelStart API involvement, so it is deliberately **not** module-gated — gating it would block a plain spreadsheet upload.

Projects (lines ~314-316) — keep the existing registration order when wrapping:

```php
Route::middleware('tenant.module:projects')->group(function () {
    Route::resource('projects', ProjectController::class);
    Route::get('projects/{project}/timeline', [ProjectController::class, 'timeline'])
        ->name('projects.timeline');
    Route::resource('projectmilestones', ProjectMilestoneController::class);
});
```

Google Calendar (lines ~343-348):

```php
Route::middleware('tenant.module:google_calendar')->group(function () {
    Route::get('google/oauth/start', [GoogleOAuthController::class, 'start'])
        ->name('google.oauth.start');
    Route::get('google/oauth/callback', [GoogleOAuthController::class, 'callback'])
        ->name('google.oauth.callback');
    Route::delete('google/integration', [GoogleOAuthController::class, 'destroy'])
        ->name('google.integration.destroy');
});
```

Location tracking — inside the nested `admin` group (line ~354), wrap the settings route at lines ~381-384:

```php
Route::put('admin/settings/location-tracking', [GeneralSettingsController::class, 'updateLocationTracking'])
    ->middleware('tenant.module:location_tracking')
    ->name('admin.settings.location-tracking');
```

(Use the controller/action already on that route — copy it from the current lines 381-384 rather than retyping the signature from scratch, since the exact method name should match what's there today.)

Also add the same `tenant.module:google_calendar` middleware to the `api.google.integration.status` route in `routes/api.php` (`GET google/integration/status`, an invokable `GoogleIntegrationStatusController`, inside the `tenant.api` group from Task 24), so the status check itself 403s for tenants without the module rather than reporting "not connected".

- [ ] **Step 4: Gate the SnelStart UI at the source — extend the existing `snelStartEnabled` flag**

There is now exactly **one** `snelStartEnabled` producer, `ServiceOrderController.php:326` (the previous revision listed three; `CustomerController` and `CustomerImportController` no longer expose it):

```php
'snelStartEnabled' => filled(config('services.snelstart.client_key')),
```

Change it to also require the module:

```php
'snelStartEnabled' => filled(config('services.snelstart.client_key'))
    && tenancy()->initialized
    && tenancy()->tenant->hasModule('snelstart'),
```

This reuses the exact prop `ServiceOrders/ShowPage.vue` already gates its SnelStart button on (`v-if="snelStartEnabled && hasPermission('snelstart.send_serviceorder')"`, line 448) — no frontend changes needed for SnelStart. The materials-import button is gated by the route middleware from Step 3 alone.

- [ ] **Step 5: Gate the Tickets and Projects nav items**

Navigation moved out of `MainLayout.vue` in the 2026-07 sidebar redesign — it now lives in **`resources/js/Composables/useSidebarNav.js`**. Add `requiresModule` next to the existing `requiresPermission` on these two entries (lines ~116 and ~172):

```js
{ name: 'Storingen', href: '/tickets', icon: ExclamationCircleIcon, current: false, requiresPermission: 'ticket.see_all', requiresModule: 'tickets' },
```

```js
{ name: 'Projecten', href: '/projects', icon: ClipboardDocumentListIcon, current: false, requiresPermission: 'project.read', requiresModule: 'projects' },
```

Extend `canSeeNavItem` (line ~179) to also check it. The module check goes **first**, before the `adminOnly` branch: a module is a subscription boundary, not a permission, so an admin of a tenant that doesn't pay for Projecten must not see it either. (Contrast `hasPermission`, which deliberately returns `true` for admins.)

```js
import { hasModule, hasPermission, initials as getInitials } from '@/Utilities/Utilities'

const canSeeNavItem = (item) => {
    if (item?.requiresModule && !hasModule(item.requiresModule)) return false
    if (item?.adminOnly) return isAdmin.value
    if (item?.requiresAnyPermission) return item.requiresAnyPermission.some(hasPermission)
    if (!item?.requiresPermission) return true
    return hasPermission(item.requiresPermission)
}
```

`canSeeNavItem` is reused by `filteredNavigation`, `filteredLists` and the children filter (lines ~186-195), so this one change covers nested entries too. The file currently imports `{ hasPermission, initials as getInitials }` on line 3 — extend that import rather than adding a second one.

- [ ] **Step 6: Gate the Google Calendar section and location-tracking settings**

In `resources/js/Components/GoogleCalendarSection.vue`, import `hasModule` from `@/Utilities/Utilities` and wrap the section's root template element in `v-if="hasModule('google_calendar')"`.

In `resources/js/Pages/Admin/GeneralSettingsPage.vue`, do the same around the location-tracking settings block, using `hasModule('location_tracking')`.

- [ ] **Step 7: Verify**

- As a tenant without the `tickets` module: `GET /tickets` returns 403; the "Storingen" nav item does not render.
- `php artisan tenant:modules <id> --add=tickets`, reload: the route works and the nav item appears.
- Same pattern for `projects`, `google_calendar`, `snelstart`, `location_tracking`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/EnsureTenantHasModule.php bootstrap/app.php routes/web.php routes/api.php \
        app/Http/Controllers/ServiceOrderController.php \
        resources/js/Composables/useSidebarNav.js resources/js/Components/GoogleCalendarSection.vue \
        resources/js/Pages/Admin/GeneralSettingsPage.vue
git commit -m "feat(tenancy): enforce module subscriptions on gated routes and UI"
```

---

## Task 32: Per-tenant Microsoft Graph mail credentials

Today `Mail::extend('graph', ...)` (`AppServiceProvider.php:83`) builds the transport entirely from `config('services.graph.*')`, which reads global `GRAPH_TENANT_ID` / `GRAPH_CLIENT_ID` / `GRAPH_CLIENT_SECRET` / `GRAPH_USER_ID` / `GRAPH_ENDPOINT` env vars — every tenant sends mail through the same Azure app registration and mailbox. Move these into each tenant's `general_settings` table (already a tenant-scoped table per the top-of-plan table, using the existing `GeneralSetting::get`/`set` key-value helper — the same mechanism Known impact point 4 recommends), read per-request from the active tenant, and fall back to the global env values when a tenant hasn't configured its own mailbox yet, so nothing regresses for the current single tenant during the Task 27 migration.

**The subtlety this task exists to handle:** `Illuminate\Mail\MailManager` caches a resolved `graph` transport instance for the lifetime of the container. In a classic PHP-FPM request that's harmless (one tenant per request/process). On a queue worker processing jobs for multiple tenants in sequence without restarting, the *first* tenant's credentials would get cached and silently reused for a *later* tenant's queued mail (e.g. `SendStandardEmailJob`) in the same worker process. This is fixed by forgetting the resolved mailer whenever tenancy switches, mirroring the `forgetDriver`/`forgetDisk` pattern already used by `PrefixCacheBootstrapper` (Task 10) and `TenantStorageBootstrapper` (Task 14).

**Files:** `app/Providers/AppServiceProvider.php`, `app/Providers/TenancyServiceProvider.php`

- [ ] **Step 1: Rewrite the `Mail::extend('graph', ...)` closure**

In `AppServiceProvider::boot()`, replace the existing closure:

```php
Mail::extend('graph', function () {
    $tenant_setting = fn (string $key, string $config_key) => tenancy()->initialized
        ? GeneralSetting::get($key, config("services.graph.{$config_key}"))
        : config("services.graph.{$config_key}");

    return new GraphTransport(
        tenantId: $tenant_setting('graph_tenant_id', 'tenant_id'),
        clientId: $tenant_setting('graph_client_id', 'client_id'),
        clientSecret: $tenant_setting('graph_client_secret', 'client_secret'),
        fromAddress: config('mail.from.address'),
        userId: $tenant_setting('graph_user_id', 'user_id'),
        graphEndpoint: $tenant_setting('graph_endpoint', 'endpoint'),
        dispatcher: app('events'),
        logger: app('log')->channel()
    );
});
```

Add `use App\Models\GeneralSetting;` to the file's imports.

- [ ] **Step 2: Forget the cached mailer whenever tenancy switches**

In `TenancyServiceProvider::boot()` (Task 11), add listeners for both tenancy events next to the existing ones:

```php
Event::listen(TenancyInitialized::class, function () {
    app('mail.manager')->forgetMailers();
});
Event::listen(TenancyEnded::class, function () {
    app('mail.manager')->forgetMailers();
});
```

These run in addition to (not instead of) the existing `BootstrapTenancy` / `RevertToCentralContext` listeners already registered for those events — Laravel dispatches all listeners for an event, order doesn't matter here since both only affect mailer resolution, not tenancy state itself.

- [ ] **Step 3: Set a tenant's Graph credentials via `GeneralSetting`**

No new CLI command — `general_settings` is a plain key-value tenant table, so this is done the same way any other tenant setting is set today (e.g. through `php artisan tinker` while tenancy is initialized for that tenant, or a future admin UI). Document the four keys tenants can override: `graph_tenant_id`, `graph_client_id`, `graph_client_secret`, `graph_user_id` (optional — omit to send as the shared mailbox), `graph_endpoint` (optional — omit to use the default `https://graph.microsoft.com/v1.0`).

- [ ] **Step 4: Verify**

- With no tenant-specific settings: mail still sends via the global `GRAPH_*` env credentials (unchanged behavior).
- Set `graph_tenant_id`/`graph_client_id`/`graph_client_secret` via `GeneralSetting::set()` for the test tenant from Task 28, send a test mail (`php artisan` equivalent of `TestGraphMail`), confirm it authenticates against the tenant-specific Azure app registration (check the Microsoft Entra sign-in log for that tenant ID, or deliberately misconfigure the secret and confirm the send fails with that tenant's error rather than silently succeeding via the global credentials).
- Dispatch two queued `SendStandardEmailJob`s for two different tenants with different Graph credentials back-to-back on the same worker process; confirm both use their own tenant's credentials (this is the scenario Step 2 fixes — without it, the second job would silently reuse the first tenant's transport).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Providers/TenancyServiceProvider.php
git commit -m "feat(tenancy): resolve Microsoft Graph mail credentials per tenant"
```

---

## Task 33: Add `seat_type` to users (migration, backfill, factory, form field)

Every user is a `field` (buitendienst) or `office` (kantoor) seat. This is a tenant-database column. The column carries a DB-level default of `office` — the cheaper seat — as a safety net, but the create form still **requires** an explicit choice so a human never bills the wrong bucket by omission. The backfill sets every existing user to `office` and forces `plannable = false`; field staff are marked by hand afterwards (this empties the planner until that is done — an accepted, one-time cost).

**Files:**
- `database/migrations/tenant/2026_07_21_130001_add_seat_type_to_users_table.php` (new)
- `database/factories/UserFactory.php`
- `app/Http/Requests/UserStoreRequest.php`, `app/Http/Requests/UserUpdateRequest.php`
- `resources/js/Pages/Users/EditPage.vue`

**Interfaces:**
- Produces: `users.seat_type` (`field`|`office`, default `office`); `UserFactory` default `seat_type = 'office'` with a `field()` state; `seat_type` required (`in:field,office`) in the user store/update requests.

- [ ] **Step 1: Create the tenant migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('seat_type')->default('office')->after('plannable');
        });

        // Backfill: everyone office (the column default already did this) and not plannable.
        // Field staff are promoted by hand afterwards.
        DB::table('users')->update(['plannable' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('seat_type');
        });
    }
};
```

- [ ] **Step 2: Give the factory a seat type**

In `database/factories/UserFactory.php`, add `'seat_type' => 'office'` to the `definition()` array, and add a state below it:

```php
public function field(): static
{
    return $this->state(fn (array $attributes) => ['seat_type' => 'field']);
}
```

- [ ] **Step 3: Require `seat_type` in the store request**

In `UserStoreRequest::rules()`, add:

```php
'seat_type' => 'required|in:field,office',
```

- [ ] **Step 4: Require `seat_type` in the update request**

In `UserUpdateRequest::rules()`, add the same line to the `$rules` array:

```php
'seat_type' => 'required|in:field,office',
```

- [ ] **Step 5: Add the seat-type field to the user form**

In `resources/js/Pages/Users/EditPage.vue`, add `seat_type` to the `useForm({...})` call:

```js
seat_type: props.user?.seat_type || 'office',
```

and render a select next to the `plannable` checkbox (reuse the `SelectMenuComponent` or a plain `<select>` already used elsewhere in this form), with options `Buitendienst` → `field` and `Kantoor` → `office`, plus the standard `form.errors.seat_type` line:

```vue
<select v-model="form.seat_type" class="...">
    <option value="field">Buitendienst</option>
    <option value="office">Kantoor</option>
</select>
<div v-if="form.errors.seat_type" class="text-xs text-red-600 mt-1">{{ form.errors.seat_type }}</div>
```

- [ ] **Step 6: Run the suite — existing user tests must still pass**

Run: `php artisan test --filter=User`
Expected: PASS. The factory now supplies `seat_type`, so inserts satisfy the column; the store/update requests now require it, so any test that POSTs a user must include `seat_type` (update those tests to pass `'seat_type' => 'office'`).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/tenant/2026_07_21_130001_add_seat_type_to_users_table.php \
        database/factories/UserFactory.php \
        app/Http/Requests/UserStoreRequest.php app/Http/Requests/UserUpdateRequest.php \
        resources/js/Pages/Users/EditPage.vue
git commit -m "feat(tenancy): add seat_type to users with form field and backfill"
```

---

## Task 34: Licensing CLI — catalogue CRUD and per-tenant subscription commands

The commands that manage the price catalogue and each tenant's subscription. All run in central context. Money arguments are in cents. `set` commands upsert. Catalogue-delete commands refuse while a tenant still references the row.

**Files (new):**
- `app/Console/Commands/Licensing/PackageCommand.php`, `ModuleCommand.php`, `BundleCommand.php`, `PricingCommand.php`
- `app/Console/Commands/Licensing/TenantPackageCommand.php`, `TenantSeatsCommand.php`, `TenantModulesCommand.php`, `TenantStorageCommand.php`, `TenantOverrideCommand.php`, `TenantOverviewCommand.php`
- `tests/Feature/LicensingCommandsTest.php`

**Interfaces:**
- Consumes: `Package`, `Module`, `ModuleBundle`, `PricingSetting`, `TenantSubscription` (Task 16); `Tenant` (Task 4).

- [ ] **Step 1: Write the failing referential-integrity test**

```php
<?php

namespace Tests\Feature;

use App\Models\Central\Package;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LicensingCommandsTest extends TestCase
{
    public function test_package_delete_refuses_while_a_tenant_uses_it(): void
    {
        Tenant::on('central')->where('id', '!=', 'test-tenant')->delete();
        Tenant::create(['id' => 'acme', 'name' => 'Acme', 'package_key' => 'business']);

        $code = Artisan::call('package:delete', ['key' => 'business']);

        $this->assertSame(1, $code); // FAILURE
        $this->assertTrue(Package::on('central')->where('key', 'business')->exists());
    }

    public function test_package_set_updates_an_existing_price(): void
    {
        Artisan::call('package:set', ['key' => 'business', '--price' => 17000, '--no-interaction' => true]);

        $this->assertSame(17000, (int) Package::on('central')->where('key', 'business')->value('price_cents'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=LicensingCommandsTest`
Expected: FAIL — command `package:delete` / `package:set` not defined.

- [ ] **Step 3: Implement `package:list|set|delete`**

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use Illuminate\Console\Command;

class PackageCommand extends Command
{
    protected $signature = 'package:set {key}
        {--name=} {--field-seats=} {--office-seats=} {--price=} {--extra-field=} {--extra-office=}';
    protected $description = 'Create or update a package';

    public function handle(): int
    {
        $map = array_filter([
            'name'               => $this->option('name'),
            'field_seats'        => $this->option('field-seats'),
            'office_seats'       => $this->option('office-seats'),
            'price_cents'        => $this->option('price'),
            'extra_field_cents'  => $this->option('extra-field'),
            'extra_office_cents' => $this->option('extra-office'),
        ], fn ($v) => $v !== null);

        $package = Package::on('central')->firstOrNew(['key' => $this->argument('key')]);
        $affected = Tenant::on('central')->where('package_key', $package->key)->count();

        if ($package->exists && isset($map['price_cents']) && $affected > 0) {
            $this->warn("This re-prices {$affected} tenant(s) on '{$package->key}'.");
            if (!$this->confirm('Continue?', false)) {
                return self::FAILURE;
            }
        }

        $package->fill($map);
        $package->name ??= ucfirst($package->key);
        $package->save();

        $this->info("Package '{$package->key}' saved.");
        return self::SUCCESS;
    }
}
```

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use Illuminate\Console\Command;

class PackageDeleteCommand extends Command
{
    protected $signature = 'package:delete {key}';
    protected $description = 'Delete a package unless a tenant uses it';

    public function handle(): int
    {
        $package = Package::on('central')->where('key', $this->argument('key'))->first();
        if (!$package) {
            $this->error('Package not found.');
            return self::FAILURE;
        }

        $tenants = Tenant::on('central')->where('package_key', $package->key)->pluck('name');
        if ($tenants->isNotEmpty()) {
            $this->error("Refusing to delete '{$package->key}' — used by: " . $tenants->implode(', '));
            return self::FAILURE;
        }

        $package->delete();
        $this->info("Package '{$package->key}' deleted.");
        return self::SUCCESS;
    }
}
```

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use Illuminate\Console\Command;

class PackageListCommand extends Command
{
    protected $signature = 'package:list';
    protected $description = 'List packages';

    public function handle(): int
    {
        $rows = Package::on('central')->orderBy('sort_order')->get()
            ->map(fn ($p) => [
                $p->key, $p->name, $p->field_seats, $p->office_seats,
                number_format($p->price_cents / 100, 2, ',', '.'),
                number_format($p->extra_field_cents / 100, 2, ',', '.'),
                number_format($p->extra_office_cents / 100, 2, ',', '.'),
            ])->all();

        $this->table(['key', 'name', 'field', 'office', 'prijs', 'extra field', 'extra office'], $rows);
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Implement `module:list|set|delete` and `bundle:list|set|delete`**

Mirror the package commands. `module:set {key} {--name=} {--price=}` upserts a `Module`; `module:delete {key}` refuses while any tenant's `modules` JSON contains the key:

```php
$in_use = Tenant::on('central')->whereJsonContains('modules', $key)->pluck('name');
if ($in_use->isNotEmpty()) {
    $this->error("Refusing to delete module '{$key}' — used by: " . $in_use->implode(', '));
    return self::FAILURE;
}
```

`bundle:set {name} {--modules=} {--price=}` upserts a `ModuleBundle` (splitting `--modules=quotes,invoices` on commas into the `module_keys` array); `bundle:delete {name}` deletes by name.

- [ ] **Step 5: Implement `pricing:list|set` for the storage scalars**

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\PricingSetting;
use Illuminate\Console\Command;

class PricingCommand extends Command
{
    protected $signature = 'pricing:set {key} {value}';
    protected $description = 'Set a pricing scalar (included_storage_gb, storage_extra_per_gb_cents)';

    public function handle(): int
    {
        $allowed = ['included_storage_gb', 'storage_extra_per_gb_cents'];
        if (!in_array($this->argument('key'), $allowed, true)) {
            $this->error('Unknown key. Allowed: ' . implode(', ', $allowed));
            return self::FAILURE;
        }

        PricingSetting::on('central')->updateOrCreate(
            ['key' => $this->argument('key')],
            ['value' => (int) $this->argument('value')]
        );

        $this->info("Set {$this->argument('key')} = {$this->argument('value')}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Implement the per-tenant subscription commands**

`tenant:package {id} {key}` validates the key against `packages` and sets `package_key`. `tenant:seats {id} {--field=} {--office=}` accepts signed deltas (`+5`, `-2`) or absolute values and clamps at 0. `tenant:modules {id} {--add=*} {--remove=*}` validates against `modules` and edits the JSON (the same body the old command used). `tenant:storage {id} {--limit=}` sets `storage_limit_gb`. `tenant:override {id} {--price=} {--clear}` sets or nulls `price_override_cents`. Each prints the tenant's new monthly total via `(new TenantSubscription($tenant))->monthlyTotalCents()`.

Example — `tenant:storage`:

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantStorageCommand extends Command
{
    protected $signature = 'tenant:storage {id} {--limit=}';
    protected $description = 'Show or set a tenant storage limit (GB)';

    public function handle(): int
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        if ($this->option('limit') !== null) {
            $tenant->update(['storage_limit_gb' => max(0, (int) $this->option('limit'))]);
        }

        $this->info("Tenant '{$tenant->name}' storage limit: {$tenant->fresh()->storage_limit_gb} GB");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Implement `tenant:overview`**

For each tenant it switches into tenant context, counts users by `seat_type` and reads `storage_used_bytes` (default 0 until Task 36 populates it), then computes the monthly total in central context. Follows the scheduler's per-tenant pattern (Task 20). Flags a `⚠` when usage exceeds a limit.

```php
public function handle(): int
{
    $rows = [];
    $total = 0;

    Tenant::on('central')->cursor()->each(function (Tenant $tenant) use (&$rows, &$total) {
        $package = \App\Models\Central\Package::on('central')->where('key', $tenant->package_key)->first();

        tenancy()->initialize($tenant);
        $field  = \App\Models\User::where('seat_type', 'field')->count();
        $office = \App\Models\User::where('seat_type', 'office')->count();
        $used_gb = (int) round(\App\Models\GeneralSetting::get('storage_used_bytes', 0) / (1024 ** 3));
        tenancy()->end();

        $field_limit  = ($package->field_seats ?? 0) + $tenant->extra_field_seats;
        $office_limit = ($package->office_seats ?? 0) + $tenant->extra_office_seats;
        $monthly = (new \App\Services\TenantSubscription($tenant))->monthlyTotalCents();
        $total += $monthly;

        $rows[] = [
            $tenant->name,
            $package->name ?? '—',
            $field . '/' . $field_limit . ($field > $field_limit ? ' ⚠' : ''),
            $office . '/' . $office_limit . ($office > $office_limit ? ' ⚠' : ''),
            $used_gb . '/' . $tenant->storage_limit_gb . ' GB',
            implode(',', $tenant->modules ?? []) ?: '—',
            '€' . number_format($monthly / 100, 2, ',', '.'),
        ];
    });

    $this->table(['Naam', 'Pakket', 'Field', 'Office', 'Opslag', 'Modules', '/mnd'], $rows);
    $this->info('Totaal: €' . number_format($total / 100, 2, ',', '.'));
    return self::SUCCESS;
}
```

- [ ] **Step 8: Run the command tests to verify they pass**

Run: `php artisan test --filter=LicensingCommandsTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Console/Commands/Licensing/ tests/Feature/LicensingCommandsTest.php
git commit -m "feat(tenancy): licensing CLI for catalogue and tenant subscriptions"
```

---

## Task 35: Enforce seat limits and seat-type capability

Two enforcement layers, both validation. **Seat limits:** a `SeatAvailable` rule blocks creating/promoting a user into a full seat type, while never touching existing users. **Capability:** an office user cannot be made plannable and cannot be assigned as an executing user on an event — this is what makes a seat type mean something and stops the limit being gamed. Seat usage is also shared to the frontend so the user page can show "5 van 10".

**Files:**
- `app/Rules/SeatAvailable.php` (new)
- `app/Http/Requests/UserStoreRequest.php`, `UserUpdateRequest.php`, `UserRestoreRequest.php`
- `app/Http/Requests/UpdateUserPlannableRequest.php`, `EventStoreRequest.php`, `EventUpdateRequest.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `tests/Feature/SeatLimitTest.php`, `tests/Feature/SeatCapabilityTest.php`

**Interfaces:**
- Consumes: `Package` (Task 16), `users.seat_type` (Task 33), `tenancy()->tenant` (Task 4/12).
- Produces: `App\Rules\SeatAvailable` — `new SeatAvailable(?int $ignore_user_id = null)`. The seat type it checks is the attribute value the validator passes in, not a constructor argument.

- [ ] **Step 1: Write the failing seat-limit test**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class SeatLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin the test tenant to Starter (1 field / 1 office) for these tests.
        Tenant::on('central')->where('id', 'test-tenant')->update([
            'package_key' => 'starter', 'extra_field_seats' => 0, 'extra_office_seats' => 0,
        ]);
    }

    public function test_creating_a_field_user_beyond_the_limit_fails(): void
    {
        $admin = User::factory()->field()->create();
        User::factory()->field()->create(); // fills the single field seat

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nieuw', 'email' => 'nieuw@x.nl', 'password' => 'secret12',
            'seat_type' => 'field',
        ])->assertSessionHasErrors('seat_type');

        $this->assertSame(2, User::where('seat_type', 'field')->count());
    }

    public function test_a_soft_deleted_user_frees_a_seat(): void
    {
        $admin = User::factory()->office()->create();
        $worker = User::factory()->field()->create();
        $worker->delete(); // soft delete frees the field seat

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nieuw', 'email' => 'nieuw@x.nl', 'password' => 'secret12',
            'seat_type' => 'field',
        ])->assertSessionHasNoErrors();
    }
}
```

(Add an `office()` factory state alongside `field()` in Task 33's factory edit, or use the default.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SeatLimitTest`
Expected: FAIL — a third field user is allowed because no rule enforces the limit.

- [ ] **Step 3: Implement the `SeatAvailable` rule**

```php
<?php

namespace App\Rules;

use App\Models\Central\Package;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SeatAvailable implements ValidationRule
{
    public function __construct(private ?int $ignore_user_id = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== 'field' && $value !== 'office') {
            return; // the in: rule reports an invalid value
        }

        if (!tenancy()->initialized) {
            return;
        }

        $package = Package::on('central')->where('key', tenancy()->tenant->package_key)->first();
        if (!$package) {
            return;
        }

        $base  = $value === 'field' ? $package->field_seats : $package->office_seats;
        $extra = $value === 'field' ? tenancy()->tenant->extra_field_seats : tenancy()->tenant->extra_office_seats;
        $limit = $base + $extra;

        $query = User::where('seat_type', $value);
        if ($this->ignore_user_id) {
            $query->whereKeyNot($this->ignore_user_id);
        }

        if ($query->count() >= $limit) {
            $label = $value === 'field' ? 'buitendienst' : 'kantoor';
            $fail("Uw licentie staat {$limit} {$label}gebruikers toe. Neem contact op om uit te breiden.");
        }
    }
}
```

- [ ] **Step 4: Apply the rule in the user requests**

In `UserStoreRequest::rules()`, change the `seat_type` line (added in Task 33) to:

```php
'seat_type' => ['required', 'in:field,office', new \App\Rules\SeatAvailable()],
```

In `UserUpdateRequest::rules()`, pass the ignored user so an unchanged office→office or field→field edit does not count the user against itself:

```php
'seat_type' => ['required', 'in:field,office', new \App\Rules\SeatAvailable($ignore_id)],
```

(`$ignore_id` is already computed in that request for the email rule.) In `UserRestoreRequest`, add an `after` validation hook or a `rules()` entry that runs `SeatAvailable` against the trashed user's `seat_type`, so restoring into a full seat type is refused.

- [ ] **Step 5: Write and pass the capability test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class SeatCapabilityTest extends TestCase
{
    public function test_an_office_user_cannot_be_made_plannable(): void
    {
        $admin  = User::factory()->office()->create();
        $office = User::factory()->office()->create();

        $this->actingAs($admin)
            ->patch("/api/users/{$office->id}/plannable", ['plannable' => true])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 6: Enforce capability in the requests**

In `UpdateUserPlannableRequest`, reject `plannable = true` when the target user (the route `{user}`) is an office seat:

```php
public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        $user = $this->route('user');
        if ($this->boolean('plannable') && $user && $user->seat_type === 'office') {
            $validator->errors()->add('plannable', 'Een kantoorgebruiker kan niet ingepland worden.');
        }
    });
}
```

In `EventStoreRequest` and `EventUpdateRequest`, reject any office user in the executing-user list (the field that carries executing user ids — reuse the exact field name already validated there):

```php
$validator->after(function ($validator) {
    $office_ids = \App\Models\User::whereIn('id', (array) $this->input('executing_user_ids', []))
        ->where('seat_type', 'office')->pluck('id');
    if ($office_ids->isNotEmpty()) {
        $validator->errors()->add('executing_user_ids', 'Kantoorgebruikers kunnen niet worden ingepland op een afspraak.');
    }
});
```

Confirm the executing-user field name against the current request (Task interfaces note it may be `executing_user_ids` or nested) before wiring.

- [ ] **Step 7: Share seat usage with the frontend**

Extend the `tenant` share added in Task 16 (`HandleInertiaRequests`) with seat usage, so the user page can show "5 van 10":

```php
'tenant' => tenancy()->initialized ? [
    'package' => tenancy()->tenant->package_key,
    'modules' => tenancy()->tenant->modules ?? [],
    'seats'   => [
        'field'  => ['used' => \App\Models\User::where('seat_type', 'field')->count(),  'limit' => $field_limit],
        'office' => ['used' => \App\Models\User::where('seat_type', 'office')->count(), 'limit' => $office_limit],
    ],
] : null,
```

where `$field_limit` / `$office_limit` are `package.field_seats + tenant.extra_field_seats` (look the package up once from `tenancy()->tenant->package_key`). Show these counts on `Users/IndexPage.vue` next to the "add user" button.

- [ ] **Step 8: Run all seat tests to verify they pass**

Run: `php artisan test --filter=Seat`
Expected: PASS. Fix any pre-existing user/event/planner test broken by the new rules by giving its users the correct seat type (`->field()` for anyone made plannable or assigned to an event).

- [ ] **Step 9: Commit**

```bash
git add app/Rules/SeatAvailable.php app/Http/Requests/ app/Http/Middleware/HandleInertiaRequests.php \
        resources/js/Pages/Users/IndexPage.vue tests/Feature/SeatLimitTest.php tests/Feature/SeatCapabilityTest.php
git commit -m "feat(tenancy): enforce seat limits and seat-type capability"
```

---

## Task 36: Per-tenant storage quota

Each tenant has a `storage_limit_gb` allowance (default 50, Task 6). A `StorageQuota` service tracks bytes used via a running counter in the tenant's `general_settings`, enforced as a `WithinStorageQuota` validation rule on the upload paths, and corrected nightly from disk. New uploads over the limit are blocked; existing files are never deleted.

**Files:**
- `app/Services/StorageQuota.php` (new)
- `app/Rules/WithinStorageQuota.php` (new)
- `app/Jobs/ReconcileStorageUsageJob.php` (new)
- `routes/console.php`
- upload requests: `app/Http/Requests/ImageStoreRequest.php` (or the image controller's inline validation), `DocumentStoreRequest.php`, `UserStoreRequest.php`/`UserUpdateRequest.php` (avatar), company-logo request
- `app/Http/Controllers/Api/ImageController.php`, `DocumentController.php` and the other upload paths (call `add()`/`subtract()`)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `tests/Feature/StorageQuotaTest.php`

**Interfaces:**
- Produces: `App\Services\StorageQuota` — `usedBytes(): int`, `limitBytes(): int`, `remainingBytes(): int`, `hasRoomFor(int $bytes): bool`, `add(int $bytes): void`, `subtract(int $bytes): void`, `reconcile(): int`. Backed by `GeneralSetting` key `storage_used_bytes` and `tenancy()->tenant->storage_limit_gb`.

- [ ] **Step 1: Write the failing service test**

```php
<?php

namespace Tests\Feature;

use App\Models\GeneralSetting;
use App\Models\Tenant;
use App\Services\StorageQuota;
use Tests\TestCase;

class StorageQuotaTest extends TestCase
{
    public function test_limit_bytes_follows_the_tenant_limit(): void
    {
        Tenant::on('central')->where('id', 'test-tenant')->update(['storage_limit_gb' => 50]);
        tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

        $this->assertSame(50 * (1024 ** 3), (new StorageQuota())->limitBytes());
    }

    public function test_add_and_subtract_move_the_counter(): void
    {
        $quota = new StorageQuota();
        $quota->add(1000);
        $quota->add(500);
        $quota->subtract(200);

        $this->assertSame(1300, (int) GeneralSetting::get('storage_used_bytes', 0));
    }

    public function test_has_room_for_respects_the_limit(): void
    {
        Tenant::on('central')->where('id', 'test-tenant')->update(['storage_limit_gb' => 0]);
        tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

        $this->assertFalse((new StorageQuota())->hasRoomFor(1));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=StorageQuotaTest`
Expected: FAIL — `App\Services\StorageQuota` not found.

- [ ] **Step 3: Implement the `StorageQuota` service**

```php
<?php

namespace App\Services;

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Storage;

class StorageQuota
{
    public function usedBytes(): int
    {
        return (int) GeneralSetting::get('storage_used_bytes', 0);
    }

    public function limitBytes(): int
    {
        return (int) tenancy()->tenant->storage_limit_gb * (1024 ** 3);
    }

    public function remainingBytes(): int
    {
        return max(0, $this->limitBytes() - $this->usedBytes());
    }

    public function hasRoomFor(int $bytes): bool
    {
        return $this->usedBytes() + $bytes <= $this->limitBytes();
    }

    public function add(int $bytes): void
    {
        GeneralSetting::set('storage_used_bytes', $this->usedBytes() + max(0, $bytes));
    }

    public function subtract(int $bytes): void
    {
        GeneralSetting::set('storage_used_bytes', max(0, $this->usedBytes() - max(0, $bytes)));
    }

    public function reconcile(): int
    {
        $total = 0;
        foreach (['public', 'local'] as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $file) {
                $total += Storage::disk($disk)->size($file);
            }
        }
        GeneralSetting::set('storage_used_bytes', $total);

        return $total;
    }
}
```

- [ ] **Step 4: Run the service test to verify it passes**

Run: `php artisan test --filter=StorageQuotaTest`
Expected: PASS.

- [ ] **Step 5: Implement the `WithinStorageQuota` rule and apply it to the upload requests**

```php
<?php

namespace App\Rules;

use App\Services\StorageQuota;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class WithinStorageQuota implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        $files = is_array($value) ? $value : [$value];
        $incoming = collect($files)
            ->filter(fn ($f) => $f instanceof UploadedFile)
            ->sum(fn (UploadedFile $f) => $f->getSize());

        if (!(new StorageQuota())->hasRoomFor((int) $incoming)) {
            $fail('Uw opslaglimiet is bereikt. Neem contact op om uit te breiden.');
        }
    }
}
```

Apply it to the array field of each upload request — e.g. in the image upload request add `new \App\Rules\WithinStorageQuota()` to the `images` rule, in `DocumentStoreRequest` to the `documents` rule, and to the `avatar` and company-logo rules.

- [ ] **Step 6: Account for stored bytes on upload and delete**

After a successful store in each upload path, add the bytes; on delete, subtract. In `ImageController::store`, after `storePubliclyAs`:

```php
app(\App\Services\StorageQuota::class)->add($image->getSize());
```

In `ImageController::destroy` / `DocumentController::destroy`, before deleting the file, capture its size and `subtract()` it. Do the same for avatar replacement and company-logo upload/replace. The nightly reconcile (Step 7) is the backstop for any path missed here.

- [ ] **Step 7: Add the nightly reconcile job and schedule it per tenant**

```php
<?php

namespace App\Jobs;

use App\Services\StorageQuota;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileStorageUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        (new StorageQuota())->reconcile();
    }
}
```

In `routes/console.php`, add a per-tenant dispatch alongside the existing scheduled blocks (same pattern as Task 20):

```php
Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        \App\Jobs\ReconcileStorageUsageJob::dispatch();
        tenancy()->end();
    });
})->dailyAt('03:30')->name('reconcile-storage-usage')->withoutOverlapping();
```

- [ ] **Step 8: Share storage usage with the frontend**

Extend the `tenant` share (Task 16/35) with storage, so a usage bar can be shown:

```php
'storage' => [
    'used_bytes'  => (int) \App\Models\GeneralSetting::get('storage_used_bytes', 0),
    'limit_bytes' => (int) tenancy()->tenant->storage_limit_gb * (1024 ** 3),
],
```

- [ ] **Step 9: Write the enforcement test and confirm the suite is green**

```php
public function test_an_upload_over_the_limit_is_rejected(): void
{
    \Illuminate\Support\Facades\Storage::fake('public');
    Tenant::on('central')->where('id', 'test-tenant')->update(['storage_limit_gb' => 0]);
    tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

    $rule = new \App\Rules\WithinStorageQuota();
    $failed = false;
    $rule->validate('images', [\Illuminate\Http\UploadedFile::fake()->image('x.jpg')], function () use (&$failed) {
        $failed = true;
    });

    $this->assertTrue($failed);
}
```

Run: `php artisan test --filter=StorageQuotaTest`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Services/StorageQuota.php app/Rules/WithinStorageQuota.php \
        app/Jobs/ReconcileStorageUsageJob.php routes/console.php \
        app/Http/Requests/ app/Http/Controllers/ app/Http/Middleware/HandleInertiaRequests.php \
        tests/Feature/StorageQuotaTest.php
git commit -m "feat(tenancy): per-tenant storage quota with nightly reconcile"
```

---

## Task 37: Landlord admin sub-app

A small internal admin on its own subdomain (`beheer.lavorofsm.nl`) for managing the catalogue and every tenant's subscription in a browser. It runs **central-only** — its routes never carry the tenancy middleware — with its own `landlord` guard and `landlord_users` table. It is a thin visual layer over the Task 34 logic and the `TenantSubscription` service; controllers hold no pricing logic.

Built last: it depends on the catalogue (Task 6/16), the commands' logic (Task 34), seat counting (Task 35) and the storage counter (Task 36).

**Files:**
- `database/migrations/2026_07_21_000005_create_landlord_users_table.php` (central)
- `app/Models/Central/LandlordUser.php`
- `config/auth.php` (landlord guard + provider)
- `app/Console/Commands/CreateLandlordUser.php`
- `routes/landlord.php`, `bootstrap/app.php`
- `app/Http/Controllers/Landlord/` (auth, tenants, packages, modules, bundles, pricing)
- `resources/js/Pages/Landlord/**`, a `LandlordLayout.vue`
- `tests/Feature/Landlord/LandlordAccessTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `App\Models\Central\LandlordUser`; `landlord` auth guard; routes under the `beheer` subdomain.

- [ ] **Step 1: Create the `landlord_users` central migration and model**

```php
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('landlord_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landlord_users');
    }
};
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Foundation\Auth\User as Authenticatable;

class LandlordUser extends Authenticatable
{
    protected $connection = 'central';
    protected $table = 'landlord_users';
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password' => 'hashed'];
}
```

- [ ] **Step 2: Register the `landlord` guard**

In `config/auth.php`, add a guard and provider:

```php
'guards' => [
    // ...existing...
    'landlord' => ['driver' => 'session', 'provider' => 'landlord_users'],
],

'providers' => [
    // ...existing...
    'landlord_users' => ['driver' => 'eloquent', 'model' => App\Models\Central\LandlordUser::class],
],
```

- [ ] **Step 3: Add the `landlord:create` command**

```php
protected $signature = 'landlord:create {name} {email} {--password=}';

public function handle(): int
{
    $password = $this->option('password') ?: \Illuminate\Support\Str::password(16);
    \App\Models\Central\LandlordUser::on('central')->updateOrCreate(
        ['email' => $this->argument('email')],
        ['name' => $this->argument('name'), 'password' => $password]
    );
    $this->info("Landlord {$this->argument('email')} created. Password: {$password}");
    return self::SUCCESS;
}
```

- [ ] **Step 4: Register the landlord route file on the subdomain, without tenancy middleware**

In `bootstrap/app.php`, load `routes/landlord.php` in `withRouting` via a `then:` closure, wrapping it in the `web` group **minus** `InitializeTenancyBySession`, scoped to the `beheer` domain:

```php
->withRouting(
    web: __DIR__ . '/../routes/web.php',
    api: __DIR__ . '/../routes/api.php',
    commands: __DIR__ . '/../routes/console.php',
    health: '/up',
    then: function () {
        Route::middleware('web')
            ->domain(config('app.landlord_domain'))
            ->group(base_path('routes/landlord.php'));
    },
)
```

Add `'landlord_domain' => env('LANDLORD_DOMAIN', 'beheer.lavorofsm.nl')` to `config/app.php`. Because these routes are registered in the `web` group but **not** appended with `InitializeTenancyBySession` (Task 12 appends that only to the main web group), they never initialize tenancy. Add a feature test asserting the default connection stays `central` through a landlord request.

- [ ] **Step 5: Write the failing access test**

```php
<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\LandlordUser;
use App\Models\User;
use Tests\TestCase;

class LandlordAccessTest extends TestCase
{
    public function test_a_tenant_user_cannot_authenticate_as_landlord(): void
    {
        $tenant_user = User::factory()->create(['email' => 'worker@acme.nl']);

        $this->assertFalse(
            auth('landlord')->attempt(['email' => 'worker@acme.nl', 'password' => 'password'])
        );
    }

    public function test_a_landlord_can_authenticate(): void
    {
        LandlordUser::on('central')->create(['name' => 'Ops', 'email' => 'ops@lavoro.nl', 'password' => 'secret123']);

        $this->assertTrue(
            auth('landlord')->attempt(['email' => 'ops@lavoro.nl', 'password' => 'secret123'])
        );
    }
}
```

- [ ] **Step 6: Build the login screen and guard the group**

Landlord `login`/`logout` controllers authenticate against the `landlord` guard; every other landlord route sits behind `auth:landlord`. Reuse the app's Inertia setup with a distinct `LandlordLayout.vue` (no tenant branding, no `company` share). Routes:

```php
Route::middleware('auth:landlord')->group(function () {
    Route::get('/', [TenantOverviewController::class, 'index'])->name('landlord.tenants');
    Route::get('/tenants/{tenant}', [TenantController::class, 'edit'])->name('landlord.tenant.edit');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('landlord.tenant.update');
    Route::resource('packages', PackageController::class)->except('show');
    Route::resource('modules', ModuleController::class)->except('show');
    Route::resource('bundles', BundleController::class)->except('show');
    Route::get('/pricing', [PricingController::class, 'edit'])->name('landlord.pricing');
    Route::put('/pricing', [PricingController::class, 'update'])->name('landlord.pricing.update');
});
```

- [ ] **Step 7: Build the tenant overview and tenant-edit screens**

The overview reuses the exact computation from `tenant:overview` (Task 34 Step 7) — extract that loop into a shared method (e.g. a `TenantOverviewController` calling a small helper) so the CLI and the UI produce identical figures. The tenant-edit screen posts package, extra seats, storage limit, modules and price override; on a catalogue price edit that re-prices tenants, show the same blast-radius list the CLI shows and require a confirm.

- [ ] **Step 8: Build the catalogue screens**

Package/module/bundle/pricing screens are thin CRUD over the Task 16 models, reusing `ComboBox`/`TextInput`/`ModalDialog`. All money shown and entered in euros, stored in cents.

- [ ] **Step 9: Run the landlord tests and the full suite**

Run: `php artisan test --filter=Landlord` then `composer test`
Expected: PASS, including the assertion that a landlord request never initializes tenancy.

- [ ] **Step 10: Operational notes**

Add the `beheer.lavorofsm.nl` DNS record and a vhost pointing at the same app; it shares the codebase and central database. Create the first landlord with `php artisan landlord:create "Naam" ops@lavoro.nl`.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_21_000005_create_landlord_users_table.php \
        app/Models/Central/LandlordUser.php config/auth.php config/app.php \
        app/Console/Commands/CreateLandlordUser.php routes/landlord.php bootstrap/app.php \
        app/Http/Controllers/Landlord/ resources/js/Pages/Landlord/ resources/js/Layouts/LandlordLayout.vue \
        tests/Feature/Landlord/
git commit -m "feat(tenancy): landlord admin sub-app for licensing management"
```

---

## Known impact and follow-up work

1. ~~Existing test suite will break.~~ **Resolved by Task 30.** `phpunit.xml` moves from SQLite `:memory:` to a dedicated MySQL test database (`lavoro_test_central` plus a `lavoro_test_`-prefixed tenant database), with a hard runtime assertion and a narrowly-grants-only MySQL user as two independent layers ensuring a misconfiguration cannot make a test run reach `lavoro` or a real customer database. (Vitest frontend tests are unaffected.)

2. **Login page shows no per-tenant branding.** It already renders the static Lavoro logo today, so nothing regresses; but per-tenant branding before login would require a two-step login (email → resolve tenant → branded password step).

3. **File access is authenticated but not permission-scoped.** Task 14 serves files only to logged-in users of the owning tenant (cross-tenant ids 404 via model binding), which already closes the old world-readable hole. It does not yet apply per-resource permission checks — every authenticated user in the tenant can fetch any file id in that tenant. Adding policy checks in `FileController` is a reasonable follow-up if finer-grained access is required. Relatedly, `Storage::response()` sends no cache-control headers; if browser caching of served files ever becomes a concern, add `Cache-Control: private` in `FileController`.

4. **SnelStart and Firebase (FCM) credentials are still global env vars.** ~~Microsoft Graph~~ Microsoft Graph mail is **resolved per tenant as of Task 32** (tenant `general_settings` keys, falling back to the global env credentials for tenants that haven't configured their own mailbox). SnelStart and Firebase remain shared across all tenants — the same `general_settings` pattern used for Graph in Task 32 applies directly if/when per-tenant SnelStart or FCM credentials are needed; until then SnelStart access is already gated per tenant by the `snelstart` module subscription (Task 31).

5. ~~Module subscriptions are stored but not yet enforced.~~ **Resolved by Task 31.** The `tenant.module` route middleware gates tickets, projects, SnelStart imports/send, and Google Calendar OAuth routes; the `snelStartEnabled` Inertia props and the Tickets/Projects nav items in `useSidebarNav.js` are gated the same way on the frontend. Extending the same middleware to further routes as new module-gated features are added is a one-line addition per route group, not new plumbing.

6. ~~Scheduler scales linearly with tenant count.~~ **Mitigated by Task 20.** Every scheduled tick now only dispatches one queued job per tenant (a config swap plus a single `INSERT` into the central `jobs` table) instead of running a query or delete inline per tenant, so tick cost no longer scales with each tenant's data volume — only with tenant *count*, which is cheap. If tenant count itself grows into the hundreds and the dispatch loop alone becomes the bottleneck, chunking the central tenant list (already using `cursor()` rather than `get()`) or splitting the loop across multiple scheduled entries are the next levers.

7. **Middleware ordering is load-bearing and invisible.** Task 12 pins the tenancy initializers into `$middleware->priority()`. Nothing enforces that a future middleware addition preserves it, and getting it wrong presents as mass 404s that look like a routing bug. If this bites twice, a cheap feature test — hit a bound-model route as a tenant user and assert 200 — is worth more than a comment.

8. **`storage_path()` is a footgun for the lifetime of this codebase.** Task 14 fixes the six current offenders, but nothing prevents new code from writing `storage_path('app/public/…')` again, and the failure is silent (a missing file reads as "no image"). Consider a Pint/PHPStan rule or a grep in CI over `app/` and `resources/views/` for `storage_path('app/` once tenancy is live.

9. **Test isolation changed shape.** Task 30 swaps `RefreshDatabase`'s truncate-and-remigrate for transaction rollback across two connections. Auto-increment ids no longer reset between tests, and any code under test that commits (DDL, explicit transactions) escapes the wrapper. Expect some churn in the 35 converted test files.

10. **Per-tenant database credentials are not exercised by the test suite.** Tests run on the plain `MySQLDatabaseManager` (Task 30) so the narrow test grant stays narrow, which means `TenantUserProvisioner` and the `encrypted` password cast are only verified manually (Task 21 Step 3, Task 26 Step 4). Re-run those after touching provisioning.

11. **`APP_KEY` is now backup-critical.** Tenant database passwords are stored encrypted with it (Task 4). Losing or rotating `APP_KEY` without re-encrypting makes every tenant database unreachable. Rotation means: decrypt with the old key, re-run `tenant:provision-user` per tenant, or keep the old key in `APP_PREVIOUS_KEYS`.

12. **The provisioner is tied to this machine.** `auth_socket` authenticates by Unix socket, so it only works while MySQL runs on the same host as the app. Moving the database to its own server breaks provisioning and requires a different mechanism (client certificates, or a root-readable credentials file). Ordinary tenant traffic is unaffected — those users authenticate by password over TCP.

13. **Tenant MySQL users are created as `user@%`, not `user@localhost`.** That is the package's behaviour (`PermissionControlledMySQLDatabaseManager::createUser`), so a tenant credential leaked off-box could be used from anywhere the MySQL port is reachable. Keep MySQL bound to localhost/private network. Tightening this means overriding the manager's `createUser`.

10. **Future bearer-token API clients.** No `createToken()` call exists today — all API auth is stateful Sanctum cookies, which Task 24 covers via the session. If a native client later moves to bearer tokens, add a `POST /api/login` (without `tenant.api`) that resolves the tenant from the email, issues the token, and returns the `tenant_id` for the client to send as `X-Tenant-ID` — the fallback in Task 24's middleware already accepts it.
