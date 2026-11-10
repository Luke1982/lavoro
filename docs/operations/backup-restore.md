# Backups and restoring

This page covers what to back up, how to do it, and how to put it back. It
assumes you have shell access to the server Lavoro runs on.

Where to run the commands: unless a command starts with `sudo`, run it from the
folder Lavoro is installed in (`/var/www/lavoro` in these examples), logged in
as the Linux account that owns those files. `php artisan …` run as any other
account will either fail or leave files that account cannot read.

## How Lavoro stores its data

One Lavoro installation serves several companies at once. Each company is called
a customer.

**Every customer has its own MySQL database**, named `lavoro_tenant_<name>`,
where `<name>` is a short name chosen when the customer was created. To see them
all, run `php artisan tenants:list`; it prints each customer's name, database and
id.

**One shared database, `lavoro_landlord`**, holds the list of customers, their
subscriptions and invoices, and which email address belongs to which customer.
That last table is what makes logging in work: it is how Lavoro knows which
company somebody belongs to.

**Uploaded photos and documents are not in any database.** They are files on
disk, one folder per customer: `storage/tenant-<customer id>/`. The customer id
is the long identifier in the third column of `php artisan tenants:list`.

**The passwords for the customer databases are stored encrypted in the shared
database.** The key that decrypts them is `APP_KEY`, a line in the `.env` file in
the installation folder. It was generated when the server was installed and
normally never changes. Without that exact key, Lavoro cannot open any customer
database, and there is no way to recover those passwords.

So a backup has four parts, and you need all four:

| Part | Where it is | What happens if you lose it |
| --- | --- | --- |
| The shared database | `lavoro_landlord` | you still have every company's data, but nothing records which company it belongs to, and nobody can log in |
| The customer databases | `lavoro_tenant_*` | that company's work is gone: customers, work orders, appointments, invoices |
| The uploaded files | `storage/tenant-*/` | photos and documents are gone. The app still lists them, so work orders show broken images |
| `APP_KEY` | `.env` | the customer database passwords cannot be decrypted, so a restored shared database is useless |

## Where the backups are

Unless you point them elsewhere, the dumps Lavoro makes go into
`storage/backups`, one folder per reason:

| Folder | What is in it | Kept |
| --- | --- | --- |
| `scheduled/` | the nightly backup | the newest 7 runs |
| `predeploy/` | taken by every deploy, before it changes anything | the newest 7 runs |
| `manual/` | `lavoro backup`, or `backup.sh` run by hand | the newest 7 runs |
| `before-import/` | a customer's database just before an import with `--refresh` replaced it | the newest 7 per customer |

In `scheduled/`, `predeploy/` and `manual/` every run is a folder of its own,
named after the moment it started, with one file per database in it:

```
storage/backups/scheduled/2026-11-05_03-30-00/lavoro_landlord.sql.gz
storage/backups/scheduled/2026-11-05_03-30-00/lavoro_tenant_acme.sql.gz
```

So the shared database and every customer database of one moment are always
together, which is what a restore needs.

A run is written into `<moment>.part` and only gets its real name once every
database is in it. A run that missed a database is named
`<moment>-incomplete`: the dumps that are in it are whole and usable, but it
does not count as a backup.

Old runs are removed after a complete run, never after a failed one. A backup
that fails night after night therefore never removes the last good ones.

`lavoro backup list` shows what every folder holds; `lavoro status` shows when
each last ran.

## Taking a backup

The databases are one command, and the nightly timer runs it for you. The
uploaded files and `APP_KEY` are two more, and nothing runs those by itself.

```bash
cd /var/www/lavoro

# 1. All databases, every night (once, as root)
sudo scripts/tenancy/setup-backups.sh

# 2. The uploaded files
mkdir -p /backups/lavoro/files
rsync -a storage/tenant-* /backups/lavoro/files/

# 3. APP_KEY, which is what makes the database dumps usable
cp .env /backups/lavoro/lavoro.env
```

That still leaves everything on this same server, which does not survive the
server failing. Copy it to another machine, for example:

```bash
rsync -a storage/backups/scheduled/ backup-host:/backups/lavoro/databases/
rsync -a /backups/lavoro/ backup-host:/backups/lavoro/
```

Without `--delete`, the other machine keeps every night, including the ones
removed here after a week; clear out old ones there as you see fit. With
`--delete` it keeps exactly what is here, which also means that whatever goes
missing here goes missing there the next night.

### 1. The databases, every night

```bash
sudo scripts/tenancy/setup-backups.sh --dry-run    # show what it would put in place
sudo scripts/tenancy/setup-backups.sh              # every night at 03:30, newest 7 kept
sudo scripts/tenancy/setup-backups.sh --at=02:15 --keep=14 --to=/backups/lavoro/databases
```

This installs a systemd timer, `lavoro-backup.timer`, which runs
`scripts/tenancy/backup.sh` every night into `storage/backups/scheduled`, and
then runs it once straight away so you know now whether it works. If the server
is off at that time, the backup runs as soon as it is back on.

It runs as the account the site runs as, not as `lavoro_provisioner`. A backup
only reads, and it reads each database with that database's own login, which
the application hands it. The provisioner may create and drop databases and
users, and a process that runs every night with nobody watching should not have
that. The script refuses to set it up as the provisioner or as root.

Run it again to change the time, the folder or how many are kept. To see when
it runs next and what the last run said:

```bash
systemctl list-timers lavoro-backup.timer
journalctl -u lavoro-backup -n 50
```

`php artisan tenancy:doctor` reports it when the timer is off, when the last run
is incomplete, or when the newest complete run is more than 36 hours old. A
backup that stopped looks exactly like one that runs, so that check is how you
find out.

### Dumping by hand

```bash
lavoro backup                                      # into storage/backups/manual
scripts/tenancy/backup.sh --to=/backups/lavoro     # into a folder of your own
scripts/tenancy/backup.sh --to=/backups/lavoro --keep=14
```

It creates the folder you give it with `--to` if it does not exist, and puts a
run folder in it as described above. The runs get the rights of the folder they
are in, ACL included, so whatever copies that folder off the machine can read
them. They contain all data of every company: keep that folder itself closed to
anyone who should not have it.

`--keep=7` is the default: the newest seven complete runs in that folder stay,
older ones go. `--keep=0` removes nothing. It only ever removes run folders it
recognises by name, so pointing it at a folder that holds other things as well
is safe.

If one customer's database cannot be opened, the script prints that customer's
name, dumps all the other databases anyway, names the run `-incomplete` and
finishes with exit code 1.

It does not touch the uploaded files or `APP_KEY`; steps 2 and 3 do that. It
does not copy anything to another machine either.

To check afterwards that a dump is readable and not empty:

```bash
ls -lh storage/backups/scheduled/<moment>/                             # none of them should be tiny
gunzip -t storage/backups/scheduled/<moment>/lavoro_tenant_acme.sql.gz   # silence means it is intact
```

### 2. The uploaded files

```bash
rsync -a storage/tenant-* /backups/lavoro/files/
```

This copies the photos, documents and logos that users uploaded. They are
ordinary files on disk and are in no database, so no database dump contains
them.

`rsync -a` copies only what changed since last time, so running it again is
quick.

### 3. APP_KEY

```bash
cp .env /backups/lavoro/lavoro.env
```

This saves the configuration file, and with it `APP_KEY`.

That key decrypts the customer database passwords held in the shared database.
If you restore the shared database on a machine with a different key, Lavoro
cannot open a single customer database and those passwords cannot be recovered.

`APP_KEY` only changes if somebody changes it, so this only needs redoing after
such a change. The file also contains your mail and database passwords, so keep
it somewhere only you can read.

## `tenancy:backup-targets` prints a list and makes no backup

```bash
php artisan tenancy:backup-targets
```

This command prints a list on screen and writes no files. Running it does not
give you a backup. It is used by the backup script above.

It exists because each customer database has its own MySQL user, so there is no
single account that can read all of them. Something has to look up which
databases exist and which username and password opens each one. That is what
this prints, one line per database, with the values separated by tab characters:

```
DUMP	lavoro_tenant_acme	Uu7zDAVWguJ3ZdQE	<password>	127.0.0.1	3306
SKIP	Van der Meulen Installatie
```

A `DUMP` line is a database that can be backed up. A `SKIP` line is a customer
whose database could not be opened at all, which is a problem in itself: see
[troubleshooting](troubleshooting.md).

The lines contain passwords, so do not paste them into a chat, a ticket or a log
file.

You only need this command directly if you are writing your own backup script.
If you do, pass each password to `mysqldump` through a temporary
`--defaults-extra-file` rather than on the command line, where any user on the
server can read it with `ps`, and give `mysqldump` the options
`--single-transaction --no-tablespaces`.

## The dumps the deploy leaves behind are not a backup

`scripts/deploy.sh` dumps every database into `storage/backups/predeploy/`
before it changes anything, and refuses to deploy if a dump fails. A customer
whose database cannot be opened at all is named and skipped; that does not stop
the deploy. The newest seven deploys are kept.

Those runs exist so you can undo that one deploy. They are not a backup of the
installation: they are on the same disk as the databases they came from, they
do not include the uploaded files or `APP_KEY`, and a week without a deploy
means a week without one.

`storage/backups/before-import/<name>-<moment>.sql.gz` is a customer's database
as it was just before an import replaced it; see
[taking over an installation](../install/import-existing.md). The newest seven
per customer are kept.

## Before restoring: the MySQL login you need

The restore commands below write directly into MySQL with an account that is
allowed to write to that database. The `lavoro_app` account is not: it may only
reach the shared database, and no customer database.

Use the server's administrative MySQL account, and put its details in a file so
the password does not appear on the command line, where any user on the server
can read it with `ps`. As root:

```bash
cat > /root/backup.cnf <<'EOF'
[client]
user=root
password=<the MySQL root password>
EOF
chmod 600 /root/backup.cnf
```

Every `mysql --defaults-extra-file=/root/backup.cnf` below uses that file.

## Restoring one customer

Use this when one company's data is damaged or lost. It replaces the contents of
that customer's database. The customer itself stays as it is: same database
name, same MySQL user, same id, same subscription.

**Maintenance mode applies to the whole installation.** The first command below
takes Lavoro offline for every company, not just this one, so do this outside
working hours and keep it short.

```bash
cd /var/www/lavoro

# Take Lavoro offline and show a message to anyone who visits
php artisan app:maintenance --message="Bezig met herstel." --until="16:00"

# Find out which database belongs to this customer
php artisan tenants:list

# Load the dump back into that database
gunzip -c storage/backups/scheduled/2026-09-20_03-30-00/lavoro_tenant_acme.sql.gz \
    | mysql --defaults-extra-file=/root/backup.cnf lavoro_tenant_acme

# The dump may be older than the current code, so bring the tables up to date
php artisan tenants:migrate --tenants=<customer id>

# Check that the customer works again
php artisan tenancy:doctor

# Put Lavoro back online
php artisan app:maintenance
```

`php artisan app:maintenance` is a switch. With `--message` or `--until` it takes
the installation offline, or updates the text shown. Run it with no options and
it puts the installation back online. `--until` only changes what visitors are
told; nothing comes back automatically.

That restores the database. Restore that customer's files from the same moment
as well, or the application will show work orders whose photos are no longer on
disk:

```bash
rsync -a /backups/lavoro/files/tenant-<customer id>/ storage/tenant-<customer id>/
```

If your copy is on another machine, restore it from there instead:

```bash
rsync -a backup-host:/backups/lavoro/files/tenant-<customer id>/ storage/tenant-<customer id>/
```

## Restoring the shared database

The shared database records which user may log in to which customer, and which
customers exist at all. Restore it from the same moment as the customer
databases.

If you restore an older shared database next to newer customer databases, a
customer created in between disappears from the admin panel while its data is
still on the server. Its database and files are still there, so you can put it
back with [taking over an installation](../install/import-existing.md).

```bash
cd /var/www/lavoro

php artisan app:maintenance --message="Bezig met herstel."

gunzip -c storage/backups/scheduled/2026-09-20_03-30-00/lavoro_landlord.sql.gz \
    | mysql --defaults-extra-file=/root/backup.cnf lavoro_landlord

php artisan migrate --force
php artisan tenancy:doctor
php artisan app:maintenance
```

The `.env` file on this machine must contain the same `APP_KEY` as when the dump
was taken. With a different key, the customer database passwords in this dump
cannot be decrypted. `php artisan tenancy:doctor` reports that on the first
customer it checks.

## Rebuilding on a new server

This is the full recovery: a new machine, from backups only.

1. [Install the server](../install/server.md) up to and including step 7, so the
   web server and both background processes are running. When step 4 asks for
   `APP_KEY`, paste the **old** one from your backed-up `.env`. A new key makes
   every customer database password in the backup unreadable.
2. Restore the shared database, as described above.
3. For each customer, create its database and load its dump:

   ```bash
   mysql --defaults-extra-file=/root/backup.cnf \
       -e "CREATE DATABASE lavoro_tenant_acme CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

   gunzip -c /backups/lavoro/databases/<moment>/lavoro_tenant_acme.sql.gz \
       | mysql --defaults-extra-file=/root/backup.cnf lavoro_tenant_acme
   ```

4. For each customer, recreate its MySQL login:

   ```bash
   php artisan tenant:setup-existing "Acme BV" lavoro_tenant_acme
   ```

   The customer is already in the restored shared database, so this does not
   create a second one. It gives the customer a new MySQL user and password,
   stores those in the shared database, and registers its users' email addresses
   again so they can log in. The subscription, the package and the invoices are
   left as they are.

   It also writes your own super admin accounts back into that customer's
   database. If the dump you restored predates them, run
   `php artisan tenant:superadmins "Acme BV" --sync` afterwards to be sure.

5. Copy the uploaded files back, and make sure they end up owned by the account
   that runs Lavoro. Restored as root they are unreadable to the web server:

   ```bash
   rsync -a /backups/lavoro/files/ /var/www/lavoro/storage/
   chown -R lavoro:lavoro /var/www/lavoro/storage/tenant-*
   ```

6. Run `php artisan tenancy:doctor`. It goes through every customer and reports
   which databases cannot be opened, which are missing tables and which folders
   are missing. Fix what it names before letting anyone in.

## Things worth doing before you need them

- **Try a restore.** Restore one customer onto a test machine and run
  `php artisan tenancy:doctor` afterwards. That is the only way to find out
  whether your backups can actually be read back, and the only bad moment to
  find out is during a real failure.
- **Keep a copy on another machine**, and keep backups far enough back to
  recover from a mistake nobody noticed for a week. The nightly timer keeps
  seven nights; `setup-backups.sh --keep=14` gives you two weeks.
- **Remember that deleting a customer in the admin panel is immediate.** It
  deletes that customer's database, its MySQL user and its files. These backups
  are the only way back.

## Next

- [Troubleshooting](troubleshooting.md) — if a customer's database cannot be
  opened at all
- [The runbook](runbook.md) — the rest of running this server
- [Taking over an installation](../install/import-existing.md) — putting a
  customer back that is missing from the admin panel
