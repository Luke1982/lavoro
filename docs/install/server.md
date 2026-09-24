# Installing Lavoro on a new server

Follow these steps in order. Allow about an hour, plus the time a database dump
takes if you are moving an existing installation in.

If you are moving an existing installation in, it keeps running until step 7, so
everything before that is safe.

These instructions are written for MariaDB 10.11 and PHP 8.3. Where MySQL works
differently, it says so.

## Check after every step

```bash
php artisan tenancy:doctor
```

This is the check you use throughout. It looks at the database accounts and
their permissions, the PHP extensions, the `.env` file, both background
processes, the cron job, your invoicing details and every customer database. It
prints what is wrong and what to do about it, and exits with an error code so a
script can stop on it.

While you are working through this page it will complain about things that are
not set up yet. That is expected; the complaints disappear step by step.

## Three accounts, and which one does what

| Account | Runs |
| --- | --- |
| **root** | everything with `sudo` in front of it here: creating accounts, `setfacl`, `systemctl`, `crontab` |
| **the application account** (the Linux user that owns the files; `lavoro` in these examples) | `git`, `composer`, `npm` and every `php artisan` command. Never with `sudo`: this account deliberately has no sudo rights |
| **lavoro_provisioner** | creating and deleting customer databases, and nothing else. You never log in as this account yourself. After step 6 the relevant commands switch to it automatically |

If a command asks you for a password, you are running it as the wrong account.
Logged in as the application account, `php artisan …` needs no `sudo`.

Why these are separate, and what each may reach in the database, is explained in
[multi-tenancy](../development/multi-tenancy.md#three-kinds-of-mysql-account).

## Before you start

Have ready:

- root or sudo access on the new server;
- a database account that can create users and grant permissions;
- the company name as it should appear on invoices.

**If you are moving an existing Lavoro installation in** (one that currently
serves a single company, and will become the first customer here), also have:

- the path to that installation, and a database backup of it that you have
  restored somewhere and seen working;
- **its `APP_KEY`, from its `.env` file.** You paste this in step 4 instead of
  generating a new one. That key decrypts its stored Google connections and all
  its other encrypted fields. Without it, that data cannot be read.

If you are starting empty, none of that applies. Step 4 generates a key for you,
and you create your first customer in step 8.

## 1. Get the code

```bash
sudo mkdir -p /var/www/lavoro
sudo chown "$USER" /var/www/lavoro
git clone <repository-url> /var/www/lavoro
cd /var/www/lavoro

composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

## 2. Check that socket login is available

Lavoro uses a database account that logs in without a password, identified by
the Linux user it belongs to. This is called socket authentication. Check that
the database server supports it:

```sql
SELECT plugin_name, plugin_status, plugin_library
  FROM information_schema.plugins
 WHERE plugin_name IN ('unix_socket', 'auth_socket');
```

**On MariaDB this is almost always `ACTIVE` already.** Since version 10.4 the
plugin is built into the server, so `plugin_library` is empty and there is
nothing to install. Running `INSTALL SONAME 'auth_socket'` then fails with
"cannot open shared object file", which does not mean anything is missing: there
is simply no separate file to load. If the query says `ACTIVE`, this step is
done.

**On MySQL** it usually has to be switched on once:

```sql
INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';
```

That setting survives a restart.

If neither server reports the plugin at all, install the matching server
package. `SELECT @@plugin_dir` shows where the plugin files would go.

The next step picks the right syntax for your server automatically: MariaDB
needs `IDENTIFIED VIA unix_socket`, MySQL needs `IDENTIFIED WITH auth_socket`.

## 3. Create the database accounts

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run    # prints the SQL, changes nothing
sudo scripts/tenancy/setup-mysql.sh --write-env  # creates everything
```

This creates:

- **the shared database**, `lavoro_landlord`;
- **`lavoro_app`**, with a password. The website runs as this account, and it
  can reach only that one database;
- **the Linux user `lavoro_provisioner`** and a matching database account with
  no password, tied to that Linux user. This is the only account that can create
  and delete customer databases.

`--write-env` writes the results into `.env`, making a backup of the old file
first. It also writes the provisioner's account name and socket, and removes
`DB_PROVISIONER_PASSWORD` and `DB_PROVISIONER_HOST` if they are present.

It also creates a small database called `lavoro_admin` containing one stored
procedure. That procedure gives a new customer's database account permission on
its own database.

This roundabout way is necessary. MySQL and MariaDB check a `GRANT` for one
specific database against a permission entry for exactly that name, and never
against a wildcard. The provisioner holds a wildcard permission on
`lavoro_tenant_%`, so it can create `lavoro_tenant_acme` but cannot grant
permissions on it. The only grant that would work is permission on every
database, which is exactly what this account must not have. So the granting is
done by a stored procedure that runs as root and refuses any database name
outside the customer range. The provisioner may call that procedure and nothing
else, so it cannot change it.

This separation is what the whole setup relies on. The doctor tests it by
actually trying to create a customer database as `lavoro_app` and expecting to
be refused.

Reading MySQL's permission tables requires root, which the doctor does not have,
so that half is checked by a separate script. Run it once now:

```bash
sudo scripts/tenancy/verify-mysql.sh
```

It stores its result where the doctor can read it, so from then on the doctor
reports what that check found and when. Only a complete run counts: without
`sudo` it skips most checks and leaves the previous result untouched.
`scripts/deploy.sh` runs it on every deploy.

## 4. Configure the application

```bash
scripts/tenancy/setup-env.sh
```

It asks three things: the web address Lavoro will run on, the `APP_KEY` of your
old installation, and the mail server you send your own invoices from.
Everything else it sets itself. The queue, session, cache and mail settings that
this setup depends on are not a matter of preference, so the script does not
offer them as choices.

**If you are moving an existing installation in, paste its `APP_KEY` when
asked.** It decrypts that installation's stored Google connections and other
encrypted fields. If you press Enter you get a new key, and that data becomes
permanently unreadable. The script checks that what you paste is a valid key
before writing it.

If you are starting empty, press Enter and use the key it generates.

You can run this script again later; existing values are kept unless you
overwrite them. To run it without questions:

```bash
scripts/tenancy/setup-env.sh --yes \
    --url=https://your-domain.example \
    --mail-host=smtp.example --mail-from=info@majorlabel.nl
```

Two settings are worth knowing about:

- **The provisioner has no password and no host in `.env`.** Step 3 removes
  them if they are there. If either is present, anything that can read `.env` —
  including the website — could delete any customer's database.
- **`MAIL_MAILER=tenant`** means each customer sends email using their own mail
  settings. `LANDLORD_MAIL_*` is your own mail server, used only for the
  invoices you send to your customers.

Then create the tables in the shared database:

```bash
php artisan migrate --force
```

## 5. Create your own admin login

```bash
php artisan landlord:user you@majorlabel.nl
```

It prints a generated password. This account lives in the shared database and
has nothing to do with any customer's users.

Open `https://your-domain.example/beheer`, log in, and go to **Catalogus →
Facturatie**. Fill in your address, chamber of commerce number, VAT number, IBAN
and payment terms. If you are going to collect by direct debit, add the creditor
id your bank gave you. The doctor reports these as missing until they are filled
in.

## 6. Set up the background processes

```bash
sudo scripts/tenancy/setup-workers.sh --dry-run   # shows what it will write
sudo scripts/tenancy/setup-workers.sh
```

This sets up three things. None of them happen by themselves:

- **a worker for ordinary jobs**, running as the account that owns the files —
  the account that cannot create databases;
- **a worker for creating and deleting customers**, running as
  `lavoro_provisioner`, the only account that can. It runs with `--tries=1`, so
  a failed job is not retried: retrying a half-created customer fails on
  "database already exists", which hides the real error;
- **a cron line** that runs the scheduler. Without it there are no invoices, no
  Google Calendar synchronisation and no work orders from maintenance contracts.

The script reads the account, the path, the PHP binary and the name of the
database service from the machine instead of assuming them. An installation in a
home directory runs as a different account than one in `/var/www`, and a service
file naming the wrong account starts without error and then does nothing.

### File permissions

The provisioning worker creates folders for new customers, so it needs write
access:

```bash
sudo apt install acl        # setfacl is not installed by default
sudo setfacl -R -m u:lavoro_provisioner:rwX /var/www/lavoro/storage
sudo setfacl -R -d -m u:lavoro_provisioner:rwX /var/www/lavoro/storage
```

The folders the application creates itself are group-writable (set in
`config/filesystems.php`). That matters: on a folder with `0755` permissions
these access lists only grant read access, whatever they say.

**If you installed somewhere under `/home` instead**, permissions on `storage`
alone are not enough. A home directory is normally `0750`, so the provisioner
cannot pass through it to reach anything inside, no matter what permissions
`storage` has. Give it passage on each folder above:

```bash
sudo setfacl -m u:lavoro_provisioner:x /home/youraccount
sudo setfacl -m u:lavoro_provisioner:x /home/youraccount/lavoro
```

The doctor tells these two cases apart and names the exact folders that are in
the way.

**Check which account your web server runs PHP as.** It is often not the account
that owns the files:

```bash
ps -eo user,comm | grep -iE 'lsphp|php-fpm'
```

LiteSpeed usually runs as `nobody`, Apache and nginx as `www-data`. Whichever it
is, it needs to write to `storage` and `bootstrap/cache`:

```bash
sudo setfacl -R -m u:nobody:rwX storage bootstrap/cache
sudo setfacl -R -d -m u:nobody:rwX storage bootstrap/cache
```

If this is wrong, the application cannot write its own log file. Errors then
disappear without a page, without a log entry and with nothing to search for: a
button that appears to do nothing at all. The application records which account
it runs as on a normal web request, and the doctor reports that account and
whether it can write.

### Letting commands switch to the provisioner

So that you do not have to type `sudo -u lavoro_provisioner` in front of every
customer command:

```bash
sudo scripts/tenancy/setup-sudoers.sh
```

This lets your own account become `lavoro_provisioner` without a password, so
those commands can switch by themselves. It refuses to grant that to `www-data`
or any other unattended account, because through PHP that would effectively hand
over the provisioner account. It also verifies that the rule works before it
finishes. You can skip this step; you then keep typing `sudo -u`.

The rules it writes are limited to exact commands, all without a password
prompt:

- become `lavoro_provisioner`, but only by running the PHP binary. That is how
  `tenant:create`, `tenant:delete`, `tenant:setup-existing` and `demo:install`
  reach the database and the customer folders. The `tenants:*` commands do not
  switch account; they run as whoever types them;
- `systemctl restart lavoro-worker lavoro-provisioning`, because PHP loads all
  code when it starts. Without a restart a worker keeps running the previous
  release after a deploy while still reporting that it is alive;
- `systemctl reload` of the php-fpm services on this machine, for the same
  reason on the web side. Reload rather than restart, because a restart drops
  requests that are in progress. Without this rule every deploy ends with a note
  telling you to do it by hand;
- `mysqldump` as the provisioner, for the backup the deploy makes before it
  changes anything. This rule deliberately does not include PHP: PHP can start
  any program, which would give the deploy account everything the provisioner
  can do.

The first rule goes in `/etc/sudoers.d/lavoro-admin`, the rest in
`/etc/sudoers.d/lavoro-deploy`. Administering and deploying are the same account
unless `DEPLOY_ACCOUNT` says otherwise. This is not general sudo access: no
shell, no root, nothing beyond those lines. Run `setup-sudoers.sh` again after
changing accounts.

### Restarting workers after changes

A worker reads `.env` and the code once, when it starts. If you change either
afterwards, it keeps running the old version. Nothing looks wrong from the
outside: it still reports that it is alive, but its work is out of date and the
errors point at settings that now look correct.

So after every change to `.env` or the code:

```bash
sudo systemctl restart lavoro-worker lavoro-provisioning
```

`scripts/deploy.sh` does this for you; a manual `git pull` does not.

The doctor compares the settings and the code a worker started with against what
is on disk now, and reports it when they differ.

Each worker reports in once a minute while it runs, and the doctor tells you if
one has stopped. An empty queue looks exactly like a stopped worker, so that
regular report is the only way to tell them apart. Wait a minute after this step
before trusting the doctor on this point.

**Everything above should now be clean.** Run the doctor and fix whatever it
reports before continuing. From here on, real customer data is involved.

## Uploaded files are not part of the code

`storage/tenant-<customer id>` holds a customer's files: photos on work orders,
PDFs, avatars. That is data, just like the database, and it is excluded from git
on purpose.

For a while it was committed to git, because it came along with an import, and
that nearly destroyed the folder twice: once through a `git reset --hard` onto
the wrong branch, and once through an `rm -rf` that a check had suggested. It
has since been removed from the repository history.

Two things to remember:

- **A `git pull` can delete files.** Once a path has been removed from git, the
  next pull removes it from disk as well, even if it is ignored by then. Move
  such a folder outside the repository before updating.
- **The deploy backs up databases, not files.** The uploaded files need a backup
  of their own. See [backups and restoring](../operations/backup-restore.md).

Folders belonging to customers that no longer exist are reported by the doctor
with their file count and size. Look inside first, then remove one with
`php artisan tenancy:prune-storage tenant-<customer id>`, which prints what it
is about to delete and asks first. Empty leftover folders are not reported.

## Moving an existing installation in

Now is the easiest moment to do it: nothing else is running on this server yet,
and going live below then switches over to a server that already holds the data.

[Taking over an existing installation](import-existing.md) describes the whole
procedure: the old installation goes offline, its database and files are copied
across, and its users keep their own passwords. Come back here for step 7
afterwards.

Starting empty? Skip this and continue.

## 7. Go live

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl restart lavoro-worker lavoro-provisioning php8.3-fpm
php artisan up
```

Use the name of the PHP service on this machine; `php8.3-fpm` is an example.

**If you moved an installation in:** leave the old one in place for a week with
its web server switched off. Do not delete it. It is the fastest way back if
something comes up that the checks did not catch.

## 8. Add a customer

Do this on a quiet day. It is the first time a customer database is created for
real. If you moved an installation in, it is also the first time you can see two
customers side by side and check that they cannot reach each other's data.

Either use **Nieuwe tenant** in `/beheer`, or run:

```bash
php artisan tenant:create "Customer BV" admin@customer.example --package=starter
```

Creating one through the panel queues a job for the provisioning worker. If the
request stays on "in de wacht", that worker is not running, and the doctor will
say so. While the worker is busy, the panel refreshes itself, so the list
updates without you reloading the page.

Deleting a customer works the same way: open it, choose **bewerken**, and use
the red block at the bottom, where you have to type the name in full. That
deletes the database, the database account, the files and the rows in the shared
database. There is no way back.

Then log in as the new customer. You should see an empty installation. If there
is a second customer on the server, check the thing this whole setup exists for:
logged in as one customer, try to open a file belonging to the other. You should
get a 404.

## Once you are live

- **Set up backups.** Back up the shared database, every customer database, the
  uploaded files and `APP_KEY`. See
  [backups and restoring](../operations/backup-restore.md); the command is
  `scripts/tenancy/backup.sh`.
- **Store `APP_KEY` somewhere safe.** It decrypts every customer database
  password. Without it, a restored backup cannot be used.
- **Fill in each customer's mail settings** under **Technisch beheer**. Until
  you do, that customer sends no email at all. That is deliberate: sending from
  the wrong company's mailbox would be worse.

## Further reading

| | |
| --- | --- |
| [import-existing.md](import-existing.md) | moving an existing Lavoro in as a customer |
| [fail2ban.md](fail2ban.md) | blocking repeated failed logins |
| [../operations/runbook.md](../operations/runbook.md) | running the server from here on |
| [../operations/backup-restore.md](../operations/backup-restore.md) | set up backups before you need them |
| [../development/multi-tenancy.md](../development/multi-tenancy.md) | why it is built this way |
