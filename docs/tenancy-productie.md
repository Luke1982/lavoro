# Installing Lavoro on a new server

Follow these steps in order.

After every step, run:

```bash
php artisan tenancy:doctor
```

That command is the check. It looks at the database accounts and their
privileges, the PHP extensions, the `.env`, both background workers, the
scheduler, your invoice details and every customer database. It prints what is
wrong and what to do about it, and exits with an error code so a script stops
on it. If it reports a problem, fix that before moving on.

It will complain about things that are not set up yet. That is expected — work
down the list and the complaints disappear one by one.

Written for MariaDB 10.11 and PHP 8.3. Where MySQL differs, it says so.

Budget an hour, plus however long the database dump takes. Your existing
installation keeps running until step 8, so everything before that is safe.

---

## Before you start

Have these ready:

- Root or sudo access on the new server
- A database account that can create users and grant privileges
- The path to your existing Lavoro installation
- **The `APP_KEY` from the old installation's `.env`.** Copy it, do not
  generate a new one. That key decrypts every stored Google connection and
  every encrypted field. Without it that data is unreadable.
- The company name as it should appear on invoices
- A database backup you have restored somewhere and seen working

## 1. Get the code

```bash
sudo mkdir -p /var/www/lavoro
sudo chown "$USER" /var/www/lavoro
git clone <repository-url> /var/www/lavoro
cd /var/www/lavoro

composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

## 2. Check socket login is available

Lavoro uses two database accounts. One of them logs in without a password,
using the Linux user it belongs to. Check the server can do that:

```sql
SELECT plugin_name, plugin_status, plugin_library
  FROM information_schema.plugins
 WHERE plugin_name IN ('unix_socket', 'auth_socket');
```

**On MariaDB this is almost always already `ACTIVE`.** Since 10.4 the plugin is
compiled into the server, so `plugin_library` is NULL and there is nothing to
install. `INSTALL SONAME 'auth_socket'` then fails with "cannot open shared
object file" — not because something is missing, but because there is no
separate file to load. If the query shows `ACTIVE`, this step is done.

**On MySQL** it usually needs switching on once:

```sql
INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';
```

That survives a restart.

If neither server reports the plugin at all, install the matching server
package — check `SELECT @@plugin_dir` to see where it would live.

The next step picks the right syntax for your server by itself: MariaDB wants
`IDENTIFIED VIA unix_socket`, MySQL wants `IDENTIFIED WITH auth_socket`.

## 3. Create the database accounts

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run    # prints the SQL, changes nothing
sudo scripts/tenancy/setup-mysql.sh --write-env  # actually does it
```

This creates:

- the central database, `lavoro_landlord`
- `lavoro_app`, with a password. The website runs as this account and it can
  only touch that one database.
- the Linux user `lavoro_provisioner` and a matching database account with no
  password, tied to that Linux user. This is the only account that can create
  and delete customer databases.

`--write-env` writes the results into `.env` and backs up the old file first.
It also writes the provisioner's account name and socket, and removes
`DB_PROVISIONER_PASSWORD` and `DB_PROVISIONER_HOST` if they are present.

This split is the boundary the whole setup rests on. The doctor tests it by
actually trying to create a customer database as `lavoro_app` and expecting to
be refused.

## 4. Configure the application

```bash
scripts/tenancy/setup-env.sh
```

It asks three things: the address Lavoro runs on, the `APP_KEY` of your old
installation, and the mail server you send invoices from. Everything else it
sets by itself — the queue, session, cache and mail settings that tenancy
depends on are not preferences, and the script does not offer them as choices.

**Have the old `APP_KEY` ready and paste it when asked.** It decrypts every
stored Google connection, every customer database password and every encrypted
field. Press Enter instead and you get a new key, which makes all of that
unreadable with no way back. The script checks the key you paste is a real one
before writing it.

Safe to run again; existing values stay unless you overwrite them. To run it
unattended:

```bash
scripts/tenancy/setup-env.sh --yes \
    --url=https://your-domain.example \
    --mail-host=smtp.example --mail-from=info@majorlabel.nl
```

Between this and step 3, every setting the doctor looks at is now in place.
Two of them are worth knowing about:

- **The provisioner has no password and no host in `.env`,** and step 3 removes
  them if they are there. With either one present, anything that can read
  `.env` — the website included — can delete any customer's database.
- **`MAIL_MAILER=tenant`** means every customer sends mail with their own
  settings. `LANDLORD_MAIL_*` is your own mail server, used only for the
  invoices you send to customers.

Then create the tables:

```bash
php artisan migrate --force
```

## 5. Create your admin login

```bash
php artisan landlord:user you@majorlabel.nl
```

It prints a generated password. This account lives in the central database and
has nothing to do with any customer's users.

Open `https://your-domain.example/beheer`, log in, and go to
**Catalogus → Facturatie**. Fill in your address, chamber of commerce number,
VAT number, IBAN and payment terms. If you will collect by direct debit, add
the creditor ID your bank issued. The doctor reports these as missing until
they are filled in.

## 6. Set up the two background workers

Two, on purpose. The first runs as the website's account, which cannot create
databases. The second is the only one that can.

`/etc/systemd/system/lavoro-worker.service`:

```ini
[Unit]
Description=Lavoro worker
After=mariadb.service

[Service]
User=www-data
WorkingDirectory=/var/www/lavoro
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/lavoro-provisioning.service`:

```ini
[Unit]
Description=Lavoro provisioning worker
After=mariadb.service

[Service]
User=lavoro_provisioner
Group=lavoro_provisioner
WorkingDirectory=/var/www/lavoro
ExecStart=/usr/bin/php artisan queue:work --queue=provisioning --tries=1 --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`--tries=1` on the second one is deliberate. Retrying a half-created customer
fails on "database already exists" and hides the real error.

The provisioning worker creates folders for new customers, so give it write
access:

```bash
sudo setfacl -R -m u:lavoro_provisioner:rwX /var/www/lavoro/storage
sudo setfacl -R -d -m u:lavoro_provisioner:rwX /var/www/lavoro/storage
```

Start both:

```bash
sudo systemctl enable --now lavoro-worker lavoro-provisioning
```

Finally, so you do not have to type `sudo -u lavoro_provisioner` in front of
every tenant command:

```bash
sudo scripts/tenancy/setup-sudoers.sh
```

That lets your own account become the provisioner without a password, so the
commands elevate themselves. It refuses to hand that to `www-data` or any
unattended account — through PHP it would amount to giving away the provisioner
entirely. Skipping it is fine; you then keep typing `sudo -u`.

Each worker reports in every minute while it runs, and the doctor tells you if
one has stopped. An empty queue looks exactly like a dead worker, so that
heartbeat is the only thing that can tell them apart.

## 7. Set up the scheduler

```bash
sudo crontab -u www-data -e
```

Add this line:

```
* * * * * cd /var/www/lavoro && php artisan schedule:run >> /dev/null 2>&1
```

Without it nothing happens automatically: no invoices, no Google Calendar sync,
no work orders generated from maintenance contracts. Wait five minutes, then
run the doctor — it reports whether the scheduler is alive.

**Everything above should now be clean.** Run the doctor and fix anything it
reports before continuing. What follows involves real customer data.

## 8. Move your existing installation in

From here the old installation is offline. Do this outside working hours.

Take it down and take a fresh backup while nothing is writing to it:

```bash
cd /path/to/old/lavoro
php artisan down

mysqldump --single-transaction --routines <old_database> > ~/lavoro-before-move.sql
```

Keep that dump for at least a week. Then:

```bash
cd /var/www/lavoro

scripts/tenancy/import-install.sh \
    --from /path/to/old/lavoro \
    --name "Customer Name BV" \
    --slug customername \
    --package business \
    --dry-run
```

Read what it says it will do. If that is right, run it again without
`--dry-run`.

It copies the old database into `lavoro_tenant_<slug>`, drops the tables that
are now shared (sessions, cache, jobs), registers the customer, updates the
schema, copies uploaded files into the customer's folder and sets the package.

**Existing users come across with their own passwords.** The command registers
their email addresses centrally, which is how logging in finds the right
customer. You do not need to create anyone.

Run the doctor afterwards. It now also checks this customer: the database, the
stored password, the login, the required work order stages, that every user has
a central entry, and that the file folders exist and are writable.

## 9. Test the things a program cannot check

The doctor proves the plumbing. These are the things only a person can see:

- Log in with an existing account and its old password
- Open the customer list — is the number right?
- **Open a photo on a work order.** Files move to a different folder during the
  import. If that went wrong you get no error, just an empty space.
- Open the planner and check appointments appear. They load over a different
  route than the rest of the app.
- Generate a work order PDF
- Send a test email under **Technisch beheer**
- Ask the AI assistant a question, if this customer has it
- In `/beheer`, check the customer shows the right package, seats and storage

## 10. Go live

```bash
php artisan config:cache route:cache view:cache
sudo systemctl restart lavoro-worker lavoro-provisioning php8.3-fpm
php artisan up
```

Leave the old installation in place for a week with its web server switched
off. Do not delete it.

## 11. Add a second customer

Do this on a quiet day. It is the first time a database gets created for real.

Either use **Nieuwe tenant** in `/beheer`, or:

```bash
php artisan tenant:create "Second Customer BV" admin@second.example --package=starter
```

Creating one through the panel queues a job for the provisioning worker. If the
request stays on "in de wacht", that worker is not running — and the doctor
will say so.

Then log in as the new customer. You should see an empty installation and,
above all, *not* the first customer's data. Still logged in as the second
customer, try to open a file belonging to the first: you should get a 404.

---

## If something goes wrong

| When | What to do |
| --- | --- |
| Before step 8 | Nothing is at risk, the old installation is still running. Start over. |
| The `lavoro_app` password is lost | `sudo scripts/tenancy/setup-mysql.sh --write-env --rotate-app-password`. It sets a new one and writes it to `.env`. |
| The import fails halfway | `php artisan tenant:delete <id>`, or drop `lavoro_tenant_<slug>` by hand and remove the rows from `tenants` and `user_tenant_lookups`. Then run it again. |
| After step 10, within a week | Bring the old installation back up and take the new one down. Anything entered since the move is lost. |

## Once you are live

- Back up the central database **and every customer database**. A backup of
  only the central one is a list of names and nothing else.
- Put `APP_KEY` somewhere safe. It unlocks every customer database password,
  and without it none of the encrypted data can be read.
- For each customer, fill in their mail settings under **Technisch beheer**.
  Until you do, that customer sends no email at all. That is deliberate:
  sending from another company's mailbox is worse than not sending.

## Related documents

| | |
| --- | --- |
| `tenancy-bediening.md` | day to day commands |
| `tenancy-testrisicos.md` | where this breaks and how you would notice |
| `superpowers/plans/2026-06-09-multi-database-tenancy.md` | why it is built this way |
