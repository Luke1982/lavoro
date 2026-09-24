# Taking over an existing installation

Older Lavoro installations serve one company each, on their own server or in
their own folder. This page is about moving such an installation into this one,
where it becomes one customer among several.

One command does the whole move: the database, the uploaded files, the users and
their passwords, and the subscription.

You normally do this between [installing the server](server.md) and going live,
but it works just as well on a server that is already serving other customers.

**Take the old installation offline first.** Everything below assumes nobody is
still working in it.

## 1. Take it offline and back it up

Do this outside working hours. Make a fresh database dump once nobody is using
it any more:

```bash
cd /path/to/old/lavoro
php artisan down

mysqldump --single-transaction --routines <old_database> > ~/lavoro-before-move.sql
```

Keep that dump for at least a week, in case you need to go back.

## 2. Run the import

First with `--dry-run`, which changes nothing and only prints what it would do:

```bash
cd /var/www/lavoro

scripts/tenancy/import-install.sh \
    --from /path/to/old/lavoro \
    --name "Customer Name BV" \
    --slug customername \
    --package business \
    --dry-run
```

Read that plan. If it is right, run the same command again without `--dry-run`.

What it does:

1. copies the old database into a new one called `lavoro_tenant_<slug>`;
2. removes the tables that are now shared between all customers (sessions,
   cache and queued jobs);
3. registers the company as a customer of this installation;
4. brings the database tables up to date with the current code;
5. copies the uploaded files into the customer's own folder;
6. sets the subscription package.

The existing users come across with the passwords they already had. Their email
addresses are registered centrally, which is how the login screen knows which
company somebody belongs to. You do not have to create any users.

The command needs root, because the old installation belongs to a different
Linux account and creating a database is not something the application's account
may do. If it cannot become root by itself, it prints the exact command to run
in a root shell.

Afterwards, run:

```bash
php artisan tenancy:doctor
```

It now checks this customer too: the database, the stored password, the MySQL
login, the required work order stages, whether every user is registered
centrally, and whether the file folders exist and can be written to.

## 3. Check the things a program cannot check

The doctor checks the technical side. Somebody has to look at the rest:

- log in with an existing account and its old password;
- open the customer list and check the number of customers is right;
- **open a photo on a work order.** The files moved to a different folder during
  the import. If that went wrong there is no error message, just a blank space;
- open the planner and check that appointments appear (they are loaded
  differently from the rest of the application);
- create a work order PDF;
- send a test email under **Technisch beheer**;
- ask the AI assistant a question, if this customer has it;
- in `/beheer`, check that the customer shows the right package, number of seats
  and storage limit.

## 4. Importing the same installation again later

A takeover often happens in steps. The company keeps working in the old
installation for a while, or something needs correcting. You can then import it
again, on top of the customer that is already here, by adding `--refresh`:

```bash
scripts/tenancy/import-install.sh \
    --from /path/to/old/lavoro \
    --name "Customer Name BV" \
    --slug customername \
    --package business \
    --refresh
```

**What it does:** fetches the old database again, brings its tables up to date,
copies the files across again and re-reads which users may log in.

**What it keeps:** the customer keeps its id, its package, its number of seats,
its modules, the date its subscription started and all invoices already issued.
If somebody changed the package here since the first import, that change is kept
as well, even if the command line still says the old package. The script says so
and prints the command to change it anyway.

**What it throws away:** anything that was changed on *this* side in that
customer's database since the last import. The old installation's data replaces
it. Before replacing anything, the script saves what is there to
`storage/backups/before-refresh-<slug>-<date>.sql.gz`.

**Files:** anything new at the old installation is copied here, and changed
files overwrite the ones here. Files deleted at the old installation stay here.
The import adds and overwrites; it never deletes.

Do the last import after the old installation has been taken offline for good,
then go through step 3 again.

## All options

```bash
bash scripts/tenancy/import-install.sh --from /home/klant/lavorofsm \
     --name "Bedrijf BV" --slug bedrijf --package business --dry-run
```

| Option | What it does |
| --- | --- |
| `--from` | the folder of the old installation; its `.env` says which database to copy |
| `--name` | the company name as it will appear in the admin panel |
| `--slug` | short name used for the new database, `lavoro_tenant_<slug>` |
| `--package` | the subscription package (`starter`, `team`, `business`, `enterprise`) |
| `--billing-from` | the date the subscription starts. A date, or `none` to leave it open. Without it, today. On a takeover this is usually something you agreed, not today |
| `--dry-run` | prints the whole plan and changes nothing |
| `--refresh` | imports again into a customer that is already here, as described in step 4 |

## Next

Continue with [going live](server.md#7-go-live), or go to the
[runbook](../operations/runbook.md) if this server was already running.
