# Running Lavoro — operations

Everything you do on a server. For the reasoning behind the setup:
`superpowers/plans/2026-06-09-multi-database-tenancy.md`.

**Setting up a new server, or moving the existing installation over?**
Follow [tenancy-production.md](tenancy-production.md) — that is the list from
nothing to running, in order. Below is the day-to-day work.


## Working locally

Everything below can be done locally too, without production:

```bash
./scripts/tenancy/dev.sh                 # the app, both workers and vite
./scripts/tenancy/dev.sh --reset-logins  # every password set to 'testtest'
```

The app is then at http://127.0.0.1:8199, the admin panel at /beheer. On start
the script lists the customers there are and the address to log in with at
each.

That runs on `.env.localtest`, with a central database of its own and real
customer databases next to it -- not on `.env`, which points at a database that
is not always running. That is why the script sets `APP_ENV` as an environment
variable: `--env=localtest` only applies to the artisan command itself, while
the requests the server handles boot again and then simply read `.env`.

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
| `lavoro_app` | only the landlord database | the application itself |
| `lavoro_provisioner` | only `lavoro_tenant_%` | creating and dropping customers |
| one per customer | only its own database | the connection during a request |

`lavoro_app` deliberately cannot create or drop a customer database, and the
provisioner deliberately reaches nothing outside the customer namespace. Those
are the two boundaries the whole setup leans on; `verify-mysql.sh` tries to
cross both and expects a refusal.

### Setting up (once, per server)

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run    # shows the SQL, changes nothing
sudo scripts/tenancy/setup-mysql.sh --write-env  # does it, and fixes .env
```

That creates the Linux user, the accounts, the right rights and the procedure
below. By hand it cannot be done without breaking something; the complete
installation is in `tenancy-production.md`.

### Why a procedure hands out the rights

Every customer gets a MySQL login of its own that may only reach its own
database. Creating it is the provisioner's work, but MySQL and MariaDB weigh a
`GRANT` naming a database against a row for exactly that name, and never against
the wildcard `lavoro\_tenant\_%`. So the provisioner can create
`lavoro_tenant_acme` but cannot grant rights on it: error 1044.

The temptation is then to give the account `ALL PRIVILEGES ON *.*`. Don't — that
makes it as powerful as root and leaves nothing of the separation.

Instead the procedure `lavoro_admin.grant_tenant_access` hands out the rights.
It lives in a database of its own, runs as whoever created it (root) and refuses
every name outside the customer namespace. The provisioner has nothing in that
database except the right to call it, so it cannot replace it with a broader
version. See `scripts/tenancy/setup-mysql.sh`.

### After every change: restart the workers

```bash
php artisan tenancy:restart-workers
```

That restarts both units, stops any worker that survived the restart -- one
started by hand at some point keeps running old code otherwise -- and waits
until both queues report in with the code that is checked out. `scripts/deploy.sh`
runs it for you; a manual pull does not.

The same goes for php under the web server: it holds on to the compiled code
(opcache). `view:clear` does not touch that, so after a pull the web server keeps
running the old classes while the templates are already new -- a combination
that does strange things, such as a screen that keeps reloading itself because
the controller does not send a value yet that the template already expects.

```bash
pkill -f lsphp                      # LiteSpeed: the processes come back by themselves
sudo systemctl reload php8.3-fpm    # Apache or nginx with php-fpm
```

With `-f`: `pkill` compares the exact process name by default, and that is
`lsphp8.3`, so a plain `pkill lsphp` finds nothing. lsphp has no systemd unit;
LiteSpeed starts the processes again as soon as they are gone.

A worker reads `.env` and the code once, at boot, and holds on to it. After a
`git pull` or a change in `.env` it keeps running what it had, while the
heartbeat keeps coming in and everything looks healthy. The doctor compares what
each worker booted with to what is here now, names the process when they differ,
and says so when more than one process serves the same queue.

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

## Taking over an existing installation

```bash
bash scripts/tenancy/import-install.sh --from /home/klant/lavorofsm \
     --name "Bedrijf BV" --slug bedrijf --package business --dry-run
```

Copies a single-customer installation into a customer of this setup: its
database, its uploads, a login and the package. It needs root -- the other
installation belongs to another account, and creating a database is not the app
account's -- and says exactly what to paste in a root shell when it does not
have it. `--dry-run` writes nothing and shows the whole plan; `--billing-from`
sets the day billing starts (a date, or `none`), which on a takeover is an
agreement rather than automatically today.

## The demo

`php artisan demo:install` builds a Demo customer with a complete set of
credible data, and the scheduler rebuilds it every night. Logins, what is in it
and how to put real photos in: see *The demo tenant* in `tenancy-production.md`.

## Further reading

| | |
| --- | --- |
| `tenancy-test-risks.md` | where this setup breaks and how you notice |
| `../CLAUDE.md` | rules for whoever writes code |
| `handleiding.md` | for the people who work with it |
