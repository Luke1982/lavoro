# When something goes wrong

Start with:

```bash
php artisan tenancy:doctor
```

It checks every part of the setup and names both the problem and what to do
about it. The rest of this page is for the cases where that is not enough.

## During or just after installing

| Situation | What to do |
| --- | --- |
| You have not gone live yet (before step 7 of the install) | Nothing is at risk. The old installation is still serving users. Start over. |
| You lost the password of the `lavoro_app` database account | Run `sudo scripts/tenancy/setup-mysql.sh --write-env --rotate-app-password`. It sets a new password and writes it into `.env`. |
| Importing an existing installation failed halfway | Remove what was created and run the import again. Either `php artisan tenant:delete <customer id>`, or drop the `lavoro_tenant_<name>` database by hand and delete that customer's rows from the `tenants` and `user_tenant_lookups` tables. |
| You went live and want to go back, within a week | Start the old installation again and take this one offline. Everything entered since the move is lost. |

## Somebody cannot log in

There is one login screen for all companies, so Lavoro has to work out which
company someone belongs to. It does that by email address: the shared database
has a table that maps each address to one customer. If an address is not in
that table, the person cannot log in.

```bash
php artisan tenancy:doctor      # lists users that are missing from that table
php artisan tenant:overview     # lists the customers and how many users they have
```

This usually happens when a user was created directly in a customer database,
or moved from one customer to another. An address can belong to only one
customer.

## Background work is not happening

Emails, PDFs and new customer databases are handled in the background. The jobs
are queued in the shared database and picked up by two background processes
(workers): a normal one and one that only creates and deletes customers.

If nothing happens at all, usually a worker is not running, or it is still
running the code from before the last deploy.

```bash
php artisan tenancy:doctor            # says which worker is not running
php artisan tenancy:restart-workers   # restarts both and waits until they are up
php artisan queue:failed              # shows jobs that failed, and when
```

Jobs belonging to a customer that has since been deleted are discarded instead
of failing, so a deleted customer does not leave failed jobs behind.

## A customer's database is missing or broken

The rest of the installation keeps working. Lavoro checks that it can open a
customer's database before switching to it; if it cannot, it logs that person
out instead of showing an error page. Other customers are unaffected.

That customer's page in the admin panel at `/beheer` still opens, so you can
either delete the customer or point it at a restored database. See
[backups and restoring](backup-restore.md).
