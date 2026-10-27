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

## What you need before you start

**The old installation has to be on this server**, both its folder and its
database. The import reads its `.env` from a path on this machine, and copies
its database from the MySQL server running here.

If it is still on another machine, bring it over first:

```bash
# On the new server, as root: copy the folder across
rsync -a old-server:/home/customer/lavorofsm/ /home/customer/lavorofsm/

# Copy its database dump across and load it into a database here
scp old-server:~/lavoro-before-move.sql .
mysql -e "CREATE DATABASE old_customer_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql old_customer_db < lavoro-before-move.sql
```

Then make sure the `DB_DATABASE` line in the copied `.env` names the database
you just loaded, because that is the line the import reads to find it.

**The import needs root**, because the old installation belongs to a different
Linux account and creating a database is not something the application account
may do. Run it with `sudo`, or as root. If it cannot become root by itself, it
stops and prints the exact command to run in a root shell.

**Pick a slug**: a short name for this customer, used for the new database name
`lavoro_tenant_<slug>`. Use lowercase letters, digits and underscores only, and
keep it recognisable: `spee`, `vandermeulen`.

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
addresses are registered in the shared database, which is how the login screen
knows which company somebody belongs to. You do not have to create any users,
and nobody has to choose a new password.

If one of those addresses is already in use by another customer of this
installation, the import stops and names it. An address can belong to only one
company, so that has to be sorted out first: change it in the old installation,
or in the other company.

Afterwards, run:

```bash
php artisan tenancy:doctor
```

It now checks this customer too: the database, the stored password, the MySQL
login, the required work order stages, whether every user is registered
centrally, and whether the file folders exist and can be written to.

## 3. Check the things a program cannot check

The doctor checks the technical side. Somebody has to look at the rest:

- log in as one of that company's own users, with the password they already
  had;
- open the customer list and check the number of customers is right;
- **open a photo on a work order.** The files moved to a different folder during
  the import. If that went wrong there is no error message, just a blank space;
- open the planner and check that appointments appear (they are loaded
  differently from the rest of the application);
- create a work order PDF;
- send a test email under **Technisch beheer** (Dutch for "technical
  management"), inside that company;
- ask the AI assistant a question, if this customer's package includes it;
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
Your own super admin accounts are written back into the replaced database as
well: they are kept in the shared database for exactly this reason. The mail,
Microsoft 365 and SnelStart settings are read from the old installation's `.env`
again, and settings this customer already has are left alone.
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

## The night of the move

The order that leaves the least to go wrong, with what to check after each step.

**1. Before you start, on the new server**

```bash
lavoro status
```

Everything green, or at least nothing you do not recognise. A problem that was
already there is harder to see once a customer has been moved on top of it.

**2. Take the old installation offline**

On the old server: `php artisan down`, and switch its web server off. From this
moment nobody can add anything there that the import would not carry across.

**3. Import, for the last time**

```bash
sudo scripts/tenancy/import-install.sh --from /path/to/old/lavoro \
    --name "Company BV" --slug company --refresh
```

Watch for the steps named **Our own accounts** and **Settings from the old
installation**: those write your super admins back and take the mail,
Microsoft 365 and SnelStart settings out of the old `.env`. If the settings step
says it found nothing, that installation kept them somewhere else and you fill
them in by hand under Technisch beheer.

**4. Check what a program can check**

```bash
php artisan tenancy:doctor
```

For this customer you want to see: the database opens with its own login, every
user is in the central list, `storage public` and `storage local` are writable,
`images: N of M checked, all on disk`, and `sends mail over graph` (or `smtp`).

If storage is not writable: `sudo lavoro setup permissions`. That happens because
the copy brings the old installation's modes along, and a mode sets the access
list's mask.

**5. Check what only a person can check**

Go through [step 3 above](#3-check-the-things-a-program-cannot-check), and add
two that matter most on the night:

- **upload a photo to a work order**, so you know writing works and not just
  reading;
- **send a test mail** under Technisch beheer, to yourself.

**6. Send everybody to the new address**

Point the old hostname at this installation, or redirect it. Then the phones:
each Android install stores its own server address and talks to `/api` directly,
where a redirect does not carry the session. Every technician sets the new
address in the launcher once.

**7. Leave the old installation alone for a week**

Switched off, not deleted. It is the fastest way back if something turns up that
none of the checks caught.

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

Continue with [going live](server.md#8-go-live), or go to the
[runbook](../operations/runbook.md) if this server was already running.
