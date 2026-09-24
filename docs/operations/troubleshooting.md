# When something goes wrong

Start with `php artisan tenancy:doctor`: it names the problem and what to do
about it, and it is the only check that looks at every part of the setup at
once. What follows is for the cases where that is not enough.

## During or just after an installation

| When | What to do |
| --- | --- |
| Before step 7 | Nothing is at risk, the old installation is still running. Start over. |
| The `lavoro_app` password is lost | `sudo scripts/tenancy/setup-mysql.sh --write-env --rotate-app-password`. It sets a new one and writes it to `.env`. |
| The import fails halfway | `php artisan tenant:delete <id>`, or drop `lavoro_tenant_<slug>` by hand and remove the rows from `tenants` and `user_tenant_lookups`. Then run it again. |
| After step 9, within a week | Bring the old installation back up and take the new one down. Anything entered since the move is lost. |

## A customer cannot log in

The address decides which customer someone lands in, so the answer is nearly
always in the central lookup:

```bash
php artisan tenancy:doctor            # reports users without a central entry
php artisan tenant:overview           # who exists, and how big they are
```

An address may belong to one customer only. A user moved between customers, or
created by hand in a customer database, has no central entry and cannot log in.

## Work that does not happen

Queued work sits in the central database, and two workers take it: the ordinary
one and the provisioning one. Nothing happening at all is nearly always a worker
that is not running, or one running old code after a deploy.

```bash
php artisan tenancy:doctor            # names the queue and the process
php artisan tenancy:restart-workers   # restarts both units and waits for them
php artisan queue:failed              # what failed, and when
```

A job whose customer no longer exists is thrown away rather than failed, so a
deleted customer does not leave failures behind.

## A customer's database is gone

The login screen keeps working: the middleware checks the database can be opened
before it switches over, and forgets the session when it cannot. The customer's
own page in `/beheer` still opens, so you can delete it or point it at a
restored database. Restoring is in [backup and restore](backup-restore.md).
