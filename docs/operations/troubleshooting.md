# When something goes wrong

Run the commands on this page from the folder Lavoro is installed in
(`/var/www/lavoro` in these examples), logged in as the Linux account that owns
those files, unless the command starts with `sudo`.

## Always start here

```bash
php artisan tenancy:doctor
```

This goes through the whole installation and prints one line per check, marked
`OK`, `FAIL` or `SKIP`, with a short explanation and what to do about it. A
`SKIP` means it could not check that point from here, not that it is fine. The
command exits with code 1 if anything failed, so a script can act on it.

It checks the database accounts and their permissions, the PHP extensions, the
`.env` settings, both background processes, the cron job, your invoicing
details, and every customer's database, users and folders.

The rest of this page is for problems the doctor cannot solve for you.

## Where the log files are

| File | What is in it |
| --- | --- |
| `storage/logs/laravel.log` | errors from the application, for every company together |
| `storage/logs/auth.log` | every refused login; see [fail2ban](../install/fail2ban.md) |
| `journalctl -u lavoro-worker` | the background worker for ordinary jobs |
| `journalctl -u lavoro-provisioning` | the background worker that creates and deletes customers |

If `storage/logs/laravel.log` is empty while the site is clearly broken, the web
server's account probably cannot write to it. That account is often not the one
that owns the files. `php artisan tenancy:doctor` reports which account the web
server runs as and whether it can write there; the fix is in
[installing a server](../install/server.md#file-permissions).

## Something went wrong while installing

| Situation | What to do |
| --- | --- |
| You are still installing and have not gone live (before [step 8](../install/server.md#8-go-live)) | Nothing is at risk yet. If you are moving an existing installation in, that one is still serving its users. Start the step again. |
| You lost the password of the `lavoro_app` database account | The site cannot reach the shared database and is down. Run `sudo scripts/tenancy/setup-mysql.sh --write-env --rotate-app-password`. It sets a new password, writes it into `.env`, and leaves the customer databases alone. |
| Importing an existing installation failed halfway | See the next section. |
| You went live and want to go back to the old installation, within a week | On this server: `php artisan app:maintenance --message="Tijdelijk offline."`. On the old server: switch its web server back on and run `php artisan up`. Point the domain back at it. Everything entered here since the move is lost. |

## An import failed halfway

An import that stops partway can leave a customer record without a database, or
a database without a customer record. `php artisan tenancy:doctor` reports both.

If the customer record and the database both exist, import the same installation
again on top of it:

```bash
scripts/tenancy/import-install.sh --from /path/to/old/lavoro \
    --name "Company BV" --slug company --refresh
```

That replaces the database with the source again and keeps the customer's id,
subscription and invoices. It is described in
[taking over an installation](../install/import-existing.md#4-importing-the-same-installation-again-later).

If you would rather start over, delete the half-made customer first:

```bash
php artisan tenant:delete <customer id>
```

If there is no customer record at all, there is nothing for that command to
delete. Remove the leftover database by hand, as root:

```bash
mysql -e "DROP DATABASE lavoro_tenant_company"
```

## Somebody cannot log in

All companies share one login screen, so Lavoro has to work out which company
somebody belongs to. It does that by email address: a table in the shared
database maps each address to exactly one customer. If an address is missing
from that table, that person cannot log in, whatever their password is.

```bash
php artisan tenancy:doctor      # names users that are missing from that table
php artisan tenant:overview     # lists the customers and how many users each has
```

This usually happens when a user was created directly in a customer database, or
moved from one customer to another.

The repair is to register that customer's addresses again:

```bash
php artisan tenant:setup-existing "Company BV" lavoro_tenant_company
```

Despite its name, this does not create a second customer. It reads the users
from that customer's database and writes every address into the table again,
which also removes addresses of users who no longer exist there. The
subscription, the package and the invoices are left alone. It does give the
customer a new MySQL password, so the database is briefly unreachable while it
runs; do it outside working hours if you can.

`php artisan tenancy:doctor` prints this same command, filled in with the right
name and database, next to the users it found.

Do not use `php artisan tenant:admin` for this. It only writes that table entry
when it creates a new user, and on an existing one it resets their password and
makes them an administrator.

An address can belong to only one customer in the whole installation. If
somebody needs access to two companies, they need two addresses.

## Background work is not happening

Emails, PDFs and new customer databases are all handled in the background. The
jobs wait in the shared database and are picked up by two processes, called
workers: a normal one, and one that only creates and deletes customers.

If nothing happens at all, a worker is usually not running, or it is still
running the code from before the last deploy.

```bash
php artisan tenancy:doctor            # says which worker is not running, or is running old code
php artisan tenancy:restart-workers   # restarts both and waits until they report back
php artisan queue:failed              # lists jobs that failed, with the reason and the time
php artisan queue:retry all           # tries the failed ones again
php artisan queue:flush               # throws the failed ones away
```

A worker loads the code once, when it starts, so after a `git pull` it keeps
running the old version without any sign that something is wrong.
`scripts/deploy.sh` restarts them for you.

Jobs belonging to a customer that has since been deleted are thrown away rather
than failed, so a deleted customer does not leave failed jobs behind.

## A new customer stays on "in de wacht"

Creating a customer in the admin panel writes a request that the second worker
picks up. "In de wacht" means the request is still waiting, so that worker is
not running or cannot do its work.

```bash
php artisan tenancy:doctor            # reports it after about fifteen minutes
sudo systemctl status lavoro-provisioning
php artisan tenancy:restart-workers
```

If the request failed, the panel shows the reason next to it. It stays there
until you remove it with **weghalen** (Dutch for "remove"), so that somebody
sees what went wrong. Anything half-created is cleaned up automatically.

## A customer's database is missing or broken

The rest of the installation keeps working. Before switching to a customer,
Lavoro checks that its database can be opened; if it cannot, that person is
logged out rather than shown an error page. Other companies are unaffected.

That customer's own page in the admin panel still opens, and that is where the
delete button sits, so you can remove a customer whose database is beyond
repair. To put the data back instead, see
[backups and restoring](backup-restore.md#restoring-one-customer).

## A customer sends no email

Each company sends mail with its own settings, filled in under **Technisch
beheer** (Dutch for "technical management") inside that company. Until somebody
fills those in, that company sends no email at all. That is deliberate: sending
from the wrong company's mail server would be worse than not sending.

The invoices you send to your own customers are separate. They go through the
mail server in `LANDLORD_MAIL_*` in `.env`, so they keep working even if a
customer breaks their own settings.

## The disk is full

The most common cause is dumps piling up in `storage/backups`. The deploy script
adds a set on every deploy and never deletes them.

```bash
du -sh storage/backups storage/tenant-* | sort -h | tail
find storage/backups -name '*.sql.gz' -mtime +14 -delete
```

Folders belonging to customers that no longer exist are reported by
`php artisan tenancy:doctor`, with their size. Look inside before removing one:

```bash
php artisan tenancy:prune-storage tenant-<customer id>
```

It shows what it is about to delete and asks first.

## If none of this helps

- [Backups and restoring](backup-restore.md) — putting data back
- [The runbook](runbook.md) — what is supposed to be running, and why
- [What can go wrong](../development/risks.md) — the longer list, written for
  developers, including problems that produce no error at all
