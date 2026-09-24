# Running Lavoro — the runbook

This page is about a server where Lavoro is already installed. For a new server,
see [installing a server](../install/server.md). To move an existing Lavoro in as
a customer, see [taking over an installation](../install/import-existing.md). For
how the whole thing is put together, see
[multi-tenancy](../development/multi-tenancy.md).

Working on the code instead? See
[getting started](../development/getting-started.md).

Run the commands on this page from the folder Lavoro is installed in
(`/var/www/lavoro` in these examples), logged in as the Linux account that owns
those files, unless the command starts with `sudo`. Running `php artisan …` as
another account either fails or leaves files that account cannot read.

## Some words used here

- **Customer** (tenant): one company using this installation. Each has its own
  database, named `lavoro_tenant_<name>`.
- **Shared database** (landlord): `lavoro_landlord`. It holds the list of
  customers, their subscriptions and invoices, and which email address belongs
  to which customer.
- **Admin panel**: the pages under `/beheer`, where you manage customers and
  invoices. It has its own login accounts, separate from the customers' users.
- **Worker**: a background process that runs queued jobs, such as sending mail
  or creating a new customer database. There are two, described below.
- **Provisioner**: the `lavoro_provisioner` account, the only one allowed to
  create and delete customer databases.
- **The doctor**: `php artisan tenancy:doctor`, the command that checks the
  whole installation. It prints one line per check (`OK`, `FAIL` or `SKIP`) with
  what to do about it, and exits with code 1 if anything failed. It is mentioned
  throughout this page.
- **Customer id**: the long identifier of a customer, shown by
  `php artisan tenants:list`. Commands that take `<customer id>` want that value,
  and most also accept the customer's name.

## What has to be running

| | Command | Which account |
| --- | --- | --- |
| Web server | php-fpm or lsphp | the web server's own account |
| Worker | `php artisan queue:work` | the account that owns the files |
| Worker | `php artisan queue:work --queue=provisioning` | `lavoro_provisioner` |
| Cron | `* * * * * cd /var/www/lavoro && php artisan schedule:run` | the account that owns the files |

The first two have no fixed account name, because it differs per server. Apache
and nginx normally run PHP as `www-data`, LiteSpeed as `nobody`, and that is not
always the same account that owns the files. Check on this server:

```bash
ps -eo user,comm | grep -iE 'lsphp|php-fpm'   # the web server's account
cd /var/www/lavoro && stat -c %U artisan      # the account that owns the files
```

This matters: if the web server cannot write in `storage/logs`, errors from web
requests are not recorded anywhere and you have nothing to look up.

`php artisan tenancy:doctor` reports the web server's account (the application
records it on a normal web request) and checks that this account can write to
the log and to every customer's folder.

You do not have to write any of this by hand.
`sudo scripts/tenancy/setup-workers.sh` creates the systemd services for both
workers and the cron line, filling in the accounts, paths and PHP binary of this
server. Run it again after moving the installation or changing accounts.

### Why there are two workers

The normal worker runs as the account that owns the files. That account is not
allowed to create or delete databases.

The second worker runs as `lavoro_provisioner`, which is allowed to do exactly
that, and nothing else. Creating and deleting customers happens there. If the
admin panel itself could create databases, a bug in the panel could also delete
one.

The normal worker deliberately ignores the `provisioning` queue, so nothing else
picks that work up. If the second worker is not running, a new customer stays on
"in de wacht" (Dutch for "waiting") in the panel, and the doctor reports it once
a request has been waiting about fifteen minutes.

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

This script tries to cross both limits and expects to be refused. It prints one
line per attempt and exits with code 1 if any of them succeeded, which would
mean the separation is gone:

```bash
sudo scripts/tenancy/verify-mysql.sh
```

Run it once while installing and whenever you change database permissions.
`scripts/deploy.sh` runs it on every deploy. It needs root, because reading
MySQL's permission tables does.

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

`tenant:create` and `tenant:delete` need the provisioner account, because they
create and delete databases. They switch to it by themselves if the sudo rule is
in place (`sudo scripts/tenancy/setup-sudoers.sh`, once per server). Without that
rule they stop and print the `sudo -u lavoro_provisioner …` command to run
instead.

You can also create a customer in the admin panel, with **Nieuwe tenant** (Dutch
for "new customer"). That writes a request which the provisioning worker picks
up, so the customer appears a moment later. If it stays on "in de wacht" (Dutch
for "waiting"), that worker is not running; see
[troubleshooting](troubleshooting.md#a-new-customer-stays-on-in-de-wacht).

A request that failed stays on screen with the reason next to it until you
remove it with **weghalen** (Dutch for "remove"). Anything half-created is
cleaned up automatically; only the message waits, so that somebody reads it.

Deleting can be done in the panel as well: open the customer, choose
**bewerken** (Dutch for "edit"), and use the red block at the bottom. You have
to type the customer's name exactly, because there is no undo and no recycle
bin. That edit screen keeps working when the customer's database is already
gone, which is usually the moment you need it.

The customer's invoices are kept when the customer is deleted. Issued invoices
have to be kept for seven years under Dutch bookkeeping rules, and their numbers
must never be reused.

In the output of `tenant:overview`, `NONE` in the *Since* column means that
customer has no subscription start date, and a customer without one is never
invoiced. Fill it in at `/beheer` → the customer → **Abonnement** (Dutch for
"subscription").

### Folders left behind

A customer's uploaded files live in `storage/tenant-<customer id>`. If the
customer is gone but the folder is not, the doctor reports it with the number of
files and its size. Look inside before deleting:

```bash
php artisan tenancy:prune-storage tenant-<customer id>   # shows what it will delete, asks first
```

Empty leftover folders are not reported.

### Giving a customer an administrator, or resetting a password

`tenant:create` makes an administrator straight away. A customer that came from
an imported installation keeps its own users, including whoever was
administrator there, so normally you do not need this. Use it when no
administrator is left, or when somebody is locked out:

```bash
php artisan tenant:admin "Bedrijf BV" beheer@bedrijf.nl
php artisan tenant:admin <customer id> beheer@bedrijf.nl --password=yourpassword
```

Without `--password` it generates one and prints it on screen.

If that email address already belongs to a user of this customer, the command
resets their password **and gives them the administrator role**. Do not use it
on an ordinary user just to reset a password; ask an administrator of that
company to do it from inside the application instead.

### An account for the admin panel itself

```bash
php artisan landlord:user you@majorlabel.nl
```

This creates a login for `/beheer` and prints a generated password. It lives in
the shared database and has nothing to do with any customer's users, so it
cannot log in to a company's own screens.

## Changing a subscription

```bash
php artisan tenant:package <customer id> business
php artisan tenant:modules <customer id> --add=assistant --remove=quotes
php artisan tenant:seats <customer id> --field=+5 --office=2
php artisan tenant:storage <customer id> --limit=200
php artisan tenant:override <customer id> --price=14900   # fixed package price in cents; --clear removes it
```

`--field` and `--office` are the two kinds of seat. A field seat is for somebody
who can be planned on work orders, such as a mechanic; an office seat is for
everybody else. Each package includes a number of both, and these commands set
the extra ones on top of that. `+5` adds five to what is there now, `2` sets it
to two.

`--limit` for storage is in whole gigabytes. `--price` is in cents, so `14900`
means € 149,00 per period.

All of this can also be done in the admin panel, at `/beheer` → the customer →
**Abonnement** (Dutch for "subscription").

**A fixed price applies to the package only.** Extra seats, extra modules and
extra storage are charged on top. Otherwise a customer with a fixed price would
get every addition for free. A single module can also have its own agreed price,
which then overrides the price the catalogue gives it. A bundle is a set of
modules sold together for one price; an agreed price on a single module wins
over that too.

Where such an agreement applies, the invoice shows the normal price next to it,
for example (invoices are in Dutch): *Abonnement Lavoro Starter 01-09-2026 t/m
30-09-2026, normaal € 27,50, speciale prijsafspraak*. A year later nobody remembers why the amount
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

**Cancelling** uses the date *Opgezegd per* (Dutch for "cancelled as of") on the
subscription screen, which is the last day the subscription runs. That day is included, so the final month is charged for part
of the month. If that month was already invoiced, the difference comes back as
credit on the next invoice. If the cancellation is withdrawn, that credit
disappears again. After the last day there is nothing left to invoice.

If there is more credit than there is left to invoice — which is normal for a
customer who has left, because no next invoice will come — then the invoice
button on that customer's screen creates a **credit note** instead: a number
from the same series, with a negative amount. The PDF and the email say credit
note as well. It is not collected by direct debit, because money going back is
paid by hand; the direct debit file leaves credit notes out.

**Switching between monthly and yearly** takes effect at the start of the first
period that has not been paid for. It is never applied retroactively. Somebody
who has already paid September and switches to yearly gets their yearly invoice
from 1 October. Somebody who paid a year in advance and switches to monthly gets
their first monthly invoice once that year has passed. The start date shown on
screen does not change.

## Invoices

Invoices are created automatically, once an hour, and only for customers that
have something due. That hourly run depends on the cron line above; without it
no invoice is ever created.

```bash
php artisan invoices:issue --dry-run     # shows what would be created
php artisan invoices:issue               # creates them, sends nothing
php artisan invoices:issue --mail        # creates and emails them
```

Sending is never automatic, so an invoice can be checked before it goes out. In
the admin panel each invoice has a send button. The email carries two
attachments: the PDF, and a UBL file, which is the same invoice in a format
accounting software can read.

**Invoice numbers only go up and are never reused.** There is one series per
year, shared by all customers. An invoice that should not have been created can
be deleted with **verwijderen** (Dutch for "delete") as long as it has not been
sent or collected.
Its number stays used, and the next invoice gets the following number. A missing
number can be explained afterwards; two invoices with the same number cannot.
Once an invoice has been sent, the way to undo it is a credit note.

**Direct debit.** The page at `/beheer/incasso` (Dutch for "direct debit")
creates a SEPA file in pain.008 format, which is the file banks accept for
collecting payments. Upload it in your bank's own software.

Before it works you need three things: a signed mandate and an IBAN for each
customer you collect from, filled in on that customer's subscription screen, and
your own creditor id from your bank, filled in once at `/beheer` → **Catalogus**
→ **Facturatie** (Dutch for "catalogue" and "invoicing").

## Database migrations

A migration is a change to the structure of a database — a new table, a new
column — that ships with the code. New code often needs one, so after fetching
new code the databases have to be brought up to date.

Because every customer has a database of its own, that is two commands:

```bash
php artisan migrate                # the shared database only
php artisan tenants:migrate        # every customer database, one after another
```

If you run only the first, the customer databases stay behind, and the
application breaks at the first request that uses the new column. `tenants:migrate`
prints each customer and what it applied, or "Nothing to migrate".

`scripts/deploy.sh` runs both, so you only need these by hand if you updated the
code some other way.

For developers: a migration goes in `database/migrations/tenant/` unless it sets
`protected $connection = 'central'`, which makes it part of the shared database
instead.

## Deploying

```bash
bash scripts/deploy.sh
```

This takes the installation offline for everyone while it runs, so deploy
outside working hours. In order, it:

1. puts up the maintenance page;
2. dumps every database into `storage/backups/` and stops if a dump fails;
3. fetches the new code with `git pull --ff-only`, which refuses if anything on
   the server was changed by hand;
4. runs the migrations for the shared database and for every customer;
5. rebuilds the caches and the front-end assets;
6. restarts both workers and reloads PHP, so they run the new code;
7. runs `scripts/tenancy/verify-mysql.sh` and `php artisan tenancy:doctor`;
8. takes the maintenance page down.

It stops as soon as a step fails and prints which one. The maintenance page is
taken down in that case too, so the site does not stay offline after a failed
deploy — but the code may then be half updated, so read what it printed before
walking away.

The dumps from step 2 exist to undo that one deploy. They are not a backup of
the installation; see [backups and restoring](backup-restore.md#the-dumps-the-deploy-leaves-behind-are-not-a-backup).

Steps 6 and 7 need the sudo rules from `sudo scripts/tenancy/setup-sudoers.sh`.
Without them the deploy finishes but tells you to restart the workers and reload
PHP by hand.

## Other pages

| | |
| --- | --- |
| [backup-restore.md](backup-restore.md) | what to back up, and how to restore it |
| [troubleshooting.md](troubleshooting.md) | when the doctor is not enough |
| [demo.md](demo.md) | the demo customer |
| [../install/fail2ban.md](../install/fail2ban.md) | locking out password guessing |
| [../install/import-existing.md](../install/import-existing.md) | taking over an existing Lavoro |
| [../development/risks.md](../development/risks.md) | where this can break and how you would notice |
