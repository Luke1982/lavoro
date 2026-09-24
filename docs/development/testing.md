# Testing

```bash
composer test          # everything
php artisan test --filter=IsolationTest
npx vitest run         # the Vue side
```

The MySQL account comes from `sudo scripts/tenancy/setup-test-db.sh`, once per
machine. `dev.sh` runs it for you when it is missing.

## On MySQL, never SQLite

The suite runs on the real database engine, because the differences are exactly
where the bugs were: `DROP TABLE` is an implicit commit on MySQL and once
invalidated the test transaction for 23 other tests; MySQL reorders the keys of
a JSON object, so a test comparing them in order was green on SQLite and red
here; index names and key lengths differ.

Production is MariaDB 10.11 and the development machine is MySQL 8. That
difference is the biggest thing the suite does not prove.

## How a test gets a customer

`Tests\TestCase` creates one customer per run and wraps every test in a
transaction on **both** connections, central and tenant. So:

- do not add `RefreshDatabase`;
- **switching or ending tenancy throws that transaction away** — the connection
  is purged, and everything the test made goes with it. Anything that has to
  survive a switch belongs in `Tests\Concerns\UsesASecondTenant`, whose database
  is committed and emptied per test instead;
- `Storage::fake()` does not survive it either: re-initialising a customer calls
  `Storage::forgetDisk()`.

`phpunit.xml` pins every setting the tests depend on, including the
provisioner's socket. Anything it does not pin falls back to your own `.env`,
and then a test passes on your machine and nowhere else.

## What the suite actually walks

`IsolationTest` creates two real customers, through the real provisioning path,
and proves they cannot see each other: data, cache, files, logins, activity
trail. Code on that path cannot be judged by reading it — every bug in it so far
was invisible in the source and obvious on the first run. Run the suite before
touching anything that creates or deletes a customer.

The rest of the map — what is covered, what is deliberately not, and how each
gap would show itself — is in [risks](risks.md).

## Writing tests here

- A test is named after the behaviour, not the method:
  `test_a_customer_without_a_session_reaches_the_page`.
- The docblock says what went wrong once, so the next person knows why the test
  exists.
- Assertions carry a message that reads as a sentence when it fails.
- Tests are not written unless asked, but a bug that was invisible in the source
  gets one.
