# Lavoro

Field-service management for installation companies: customers and their
machines, work orders, planning, inspections, tickets, projects and invoicing.
Laravel 12 with Inertia and Vue 3. Every customer (tenant) has a database of its
own; a central database holds the tenants, logins and billing.

## Local development

Needs PHP 8.3 (with `pdo_mysql`, `pcntl` and `posix`), Composer, Node 22 and a
local MySQL 8 or MariaDB 10.11.

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
./scripts/tenancy/dev.sh --reset-logins   # every password back to 'testtest'
```

Your own `.env` is left alone. More in
[docs/tenancy-operations.md](docs/tenancy-operations.md#working-locally).

## Tests

```bash
composer test
```

They run on MySQL, not SQLite, against the same MySQL account `dev.sh` sets up.
Without `dev.sh`, set that up once with `sudo scripts/tenancy/setup-test-db.sh`.

## Servers

| | |
| --- | --- |
| A new server, or moving an installation over | [docs/tenancy-production.md](docs/tenancy-production.md) |
| Deploying, customers, workers, the day-to-day | [docs/tenancy-operations.md](docs/tenancy-operations.md) |
| Where this can break, and what catches it | [docs/tenancy-test-risks.md](docs/tenancy-test-risks.md) |
| The user manual the assistant answers from | [docs/handleiding.md](docs/handleiding.md) |

Deploy with `scripts/deploy.sh`; `php artisan tenancy:doctor` checks the whole
setup and says what to fix.
