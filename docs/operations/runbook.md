# Running Lavoro — the runbook

Everything you do on a server that is already installed. Putting a new one up is
[installing a server](../install/server.md); taking over an existing Lavoro is
[import-existing](../install/import-existing.md). How the whole thing hangs
together is [multi-tenancy](../development/multi-tenancy.md).

Working on the code instead? [Getting started](../development/getting-started.md).

## What has to run

| | What | As whom |
| --- | --- | --- |
| Web server | php-fpm / lsphp | the web server's account |
| Worker | `php artisan queue:work` | the installation's account |
| Worker | `php artisan queue:work --queue=provisioning` | `lavoro_provisioner` |
| Cron | `* * * * * php artisan schedule:run` | the installation's account |

The first two deliberately have no name next to them. Which account the web
server uses differs per server -- `www-data` for Apache and nginx, `nobody` for
LiteSpeed -- and it need not be the account the files belong to. Assuming it was
`www-data` cost a whole day: the web server could not write in `storage/logs`,
so every error from a web request vanished without a trace, and there was not a
single message to look up.

Finding out which they are:

```bash
ps -eo user,comm | grep -iE 'lsphp|php-fpm'
stat -c %U artisan
```

The doctor reads it from the compiled templates itself -- the web server writes
those -- and checks that this account can reach the log and every customer's
folders. `scripts/tenancy/setup-workers.sh` writes the systemd units with the
accounts and paths that apply on this server.

Two workers, and that is deliberate. The ordinary one runs as the
application's account, which may not create databases. Only the second may: if
the panel could create databases itself, a mistake in the panel could drop one
as well.

The ordinary worker does not pick up the `provisioning` queue, and that is
right: it would only break on it. If the second is not running, a request in
the panel stays on "in de wacht" and `tenancy:doctor` reports it after a quarter
of an hour.

`--tries=1` on the second is not carelessness: trying to create a half-made
customer again gets stuck on "the database already exists" and hides the real
error. `scripts/tenancy/setup-workers.sh` writes the units; it reads the
account, the path, the php binary and the name of the database service off the
machine.

Without the cron nothing happens by itself: no invoices, no Google
synchronisation, no service orders from maintenance contracts, no fresh demo.
`php artisan tenancy:doctor` notices within a quarter of an hour.

## Checking that it is right

```bash
php artisan tenancy:doctor
```

On a development machine the test suite should run before anything goes to a
server:

```bash
sudo scripts/tenancy/setup-test-db.sh    # once
composer test
```

That script sets up the test database with the same grant procedure as
production, so the tests walk the same path. A test environment that does it
slightly differently tests the wrong thing. `IsolationTest` creates two real
customers and so passes the creating, the rights and the separation between
customers.

The doctor walks every tenant and checks, among other things: are the tables
there, does the cron run, can the application account *not* drop customer
databases, does the provisioner account exist, are there no databases or folders
without a tenant, do both workers run the code that is checked out. It exits
with 1 on a problem, and says per finding what to do about it.

## The three MySQL accounts

| Account | May | For |
| --- | --- | --- |
| `lavoro_app` | only the central database | the application itself |
| `lavoro_provisioner` | only `lavoro_tenant_%` | creating and dropping customers |
| one per customer | only its own database | the connection during a request |

`lavoro_app` deliberately cannot create or drop a customer database, and the
provisioner deliberately reaches nothing outside the customer namespace. Those
two boundaries are what the separation rests on, and
[multi-tenancy](../development/multi-tenancy.md#three-mysql-accounts) explains
why a stored procedure hands out each customer's rights. `verify-mysql.sh` tries
to cross both and expects a refusal:

```bash
sudo scripts/tenancy/verify-mysql.sh
```

### Setting up (once, per server)

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run    # shows the SQL, changes nothing
sudo scripts/tenancy/setup-mysql.sh --write-env  # does it, and fixes .env
```

That creates the Linux user, the accounts, their rights and the procedure. By
hand it cannot be done without breaking something; the complete installation is
in [installing a server](../install/server.md).

### After every change: restart the workers

```bash
php artisan tenancy:restart-workers
```

A worker reads `.env` and the code once, when it starts, and keeps running what
it had. The heartbeat carries on as if nothing is wrong; only the work goes
quietly wrong. The command restarts both units, stops anything of ours running
outside them, and waits until both queues report the code that is checked out.

## Customers

```bash
php artisan tenants:list                                  # name, database, users
php artisan tenant:overview                               # seats, storage, monthly price, start date, what is due

php artisan tenant:create "Bedrijf BV" beheer@bedrijf.nl  # customer + first admin
php artisan tenant:delete <id>                            # everything gone, asks for confirmation
```

Both commands elevate themselves to `lavoro_provisioner` when the sudo rule is
there (`sudo scripts/tenancy/setup-sudoers.sh`, once). Without it they say which
command to type.

Deleting can be done in the panel as well: open the customer with **bewerken**
and use the red block at the bottom. The name has to be typed over literally
there, because there is no way back and no bin. If that customer's database is
already gone, that screen keeps working -- precisely when you need that button.
Their invoices stay: the books keep an issued invoice for seven years, and its
number must never come free.

Creating can be done in the admin panel under `/beheer` too. That puts down a
request the provisioning worker carries out; if it stays on "in de wacht", that
worker is not running. If a request fails, it stays with the reason next to it
until you click it away with **weghalen** -- the cleaning up happens by itself,
but the reason should stay until someone has seen it.

`NONE` under *Since* in `tenant:overview` means the customer has no start date,
and without one nothing is ever invoiced. Set it on the subscription screen.

### Left-over folders

A customer's files live in `storage/tenant-<id>`. When a customer is gone and its
folder is not, the doctor names it with its file count and size. Look inside
first, then:

```bash
php artisan tenancy:prune-storage tenant-<id>   # shows what it deletes, asks first
```

Empty left-overs are not reported; there is nothing to decide about them.

### Admin for an existing customer

`tenant:create` makes one straight away. A database taken over with
`tenant:setup-existing` has none yet:

```bash
php artisan tenant:admin "Bedrijf BV" beheer@bedrijf.nl
php artisan tenant:admin <id> beheer@bedrijf.nl --password=ownpassword
```

If the user exists already, this resets their password and confirms the admin
role. The password appears on screen.

### Admin for the panel itself

```bash
php artisan landlord:user you@majorlabel.nl
```

That is a separate account in the landlord database; it has nothing to do with a
customer's users.

## Changing a subscription

```bash
php artisan tenant:package <id> business
php artisan tenant:modules <id> --add=assistant --remove=quotes
php artisan tenant:seats <id> --field=+5 --office=2
php artisan tenant:storage <id> --limit=200
php artisan tenant:override <id> --price=14900   # fixed package price in cents, --clear removes it
```

**A fixed price covers the package, not the rest.** Extra seats, modules and
extra storage come on top; otherwise a customer with a fixed price would get
everything they add for free. A single module can have a price of its own agreed
in the panel, and that wins over a bundle price from the catalogue.

Wherever such an agreement applies, the invoice puts the normal price next to
it: *Abonnement Lavoro Starter 01-09-2026 t/m 30-09-2026, normaal € 27,50,
speciale prijsafspraak*. In a year nobody remembers why a different amount was
there, and the customer should see it was an agreement and not a mistake.

All of it can be done in the panel too.

**Only a package change is settled.** Switch package halfway through a period,
or agree a different price for that package, and over that period the customer
pays the old one up to the day of the switch and the new one after. What is
added on its own produces no separate settlement line. A module switched on
halfway through the month is charged pro rata on its own line: *AI-assistent
07-09-2026 t/m 30-09-2026 (24 van 30 dagen)*. If it has a price agreement, the
normal price next to it is the catalogue price, so the two amounts can be
compared. A bundle counts from the day it became complete.

Extra seats and storage do go along for the whole period; they belong to the
size of the subscription and not to a single product.

**Cancelling** goes with the date *Opgezegd per*: the last day the subscription
runs. It is charged up to and including that day, so the last month is on the
invoice pro rata. If that month was already invoiced, the overpayment comes back
as credit on the next invoice. If the cancellation is withdrawn, that credit
lapses. After the last day there is nothing left to invoice.

When more credit is outstanding than there is to invoice -- and for a customer
who has left that is the rule, because no next invoice comes -- the button
produces a **credit note**: a number from the same series, with a negative
amount. The pdf and the mail are called a credit note too. It cannot be
collected: paying back is done by hand, and the collection file skips credit
notes.

**Monthly to yearly or back** starts the new term at the first period that has
not been paid for, and never retroactively. Someone who already paid September
and switches to yearly gets their yearly invoice from 1 October; someone who paid
a year ahead and switches to monthly gets their first monthly invoice once that
year is over. That anchor is separate from the start date on screen, which stays
what it was.

Settling a package change can go two ways:

- If the period was already invoiced at the old price, the difference is added
  over the days still to come.
- If it was not invoiced yet, the next invoice charges the new package over the
  whole period -- including the days on the old package. Those come off as
  credit.

A switch on the first day of a period that has not been invoiced therefore
produces nothing: not a day on the old package has passed yet.

## Invoices

Runs every hour by itself and only creates what is due:

```bash
php artisan invoices:issue --dry-run     # shows what would come
php artisan invoices:issue               # creates, sends nothing
php artisan invoices:issue --mail        # creates and sends
```

Sending deliberately does not happen by itself. In the panel every invoice has
a button, with the PDF and the UBL file attached.

**Numbering runs on and never goes back.** One series per year across all
customers, from a counter that only goes up. An invoice that should not have been
made can be removed with **verwijderen** as long as it has not been sent or
collected; its number stays spent and the next invoice takes the following one.
A gap in the series is a question you can answer; two invoices with the same
number is not. Once an invoice has gone out, the way back is a credit note.

Collection: `/beheer/incasso` produces a SEPA file (pain.008) for the bank. It
needs a mandate and an IBAN per customer, and once a creditor id under
Catalogus → Facturatie.

## Migrations

```bash
php artisan migrate                # only the landlord database
php artisan tenants:migrate        # every customer
```

New migrations belong in `database/migrations/tenant/`, unless they set
`protected $connection = 'central'`. Forget `tenants:migrate` and every customer
stays behind, and the application only breaks at the first request that touches
the new column. `scripts/deploy.sh` does both.

## Deploying

```bash
bash scripts/deploy.sh
```

Maintenance page on, a backup of every database, fetching the code, both
migrations, caches, restarting the workers and php, the checks, maintenance page
off. It stops as soon as something goes wrong, says on which line, and the
maintenance page still goes off.

## Also here

| | |
| --- | --- |
| [backup-restore.md](backup-restore.md) | what to keep, and how to put it back |
| [troubleshooting.md](troubleshooting.md) | when the doctor is not enough |
| [demo.md](demo.md) | the demo customer |
| [../install/fail2ban.md](../install/fail2ban.md) | refused logins, and who gets locked out |
| [import-existing.md](../install/import-existing.md) | take over a single-customer Lavoro |
| [../development/risks.md](../development/risks.md) | where this breaks and how you notice |
