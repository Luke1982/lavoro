# Getting started

You need PHP 8.2 or newer with `pdo_mysql`, `pcntl` and `posix` (the server runs
8.3), Composer, Node 22, and MySQL 8 or MariaDB 10.11 running locally.

```bash
./scripts/tenancy/dev.sh
```

One command, and it is also the command you use every day after that. The first
run:

1. installs Composer and npm packages if they are missing;
2. sets up the MySQL account the tests and the local install share — this is the
   one step that needs `sudo`, once per machine;
3. writes `.env.local`, creates the central database `lavoro_local_landlord`,
   an admin for the panel and the demo company;
4. starts the app, both queue workers and Vite.

Every later run migrates the central database and every customer, then starts.
Your own `.env` is never touched.

| | Address | Login |
| --- | --- | --- |
| The app | http://127.0.0.1:8199 | `demo@lavorofsm.nl` / `demo` |
| The panel | http://127.0.0.1:8199/beheer | `admin@lavoro.local` / `testtest` |

Every demo user logs in with the password `demo`: `mark@` is the planner,
`lisa@` the service desk, `jeroen@` a mechanic. What is in the demo is described
in [the demo customer](../operations/demo.md).

```bash
./scripts/tenancy/dev.sh --fresh          # throw it away and build it again
./scripts/tenancy/dev.sh --reset-logins   # the panel and the first user of each
                                          # customer back to 'testtest'
```

## Two environments on one machine

| | Reads | Databases |
| --- | --- | --- |
| `dev.sh` | `.env.local` | `lavoro_local_landlord`, `lavoro_test_tenant_local_*` |
| `composer test` | `phpunit.xml` | `lavoro_test_landlord`, `lavoro_test_tenant_test`, `_two` |

They share one MySQL account and stay out of each other's way. `.env` is yours
and is used by neither — a plain `php artisan` in this checkout reads it, so
point it somewhere real or expect it to fail.

The environment is `local` and nothing else: only then does Laravel skip the
service worker and does `artisan serve` pick up the upload limits from `.php.d`.

## Running things

```bash
composer test                       # the whole suite, on MySQL
php artisan test --filter=Isolation # one test or one class
npx vitest run                       # the Vue components
./vendor/bin/pint app/Models/User.php   # PHP formatting, per file, never a directory
npm run fix:eslint                   # JS and Vue
```

More about the suite, and what it does and does not cover, in
[testing](testing.md).

## Then

- [How the application is put together](architecture.md)
- [How tenancy works](multi-tenancy.md) — read this before touching anything
  that queues, stores a file or signs a link
- [`CLAUDE.md`](../../CLAUDE.md) — the rules, for people and models alike
