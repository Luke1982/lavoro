# The `lavoro` command

One command for the things you do to a running installation: check whether it is
healthy, see whether there is a newer version, install that version, list
customers, take a backup, read the logs, restart the background processes.

It does not replace the scripts in `scripts/`. It calls them, so anything here
can still be done the long way, and the scripts keep working on their own.

## Installing it

```bash
sudo /var/www/lavoro/bin/lavoro setup cli
```

That makes a symlink in `/usr/local/bin`, so `lavoro` works from any folder and
finds the installation by itself. Without it, run `bin/lavoro` from the
installation folder.

## The one to remember

```bash
lavoro status
```

It answers, in one screen: which version is installed, whether origin has a
newer one, whether both background processes and the cron job are running, how
much disk is left, when the last backup was taken, whether fail2ban is actually
watching refused logins, how many customers there are, and what
`php artisan tenancy:doctor` says — with the failing lines quoted, not just a
count.

It ends with **What to do**: one numbered command per thing it found, with the
reason underneath, in the order they came up. Nothing found means it says so.
Either way the last line points at `lavoro help` for everything else.

Run it when something is reported, and once in a while when nothing is.

## Updating

```bash
lavoro update --check     # what is waiting, and whether it changes the database
lavoro update             # fetch it and deploy it
```

`--check` fetches from origin and lists the commits you do not have yet. It says
separately whether any of them change the database structure, because those are
the ones that need the maintenance window.

`lavoro update` refuses if files on the server were changed by hand — the deploy
pulls with `--ff-only` and would stop halfway. It warns that the installation
goes offline, asks once, and then runs `scripts/deploy.sh`, which takes the
backup, pulls, migrates, rebuilds caches, restarts the workers and runs the
checks.

## Customers

```bash
lavoro tenants                        # name, database, users, package, id
lavoro tenants overview               # seats, storage, monthly price, what is due
lavoro tenants create "Name BV" admin@example.nl
lavoro tenants delete <customer id>
lavoro tenants admin <customer id> them@example.nl

sudo lavoro tenants import --from /path/to/old/lavoro --name "Name BV" --slug name
sudo lavoro tenants import --from /path/to/old/lavoro --name "Name BV" --slug name --refresh
```

The import is [taking over an installation](../install/import-existing.md);
`--refresh` fetches the same source again into a customer that is already here.

## Backups

```bash
lavoro backup                     # dump every database
lavoro backup --keep-days=14      # and delete dumps older than two weeks
lavoro backup list                # what is in the backup folder, newest first
```

By default it writes into `storage/backups`, which is the same disk as the
databases. Set `LAVORO_BACKUP_DIR=/backups/lavoro` in the environment to put
them somewhere else; `lavoro status` warns as long as they are inside the
installation.

This covers databases only. The uploaded files and `APP_KEY` are two more
commands, described in [backups and restoring](backup-restore.md).

Putting one customer's database back:

```bash
sudo lavoro restore lavoro_tenant_acme /backups/lavoro/lavoro_tenant_acme-2026-09-20_04-00-01.sql.gz
```

It tells you what it is about to replace and when that dump was made, asks once,
then takes the installation offline, loads the dump, brings the tables up to
date, runs the doctor and puts the installation back online. If the load fails
it stays offline on purpose and says so.

It needs a MySQL account that may write to that database, in
`/root/backup.cnf` — see
[backups and restoring](backup-restore.md#before-restoring-the-mysql-login-you-need).
Point `LAVORO_MYSQL_DEFAULTS` elsewhere if you keep it somewhere else.

## Logs and background processes

```bash
lavoro logs app -f             # storage/logs/laravel.log
lavoro logs auth               # refused logins
lavoro logs worker -f          # the ordinary background process
lavoro logs provisioning       # the one that creates and deletes customers

lavoro workers                 # are they running, and what failed
lavoro workers restart         # restart both and wait for them
lavoro workers failed          # failed jobs, with the reason
lavoro workers retry           # try them again
lavoro workers flush           # throw them away
```

## Maintenance mode

```bash
lavoro maintenance on          # with the default message
lavoro maintenance on "Bezig met herstel."
lavoro maintenance off
```

This takes the whole installation offline, every company at once, not one
customer.

## Setting a server up

These wrap the scripts the installation page uses, so you do not have to
remember where they live:

```bash
sudo lavoro setup mysql        # the database accounts and the grant procedure
sudo lavoro setup workers      # both background processes and the cron line
sudo lavoro setup sudoers      # let commands switch to the provisioner account
sudo lavoro setup fail2ban     # block repeated failed logins
sudo lavoro setup permissions  # rights on storage/ for the web server and the provisioner
sudo lavoro setup verify       # try to cross the account boundaries; expects refusal
lavoro setup env               # write .env (asks three questions)
sudo lavoro setup cli          # put lavoro on PATH
```

The order and the reasoning are in [installing a server](../install/server.md).

`setup permissions` is the one you reach for again later. It gives both accounts
their access lists on `storage/` **and** makes the files group-writable, which is
the half people miss: the mode sets the access list's mask, so a folder that
arrives as `0755` reduces "may write here" to read-only while still listing the
entry. Importing a customer's installation brings that installation's modes
along, which is how a working setup quietly becomes one where uploads fail.

## Anything else

```bash
lavoro doctor                  # the full check, every customer
lavoro artisan <command>       # any artisan command, as the right account
lavoro help
```

`lavoro artisan` exists because every other command runs artisan as the account
that owns the files. Running `php artisan` as root leaves root-owned caches and
log files that the web server cannot write to afterwards.

## Next

- [The runbook](runbook.md) — what these commands are doing underneath
- [Backups and restoring](backup-restore.md) — the parts a database dump does
  not cover
- [Troubleshooting](troubleshooting.md) — when `lavoro status` reports something
