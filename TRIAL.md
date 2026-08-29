# Tenancy trial

A working multi-tenant conversion with the Spee production dump imported as a
tenant. Runs against its own MySQL on port 3307, so it never touches your
`lavoro_fsm` database or the system MySQL.

## Start

```bash
./trial.sh start      # starts MySQL (3307) and the app (8123)
./trial.sh stop
./trial.sh status
```

Then open http://127.0.0.1:8123 and log in:

    info@speetotaaltechniek.nl / tenancytest

## What is here

* One tenant, "Spee Totaaltechniek", on `lavoro_tenant_spee` — 2063 customers,
  389 werkbonnen, 18 e-mail addresses in the central lookup.
* A landlord database `lavoro_landlord` holding `tenants`, `user_tenant_lookups`,
  `sessions`, `cache` and `jobs`.
* `scripts/tenancy/import-install.sh`, which did the import and can do it again.

## Try

```bash
php artisan tenants:migrate                       # migrates every tenant
php artisan tinker --execute='tenancy()->initialize(App\Models\Tenant::on("central")->first()); echo App\Models\Customer::count();'

# the app account cannot read tenant data, by design
MYSQL_PWD=apppass mysql -u lavoro_app -h 127.0.0.1 -P 3307 \
    -e "SELECT * FROM lavoro_tenant_spee.customers LIMIT 1"
```

## Not built

Tasks 16-25 and 28-44 beyond what the import needed: licensing, seats, modules,
storage quota, the landlord admin, per-tenant integration credentials. The
provisioner uses a password rather than `auth_socket`, because the trial had no
root to create a Linux user with.
