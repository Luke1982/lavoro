# Backups and restoring

Every customer has a database of its own, so a backup is never one file. What
has to survive a server dying:

| | Where | Without it |
| --- | --- | --- |
| The central database | `lavoro_landlord` | you have the customers' data and no idea whose it is |
| Every customer database | `lavoro_tenant_*` | that customer's work is gone |
| `APP_KEY` | `.env` | every customer database password and every stored Google connection is unreadable |
| Uploaded files | `storage/tenant-*/` | photos, documents and logos are gone; the rows pointing at them remain |

A dump of the central database alone is a list of names. It is worth saying out
loud because it is the mistake that looks like a backup.

## What the deploy already does

`scripts/deploy.sh` dumps **every** database before it touches anything, into
`storage/backups/<database>-<date>_<time>.sql.gz`. It refuses to deploy when a
dump fails. A customer whose database will not open is skipped and named — that
is a warning, not a backup.

Those dumps are a safety net for that one deploy. They are **not** your backup:
they only exist on the same disk as the thing they protect, and nothing removes
them, so they grow until the disk does not. Clear old ones:

```bash
find storage/backups -name '*.sql.gz' -mtime +14 -delete
```

## Taking a backup yourself

```bash
php artisan tenancy:backup-targets
```

Every line is `DUMP<tab>database<tab>user<tab>password<tab>host<tab>port`, or
`SKIP<tab>name` for a customer whose database will not open. **The lines carry
passwords**: do not paste them into a chat, a ticket or a log.

That is what a backup script reads. The deploy writes each password into a
temporary `--defaults-extra-file` rather than the command line, where anyone
running `ps` would see it, and passes `--single-transaction --no-tablespaces`.
Copy that if you write your own; `scripts/deploy.sh` is the worked example.

Off the machine as well, and files too:

```bash
rsync -a storage/tenant-* backup-host:/backups/lavoro/files/
```

## Restoring one customer

The customer keeps its database, its login and its id; only the contents go
back. Do it with the application down, so nothing writes while you work.

```bash
php artisan app:maintenance --message="Bezig met herstel."

# Which database belongs to this customer
php artisan tenants:list

gunzip -c storage/backups/lavoro_tenant_acme-2026-09-20_04-00-01.sql.gz \
    | mysql --defaults-extra-file=/root/backup.cnf lavoro_tenant_acme

php artisan tenants:migrate --tenants=<tenant id>   # the dump may be older than the code
php artisan tenancy:doctor
php artisan app:maintenance
```

Restore the files of the same moment alongside it, or the work orders point at
photos that are not there:

```bash
rsync -a backup-host:/backups/lavoro/files/tenant-<id>/ storage/tenant-<id>/
```

## Restoring the central database

This is the one that decides who may log in where, so it belongs to the same
moment as the customer databases. Restoring it alone next to newer customer
databases is how a customer disappears from the panel while its data is still
there.

```bash
php artisan app:maintenance --message="Bezig met herstel."
gunzip -c storage/backups/lavoro_landlord-<stamp>.sql.gz \
    | mysql --defaults-extra-file=/root/backup.cnf lavoro_landlord
php artisan migrate --force
php artisan tenancy:doctor
php artisan app:maintenance
```

`APP_KEY` must be the one that was in use when the dump was taken. With a
different key the stored customer passwords decrypt to nothing, and the doctor
says so on the first customer it checks.

## Onto a new server

1. [Install the server](../install/server.md) up to and including step 6, with
   the **old** `APP_KEY` in `.env`.
2. Restore `lavoro_landlord`.
3. For every customer: create the database and its login the way the installer
   does (`scripts/tenancy/setup-mysql.sh` made the accounts; the customer logins
   come back with the central database), then restore each dump.
4. Copy `storage/tenant-*` across.
5. `php artisan tenancy:doctor` — it walks every customer and says which ones
   cannot be opened, are missing tables or have no folders.

## Worth knowing

- **A restore you have not done is not a backup.** Restore one customer onto a
  test machine now and then; the doctor tells you whether it worked.
- Keep at least one copy off this server, and one far enough back to survive a
  mistake nobody noticed for a week.
- Deleting a customer in the panel drops its database, its login and its files.
  There is no undo other than these backups.
