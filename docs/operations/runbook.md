# Running Lavoro — the runbook

This page is about a server where Lavoro is already installed. For a new server,
see [installing a server](../install/server.md). To move an existing Lavoro in as
a customer, see [taking over an installation](../install/import-existing.md). For
how the whole thing is put together, see
[multi-tenancy](../development/multi-tenancy.md).

Working on the code instead? See
[getting started](../development/getting-started.md).

## Some words used here

- **Customer** (tenant): one company using this installation. Each has its own
  database, named `lavoro_tenant_<name>`.
- **Shared database** (landlord): `lavoro_landlord`. It holds the list of
  customers, their subscriptions and invoices, and which email address belongs
  to which customer.
- **Admin panel**: the pages under `/beheer`, where you manage customers and
  invoices. It has its own login accounts, separate from the customers' users.
- **Worker**: a background process that runs queued jobs, such as sending mail
  or creating a new customer database.

## What has to be running

| | Command | Which account |
| --- | --- | --- |
| Web server | php-fpm or lsphp | the web server's own account |
| Worker | `php artisan queue:work` | the account that owns the files |
| Worker | `php artisan queue:work --queue=provisioning` | `lavoro_provisioner` |
| Cron | `* * * * * php artisan schedule:run` | the account that owns the files |

The first two have no fixed account name, because it differs per server. Apache
and nginx normally run PHP as `www-data`, LiteSpeed as `nobody`, and that is not
always the same account that owns the files. Check on this server:

```bash
ps -eo user,comm | grep -iE 'lsphp|php-fpm'   # the web server's account
stat -c %U artisan                            # the account that owns the files
```

This matters: if the web server cannot write in `storage/logs`, errors from web
requests are not recorded anywhere and you have nothing to look up.

`php artisan tenancy:doctor` reports the web server's account (the application
records it on a normal web request) and checks that this account can write to
the log and to every customer's folder.

`scripts/tenancy/setup-workers.sh` creates the systemd services for both
workers, filling in the accounts and paths of this server.

### Why there are two workers

The normal worker runs as the account that owns the files. That account is not
allowed to create or delete databases.

The second worker runs as `lavoro_provisioner`, which is allowed to do exactly
that, and nothing else. Creating and deleting customers happens there. If the
admin panel itself could create databases, a bug in the panel could also delete
one.

The normal worker deliberately ignores the `provisioning` queue. If the second
worker is not running, a new customer stays on "in de wacht" in the panel, and
`tenancy:doctor` reports it after about fifteen minutes.

The second worker runs with `--tries=1`, so a failed job is not retried.
Retrying a half-created customer fails on "database already exists", which hides
the original error.

### Why the cron matters

Without the cron line, nothing happens on its own: no invoices, no Google
Calendar synchronisation, no service orders from maintenance contracts and no
fresh demo. `php artisan tenancy:doctor` notices a missing cron within about
fifteen minutes.

## Checking that everything is right

```bash
php artisan tenancy:doctor
```

The doctor goes through every customer and checks, among other things: are the
database tables up to date, does the cron run, is the application account
correctly *unable* to delete customer databases, does the provisioner account
exist, are there databases or folders left over from deleted customers, and are
both workers running the code that is currently checked out.

It exits with code 1 when there is a problem, and says per finding what to do.

On a development machine, run the tests before anything goes to a server:

```bash
sudo scripts/tenancy/setup-test-db.sh    # once
composer test
```

That script creates the test database with the same permission setup as a real
server, so the tests exercise the same code path. `IsolationTest` creates two
real customers and checks that they cannot see each other's data.

## The three kinds of MySQL account

| Account | Can reach | Used for |
| --- | --- | --- |
| `lavoro_app` | only the shared database | the application during normal use |
| `lavoro_provisioner` | only databases named `lavoro_tenant_*` | creating and deleting customers |
| one per customer | only that customer's own database | serving that customer's requests |

`lavoro_app` cannot create or delete a customer database, and the provisioner
cannot touch anything outside the customer databases. Those two limits are what
keeps customers separated. [Multi-tenancy](../development/multi-tenancy.md#three-kinds-of-mysql-account)
explains why a stored procedure is used to grant each customer its rights.

This script tries to cross both limits and expects to be refused:

```bash
sudo scripts/tenancy/verify-mysql.sh
```

### Setting the accounts up (once per server)

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run    # shows the SQL, changes nothing
sudo scripts/tenancy/setup-mysql.sh --write-env  # creates everything and updates .env
```

This creates the Linux user, the MySQL accounts, their permissions and the
stored procedure. Doing it by hand is error-prone. The full installation is in
[installing a server](../install/server.md).

### Restart the workers after every change

```bash
php artisan tenancy:restart-workers
```

A worker reads the code and `.env` once, when it starts, and then keeps running
that version. After a deploy or an `.env` change it carries on with the old one.
Nothing looks wrong from the outside: the worker still reports that it is alive,
but the work it does is out of date.

This command restarts both workers, stops any stray ones running outside the
services, and waits until both report the version of the code that is currently
checked out.

## Customers

```bash
php artisan tenants:list                                  # name, database, number of users
php artisan tenant:overview                               # seats, storage, monthly price, start date, amount due

php artisan tenant:create "Bedrijf BV" beheer@bedrijf.nl  # new customer plus first administrator
php artisan tenant:delete <customer id>                   # deletes everything, asks for confirmation
```

Both commands need the provisioner account. They switch to it automatically if
the sudo rule is in place (`sudo scripts/tenancy/setup-sudoers.sh`, once per
server). Without that rule they print the command you should run instead.

You can also create a customer in the admin panel. That creates a request which
the provisioning worker picks up. If it stays on "in de wacht", that worker is
not running. A failed request stays visible with the reason next to it until you
remove it with **weghalen**. Anything half-created is cleaned up automatically,
but the reason stays on screen so somebody sees it.

Deleting can be done in the panel too: open the customer, choose **bewerken**,
and use the red block at the bottom. You have to type the customer's name
exactly, because there is no undo and no recycle bin. That screen still works if
the customer's database is already gone, which is usually when you need it. The
customer's invoices are kept: issued invoices have to be kept for seven years
and their numbers must never be reused.

In `tenant:overview`, `NONE` under *Since* means the customer has no start date.
Such a customer is never invoiced. Set the date on the subscription screen.

### Folders left behind

A customer's uploaded files live in `storage/tenant-<customer id>`. If the
customer is gone but the folder is not, the doctor reports it with the number of
files and its size. Look inside before deleting:

```bash
php artisan tenancy:prune-storage tenant-<customer id>   # shows what it will delete, asks first
```

Empty leftover folders are not reported.

### Giving an existing customer an administrator

`tenant:create` creates one immediately. A customer imported with
`tenant:setup-existing` does not have one yet:

```bash
php artisan tenant:admin "Bedrijf BV" beheer@bedrijf.nl
php artisan tenant:admin <customer id> beheer@bedrijf.nl --password=yourpassword
```

If that user already exists, this resets their password and makes sure they have
the administrator role. The password is shown on screen.

### An account for the admin panel itself

```bash
php artisan landlord:user you@majorlabel.nl
```

This account lives in the shared database and has nothing to do with any
customer's users.

## Changing a subscription

```bash
php artisan tenant:package <customer id> business
php artisan tenant:modules <customer id> --add=assistant --remove=quotes
php artisan tenant:seats <customer id> --field=+5 --office=2
php artisan tenant:storage <customer id> --limit=200
php artisan tenant:override <customer id> --price=14900   # fixed package price in cents; --clear removes it
```

All of this can be done in the admin panel as well.

**A fixed price applies to the package only.** Extra seats, extra modules and
extra storage are charged on top. Otherwise a customer with a fixed price would
get every addition for free. A single module can also have its own agreed price,
which overrides the bundle price from the catalogue.

Where such an agreement applies, the invoice shows the normal price next to it,
for example: *Abonnement Lavoro Starter 01-09-2026 t/m 30-09-2026, normaal
€ 27,50, speciale prijsafspraak*. A year later nobody remembers why the amount
was different, and the customer can see it was agreed and not a mistake.

**Only a change of package is settled against what was already invoiced.** If a
customer changes package halfway through a period, they pay the old price up to
the day of the change and the new price after it. Things that are merely added
do not produce a settlement line of their own.

A module switched on halfway through the month is charged for part of the month
on its own line: *AI-assistent 07-09-2026 t/m 30-09-2026 (24 van 30 dagen)*. If
that module has an agreed price, the catalogue price is shown next to it. A
bundle is charged from the day it became complete.

Extra seats and extra storage are charged for the whole period. They belong to
the size of the subscription rather than to one product.

Settling a package change works in one of two ways:

- If the period was already invoiced at the old price, the difference for the
  remaining days is added to the next invoice.
- If the period was not invoiced yet, the next invoice charges the new package
  for the whole period, and the days on the old package are credited back.

A change on the first day of a period that has not been invoiced yet therefore
changes nothing: no day on the old package has passed.

**Cancelling** uses the date *Opgezegd per*, which is the last day the
subscription runs. That day is included, so the final month is charged for part
of the month. If that month was already invoiced, the difference comes back as
credit on the next invoice. If the cancellation is withdrawn, that credit
disappears again. After the last day there is nothing left to invoice.

If there is more credit than there is to invoice — which is normal for a
customer who has left, because no next invoice will come — the button creates a
**credit note** instead: a number from the same series with a negative amount.
The PDF and the email are labelled as a credit note. It is not collected by
direct debit; paying it back is done by hand, and the direct debit file skips
credit notes.

**Switching between monthly and yearly** takes effect at the start of the first
period that has not been paid for. It is never applied retroactively. Somebody
who has already paid September and switches to yearly gets their yearly invoice
from 1 October. Somebody who paid a year in advance and switches to monthly gets
their first monthly invoice once that year has passed. The start date shown on
screen does not change.

## Invoices

Invoicing runs automatically every hour and only creates invoices that are due.

```bash
php artisan invoices:issue --dry-run     # shows what would be created
php artisan invoices:issue               # creates them, sends nothing
php artisan invoices:issue --mail        # creates and emails them
```

Sending does not happen automatically. In the admin panel each invoice has a
send button, with the PDF and the UBL file attached.

**Invoice numbers only go up and are never reused.** There is one series per
year, shared by all customers. An invoice that should not have been created can
be deleted with **verwijderen** as long as it has not been sent or collected.
Its number stays used, and the next invoice gets the following number. A missing
number can be explained afterwards; two invoices with the same number cannot.
Once an invoice has been sent, the way to undo it is a credit note.

Direct debit: `/beheer/incasso` creates a SEPA file (pain.008) for the bank. It
needs a mandate and an IBAN per customer, and a creditor id, which is set once
under Catalogus → Facturatie.

## Database migrations

```bash
php artisan migrate                # the shared database only
php artisan tenants:migrate        # every customer database
```

New migrations belong in `database/migrations/tenant/`, unless they set
`protected $connection = 'central'`, which makes them part of the shared
database.

If you forget `tenants:migrate`, the customer databases stay behind and the
application breaks at the first request that uses the new column.
`scripts/deploy.sh` runs both.

## Deploying

```bash
bash scripts/deploy.sh
```

This puts up the maintenance page, backs up every database, fetches the code,
runs both sets of migrations, rebuilds the caches, restarts the workers and PHP,
runs the checks, and takes the maintenance page down again.

It stops as soon as something fails and says at which step. The maintenance page
is taken down even then.

## Other pages

| | |
| --- | --- |
| [backup-restore.md](backup-restore.md) | what to back up, and how to restore it |
| [troubleshooting.md](troubleshooting.md) | when the doctor is not enough |
| [demo.md](demo.md) | the demo customer |
| [../install/fail2ban.md](../install/fail2ban.md) | locking out password guessing |
| [../install/import-existing.md](../install/import-existing.md) | taking over an existing Lavoro |
| [../development/risks.md](../development/risks.md) | where this can break and how you would notice |
