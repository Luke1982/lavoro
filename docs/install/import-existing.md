# Taking over an existing installation

A Lavoro that serves one company becomes a customer of this one: its database,
its uploads, its users and its package move across in a single command. Written
for the step between [installing the server](server.md) and going live, but it
works just as well on a server that is already running customers.

**The old installation goes offline first.** Everything below assumes nothing is
writing to it any more.

## 1. Take it down and back it up

Do this outside working hours, and take a fresh dump while nothing is writing
to it any more:

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

## 2. Test the things a program cannot check

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

## The flags, in short

```bash
bash scripts/tenancy/import-install.sh --from /home/klant/lavorofsm \
     --name "Bedrijf BV" --slug bedrijf --package business --dry-run
```

Copies a single-customer installation into a customer of this setup: its
database, its uploads, a login and the package. It needs root -- the other
installation belongs to another account, and creating a database is not the app
account's -- and says exactly what to paste in a root shell when it does not
have it. `--dry-run` writes nothing and shows the whole plan; `--billing-from`
sets the day billing starts (a date, or `none`), which on a takeover is an
agreement rather than automatically today.

## Then

Back to [installing the server](server.md#7-go-live) for going live, or to the
[runbook](../operations/runbook.md) if this server was already running.
