# Lavoro

Field-service management for installation companies: customers and their
machines, work orders, planning, inspections, tickets, projects and invoicing.
Laravel 12 with Inertia and Vue 3. Every customer (tenant) has a database of its
own; a central database holds the tenants, logins and billing.

## Local development

Needs PHP 8.2 or newer with `pdo_mysql`, `pcntl` and `posix` (the server runs
8.3), Composer, Node 22 and a local MySQL 8 or MariaDB 10.11.

```bash
./scripts/tenancy/dev.sh
```

That is all. The first run installs Composer and npm packages when they are
missing, sets up the MySQL account (asking for your sudo password, once per
machine), writes `.env.local`, creates the central database, an admin and a demo
company full of data, and then starts the app, both queue workers and Vite.
Every later run migrates everything and starts.

| | Address | Login |
| --- | --- | --- |
| App | http://127.0.0.1:8199 | `demo@lavorofsm.nl` / `demo` |
| Admin panel | http://127.0.0.1:8199/beheer | `admin@lavoro.local` / `testtest` |

```bash
./scripts/tenancy/dev.sh --fresh          # throw the local installation away and build it again
./scripts/tenancy/dev.sh --reset-logins   # the panel and the first user of each customer back to 'testtest'
```

Your own `.env` is left alone. The longer version, and what to read next, is in
[docs/development/getting-started.md](docs/development/getting-started.md).

## Tests

```bash
composer test
```

They run on MySQL, not SQLite, against the same MySQL account `dev.sh` sets up.
Without `dev.sh`, set that up once with `sudo scripts/tenancy/setup-test-db.sh`.

## Documentation

Everything lives in [docs/](docs/README.md), grouped by who is reading:

| | |
| --- | --- |
| [guide/](docs/guide/handleiding.md) | the user manual (Dutch), which the assistant answers from |
| [install/](docs/install/server.md) | a new server, taking over an existing installation, Google, Android |
| [operations/](docs/operations/runbook.md) | running it: deploys, customers, invoices, backups, troubleshooting |
| [development/](docs/development/getting-started.md) | local install, architecture, tenancy, testing, risks |

Deploy with `scripts/deploy.sh`; `php artisan tenancy:doctor` checks the whole
setup and says what to fix.
