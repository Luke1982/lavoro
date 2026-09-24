# How one installation serves several companies

One installation, one web address, many companies. Each company has its own
database. This page explains what is stored where, how a request works out which
company it belongs to, and which limits the separation depends on.

The rules you have to follow while writing code are in
[`CLAUDE.md`](../../CLAUDE.md). What can go wrong and how you would notice is in
[risks](risks.md).

The application uses the `stancl/tenancy` package, version 3.

## Two kinds of database

**The shared database** (`lavoro_landlord`). There is one, and it is small:

- `tenants`: one row per company, with its package, seats, modules and storage
  limit;
- `user_tenant_lookups`: which email address belongs to which company. Only read
  while somebody logs in;
- `sessions`, `cache`, `jobs` and `failed_jobs`: everything that has to be
  readable *before* it is known which company a request belongs to;
- the price catalogue, invoices, coupons and resellers: your own business
  administration, not the customer's;
- `assistant_usage`: what each AI request cost. This is billed to the customer,
  so it is stored where the customer cannot change it.

**A database per company** (`lavoro_tenant_<name>`): `users` and all the
business tables, their roles and permissions, their activity history, their AI
conversations and their Google connections.

A model uses the shared database when it says so. The classes in
`App\Models\Central\*` set `protected $connection = 'central'`. Every other
model uses the default connection, which is switched per request.

## How a request finds its company

There is no subdomain and no company name in the URL. Logging in decides:

1. The login form asks for an email address. The `user_tenant_lookups` table
   says which company it belongs to. This is why one address can exist in only
   one company.
2. The application switches to that company's database, checks the password
   there, and stores the company id in the session. For "remember me" it also
   goes into a long-lived cookie.
3. On every following request, `InitializeTenancyBySession` reads that id back,
   checks that the database can actually be opened, and switches the database
   connections, the cache prefix and the file storage over.

Because of that check, one broken customer cannot take the whole installation
down. If the database cannot be opened, the session is discarded and the user
ends up at the login screen.

**Anything that runs without a session has to carry the company itself.** A
customer clicking an upload link in an email has no session, so the company id
is part of the link (`{company}_{random}`). The Google webhook does the same
through its channel token. Never take a company id from a header, a query
parameter or a form field: anyone can change those.

## What is switched per company, and what is not

| | How |
| --- | --- |
| Database | the `tenant` connection is rebuilt for each company. The `central` connection stays available |
| Files | `storage/tenant-<id>/{public,local}`, reached through `Storage::disk()`. `storage_path()` points at the shared folder, which belongs to no company |
| Cache | one shared cache, with a prefix per company (`PrefixCacheBootstrapper`) |
| Background jobs | the company is stored in the job when it is queued |
| Sessions | always in the shared database, because they are read before the company is known |

Uploaded files are never served from a public URL. They are requested through
`/files/...` by id, behind the login, so one company cannot fetch another's
files by guessing a path.

## Three kinds of MySQL account

| Account | Can reach | Used by |
| --- | --- | --- |
| `lavoro_app` | only the shared database | the web application |
| `lavoro_provisioner` | only databases named `lavoro_tenant_%`, and may grant rights on them | creating and deleting companies |
| one per company | only that company's own database | serving that company's requests |

`lavoro_app` cannot create or delete a customer database. That is why creating a
customer is not done during a web request: the admin panel writes a row in
`tenant_provisioning_requests`, and a second worker running as
`lavoro_provisioner` does the actual work.

**Why a stored procedure is used to grant rights.** MySQL and MariaDB check a
`GRANT` for one named database against a permission entry for exactly that name,
never against the wildcard `lavoro\_tenant\_%` that the provisioner holds. So
the provisioner can create `lavoro_tenant_acme` but cannot grant rights on it;
it gets error 1044. The obvious fix, giving it `ALL PRIVILEGES ON *.*`, would
make it root in everything but name.

Instead there is a stored procedure, `lavoro_admin.grant_tenant_access`. It runs
with the rights of whoever created it (root) and refuses any database name
outside the customer range. The provisioner is allowed to call it and nothing
else, so it cannot widen its own rights. `verify-mysql.sh` tries to cross that
line and expects to be refused.

## Creating and deleting a company

Creating undoes itself if it fails. If any step goes wrong, `TenantProvisioner`
removes what that same run created. It does that by the id it generated, never
by database name, because a name could belong to a healthy existing customer.

Deleting removes everything: the database, the MySQL account, the files, the
rows in the shared database, and any background jobs still queued for that
company. A job the worker has already picked up is discarded rather than failed,
because retrying it would look for a company that no longer exists.

## Mistakes that are easy to make

Each of these has caused a real problem at least once, and each now has a test:

- **A job queued outside a company runs against the shared database.**
  `Job::dispatch()` only queues the job when the object is destroyed, so
  returning one from inside `Tenancy::within()` queues it after the company
  context has ended.
- **`storage_path()` and `Storage::url()` point at the shared folder.** Using
  them fails silently: a missing file simply shows as no image.
- **Anything signed with `APP_KEY` must include the company.** Record ids are
  per-company auto-increment numbers, and the key is the same for all of them,
  so a signed link with only an id is valid in every company.
- **In production, `tenants:migrate` and `tenants:seed` ask for confirmation.**
  Inside `Artisan::call` nobody can see that question: from a terminal it waits
  forever, and from a worker the answer is no and the step is silently skipped.
  Both get `--force` in `config/tenancy.php`.
- **A singleton in the service container that holds company data** must
  implement `App\Support\ForgetsTenantState` and be tagged in
  `AppServiceProvider`. Otherwise it keeps the previous company's data in the
  worker.

## Where to look in the code

| | |
| --- | --- |
| `app/Tenancy/` | the code that switches cache prefix, file storage and job payloads |
| `app/Support/Tenancy.php` | `within()` and `forEachReachable()`, the safe ways to switch company |
| `app/Services/TenantProvisioner.php` | creating and deleting, including the rollback |
| `app/Http/Middleware/InitializeTenancyBySession.php` | how a request finds its company |
| `config/tenancy.php` | which bootstrappers run, the database prefix, the grant procedure |
| `docs/archive/plans/2026-06-09-multi-database-tenancy.md` | the original build plan, task by task |

## Next

- [What can go wrong](risks.md) — every failure mode of this setup, and which
  test catches it
- [Testing](testing.md) — why the suite runs on MySQL and how a test gets a
  customer
- [The runbook](../operations/runbook.md) — the same setup seen from the server
