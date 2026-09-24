# Testing

```bash
composer test                            # everything
php artisan test --filter=IsolationTest  # one test or one class
npx vitest run                           # the Vue component tests
```

The tests need a MySQL account, created once per machine by
`sudo scripts/tenancy/setup-test-db.sh`. `dev.sh` runs that for you if the
account is missing.

## The tests run on MySQL, not SQLite

The test suite uses a real MySQL server, because the differences between the two
are where the bugs turned out to be:

- `DROP TABLE` commits the current transaction on MySQL. That once broke the
  transaction that 23 other tests were relying on.
- MySQL reorders the keys of a JSON object, so a test that compared them in
  order passed on SQLite and failed here.
- Index names and key lengths differ between the two.

Production runs MariaDB 10.11 and development machines run MySQL 8. That
difference is the biggest thing the test suite does not check.

## How a test gets a customer

`Tests\TestCase` creates one customer for the whole run, and wraps every
individual test in a database transaction on both connections (`central` and
`tenant`), which is rolled back afterwards. Because of that:

- do not add `RefreshDatabase` to a test;
- **switching to another customer, or ending the customer context, cancels that
  transaction.** The connection is closed and reopened, and everything the test
  had written is lost. If a test needs data to survive such a switch, use
  `Tests\Concerns\UsesASecondTenant`, which uses a database that is committed
  and emptied per test instead;
- `Storage::fake()` does not survive it either, because switching customer calls
  `Storage::forgetDisk()`;
- **a command that runs as the provisioner account** closes and reopens the
  central connection, which also cancels the transaction. Everything the test
  wrote before it is rolled back, and everything the command writes after it is
  committed for real. Such a test should write what it needs through
  `Tests\Concerns\OutsideTheTestTransaction`, which uses a separate connection.
  That is also the only way to read a MySQL account that another connection has
  just created. Clean up afterwards;
  `Tests\Concerns\KeepsTheTestTenantIntact` does that for the customer record.

`phpunit.xml` sets every configuration value the tests depend on, including the
provisioner's socket. Anything it does not set falls back to your own `.env`,
and then a test passes on your machine and fails everywhere else.

## What the suite checks

`IsolationTest` creates two real customers through the real creation process and
checks that they cannot reach each other's data, cache, files, logins or
history. That code cannot be judged by reading it; every bug found in it so far
was invisible in the source and obvious the first time the test ran. Run the
suite before changing anything that creates or deletes a customer.

What is covered, what is deliberately not, and how you would notice each gap is
in [risks](risks.md).

## Writing tests in this project

- Name a test after the behaviour, not the method:
  `test_a_customer_without_a_session_reaches_the_page`.
- Write in the docblock what went wrong once, so the next person knows why the
  test exists.
- Give assertions a message that reads as a sentence when it fails.
- Do not write tests unless they are asked for, with one exception: a bug that
  was invisible in the source gets one.
