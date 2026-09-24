# Backups and restoring

## How Lavoro stores its data

One Lavoro installation serves several companies. Each company is called a
customer, and every customer has its own MySQL database, named
`lavoro_tenant_<name>`. Next to those there is one shared database,
`lavoro_landlord`. It holds the list of customers, their subscriptions and
invoices, and which email address belongs to which customer.

Uploaded photos and documents are not in the databases. They are files on disk,
one folder per customer: `storage/tenant-<customer id>/`.

The passwords Lavoro uses to open the customer databases are stored in the
shared database, encrypted. The key that decrypts them is `APP_KEY` in the
`.env` file. Without that key those passwords cannot be read, and Lavoro cannot
open any customer database.

So a backup has four parts:

| Part | Where it is | If you lose it |
| --- | --- | --- |
| The shared database | `lavoro_landlord` | you still have all the customer data, but nothing says which company it belongs to |
| The customer databases | `lavoro_tenant_*` | that company's work is gone |
| The uploaded files | `storage/tenant-*/` | photos and documents are gone; the app still shows they should be there |
| `APP_KEY` | `.env` | the customer database passwords cannot be decrypted, so the restored databases cannot be opened |

## Taking a backup

A backup is three commands. Each one covers a different part of the list above,
and none of them covers the others:

```bash
# 1. All databases: the shared one and every customer's
scripts/tenancy/backup.sh --to=/backups/lavoro --keep-days=14

# 2. The uploaded files
rsync -a storage/tenant-* /backups/lavoro/files/

# 3. APP_KEY, which makes the database dumps usable
cp .env /backups/lavoro/lavoro.env
```

Then copy `/backups/lavoro` to another machine. A copy on the same server is
gone when that server is gone.

None of these run by themselves. Put them in a cron job, or run them by hand
before you change something.

### 1. The databases

```bash
scripts/tenancy/backup.sh                          # writes into storage/backups
scripts/tenancy/backup.sh --to=/backups/lavoro     # writes into /backups/lavoro
scripts/tenancy/backup.sh --to=/backups/lavoro --keep-days=14
```

You get one file per database in the folder you name, compressed:
`lavoro_landlord-2026-09-24_04-00-01.sql.gz`, then one per customer. With three
customers that is four files. Only the account that ran the script can read
them.

If one customer's database cannot be opened, the script names that customer,
dumps all the others, and ends with an error code. Check that code when you run
it from cron, because otherwise a backup that is missing one company looks
exactly like a complete one.

`--keep-days=14` deletes dumps older than fourteen days from the folder it
writes into. That is the only thing it ever deletes.

It does not touch the uploaded files or `APP_KEY`; commands 2 and 3 do that.
Nor does it copy anything to another machine, which stays a separate step.

### 2. The uploaded files

`rsync -a storage/tenant-* /backups/lavoro/files/` copies the photos, documents
and logos that users uploaded. These are ordinary files on disk and are in no
database, so nothing else in this list copies them.

### 3. APP_KEY

`cp .env /backups/lavoro/lavoro.env` saves the configuration file, and with it
`APP_KEY`.

That key decrypts the customer database passwords stored in the shared database.
Restore the shared database with a different key and Lavoro cannot open a single
customer database, and there is no way to recover those passwords. The key only
changes if you change it, so this only needs redoing after such a change.

## The command that prints a list

```bash
php artisan tenancy:backup-targets
```

This prints a list on screen and writes no files. Running it does not give you a
backup.

Each customer database has its own MySQL user, so there is no single account
that can read all of them. This command looks up which databases exist and which
username and password opens each one, and prints one line per database:

```
DUMP<tab>database<tab>user<tab>password<tab>host<tab>port
SKIP<tab>company name            # this customer's database could not be opened
```

`scripts/tenancy/backup.sh` runs this command and does the dumping. You only
need it directly if you are writing your own backup script.

Those lines contain passwords, so do not paste them into a chat, a ticket or a
log file. If you do write your own script around it, pass each password through
a temporary `--defaults-extra-file` rather than on the command line, where
anyone on the server can read it with `ps`, and give `mysqldump` the options
`--single-transaction --no-tablespaces`.

## The dumps the deploy leaves behind

`scripts/deploy.sh` dumps every database into `storage/backups/` before it
changes anything, and stops the deploy if a dump fails. Those files are there to
undo that one deploy.

They are not a backup of the system. They sit on the same disk as the databases
they came from, they do not include the uploaded files or `APP_KEY`, and nothing
deletes them, so they fill the disk over time. Delete old ones:

```bash
find storage/backups -name '*.sql.gz' -mtime +14 -delete
```

Importing an existing installation a second time also leaves a file there,
called `before-refresh-<name>-<date>.sql.gz`. That is the customer's database as
it was just before the import replaced it.

## Restoring one customer

This replaces the contents of one customer's database. The customer keeps its
database, its MySQL user and its id.

Put the application in maintenance mode first, so nobody writes to it while you
work.

```bash
# Show a maintenance page to everyone
php artisan app:maintenance --message="Bezig met herstel."

# Find out which database belongs to this customer
php artisan tenants:list

# Load the dump back into that database
gunzip -c storage/backups/lavoro_tenant_acme-2026-09-20_04-00-01.sql.gz \
    | mysql --defaults-extra-file=/root/backup.cnf lavoro_tenant_acme

# The dump can be older than the current code, so bring the tables up to date
php artisan tenants:migrate --tenants=<customer id>

# Check that the customer works again
php artisan tenancy:doctor

# Put the application back online
php artisan app:maintenance
```

This restores the database only. Restore that customer's files from the same
moment as well:

```bash
rsync -a backup-host:/backups/lavoro/files/tenant-<customer id>/ storage/tenant-<customer id>/
```

If you skip that, the application shows work orders that refer to photos that
are no longer on disk.

## Restoring the shared database

The shared database decides which user may log in to which customer. Restore it
from the same moment as the customer databases.

If you restore an older shared database next to newer customer databases, a
customer can disappear from the admin panel while its data is still on the
server.

```bash
php artisan app:maintenance --message="Bezig met herstel."

gunzip -c storage/backups/lavoro_landlord-<date>.sql.gz \
    | mysql --defaults-extra-file=/root/backup.cnf lavoro_landlord

php artisan migrate --force
php artisan tenancy:doctor
php artisan app:maintenance
```

Use the same `APP_KEY` as when the dump was taken. With a different key the
stored customer database passwords cannot be decrypted. `php artisan
tenancy:doctor` reports this on the first customer it checks.

## Moving everything to a new server

1. [Install the server](../install/server.md) up to and including step 6. Put
   the **old** `APP_KEY` in `.env`.
2. Restore `lavoro_landlord`.
3. Create each customer database and its MySQL user the way the installer does.
   `scripts/tenancy/setup-mysql.sh` creates the shared accounts; the customer
   usernames and passwords come back with the shared database.
4. Restore each customer dump into its database.
5. Copy the `storage/tenant-*` folders across.
6. Run `php artisan tenancy:doctor`. It checks every customer and reports which
   databases cannot be opened, which are missing tables, and which folders are
   missing.

## Practical advice

- Restore a customer onto a test machine now and then and run
  `php artisan tenancy:doctor` afterwards. That is the only way to know your
  backups can actually be read back.
- Keep at least one copy on another machine, and keep backups far enough back to
  recover from a mistake that nobody noticed for a week.
- Deleting a customer in the admin panel deletes its database, its MySQL user
  and its files immediately. These backups are the only way back.
