# Where this setup breaks, and how you notice

A risk list for the move to multi-tenancy: which places can go wrong, how that
failure shows itself, and what catches it.

Sorted by what it costs when it goes wrong, not by how likely it is. Operations
are in [tenancy-operations.md](tenancy-operations.md).

**What drives this list:** nearly everything below fails *silently*. Everyone
finds a 500 within a day. Nobody finds a service order that lands in the wrong
customer's database, and that is exactly what can go wrong in this move. So
every line below is written as "what would this look like if it were already
broken".

The suite runs well over a thousand tests, green. Where it says "not covered"
below, that was checked, not guessed.

---

## 1. Isolation between customers — the whole point

**Covered** by `tests/Feature/Tenancy/IsolationTest.php`: two customers side by
side, and each time first proving the data is there in the one before looking
whether it is missing in the other. Data, the same id in both, separate
databases, a separate cache prefix, separate file folders, an email address that
can belong to one customer only, and the trail that stays with its own customer.

What it does not cover yet: requesting files over http as the wrong customer
(the row below), and the mailer that lingers between two customers on one
worker (point 4).

| What | How it goes wrong | How you catch it |
| --- | --- | --- |
| Queries | A model without `$connection` that belongs centrally, or the other way round | Two tenants, each with their own data, count both ways |
| Files | `/files/images/7` of customer A can be requested as customer B | Request an id that belongs to the other; should be 404. **Not covered yet** |
| Search | The spotlight searches the wrong database | Search as B for a name only A has |
| Activities | A's trail shows up in B's history | Change something as A, count `activities` in both |
| Shared folder | `storage_path()` is the one folder of the whole installation, not the customer's: logos and photos drop out of pdfs and mails without an error, an imported image lands where every customer can overwrite it | Covered by `TenantFilesStayOnTheTenantDiskTest`; every customer file goes through `Storage::disk()` |

`Tests\Concerns\UsesASecondTenant` sets up that second customer. Note: the
second database does not run in a transaction, because switching tenant throws
the connection away and the transaction with it. So it is emptied at the start of
every test that uses it.

## 2. Silently dropped fields

Hit twice in one day, both invisible:

- `is_admin` does not exist as a column. Every new customer got a first admin
  **without any rights at all**. The form reported success.
- `seat_type` was not in `User::$fillable`. The form asked for it, the
  validation approved it, `create()` threw it away. Everyone became office staff
  and so the field seats never filled up — the whole seat count had nothing to
  do with reality.

**Covered** by `SilentlyDroppedAttributesTest`, which holds every validated field
of the user form against `$fillable`. The same check is still missing for the
other forms.

A third one of the same family: `tenant:setup-existing` wrote the tenant row by
hand and left the start date empty. Without a start date nothing is ever
invoiced, and nothing says so. **Covered** by `ImportedTenantIsInvoiceableTest`.

## 3. Money

Mistakes here are visible to the customer and cost a credit note.

| What | How it goes wrong | Status |
| --- | --- | --- |
| Duplicate invoice | The same month twice; numbers from a continuous series cannot be reused | Covered |
| Empty invoice | A number without lines | Covered |
| Interim invoice | Invoicing a top-up adds the whole subscription a second time | Covered |
| Addition | The itemised lines do not add up to the monthly amount | Covered |
| Package change settlement | Days the wrong way round, or over the wrong period | Covered, both directions and both invoiced states |
| Yearly discount | Also counted over one-off charges | Covered |
| Credit | More credit than there is to invoice | Covered — becomes a credit note |
| Numbering | A removed invoice or customer hands its number to the next invoice | Covered — see below |
| Coupon | Does not expire, or stacks with the fixed discount | Covered |
| Demo | The nightly demo spends real invoice numbers | Covered — the demo is never invoiced |

Numbers come from a counter that only goes up, with the highest existing number
as its floor. Invoices survive the customer they were sent to, and an unsent
invoice can be withdrawn while its number stays spent. Two invoices issued at
the same moment are kept apart by the lock the counter row takes until the
transaction commits, with a unique index on `number` behind it. That race itself
cannot be forced from a test.

## 4. Mail

The rule is: per customer, or nothing. No falling back to `.env`.

- **Wrong sender.** The worst variant, because there is no error — the mail
  simply comes from the wrong company. Covered for the refusal
  (`IntegrationCredentialsTest`), **not covered** for the sender
  `ApplyTenantSender` puts on it.
- **Mailer lingering between customers.** One worker sending for A and then B
  uses A's connection for B. That is what `MailerState` exists for. **Not
  covered** — and it tests well with one worker and two tenants.
- **Invoices to customers** deliberately go through a separate mailer. If a
  customer breaks their own mail server, our invoices must keep going.
- **Another company's logo, or none.** The werkbon mails took their logo from
  the shared `public/storage/logo.png` and signed with the app's name, so every
  customer's mail came from "Lavoro" with whatever logo lay there. The logo is
  now the customer's own, embedded rather than linked: the link would need a
  login. **Covered** by `TenantFilesStayOnTheTenantDiskTest`.

## 5. Background work

- **A job without its tenant.** A job queued outside a tenant runs against the
  central database. The trap that made every scheduled job do that:
  `Job::dispatch()` only queues in its destructor, and an arrow function handed
  it out of the tenant before that. **Covered** by `QueuedWorkKeepsItsTenantTest`,
  which starts with no tenant open, as the scheduler does.
- **Cache pollution.** `PrefixCacheBootstrapper` is the only thing separating
  the customers' cache, and a SnelStart token lives in that cache. A mistake here
  is not a cache miss but another company's token. **Covered** by the cache test
  in `IsolationTest`.
- **Scheduler.** Runs per customer; one customer that breaks must not stop the
  rest of the round. **Covered** by `ScheduledWorkSkipsBrokenTenantsTest`.

## 6. Creating and deleting customers

New, and irreversible in one direction.

- **Deleting hits the wrong company.** That is why the name has to be typed over
  literally. Check that a nearly right name is refused.
- **Half a tenant.** If creating breaks halfway, a database without a row or a
  row without a database stays behind. `tenancy:doctor` looks for those orphans —
  run it after every failed request.
- **The worker stands still.** Then a request hangs and the panel looks broken.
  The doctor raises it after a quarter of an hour.
- **The provisioner's rights.** This account may deliberately only reach
  `lavoro_tenant_%`. So it cannot grant rights to a customer login itself; a
  procedure running as root does that and refuses everything outside the
  namespace. `verify-mysql.sh` tries that procedure with the landlord database
  and expects a refusal. If that hole ever widens, the whole separation is gone
  without anything breaking -- so that is the check that must never disappear.

What the first installation on production really cost, and so what to watch
for:

- **The web server runs as another account than you think.** LiteSpeed runs php
  as `nobody`, Apache and nginx as `www-data`, and that need not be the account
  the files belong to. If that account cannot write in `storage/logs`, every
  error from a web request vanishes without a trace: no page, no line, nothing to
  look up. A button then simply does nothing. The doctor reads which account the
  web server runs as and checks it.
- **A half creation leaves rubbish behind.** A row without a database, a database
  without a login, or folders in `storage/` of a customer that no longer exists.
  That rubbish blocks the next attempt ("that name already exists") and makes a
  next error look like something else entirely. Creating now rolls itself back;
  the doctor looks for what stays behind anyway, and
  `tenancy:prune-storage` clears left-over folders after showing what is in them.
- **A worker runs old code or old settings.** Php reads everything once, at boot.
  After a `git pull` or a change in `.env` the worker carries on with what it had,
  while the heartbeat keeps coming in and everything looks healthy. A worker once
  started by hand survives a `systemctl restart` as well, and keeps writing into
  the same heartbeat. The doctor compares what each process booted with to what is
  here now and names the process; `tenancy:restart-workers` restarts the units,
  stops what of ours runs outside them and waits for both queues.
- **A worker says nothing during a deploy.** In maintenance mode a worker skips
  its rounds without firing `Looping`, and a deploy restarts the workers exactly
  then: every deploy ended in "older code", and the stray hunt that followed took
  the unit's own fresh worker for a leftover -- it went by age -- and stopped it.
  A worker now reports at start; strays are told by their systemd unit, and a
  worker of another installation on the server is not counted. **Covered** by
  `WorkersReportDuringDeployTest`.
- **A customer whose database is gone.** That took the whole installation down:
  the session pointed at that customer, tenancy switched over without noticing,
  and every page became a 500 -- the login screen too, so there was no getting out.
  The middleware now checks first that the database is still there, and forgets
  the session otherwise. That customer's edit screen keeps working too; it holds
  the button that clears it away.
- **A question nobody sees.** In production `tenants:seed` asks "are you
  sure?", and creating a customer runs it through `Artisan::call`, where the
  question goes into a buffer. From a terminal that waited forever --
  `demo:install` hung without a word -- and from a worker the answer was no, so
  the step was skipped. `config/tenancy.php` passes `--force` to both commands.
- **Two accounts, one folder.** The web server puts uploads down and the
  provisioner seeds and deletes customers, in the same folders. The access lists
  name both, but a folder made `0755` caps them at read-only and `0700` at
  nothing: a customer's uploads could not be deleted with the customer. The
  disks now make folders group-writable. Measured with `getfacl`, not covered by
  a test.
- **A check that stops one step too early.** `verify-mysql.sh` proved the
  provisioner could create a database, but not that it could grant a login on it
  -- exactly the step that failed. Every check needs the question: does this cover
  the whole action, or only its start?

## 7. Logging in and sessions

- An address points at the customer. Two customers with the same address cannot
  be, and `user_tenant_lookups` enforces it.
- **Without a tenant the `web` guard must not resolve a user.** The session driver
  writes `user_id` through the default guard, and that query goes to the central
  database. This produced a white page twice. Covered by `MiddlewareOrderTest` for
  the order, **not** for the behaviour.
- **Forgotten password** finds the customer through the address. An address that
  exists nowhere must not give away that it exists nowhere.
- **A link without a session.** A customer opening the upload link from a mail
  has no session, so nothing said whose database to look in, and the page did
  not open. Opened in the browser of a logged-in colleague it did, which hid it.
  The tenant is now part of the link, as with the Google webhook; links from
  before that are found by asking every database, until they expire. **Covered**
  by `PublicLinkFindsItsTenantTest`, which requests with no tenant open.
- **The API has its own pipeline.** The planner talks to `/api`, which runs
  through Sanctum's own pipeline and skips it for any request it does not
  recognise as its own front end. Then there is no session and the planner gets
  'Unauthenticated' on every action while the ordinary screens work. The doctor
  checks the three settings it hangs on, and prints `APP_URL` so the one thing it
  cannot check -- whether that is the address customers use -- can be checked by
  eye.

## 8. Signed data

`APP_KEY` is one key for the whole installation; record ids count up per
customer. So everything that points at a record in encrypted form has to name the
customer too, otherwise it is valid in every customer. Covered for the
assistant's confirmation tokens; the rule applies more widely — signed URLs,
every future token.

## 9. MySQL versus SQLite

The tests run on MySQL because otherwise they do not prove what happens in
production. That is not theory:

- `DROP TABLE` is an implicit commit on MySQL. A test that did so invalidated the
  test transaction and broke 23 other tests. Gone now.
- MySQL reorders the keys of a JSON object. A test comparing in order was green on
  SQLite and red on MySQL.
- Index names and key lengths differ.

Production runs **MariaDB 10.11**, the development machine MySQL 8. The two are
not identical. That is the biggest remaining unknown in the move.

## 10. Subscription limits

Storage, seats and modules. A mistake here stops someone who did pay, or lets
someone through who did not.

- The storage counter drifts from reality — there is a nightly recount, check
  that it runs.
- A user set to inactive frees their seat.
- What was not bought is not in the menu either, and the route behind it
  refuses.
- **The seat count was broken until the day it was found** (see 2). After the
  move, count per customer whether the number of field staff matches what is
  charged.

## 11. Collection

**Covered** by `SepaDirectDebitTest` and `InvoiceUblTest`: the shape of the file,
the total, the amount format (a comma makes it unusable), one first collection per
mandate, the mandate on every transaction, and an IBAN with spaces that is written
without them.

- Mandate and IBAN belong together; an IBAN with a typo is handed back by the bank
  days later. There is a check-digit check.
- An invoice must not be in a file twice. **Not covered** — the `collected_at`
  stamp takes care of it, but no test enforces it.
- The creditor id comes from the bank and cannot be made up.

---

## What I would build next

1. **Files both ways**: `/files/...` of another customer must return 404.
2. **The mailer between two customers on one worker.**
3. **An invoice in a collection file only once** — a test on `collected_at`.
4. **A round on MariaDB**, not on MySQL.
