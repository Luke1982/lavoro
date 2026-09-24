# How tenancy works

One installation, one domain, many customers — each with a database of its own.
This page is the model: what lives where, how a request finds its customer, and
which boundaries the whole thing leans on. The rules you are held to while
writing code are in [`CLAUDE.md`](../../CLAUDE.md); where it breaks and how you
would notice is in [risks](risks.md).

Built on `stancl/tenancy` v3.

## Two kinds of database

**Central** (`lavoro_landlord`) — one, small:

- `tenants`: a row per customer, with its package, seats, modules and storage
- `user_tenant_lookups`: email → customer, read only while logging in
- `sessions`, `cache`, `jobs`, `failed_jobs`: everything that has to be readable
  *before* anyone knows which customer this is
- the price catalogue, invoices, coupons, resellers — our business, not theirs
- `assistant_usage`: what each AI call cost. It bills the customer, so a ceiling
  the customer could edit would be no ceiling

**Per customer** (`lavoro_tenant_<slug>`) — one per company: `users` and every
business table, their roles and permissions, their activity trail, their
assistant transcripts, their Google connections.

A model belongs to the central database when it says so:
`App\Models\Central\*` set `protected $connection = 'central'`. Everything else
uses the default connection, which is swapped per request.

## How a request finds its customer

There is no subdomain and no tenant in the URL. Logging in decides:

1. The login form takes an email. `user_tenant_lookups` says which customer it
   belongs to — that is why an address may exist in one customer only.
2. Tenancy is initialised, the user is authenticated against *that* database,
   and the customer id goes into the session (and a long-lived cookie, for
   remember-me).
3. On every later request `InitializeTenancyBySession` reads it back, checks the
   database can actually be opened, and switches the connections, the cache
   prefix and the file disks over.

A broken customer therefore cannot take the installation down: if the database
does not open, the session is forgotten and you land on the login screen.

**Anything that runs without a session must carry the customer itself.** A
customer clicking an upload link from an email has no session, so the tenant id
is part of the link (`{tenant}_{random}`), the same way the Google webhook
carries it in its channel token. Never take a tenant id from a header, a query
parameter or a body field.

## What switches over, and what does not

| | How |
| --- | --- |
| Database | the `tenant` connection is rebuilt per customer; `central` stays reachable |
| Files | `storage/tenant-<id>/{public,local}`, through `Storage::disk()`. `storage_path()` is the shared folder and belongs to nobody |
| Cache | one shared store, prefixed per customer (`PrefixCacheBootstrapper`) |
| Queue | the customer travels in the job payload, added at dispatch |
| Sessions | central, always — they are read before the customer is known |

Uploaded files are never served from a public URL. They go through
`/files/...` by id, behind the login, so one customer cannot fetch another's by
guessing a path.

## Three MySQL accounts

| Account | May | Used by |
| --- | --- | --- |
| `lavoro_app` | only the central database | the web application |
| `lavoro_provisioner` | only `lavoro_tenant_%`, and may grant | creating and deleting customers |
| one per customer | only its own database | the connection during a request |

`lavoro_app` deliberately cannot create or drop a customer database. That is why
creating a customer is not something a web request does: the panel writes a
`tenant_provisioning_requests` row and a **second worker**, running as
`lavoro_provisioner`, does the work.

**Why a stored procedure hands out the rights.** MySQL and MariaDB weigh a
`GRANT` naming one database against a row for exactly that name, never against
the wildcard `lavoro\_tenant\_%` the provisioner holds. So the provisioner can
create `lavoro_tenant_acme` and cannot grant on it — error 1044. The tempting
fix, `ALL PRIVILEGES ON *.*`, would make it root in all but name. Instead
`lavoro_admin.grant_tenant_access` runs as its creator (root) and refuses every
name outside the customer namespace; the provisioner may call it and nothing
else. `verify-mysql.sh` tries to cross that line and expects to be refused.

## Creating and deleting

Creating rolls itself back: if any step fails, `TenantProvisioner` removes what
that same call made — by the id it generated, never by database name, which
would delete a healthy customer of the same name.

Deleting takes everything with it: the database, the MySQL login, the files, the
central lookups, and the work still queued for that customer. A job the worker
already had in hand is thrown away instead of failed, because a retry would look
for a customer that no longer exists.

## The seams that bite

Each of these has cost a day at least once, and each has a test now:

- **A job dispatched outside a customer runs against central.** `Job::dispatch()`
  only queues in its destructor, so an arrow function handing it out of
  `Tenancy::within()` queues it after tenancy ended.
- **`storage_path()` and `Storage::url()`** reach the shared folder and fail
  silently — a missing file reads as "no image".
- **Anything signed with `APP_KEY` must name the customer too.** Record ids are
  per-customer auto-increments and the key is global, so an id alone is valid in
  every customer.
- **In production, `tenants:migrate` and `tenants:seed` ask "are you sure?"**
  Inside `Artisan::call` nobody sees that question: from a terminal it waits
  forever, from a worker the answer is no and the step is skipped. Both get
  `--force` in `config/tenancy.php`.
- **A container singleton holding customer state** must implement
  `App\Support\ForgetsTenantState` and be tagged in `AppServiceProvider`, or it
  keeps the previous customer's state in the worker.

## Where to look

| | |
| --- | --- |
| `app/Tenancy/` | the bootstrappers: cache prefix, storage roots, queue payloads |
| `app/Support/Tenancy.php` | `within()` and `forEachReachable()` — the only sane way to switch |
| `app/Services/TenantProvisioner.php` | creating and deleting, with its rollback |
| `app/Http/Middleware/InitializeTenancyBySession.php` | how a request finds its customer |
| `config/tenancy.php` | bootstrappers, database prefix, grant procedure |
| `docs/archive/plans/2026-06-09-multi-database-tenancy.md` | the original build plan, task by task |
