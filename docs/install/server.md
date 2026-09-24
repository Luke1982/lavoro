# Installing Lavoro on a new server

Lavoro is a field service application. One installation serves several
companies, each with its own database. By the end of this page you have a
working installation on your own server, with your admin login, and one company
in it.

Follow the steps in order. Allow about an hour, plus the time a database dump
takes if you are moving an existing installation in.

If you are moving an existing installation in, that one keeps running until step
7, so everything before that is safe.

These instructions are written for Ubuntu or Debian with MariaDB 10.11 and PHP
8.3. Where MySQL works differently, it says so.

## What the server needs first

Lavoro is a PHP application with a MySQL database. Install these before you
start:

```bash
sudo apt update
sudo apt install -y git curl acl \
    mariadb-server \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl
```

You also need:

- **Composer**, the PHP package manager: https://getcomposer.org/download/
- **Node 22 and npm**, to build the front end:
  `curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs`
- **a web server**, nginx or Apache, or LiteSpeed. Step 5 sets it up.
- **a domain name pointing at this server, with an HTTPS certificate**. Lavoro
  sends links by email that have to work from outside.

The `pcntl` and `posix` PHP extensions have to be available to the command line
PHP, which they are in `php8.3-cli` by default. `php artisan tenancy:doctor`
checks for every extension it needs and names any that are missing.

## A Linux account to own the files

Do not install Lavoro as root. Create an account for it, and use that account
for every command on this page that does not start with `sudo`:

```bash
sudo adduser --disabled-password --gecos "" lavoro
sudo su - lavoro
```

Whichever name you choose, this page calls it the **application account**. Its
name matters later: the background processes and the file permissions are set up
for exactly this account.

## The check you use throughout

```bash
php artisan tenancy:doctor
```

Run it from the installation folder, as the application account. It looks at the
database accounts and their permissions, the PHP extensions, the `.env` file,
both background processes, the cron job, your invoicing details and every
customer database. It prints one line per check (`OK`, `FAIL` or `SKIP`), says
what to do about each failure, and exits with code 1 if anything failed.

It only works from step 4 onwards, because before that there is no `.env` file
for it to read. Until then it stops with an error instead of a report.

While you work through this page it will complain about things you have not set
up yet. That is expected; the complaints disappear step by step.

## Three accounts, and which one does what

| Account | Runs |
| --- | --- |
| **root** | everything with `sudo` in front of it here: creating accounts, `setfacl`, `systemctl`, `crontab` |
| **the application account** (the Linux user that owns the files; `lavoro` in these examples) | `git`, `composer`, `npm` and every `php artisan` command. Never with `sudo`: this account deliberately has no sudo rights |
| **lavoro_provisioner** | creating and deleting customer databases, and nothing else. You never log in as this account yourself. After step 7 the relevant commands switch to it automatically |

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
and you create your first customer in step 9.

## 1. Get the code

As the application account:

```bash
sudo mkdir -p /var/www/lavoro
sudo chown lavoro /var/www/lavoro
git clone <repository-url> /var/www/lavoro
cd /var/www/lavoro

composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

`<repository-url>` is the Git repository you were given access to. `npm run
build` compiles the front end into `public/build`; the site shows an error page
without it.

## 2. Check that socket login is available

Lavoro uses a database account that logs in without a password, identified by
the Linux user it belongs to. This is called socket authentication, and the
account that creates customer databases uses it, so that no password for it
exists anywhere on disk.

Check that your database server supports it. Open the database client as root
(`sudo mysql`) and run:

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
    --mail-host=smtp.example --mail-from=facturen@your-company.example
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

`--force` is needed because Laravel asks for confirmation before changing a
database in production, and there is nobody to ask in a script.

## 5. Point your web server at Lavoro

The web server has to serve the `public` folder inside the installation, and
nothing above it. If you point it at `/var/www/lavoro` instead, anyone can
download your `.env` file, and with it every password on this server.

PHP allows 2 MB uploads by default, while Lavoro accepts documents of up to
100 MB. Raise both PHP and the web server, or uploads fail with an error that
does not mention a size:

```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-lavoro.ini <<'EOF'
upload_max_filesize = 100M
post_max_size = 105M
EOF
sudo systemctl restart php8.3-fpm
```

For nginx, a site file such as `/etc/nginx/sites-available/lavoro`:

```nginx
server {
    listen 443 ssl;
    server_name your-domain.example;

    root /var/www/lavoro/public;
    index index.php;

    client_max_body_size 105M;

    ssl_certificate     /etc/letsencrypt/live/your-domain.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.example/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Then enable it and reload:

```bash
sudo ln -s /etc/nginx/sites-available/lavoro /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

On Apache, set `DocumentRoot /var/www/lavoro/public`, allow `.htaccess`
(`AllowOverride All`) for that folder, and set `LimitRequestBody 110100480`. The
`.htaccess` file that Laravel ships handles the rest. On LiteSpeed, set the
document root the same way in its control panel.

Get a certificate with `sudo certbot --nginx -d your-domain.example` if you do
not have one yet, and make sure plain HTTP redirects to HTTPS.

Now open `https://your-domain.example` in a browser. You should see Lavoro's
login screen. You cannot log in yet: that is the next step.

If you get a 500 error instead, look in `storage/logs/laravel.log`. If that file
is empty or missing, the web server's account cannot write there, which step 7
fixes.

## 6. Create your own admin login

```bash
php artisan landlord:user you@your-company.example
```

It prints a generated password. Write it down; it is not shown again. This
account lives in the shared database and is only for the admin panel, so it
cannot log in to any company's own screens.

Now open `https://your-domain.example/beheer` and log in. This is the admin
panel, where you manage companies, subscriptions and invoices.

Go to **Catalogus → Facturatie** (Dutch for "catalogue" and "invoicing") and
fill in your own company details: address, chamber of commerce number, VAT
number, IBAN and payment terms. These end up on the invoices you send to your
customers. If you are going to collect by direct debit, add the creditor id your
bank gave you. The doctor reports these as missing until they are filled in.

## 7. Set up the background processes

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

Check that both are running:

```bash
systemctl status lavoro-worker lavoro-provisioning
```

Each worker reports in once a minute, so wait a minute before asking the doctor
about them. An empty queue looks exactly like a stopped worker, and that regular
report is the only way to tell the two apart.

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

LiteSpeed usually runs as `nobody`, Apache and nginx as `www-data`. Whichever
name that command printed, put it in the two lines below in place of `nobody`.
That account has to be able to write to `storage` and `bootstrap/cache`:

```bash
sudo setfacl -R -m u:nobody:rwX storage bootstrap/cache
sudo setfacl -R -d -m u:nobody:rwX storage bootstrap/cache
```

The second line is not a repeat of the first: `-d` sets the default for files
created later, so new folders inherit the same access.

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
across, and its users keep their own passwords. Come back here for step 8
afterwards.

Starting empty? Skip this and continue.

## 8. Go live

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl restart lavoro-worker lavoro-provisioning php8.3-fpm
php artisan up
```

The three `:cache` commands compile the settings, the routes and the templates
into files, which makes every request faster. **From then on, changing `.env`
has no effect until you run `php artisan config:cache` again**, so remember this
when you change a setting later. `php artisan config:clear` undoes it.

`php8.3-fpm` is an example; use the name of the PHP service on this machine.

`php artisan up` takes the installation out of maintenance mode. On a fresh
install it was never in it, and the command does no harm.

**If you moved an installation in:** leave the old one in place for a week with
its web server switched off. Do not delete it. It is the fastest way back if
something comes up that the checks did not catch.

## 9. Add a customer

Do this on a quiet day. It is the first time a customer database is created for
real. If you moved an installation in, it is also the first time you can see two
customers side by side and check that they cannot reach each other's data.

Either use **Nieuwe tenant** (Dutch for "new customer") in `/beheer`, or run:

```bash
php artisan tenant:create "Customer BV" admin@customer.example --package=starter
```

`--package` is the subscription package: `starter`, `team`, `business` or
`enterprise`. The email address becomes the first administrator of that company,
and the command prints a generated password for them.

Creating one through the panel writes a request that the provisioning worker
picks up, so it takes a few seconds. If it stays on "in de wacht" (Dutch for
"waiting"), that worker is not running, and the doctor will say so. While the
worker is busy the panel refreshes itself, so the list updates without you
reloading the page.

Deleting a customer is done in the same screen: open it, choose **bewerken**
(Dutch for "edit"), and use the red block at the bottom, where you have to type
the company name in full. That deletes the database, its database account, the
files and the rows in the shared database. There is no way back except a backup.

Then log in as the new customer's administrator. You should see an empty
installation.

If there is a second customer on this server, check the thing this whole setup
exists for: while logged in as one company, open a file belonging to the other,
for example `https://your-domain.example/files/images/1`. You should get a 404,
not the file.

## Once you are live

- **Set up backups.** Back up the shared database, every customer database, the
  uploaded files and `APP_KEY`. See
  [backups and restoring](../operations/backup-restore.md); the command is
  `scripts/tenancy/backup.sh`.
- **Store `APP_KEY` somewhere safe.** It decrypts every customer database
  password. Without it, a restored backup cannot be used.
- **Block password guessing.** Lavoro writes every refused login to a file;
  `sudo scripts/tenancy/setup-fail2ban.sh` makes fail2ban act on it. Until you
  run that, nobody is ever blocked. See [fail2ban](fail2ban.md).
- **Fill in each customer's mail settings**, inside that company under
  **Technisch beheer** (Dutch for "technical management"). Until somebody does,
  that company sends no email at all. That is deliberate: sending from the wrong
  company's mail server would be worse than not sending.

## Further reading

| | |
| --- | --- |
| [import-existing.md](import-existing.md) | moving an existing Lavoro in as a customer |
| [fail2ban.md](fail2ban.md) | blocking repeated failed logins |
| [../operations/runbook.md](../operations/runbook.md) | running the server from here on |
| [../operations/backup-restore.md](../operations/backup-restore.md) | set up backups before you need them |
| [../development/multi-tenancy.md](../development/multi-tenancy.md) | why it is built this way |
