# What can go wrong, and how you would notice

This page lists the things that can break in this setup, what each failure looks
like from the outside, and which test catches it. It was written while moving
from one database to one database per customer, and is kept up to date as a map
of what the test suite does and does not check.

It is ordered by how much damage each one does, not by how likely it is. How the
setup works is described in [multi-tenancy](multi-tenancy.md); running it is
[the runbook](../operations/runbook.md).

**Why this list exists:** almost everything below fails without an error
message. A 500 error is found within a day. A work order that ends up in the
wrong company's database is not found at all. So every item is written as: what
would this look like if it were already broken?

The test suite currently runs well over a thousand tests and passes. Where it
says "not covered" below, that was checked rather than assumed.

## 1. Keeping companies separated

This is the whole point of the setup.

**Covered** by `tests/Feature/Tenancy/IsolationTest.php`. It creates two
companies side by side and, for each check, first proves the data really is
there in the first company before checking that it is absent in the second. It
covers data, the same record id existing in both, separate databases, a separate
cache prefix, separate file folders, an email address belonging to only one
company, and each company's activity history.

Not covered yet: requesting files over HTTP as the wrong company (see the table
below), and the mail connection carrying over between two companies on one
worker (see point 4).

| What | How it goes wrong | How you would catch it |
| --- | --- | --- |
| Queries | A model that should use the shared database does not set `$connection`, or the other way round | Give two companies their own data and count in both directions |
| Files | `/files/images/7` belonging to company A can be requested while logged in as company B | Request an id belonging to the other company; it should return 404. **Not covered yet** |
| Search | The search bar searches the wrong database | Search as B for a name that only A has |
| Activity history | A's history shows up in B's | Change something as A and count the rows in `activities` in both |
| Shared folder | `storage_path()` points at the installation's own folder, not the company's. Logos and photos then disappear from PDFs and emails without an error, and an uploaded image lands where any company can overwrite it | Covered by `TenantFilesStayOnTheTenantDiskTest`. Every customer file goes through `Storage::disk()` |

`Tests\Concerns\UsesASecondTenant` creates that second company. Its database
does not run inside a transaction, because switching company closes the
connection and cancels the transaction. It is emptied at the start of every test
that uses it instead.

## 2. Fields that are silently dropped

This happened twice in one day, and neither was visible:

- `is_admin` was not a database column. Every new company got a first
  administrator **with no rights at all**. The form reported success.
- `seat_type` was missing from `User::$fillable`. The form asked for it, the
  validation accepted it, and `create()` discarded it. Everybody became office
  staff, so the field seats were never used and the seat count had nothing to do
  with reality.

**Covered** by `SilentlyDroppedAttributesTest`, which checks every validated
field of the user form against `$fillable`. The same check is still missing for
the other forms.

A third case of the same kind: `tenant:setup-existing` wrote the customer row
directly and left the subscription start date empty. Without a start date
nothing is ever invoiced, and nothing reports it. **Covered** by
`ImportedTenantIsInvoiceableTest`.

## 3. Money

Mistakes here are visible to the customer and cost a credit note.

| What | How it goes wrong | Status |
| --- | --- | --- |
| Duplicate invoice | The same month invoiced twice. Numbers come from a continuous series and cannot be reused | Covered |
| Empty invoice | An invoice number with no lines on it | Covered |
| Interim invoice | Invoicing an addition also charges the whole subscription a second time | Covered |
| Adding up | The individual lines do not add up to the total | Covered |
| Settling a package change | Days counted the wrong way round, or over the wrong period | Covered, in both directions and whether or not the period was already invoiced |
| Yearly discount | Applied to one-off charges as well | Covered |
| Credit | More credit than there is left to invoice | Covered; it becomes a credit note |
| Numbering | A deleted invoice or customer passes its number to the next invoice | Covered, see below |
| Coupon | Does not expire, or is applied on top of a fixed discount | Covered |
| Demo | The nightly demo rebuild uses up real invoice numbers | Covered; the demo is never invoiced |

Invoice numbers come from a counter that only increases, starting from the
highest number that already exists. Invoices outlive the customer they were sent
to. An invoice that has not been sent can be withdrawn, and its number stays
used.

Two invoices created at exactly the same moment are kept apart by the database
lock on the counter row, which is held until the transaction is committed, with
a unique index on the number as a second line of defence. That timing cannot be
forced from a test.

## 4. Email

The rule is: send with the customer's own settings, or do not send at all. Never
fall back to the settings in `.env`.

- **Wrong sender address.** The worst case, because there is no error at all:
  the email simply appears to come from the wrong company. The refusal to send
  without settings is covered (`IntegrationCredentialsTest`). The sender address
  that `ApplyTenantSender` sets is **not covered**.
- **The mail connection carrying over between companies.** One worker sending
  for company A and then for company B can use A's connection for B. That is
  what `MailerState` exists to prevent. **Not covered**, and it could be tested
  with one worker and two companies.
- **Invoices to customers** deliberately use a separate mail connection. If a
  customer misconfigures their own mail server, your invoices have to keep going
  out.
- **Another company's logo, or no logo.** The work order emails used to take
  their logo from the shared `public/storage/logo.png` and signed with the
  application's name, so every customer's email came from "Lavoro" with whatever
  logo happened to be there. The logo is now the customer's own, embedded in the
  message rather than linked, because a link would require a login. **Covered**
  by `TenantFilesStayOnTheTenantDiskTest`.

## 5. Background jobs

- **A job without its company.** A job queued while no company is active runs
  against the shared database. The specific trap that made every scheduled job do
  this: `Job::dispatch()` only queues the job when the object is destroyed, and an
  arrow function returned it from inside the company context before that
  happened. **Covered** by `QueuedWorkKeepsItsTenantTest`, which starts with no
  company active, like the scheduler does.
- **Cache leaking between companies.** `PrefixCacheBootstrapper` is the only
  thing separating the companies' cache entries, and a SnelStart access token is
  stored in that cache. A mistake there does not give you a cache miss; it gives
  you another company's token. **Covered** by the cache check in `IsolationTest`.
- **Scheduled tasks.** They run per company. One broken company must not stop
  the rest of the round. **Covered** by `ScheduledWorkSkipsBrokenTenantsTest`.
- **A job for a company that no longer exists.** The company id travels with the
  job and is looked up when the job runs. If the company is deleted in between —
  which happens nightly with the demo — the job used to fail with a message that
  did not name any company, because the company it was looking for was the thing
  that was missing. Retrying could never help. Deleting a company now also
  removes its queued jobs, and a job the worker has already picked up is
  discarded instead of failed. **Covered** by `DeletedTenantTakesItsWorkTest`,
  which fails against the package's own queue handling.

## 6. Creating and deleting companies

This is new code, and deleting cannot be undone.

- **Deleting the wrong company.** That is why the name has to be typed out in
  full. Check that a nearly correct name is refused.
- **Half a company.** If creating fails halfway, either a database without a
  customer record or a customer record without a database is left behind.
  `tenancy:doctor` looks for both. Run it after every failed attempt.
- **The provisioning worker is not running.** Then a request stays pending and
  the panel appears broken. The doctor reports it after about fifteen minutes.
- **The provisioner's permissions.** This account may deliberately only reach
  databases named `lavoro_tenant_%`. That means it cannot grant rights to a new
  customer account itself; a stored procedure running as root does that and
  refuses any name outside that range. `verify-mysql.sh` calls that procedure
  with the shared database name and expects to be refused. If that restriction
  is ever widened, the separation between companies is gone without anything
  appearing to break, so that check must never be removed.

### What the first production installation actually cost

- **The web server runs as a different account than you think.** LiteSpeed runs
  PHP as `nobody`, Apache and nginx as `www-data`, and that is not necessarily
  the account that owns the files. If that account cannot write in
  `storage/logs`, every error from a web request disappears without a trace: no
  page, no log line, nothing to search for. A button then appears to do nothing.
  The application now records which account it runs as during a normal web
  request, and the doctor checks that account.
- **A half-finished creation leaves things behind.** A customer record without a
  database, a database without an account, or folders in `storage/` belonging to
  a company that no longer exists. That blocks the next attempt with "name
  already exists", which makes the next error look like a different problem.
  Creating now undoes itself when it fails, the doctor looks for leftovers
  anyway, and `tenancy:prune-storage` removes leftover folders after showing what
  is in them.
- **A worker running old code or old settings.** PHP reads everything once when
  it starts. After a `git pull` or a change in `.env` the worker carries on with
  what it had, while it keeps reporting that it is alive. A worker started by
  hand also survives `systemctl restart` and keeps writing to the same status
  file. The doctor compares what each process started with against what is on
  disk now and names the process. `tenancy:restart-workers` restarts the
  services, stops any of our workers running outside them, and waits for both
  queues.
- **A worker says nothing during a deploy.** In maintenance mode a worker skips
  its rounds without firing the `Looping` event, and a deploy restarts the
  workers exactly then. Every deploy therefore ended with "worker runs older
  code". The search for stray workers that followed used their age to identify
  them, and so stopped the service's own newly started worker. A worker now also
  reports in when it starts, stray workers are identified by their systemd
  service, and a worker belonging to another installation on the same server is
  not counted. **Covered** by `WorkersReportDuringDeployTest`.
- **A company whose database was gone took the whole installation down.** The
  session pointed at that company, the application switched to it without
  checking, and every page returned a 500 error, including the login screen, so
  there was no way back in. The middleware now checks that the database can be
  opened and discards the session if it cannot. That company's edit screen keeps
  working, because that page holds the button to delete the customer.
- **A question nobody could see.** In production, `tenants:seed` asks for
  confirmation, and creating a company runs it through `Artisan::call`, where
  that question goes into a buffer nobody reads. From a terminal it waited
  forever, so `demo:install` appeared to hang. From a worker the answer was no,
  so the step was silently skipped. `config/tenancy.php` now passes `--force` to
  both commands.
- **Two accounts writing in one folder.** The web server stores uploads and the
  provisioner creates and deletes company folders, in the same place. The access
  lists name both accounts, but on a folder with `0755` permissions those lists
  only grant read access, and on `0700` nothing at all. The result was that a
  company's uploads could not be deleted along with the company. The file
  storage now creates group-writable folders. Checked with `getfacl`; there is no
  test for it.
- **A check that stopped one step too early.** `verify-mysql.sh` proved that the
  provisioner could create a database, but not that it could then grant a
  customer account rights on it, which was exactly the step that failed. Every
  check should be asked: does this cover the whole action, or only the beginning
  of it?

## 7. Logging in and sessions

- An email address determines which company somebody belongs to. The same
  address cannot exist in two companies; `user_tenant_lookups` enforces that.
- **Without a company active, the `web` guard must not look up a user.** The
  session driver writes `user_id` through the default guard, and that query goes
  to the shared database. This caused a blank page twice. `MiddlewareOrderTest`
  covers the order of the middleware, but **not** this behaviour.
- **Forgotten password** finds the company through the email address. An address
  that exists nowhere must not reveal that it exists nowhere.
- **A link that works without a session.** A customer opening an upload link
  from an email has no session, so nothing said which database to look in and the
  page did not open. It did open in the browser of a logged-in colleague, which
  hid the problem. The company is now part of the link, in the same way the
  Google webhook carries it. Links created before that change are found by asking
  every database, until they expire. **Covered** by
  `PublicLinkFindsItsTenantTest`, which makes the request with no company active.
- **The API uses a different pipeline.** The planner app talks to `/api`, which
  goes through Sanctum's own middleware and skips the session for any request it
  does not recognise as coming from its own front end. When that happens, the
  planner gets "Unauthenticated" on every action while the normal screens work
  fine. The doctor checks the three settings this depends on and prints `APP_URL`,
  so the one thing it cannot check — whether that is really the address customers
  use — can be checked by eye.

## 8. Signed links and tokens

`APP_KEY` is one key for the whole installation, and record ids count up per
company. So anything that refers to a record in signed or encrypted form must
name the company as well. Otherwise the same value is valid in every company.

This is covered for the assistant's confirmation tokens. The rule applies more
widely: to signed URLs and to any token added in future.

## 9. MySQL versus SQLite

The tests run on MySQL, because otherwise they do not prove what happens in
production:

- `DROP TABLE` commits the current transaction on MySQL. A test that did this
  cancelled the test transaction and broke 23 other tests. That is fixed.
- MySQL reorders the keys of a JSON object. A test that compared them in order
  passed on SQLite and failed on MySQL.
- Index names and key lengths differ.

Production runs **MariaDB 10.11**, development machines run MySQL 8. The two are
not identical, and that is the biggest remaining unknown.

## 10. Subscription limits

Storage, seats and modules. A mistake here either blocks somebody who has paid,
or lets somebody through who has not.

- The storage counter drifts away from reality. There is a nightly recount;
  check that it runs.
- Setting a user to inactive frees up their seat.
- Anything not bought does not appear in the menu, and the route behind it
  refuses the request.
- **The seat count was wrong until the day it was discovered** (see point 2).
  After the move, check per company that the number of field staff matches what
  is being charged.

## 11. Direct debit

**Covered** by `SepaDirectDebitTest` and `InvoiceUblTest`: the structure of the
file, the total, the number format (a comma makes the file unusable), one first
collection per mandate, the mandate on every transaction, and an IBAN with
spaces being written without them.

- A mandate and an IBAN belong together. A typo in an IBAN is rejected by the
  bank days later. There is a check-digit check.
- An invoice must not appear in a direct debit file twice. **Not covered**: the
  `collected_at` timestamp prevents it, but no test enforces that.
- The creditor id comes from the bank and cannot be invented.

## What would be worth building next

1. **File access both ways**: requesting `/files/...` belonging to another
   company must return 404.
2. **The mail connection between two companies on one worker.**
3. **An invoice appearing in a direct debit file only once**, a test on
   `collected_at`.
4. **A test run against MariaDB** instead of MySQL.

## Next

- [How one installation serves several companies](multi-tenancy.md) — the model
  these risks come from
- [Testing](testing.md) — how to run the tests named on this page
- [Troubleshooting](../operations/troubleshooting.md) — the shorter list, for
  whoever is running the server
