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

**Where the old installation is decides the script.**

- **On this server**, folder and database both: `scripts/tenancy/import-install.sh
  --from /path/to/old/lavoro`. It reads the `.env` there and copies the database
  from the MySQL server running here.
- **Somewhere else**, and you have carried three files across: a dump of its
  database (`.sql` or `.sql.gz`, from MySQL or MariaDB), its `.env`, and an
  archive of its `storage` folder (`.zip`, `.tar`, `.tar.gz`, or an unpacked
  folder). Then `scripts/tenancy/import-archive.sh --dump … --env … --storage …`.
  It loads the dump into a staging database of its own, unpacks the archive next
  to the `.env`, and hands that to `import-install.sh`. Every other option below
  works the same for both. The staging copy is removed afterwards; the three
  files are not. Remove them yourself once the customer works here, because the
  `.env` holds their mail and SnelStart secrets.

```bash
# On the old server
mysqldump --single-transaction --routines old_database | gzip > old.sql.gz
cd /path/to/old/lavoro && zip -r ~/storage.zip storage/app
```

A dump made by MariaDB 10.11 or later starts with a line the MySQL client
refuses ("enable the sandbox mode"); `import-archive.sh` takes it out, along with
its `CREATE DATABASE`/`USE` lines, `DEFINER`s and MariaDB 11's collation. A JSON
column from MariaDB arrives in MySQL as text; the adaptation step below makes it
JSON again.

**Your own account in the old installation.** A dedicated installation usually
has an account of MajorLabel's own, as an ordinary administrator. Here that
account is a super admin, and an address belongs to one customer only, so
`info@majorlabel.nl` is almost certainly taken already. Pass
`--super-admin info@majorlabel.nl=info+<slug>@majorlabel.nl`: the account is
given that address, keeps its id and its password, and becomes this customer's
super admin. Everything it did in the old installation stays attributed to it.
Without the `=` part it keeps its address, and the script stops if that address
is in use elsewhere, naming the option to use.

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
3. gives your own account its new address (`--super-admin`);
4. registers the company as a customer of this installation;
5. writes down which tables, columns and rights the old installation had;
6. brings the database tables up to date with the current code;
7. takes the mail, Microsoft 365 and SnelStart settings from the old `.env`;
8. copies the uploaded files into the customer's own folder;
9. adapts what the migrations cannot (see below);
10. sets the subscription package.

**Adapting it to this installation** (`tenant:adapt-import`). The migrations
bring the tables up to date, but a new customer is given more than tables, and
an old installation lacks things the application now depends on. This step
says what it changed:

- the work order **stages** and **roles** a new customer is given, where the old
  installation has none for that purpose. An existing stage stays the one: the
  migrations make "Afgerond" for closed work orders, and no second closed stage is
  added next to it;
- open work orders from **before the stages** have none, so they never appear in
  the planner. They are put in *Gepland* when an appointment is linked, as linking
  one does, and in *Nieuw* otherwise;
- a **closing date** for closed work orders and tickets that have none, taken from
  their last change. The old installation never wrote it down;
- **who can be planned**: from before `plannable`, nobody is, and the planner is
  empty. Whoever carried out work in the old installation becomes plannable and
  takes a field seat;
- **rights that did not exist** in the old installation are granted to the role
  of the same name as a new customer has it. Rights that did exist stay as the
  customer set them. Location tracking (`location.track`) is never granted this
  way: following employees is the customer's own decision, and one they have to
  announce;
- your own account becomes a **super admin**.

It is safe to run again, and an import with `--refresh` runs it again on the
fresh copy.

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
`storage/backups/before-import/<slug>-<date>.sql.gz`. The newest seven per
customer are kept.

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

Or, when the old installation is on another server, with the three files
carried across:

```bash
sudo scripts/tenancy/import-archive.sh --dump old.sql.gz --env old.env \
    --storage storage.zip --name "Company BV" --slug company \
    --super-admin info@majorlabel.nl=info+company@majorlabel.nl --refresh
```

Watch for the steps named **Our own accounts** and **Settings from the old
installation**: those write your super admins back and take the mail,
Microsoft 365 and SnelStart settings out of the old `.env`. If the settings step
says it found nothing, that installation kept them somewhere else and you fill
them in by hand under Technisch beheer.

Then read **Adapting it to this installation**. It names every work order that
was given a stage, every user that became plannable and every right that was
granted, and ends with the number of field and office seats in use. Check that the
package covers those seats. If it says `not granted, the customer decides:
location.track`, ask the customer whether they want location tracking for their
technicians, and grant it under Rollen if they do.

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
| `--super-admin` | `old@address[=new@address]`: our own account in the old installation, which becomes this customer's super admin. Name a new address when the old one is in use at another customer |
| `--dry-run` | prints the whole plan and changes nothing |
| `--refresh` | imports again into a customer that is already here, as described in step 4 |

`import-archive.sh` takes all of these except `--from`, and these three instead:

| Option | What it does |
| --- | --- |
| `--dump` | the database dump, `.sql` or `.sql.gz`, from MySQL or MariaDB |
| `--env` | the old installation's `.env` |
| `--storage` | its `storage` folder as `.zip`, `.tar` or `.tar.gz`, or an unpacked folder. Only `storage/app` is read |

With `--dry-run` it still makes the staging copy, so that the plan can be read
off it, and removes it again. The customer is not touched.

## Next

Continue with [going live](server.md#8-go-live), or go to the
[runbook](../operations/runbook.md) if this server was already running.
