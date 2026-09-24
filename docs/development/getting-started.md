# Getting started

You need PHP 8.2 or newer with the `pdo_mysql`, `pcntl` and `posix` extensions
(the server runs 8.3), Composer, Node 22, and MySQL 8 or MariaDB 10.11 running
on your machine.

```bash
./scripts/tenancy/dev.sh
```

That is the whole setup, and it is also the command you use to start working
every day afterwards.

The first time you run it, it:

1. installs the Composer and npm packages if they are missing;
2. creates the MySQL account that the tests and your local installation share.
   This is the only step that needs `sudo`, once per machine;
3. writes `.env.local`, creates the shared database `lavoro_local_landlord`, an
   admin account for the admin panel, and the demo company;
4. starts the application, both queue workers and Vite.

Every later run updates the database tables (the shared one and every customer)
and then starts everything. Your own `.env` file is never touched.

| | Address | Login |
| --- | --- | --- |
| The application | http://127.0.0.1:8199 | `demo@lavorofsm.nl` / `demo` |
| The admin panel | http://127.0.0.1:8199/beheer | `admin@lavoro.local` / `testtest` |

Every user in the demo company logs in with the password `demo`. `mark@` is the
planner, `lisa@` the service desk, `jeroen@` a mechanic. What the demo contains
is described in [the demo customer](../operations/demo.md).

```bash
./scripts/tenancy/dev.sh --fresh          # delete everything and build it again
./scripts/tenancy/dev.sh --reset-logins   # reset the panel login and the first
                                          # user of each customer to 'testtest'
```

## Two separate environments on one machine

Your local installation and the test suite each have their own databases, so
running the tests never disturbs what you are working on.

| | Configuration file | Databases |
| --- | --- | --- |
| `dev.sh` | `.env.local` | `lavoro_local_landlord`, `lavoro_test_tenant_local_*` |
| `composer test` | `phpunit.xml` | `lavoro_test_landlord`, `lavoro_test_tenant_test`, `..._two` |

They share one MySQL account but use different databases.

Your own `.env` is used by neither of them. If you run `php artisan` directly in
this checkout, that is the file it reads, so either point it at a real database
or expect it to fail. Use `APP_ENV=local php artisan …` to work on the local
installation instead.

The environment name has to be exactly `local`. Only then does Laravel skip the
service worker, and only then does `artisan serve` use the upload limits from
`.php.d`.

## Commands you will use

```bash
composer test                            # the whole test suite, on MySQL
php artisan test --filter=Isolation      # one test or one class
npx vitest run                           # the Vue component tests
./vendor/bin/pint app/Models/User.php    # PHP formatting; per file, never a whole folder
npm run fix:eslint                       # JavaScript and Vue formatting
```

What the test suite does and does not cover is described in [testing](testing.md).

## Next

- [How the application is put together](architecture.md)
- [How one installation serves several companies](multi-tenancy.md) — read this
  before changing anything that queues a job, stores a file or creates a signed
  link
- [`CLAUDE.md`](../../CLAUDE.md) — the rules for anyone writing code here
