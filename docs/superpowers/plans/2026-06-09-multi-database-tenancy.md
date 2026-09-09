# Multi-Database Multi-Tenancy Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Add multi-database multi-tenancy to Lavoro using `stancl/tenancy` v3, where a single domain serves all client companies and the correct database is chosen based on who logs in. The central ("landlord") database also holds the licensing model: each tenant's package, extra seats, module subscriptions and storage allowance, a price catalogue that computes each tenant's monthly total, and a landlord admin panel (built under `/beheer`, not on its own subdomain) to manage it all. Licensing is designed in `docs/superpowers/specs/2026-07-20-tenant-licensing-design.md`; Tasks 4, 6, 16, 19, 21, 26, 33–37 and 39 implement it.

**How it works, in plain terms:**

Today there is one database for everything. After this change there is one small *central* database plus one full database per client company (a "tenant"). When someone logs in, we look up their email in the central database to find which company they belong to, switch every database query to that company's database for the rest of the request, and remember the company in their session (and a long-lived cookie, for remember-me) for later requests.

**What lives where:**

Central database (small, shared infrastructure):
- `tenants` — one row per client company, including its `package_key`, extra seat counts, `storage_limit_gb`, `price_override_cents` and subscribed `modules`
- `packages`, `modules`, `module_bundles`, `pricing_settings` — the price catalogue (seeded), read to compute each tenant's monthly total
- `user_tenant_lookups` — maps an email to a tenant, used only when logging in
- `jobs`, `job_batches`, `failed_jobs` — the queue, so the worker can read jobs without first knowing the tenant
- `cache`, `cache_locks` — shared store; entries are isolated per tenant by a key prefix
- `sessions` — must be readable before we know the tenant, so it stays central
- `assistant_usage` — what each AI call cost. The one business table that moves *out* of the tenant database, because it is what the tenant is billed on and a ceiling the tenant can edit is not a ceiling (Task 39)

Tenant database (one per client company, fully isolated):
- `users`, `password_reset_tokens`
- Every business table (customers, assets, service orders, tickets, events, projects, materials, device tokens, location pings, plan groups, etc.)
- `activities` and `activity_changes` — the audit trail written by the domain-signal layer (see below)
- `assistant_questions`, `assistant_conversation_facts`, `assistant_tool_calls`, `assistant_prompts` — the assistant's transcripts, what a conversation settled, what its tools were handed, and the questions worth asking again. All of it is customer data and all of it stays with the customer
- `user_notifications`, `notification_subscriptions`, `push_subscriptions` — the in-app bell, who asked to hear about what, and which browsers agreed to be interrupted
- `roles`, `permissions`, `user_roles`, `companies`, `general_settings`, Google integration tables — everything else

Uploaded files are fully separated on disk too: each tenant's files live under their own root, `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`, and are served only through authenticated controllers (never a public URL). See Task 14. The Android APK under `storage/app/releases/` is global and intentionally unaffected.

**Three hard constraints this design imposes (read before starting):**

1. **Email must be globally unique across all tenants.** Because we find the tenant *from* the email at login, the same email cannot belong to two different companies. This is enforced at user creation (Task 19) and the central lookup table (Task 6).
2. **Sessions stay on the database driver but pinned to the central connection** (`SESSION_CONNECTION=central`, Task 7). The session is read before tenancy is initialized, so it cannot live in a tenant database.
3. **Cache stays on the database driver, pinned to the central connection, and isolated by a per-tenant key prefix** (Task 10). Both changes are needed and they do different jobs. Pinning the connection stops the store looking for a `cache` table inside the tenant database, where there is none. The prefix stops tenants reading each other's entries in the one shared table. We do *not* use the package's tag-based cache bootstrapper because the `database` cache store does not support tagging. The prefix approach is **cache-driver-agnostic** — it works identically on `file`, `database`, and `redis`, so adopting Redis later is a `CACHE_STORE=redis` change with no tenancy code touched (the connection pin becomes moot at that point, since Redis is not a database connection).

**Current environment (verified):** `DB_CONNECTION=mysql`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`. This plan keeps all of those drivers — no Redis required.

**The app runs on a domain-signal layer.** Controllers, services and observers announce facts through `Signals::dispatch(new SomeSignal(...))` (`App\Domain\Signals\*`) and listeners (`App\Listeners\*`) carry out the side effects — the audit trail, Google sync, standard e-mails, notifications, follow-on stage moves. Three consequences for this plan, all of them small:

1. **Every listener is synchronous.** None implements `ShouldQueue`; the three that touch the outside world implement `ShouldHandleEventsAfterCommit`, which defers them to after the *transaction* commits, still inside the same request. So a listener always runs in whatever tenant context the request or job established, and needs no tenancy code of its own. What listeners *dispatch* — `SendStandardEmailJob`, `PushEventJob`, `BulkMoveServiceOrderStageJob` — are ordinary queued jobs, tagged with the tenant by `QueueTenancyBootstrapper` like any other (Task 9).
2. **Per-request state lives in container singletons**, and that is the one part of the app that can leak across tenants: `ActivityBuffer` (tenant-scoped activity row ids), `Signals` (the current chain, the correlation id, the per-request signal budget), `AssistantContext` (a `User` model), the scoped `TechnicianAvailability` (a window of one tenant's diary), and Laravel's cached mailers (Task 32). Task 11 gives all of them one home: each implements `ForgetsTenantState`, and both moments that need them cleared — the queue-job boundary and the tenancy switch — call the same `TenantState::flush()`.
3. **The trail writes two tables**, `activities` (with `event_key`, `subject_*`, `actor_*`, `occurred_at`, `correlation_id`) and `activity_changes`. Both are tenant tables. The design is written up in `docs/superpowers/plans/2026-07-27-domain-signals.md`; the loop guard and correlation id are documented on the docblocks of `App\Domain\Signals\Signals` rather than there.

**Two features carry most of this plan's surprises, and neither is small.**

**The AI assistant.** It reads the database through hand-listed tools, writes records behind a confirmation gate, reads product documentation and `docs/handleiding.md`, takes photos and files with a question, searches the internet, and keeps a conversation. Five things about it matter here, and each one lands on a different task:

- It stores in the tenant database (`assistant_questions`, `assistant_conversation_facts`, `assistant_tool_calls`, `assistant_prompts`) **and** on the `local` disk: parked photos under `assistant-photos/`, borrowed files under `assistant-files/`, reported conversations under `assistant-reports/`. The disk half is customer data outside the database, and it moves per tenant for free once Task 14's bootstrapper is in place — but only for code that runs *in tenant context*, which is what makes `assistant:prune` a Task 20 conversion rather than a schedule that can be left alone.
- It costs real money per question, at a supplier, on our account. Task 39 puts a ceiling on that. There are **eight configured models across five suppliers**, with `ModelPicker` buying the cheapest one that clears the question's difficulty, so "what a question costs" is a distribution rather than a number — and two cost sources are invisible to the meter entirely. Task 39 says which.
- `AssistantContext` is a container singleton holding a `User`. See point 2 above.
- Access is a permission (`assistant.use`) that **admins deliberately do not inherit** — `AssistantPolicy` is the only authority, and `HandleInertiaRequests` shares its verdict as `auth.can.use_assistant`. Nothing about that changes under tenancy; it is noted so the share is not mistaken for something that needs guarding.
- The supplier API keys are global env vars and stay that way. Unlike Graph and SnelStart (Task 32), the key identifies *us* to the supplier, not the customer — we hold the account and pay the bill. Per-tenant keys would invert who is being billed, which is the opposite of what Task 39 is for.

**Notifications** — `user_notifications`, `notification_subscriptions`, `push_subscriptions`, a subscriptions page, a web-push sender, and a service worker that shows and routes notifications. All three tables are tenant tables. The VAPID keypair is one global application identity, correctly global for the same reason Firebase is (Known impact 4), but the *routing* of a push carries a tenancy hazard Firebase never had: the service worker navigates to a bare path like `/serviceorders/123`. See Known impact 14.

**Where this work happens.** All of it is built and tested here, on a development
machine, against a restored copy of the production database. Nothing in this plan
is developed by typing into a production server.

That splits the tasks in two:

- **Tasks 1–26 and 30–43 are ordinary development.** Code, migrations, artisan
  commands, tests. They run locally and ship through the normal deploy.
- **Tasks 27 and 29 describe a cutover that happens once on production.** What
  those tasks produce is a **script**, written and rehearsed here against a
  production dump until it runs clean start to finish. The steps in them are the
  script's contents, not a checklist somebody follows over SSH at two in the
  morning.

Task 2 is the model to copy: the MySQL account setup could have been eight
statements in a runbook, and instead it is `scripts/tenancy/setup-mysql.sh` with a
`--dry-run` flag and a matching `verify-mysql.sh`. Every operation this plan puts
on a production server should end up looking like that — a script you have already
watched work.

**Database naming and credentials:**

| | Name |
| --- | --- |
| Central database | `lavoro_landlord` |
| Tenant databases | `lavoro_tenant_<slug-or-ulid>` (prefix `lavoro_tenant_`, Task 3) |
| Web/queue MySQL user | `lavoro_app`, granted only on `lavoro_landlord` |
| Provisioning MySQL user | `lavoro_provisioner`, no password (`auth_socket`), granted on `` `lavoro\_tenant\_%` `` + `CREATE USER` |
| Per-tenant MySQL users | `lavoro_tenant_<slug>`, granted only on that tenant's own database |
| Test MySQL user | `lavoro_test`, granted only on `` `lavoro\_test\_%` `` (Task 30) |

The application **never connects as `root`**. `lavoro_app` is confined to the landlord database — it cannot read customer data at all. Each tenant's queries authenticate as that tenant's own user, so cross-tenant access is blocked by MySQL rather than only by the application switching connections correctly. `root` is used only for the one-time account creation in Task 2 and for the dump/restore steps in Tasks 27 and 29, where you are acting as an operator rather than as the app.

**Be precise about what is isolated from what.** The provisioner *does* hold privileges on every tenant database — ``GRANT ALL ON `lavoro\_tenant\_%` `` is exactly that — and this is unavoidable: MySQL only lets an account grant privileges it holds itself, so whatever creates a tenant's user and grants it `SELECT` must hold `SELECT` across the namespace. There is no "may create a database but not read it" privilege.

The claim this design actually makes is therefore narrower, and worth stating exactly:

> No credential reachable from a web request can read more than one tenant's data.

That holds. `lavoro_app` reaches only the landlord database; a tenant request authenticates as that tenant's own user, which reaches only its own database; and the provisioner has no password to leak, is bound to a Linux user by `auth_socket`, and is unusable by `www-data`.

Giving the provisioner that grant does not make a break-in any worse than it already would be, because **anyone with root on the application server can already reach every tenant**: `.env` holds `APP_KEY`, the landlord database holds every tenant's encrypted MySQL password, and `APP_KEY` decrypts them. Anyone who can `sudo -u lavoro_provisioner` can already do that. The grant makes an existing capability explicit rather than adding one.

What this does *not* protect against, stated plainly so nobody assumes otherwise: a compromise of the host, of `APP_KEY`, or of a backup containing both. Those are single points of failure for every tenant at once, and no arrangement of MySQL grants changes that.

Two consequences of this naming scheme worth knowing up front:

- **Every tenant database name starts with `lavoro_tenant_`.** That shared prefix is what lets the provisioner's grant be limited to `` `lavoro\_tenant\_%` `` — a pattern that matches *only* tenant databases. Any other database on the server (the landlord database, a pre-tenancy install, an unrelated app) is outside it and cannot be touched by that account. The landlord database is granted separately and by exact name.
- **A tenant can never collide with the landlord database**, because no name can start with `lavoro_tenant_` and also be `lavoro_landlord`. Two tenants *can* still collide with each other if their names slug identically, so Tasks 21 and 26 refuse any database name that already exists on the server.

**Database isolation is enforced by MySQL, not only by the application.** Each tenant gets its own MySQL user that can reach only its own database (Task 2/3). The web app's own credentials reach only the central database. So a bug that fails to switch tenant context produces a permission error rather than another customer's data. The account that can create databases and users authenticates by Unix socket as a dedicated Linux user (`auth_socket`) and has **no password stored anywhere** — provisioning is a deliberate CLI action, impossible from a web request.

**Prerequisites:**
- MySQL **or MariaDB** (multi-database tenancy does not work on SQLite). The two differ in how socket authentication is named and selected; `scripts/tenancy/setup-mysql.sh` detects which one it is talking to rather than assuming. Development here is MySQL 8.0.46.
- The database server on the same host as the app, reachable over its Unix socket — required for the passwordless provisioner account (Task 2)
- `root` (or another admin account) available once, to create the `lavoro_app` and `lavoro_provisioner` accounts in Task 2
- A full database backup before running the one-time deployment (Task 27)

**The account setup in Task 2 is scripted** (`scripts/tenancy/setup-mysql.sh`, `verify-mysql.sh`, `teardown-mysql.sh`). Read Task 2 before running anything: the scripts are the same statements written out there, and `--dry-run` prints them without touching the server or needing root.

---

## What was built differently, and why

Written after the first production install. The plan below is the design; these
are the points where reality argued back and won. Each one cost hours to find,
because in every case the thing that was wrong looked exactly like the thing
that was right.

**A namespace-confined account cannot hand out grants.** The plan assumed
`WITH GRANT OPTION` on `lavoro\_tenant\_%` would let the provisioner give each
tenant's login rights on its own database. It does not: MySQL and MariaDB weigh a
`GRANT` naming one database against an entry matching that name *exactly*, never
against a wildcard. Creating `lavoro_tenant_acme` works; granting on it fails
with 1044. The only sufficient grant is privileges on every database. Instead, a
stored procedure in `lavoro_admin` runs as root and refuses any name outside the
namespace; the provisioner holds nothing there but permission to call it. See
Task 2.

**The landlord panel is a path, not a subdomain.** `/beheer` on the app's own
host: one certificate, one vhost, nothing to arrange at install time. The
separation that matters lives in the middleware, which strips tenancy from those
routes, not in DNS.

**The installation is scripted, not typed.** `setup-mysql.sh`, `setup-env.sh`,
`setup-workers.sh`, `setup-sudoers.sh` and `setup-test-db.sh` replace the manual
steps this plan describes. Every one of them exists because a hand-typed step was
got wrong once: a socket path that differs per distribution, a port that is not
3306, a php binary that is a symlink, unit files naming an account that does not
run the site.

**Everything that can fail quietly now has a check beside it.** `Artisan::call`
returns an exit code nobody reads, so seeding failed silently and produced
tenants with one role instead of ten. A web server that cannot write
`storage/logs` discards every error. A worker keeps the code and `.env` it
started with. `tenancy:doctor` checks all three, and a new mechanism without a
check is not finished.

**Creating a tenant rolls itself back.** A failure used to leave a row, a
database, or a login behind, and the debris changed the error the next attempt
produced. Cleanup works from the id the call generated -- never from the database
name, which once deleted a healthy tenant of the same name.

---

## Task 1: Install the stancl/tenancy package

**Files:** `composer.json`, `config/tenancy.php` (published, replaced in Task 3), `app/Providers/TenancyServiceProvider.php` (published, replaced in Task 11)

### Task 1, Step 1: Add the package

```bash
composer require stancl/tenancy:"^3.0"
```

If composer reports a Laravel 12 conflict, check the package's GitHub releases for the newest compatible tag and require that instead.

### Task 1, Step 2: Run the installer

```bash
php artisan tenancy:install
```

This publishes `config/tenancy.php`, `app/Providers/TenancyServiceProvider.php`, and a stub tenants migration. We replace all three.

### Task 1, Step 3: Delete the stub tenants migration (we write our own in Task 6)

```bash
rm database/migrations/*_create_tenants_table.php 2>/dev/null; true
```

### Task 1, Step 4: Commit

```bash
git add composer.json composer.lock config/tenancy.php app/Providers/TenancyServiceProvider.php
git commit -m "chore: install stancl/tenancy package"
```

---

## Task 2: Database connections and the three MySQL identities

The default `mysql` connection gets switched to the tenant's database on every tenant request. We add a `central` connection that is never switched, and a `provisioner` connection used only for creating and destroying tenants.

**Three separate MySQL identities, each with the least privilege it can do its job with:**

| Identity | Authenticates by | Can reach | Used by |
| --- | --- | --- | --- |
| `lavoro_app@127.0.0.1` | password in `.env` | **only** `lavoro_landlord` (central) | the web app and queue workers |
| `lavoro_provisioner@localhost` | **no password** — Unix socket | all `lavoro_tenant_%` databases and `lavoro_landlord`, plus `CREATE USER` | `tenant:create` / `tenant:delete`, run from the CLI as a specific Linux user |
| `lavoro_tenant_<id>@%` | generated password, stored encrypted on the tenant row | **only that tenant's own database** | tenant requests, via the per-tenant connection |

Why it is split three ways:

- **The web app can no longer reach any tenant database with its own credentials.** Tenant queries authenticate as that tenant's user. A bug that fails to switch tenant context now hits a MySQL permission error instead of returning another customer's data — the isolation stops depending on the application being correct.
- **The privileged account has no password to steal.** MySQL's `auth_socket` plugin authenticates by *operating-system user identity* over the local socket. Only the Linux user named `lavoro_provisioner` can use it. There is no secret in `.env`, no secret on disk. The web server runs as `www-data`, so even a fully compromised app cannot create or drop databases or users.
- **Per-tenant credentials are low value.** Each opens exactly one customer's database and (per the package's default grant list) cannot create, drop, or grant anything.

**Requires MySQL on the same machine as the app** — `auth_socket` works over the Unix socket only. Verified present at `/var/run/mysqld/mysqld.sock`. If the database ever moves to its own host, this task needs revisiting (client certificates or a root-only credentials file).

**Files:** `config/database.php`, `.env`, `.env.example`, `scripts/tenancy/{lib,setup-mysql,verify-mysql,teardown-mysql}.sh`

### The scripts

Steps 1–3 below are automated by three scripts in `scripts/tenancy/`. The SQL is still written out in full underneath each step, because you should be able to read what the scripts do and check it against the server by hand — but do not type it in twice.

```bash
sudo scripts/tenancy/setup-mysql.sh              # creates everything in Steps 1 and 2
sudo scripts/tenancy/verify-mysql.sh             # asserts Step 3
```

Useful flags:

| Flag | Effect |
| --- | --- |
| `--dry-run` | Prints the SQL and changes nothing. Needs no root and no running server. |
| `--flavour=mysql\|mariadb` | Skips detection. Lets you review the *other* server's SQL from this machine. |
| `--with-test` | Also creates the Task 30 test account and `lavoro_test_landlord`. |
| `--write-env` | Patches `.env` with the resulting credentials, backing it up first. |

> **This bit the first production install.** `setup-mysql.sh` used to emit `DB_SOCKET=/var/run/mysqld/mysqld.sock` — the collision described in Step 4 below, where the stock `mysql` connection reads that variable and moves every tenant request onto the socket. Because `lavoro_app` exists only on `127.0.0.1`, MySQL then sees the account as `localhost` and `migrate` fails with `Access denied` naming a host that appears nowhere in `.env`. The script now writes `DB_PROVISIONER_SOCKET` instead and deletes `DB_SOCKET` if it finds it, and `tenancy:doctor` tests the template connection so the same mistake reports itself instead of surfacing as an unexplained denial.
| `--rotate-app-password` | Re-runs are otherwise non-destructive and leave an existing password alone. |
| `--generate-password` | Skip the prompt and generate the `lavoro_app` password. |
| `--admin-user=`, `--defaults-file=` | Connect as something other than socket-authenticated `root`. |

**Two passwords, both prompted rather than assumed.** You are asked for the `lavoro_app` password, with Enter generating a 32-character one. And if the admin account cannot connect over the socket without a password — true on plenty of servers, just not on stock Ubuntu — you are prompted for that too, instead of failing with a bare `Access denied`.

Neither is ever accepted as a command-line argument, because that puts it in shell history and in `ps` output for every user on the box. For unattended runs, set `LAVORO_APP_PASSWORD` and `ADMIN_PASSWORD` in the environment, or point `--defaults-file` at a `0600` `my.cnf`; with no terminal and no environment variable the app password is generated.

A typed password may not contain `'`, `"`, `\`, `` ` `` or `$`. Those five carry meaning in the MySQL statement and in the `.env` file, and refusing them is more honest than escaping for two grammars and getting one wrong. All other punctuation is accepted.

The test account's password stays the fixed, weak `lavoro_test`: `phpunit.xml` hardcodes the same value (Task 30), the account is granted only on `` `lavoro\_test\_%` ``, and prompting would mean editing `phpunit.xml` on every machine and in CI.

**The scripts detect MySQL versus MariaDB, because the two differ in ways that break a copy-pasted script.** MySQL 8 names the plugin `auth_socket` and selects it with `IDENTIFIED WITH`; MariaDB names it `unix_socket` and selects it with `IDENTIFIED VIA`, and installs it from a different SONAME. MariaDB also ships `mariadb` as the client binary and may not provide `mysql` at all. Detection reads `VERSION()` and `@@version_comment` — MariaDB always identifies itself in one of them. Development here is MySQL 8.0.46; production may not be, which is exactly why this is detected rather than assumed.

`teardown-mysql.sh` drops the landlord database, every `lavoro_tenant_*` database and account, and the two accounts. It refuses to run unless `APP_ENV=local` and requires both `--yes-really` and typing `destroy` at a prompt. It exists so a local install can be rebuilt from scratch while iterating on this plan.

### Task 2, Step 1: Create the app user, restricted to the landlord database (run once as root, per environment)

```sql
CREATE USER IF NOT EXISTS 'lavoro_app'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON `lavoro\_landlord`.* TO 'lavoro_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Note this is **not** a wildcard grant. `lavoro_app` gets the landlord database and nothing else. The underscore is escaped for the same reason as in Step 2 — unescaped, `lavoro_landlord` is a *pattern* that would also match `lavoroXlandlord`.

### Task 2, Step 2: Create the provisioner — Linux user first, then a passwordless MySQL user bound to it

```bash
sudo adduser --system --group --no-create-home lavoro_provisioner
```

```sql
CREATE USER IF NOT EXISTS 'lavoro_provisioner'@'localhost' IDENTIFIED WITH auth_socket;
GRANT ALL PRIVILEGES ON `lavoro\_tenant\_%`.* TO 'lavoro_provisioner'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON `lavoro\_landlord`.* TO 'lavoro_provisioner'@'localhost';
GRANT CREATE USER ON *.* TO 'lavoro_provisioner'@'localhost';
-- plus lavoro_admin and the grant_tenant_access procedure; see below
FLUSH PRIVILEGES;
```

**Two grants, deliberately.** The wildcard one covers every tenant database. The landlord grant is by exact name and is needed because provisioning writes the tenant row itself: `RunsAsProvisioner` (Task 21) repoints the `central` connection at this account for the life of the command, so `tenant:create` inserts into `lavoro_landlord.tenants` as the provisioner, not as `lavoro_app`. It also lets this account create the landlord database in Task 27 Step 1 — in MySQL, `GRANT ALL ON db.*` includes the `CREATE` privilege at database level, which is what permits `CREATE DATABASE db`.

Do not drop the backslashes before the underscores in either grant. An unescaped `_` is a MySQL single-character wildcard, so `lavoro_tenant_%` would also match `lavoroXtenantY…` and `lavoro_landlord` would also match `lavoroXlandlord`, widening each grant beyond its namespace.

Note what is *not* covered: a database that is neither the landlord nor a tenant — a pre-tenancy install, another app's schema — falls outside both patterns, so this account cannot read or drop it. That is what makes the pre-cutover database safe from provisioning mistakes rather than merely untouched by convention.

`CREATE USER` must be granted at `*.*` — MySQL does not accept it scoped to a database pattern.

**The grant this account cannot make, and what to do about it.** Each tenant gets its own MySQL login restricted to its own database, and creating that login is the provisioner's job. `WITH GRANT OPTION` on the wildcard was supposed to cover it. It does not, and this cost a full day on the first production install.

When a `GRANT` names one database, MySQL and MariaDB check the grantor's privileges for that name against an entry matching it *exactly*; the wildcard row is consulted for ordinary access only. So the provisioner can `CREATE DATABASE lavoro_tenant_acme` and then cannot grant anything on it — `ERROR 1044`, naming a database it demonstrably has rights to. Adding `GRANT USAGE ON *.* … WITH GRANT OPTION` does not help: the privileges being handed out must satisfy the same exact-name check, so the only sufficient grant is privileges on every database. That is the one thing this account must never have, and it is the whole point of the namespace.

The way out is a stored procedure that runs as its creator:

```sql
CREATE DATABASE IF NOT EXISTS `lavoro_admin`;

CREATE PROCEDURE `lavoro_admin`.`grant_tenant_access`(IN tenant_db VARCHAR(64), IN tenant_user VARCHAR(64))
    SQL SECURITY DEFINER
BEGIN
    IF tenant_db NOT LIKE 'lavoro\_tenant\_%'
        OR tenant_db REGEXP '[^a-zA-Z0-9_]'
        OR tenant_user REGEXP '[^a-zA-Z0-9_]' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outside the tenant namespace';
    END IF;
    -- builds and runs the GRANT for tenant_db to tenant_user
END;

GRANT EXECUTE ON PROCEDURE `lavoro_admin`.`grant_tenant_access` TO 'lavoro_provisioner'@'localhost';
```

Created by root, so it runs with root's authority — the `sudo` pattern, or `passwd` editing a file its caller may not touch. It refuses any name outside the namespace, so "runs as root" is bounded to exactly the tenant databases.

**It lives in its own database on purpose.** The provisioner holds `ALL PRIVILEGES` on `lavoro_landlord` and on every tenant database, and that includes dropping and recreating routines. In `lavoro_admin` it holds one thing: permission to call this procedure. It cannot rewrite the check that constrains it. (Replacing the procedure would not hand it root either — recreating a routine with a different definer needs a privilege it lacks — but a guard rail the guarded account can rewrite is not a guard rail worth reasoning about.)

The honest limit: the provisioner chooses both arguments, so it can arrange access for any login on any database inside the namespace, not solely the one it just created. That is not an escalation — it already has full access to every tenant database directly — but the guarantee is "nothing outside `lavoro_tenant_%`", not "only this one tenant".

`verify-mysql.sh` tests the procedure from both sides: that it grants for a tenant database, and that it refuses the landlord database. That procedure is the single deliberate opening in the confinement, so it is the one thing that must never widen unnoticed.

If `auth_socket` is unavailable, install it once: `INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';` (on MySQL 8 the plugin may be named `auth_socket` or `unix_socket` depending on the build).

### Task 2, Step 2b: Let the operator's own account become the provisioner

Provisioning commands elevate themselves (Task 21), so the operator runs
`php artisan tenant:create …` and the command re-execs under the provisioner.
That needs one rule, naming a real person's account:

```
# /etc/sudoers.d/lavoro-admin
<admin-user> ALL=(lavoro_provisioner) NOPASSWD: /usr/bin/php
```

**This is a separate file from `/etc/sudoers.d/lavoro-deploy` (Task 39), and the
two must not be merged.** The deploy rule is scoped to `/usr/bin/mysqldump` on
purpose: an unattended deploy may take backups and may not create databases or
users. This rule is the opposite — `NOPASSWD` on the PHP binary is a grant of
everything, because PHP can exec anything, so it is `sudo -u lavoro_provisioner`
with the typing removed and nothing less than that.

That is defensible for a named human, who could already run
`sudo -u lavoro_provisioner` at will and who — per the threat model above —
can already decrypt every tenant password from `APP_KEY` and the landlord
database. It is **not** defensible for the deploy user or for `www-data`, and
neither belongs in this file. Adding either turns "provisioning is impossible
from a web request" into a false statement, and nothing in the application will
object: `RunsAsProvisioner` elevates for whoever holds the rule.

Skipping this step entirely is a supported choice. Without it the elevation
finds no passwordless path, falls back to the error message it prints today, and
the operator types `sudo -u lavoro_provisioner` as before.

### Task 2, Step 3: Verify the identities behave as intended

```bash
sudo scripts/tenancy/verify-mysql.sh
```

It exits non-zero on any failure, so it can gate a deploy. The assertions, each of which is a claim the isolation depends on:

| Assertion | Why it matters |
| --- | --- |
| The socket plugin is loaded | Without it the provisioner account cannot authenticate at all |
| `lavoro_provisioner` authenticates as itself over the socket, with no password | Proves the privilege is tied to OS identity |
| The same account is **unreachable over TCP** | Proves there is no password to steal or copy |
| It can create a database inside `lavoro_tenant_*` | Tenant creation will work |
| It **cannot** create one whose name lacks that prefix | It really can only touch tenant databases, nothing else on the server |
| `lavoro_app` sees only the landlord database | The web app cannot read customer data with its own credentials |
| `lavoro_app` cannot create databases | A compromised app cannot provision |
| Every existing tenant account holds no `*.*` grant and no `GRANT OPTION` | Per-tenant credentials stay low-value |

**A skipped check is never reported as a pass.** A check like "cannot see X" passes automatically when the connection failed, so the script confirms the connection first and skips the rest with a visible `SKIP` if it can't. A run against a server where nothing exists yet reports failures and skips — never a clean sheet. That distinction is the whole value of the script over eyeballing the output of four `mysql` commands.

Run it again after the first `tenant:create` (Task 21), when the tenant-account assertions have something to check.

### Task 2, Step 4: Point `.env` at the central database and the app user

```
DB_CONNECTION=mysql
DB_DATABASE=lavoro_landlord
DB_USERNAME=lavoro_app
DB_PASSWORD=<strong-password>
DB_PROVISIONER_SOCKET=/var/run/mysqld/mysqld.sock
```

Mirror the non-secret keys into `.env.example`. `DB_DATABASE` now names the **central** database.

**Do not call that key `DB_SOCKET`.** Laravel's stock `mysql` connection already reads it — `config/database.php:53` is `'unix_socket' => env('DB_SOCKET', '')` — and `MySqlConnector::hasSocket()` is `isset($config['unix_socket']) && ! empty($config['unix_socket'])`, checked *before* host and port are looked at (`getDsn`, `MySqlConnector:45-61`). So setting `DB_SOCKET` silently moves the default `mysql` connection — the one every tenant request runs on — from TCP onto the socket.

That fails in the least helpful way available. Over a socket MySQL sees the client host as `localhost`, and Step 1 created the app account as `'lavoro_app'@'127.0.0.1'`, which does not match — so anything using the default connection *outside* tenant context dies with `Access denied`. Tenant users are created `@%`, which does match localhost, so the same queries succeed *inside* tenant context. An intermittent access-denied that depends on whether a tenant happens to be active is a bad afternoon.

A separate key avoids the collision outright and says what it is for. Nothing but the provisioner connection should ever read it.

If you would rather keep `DB_SOCKET` (a shared `.env` with other tooling, say), the alternative is to add `'unix_socket' => ''` to the `mysql` connection block as well, exactly as the `central` block below already does — but then the thing keeping you safe is a line that looks like boilerplate. The next person to regenerate `config/database.php` from a Laravel skeleton deletes it without noticing. Prefer the distinct key.

### Task 2, Step 5: Add the `central` and `provisioner` connections after the `mysql` block

```php
'central' => [
    'driver'      => 'mysql',
    'url'         => env('DB_URL'),
    'host'        => env('DB_HOST', '127.0.0.1'),
    'port'        => env('DB_PORT', '3306'),
    'database'    => env('DB_DATABASE', 'lavoro_landlord'),
    'username'    => env('DB_USERNAME', 'lavoro_app'),
    'password'    => env('DB_PASSWORD', ''),
    'unix_socket' => '',
    'charset'     => env('DB_CHARSET', 'utf8mb4'),
    'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'      => '',
    'prefix_indexes' => true,
    'strict'      => true,
    'engine'      => null,
    'options'     => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
],

// Used only by tenant:create / tenant:delete / tenant:provision-db-user, which run
// as the lavoro_provisioner Linux user — RunsAsProvisioner elevates them there.
// Authenticates by OS user over the Unix socket — deliberately has no password.
'provisioner' => [
    'driver'      => 'mysql',
    'host'        => null,
    'port'        => null,
    'database'    => env('DB_DATABASE', 'lavoro_landlord'),
    'username'    => 'lavoro_provisioner',
    'password'    => '',
    'unix_socket' => env('DB_PROVISIONER_SOCKET', '/var/run/mysqld/mysqld.sock'),
    'charset'     => env('DB_CHARSET', 'utf8mb4'),
    'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'      => '',
    'prefix_indexes' => true,
    'strict'      => true,
    'engine'      => null,
    'options'     => [],
],
```

A TCP connection here would authenticate as `lavoro_provisioner@127.0.0.1`, a *different* MySQL account that does not exist, and would fail. What forces the socket is **the non-empty `unix_socket` value alone** — `MySqlConnector::hasSocket()` looks at nothing else, and `host`/`port` are never consulted once it returns true. They are `null` here for honesty rather than for effect: leaving a host in place would read as though TCP were a possibility.

The corollary is the one that bites. A connection with a host and an *empty* `unix_socket` silently uses TCP, which is why the `central` block above sets `'unix_socket' => ''` explicitly instead of omitting the key and inheriting `env('DB_SOCKET', '')` from a skeleton regeneration.

### Task 2, Step 6: Commit

```bash
git add config/database.php .env.example scripts/tenancy/
git commit -m "feat(tenancy): add central and provisioner database connections"
```

---

## Task 3: Replace `config/tenancy.php`

Four bootstrappers run when a tenant is initialized. Two come from the package and two are ours:

| Bootstrapper | Source | What it does |
| --- | --- | --- |
| `DatabaseTenancyBootstrapper` | package | switches the database connection |
| `QueueTenancyBootstrapper` | package | tags queued jobs with the tenant |
| `PrefixCacheBootstrapper` | ours (Task 10) | prefixes cache keys per tenant |
| `TenantStorageBootstrapper` | ours (Task 14) | repoints the `public` and `local` disk roots |

The package has its own cache and filesystem bootstrappers. Neither is used, for a different reason each.

Its cache bootstrapper isolates tenants using cache *tags*, and the `database` cache store does not support tags at all.

Its `FilesystemTenancyBootstrapper` moves the whole storage tree by suffixing `storage_path()`, which would give every tenant its own logs and compiled views — see Task 14 Step 1 for why that is unwanted. Ours repoints two disk roots and nothing else, and carries a different name so the two are never mistaken for each other.

**The `mysql` manager is `PermissionControlledMySQLDatabaseManager`** (namespace `Stancl\Tenancy\TenantDatabaseManagers\`, verified against the v3.10 source — note it is *not* under `Database\Drivers`, which does not exist). It extends the plain `MySQLDatabaseManager` and additionally implements `ManagesDatabaseUsers`, so creating a tenant also creates a MySQL user scoped to that tenant's database, and deleting a tenant drops it. Its default grant list is data-manipulation only:

```
ALTER, ALTER ROUTINE, CREATE, CREATE ROUTINE, CREATE TEMPORARY TABLES, CREATE VIEW,
DELETE, DROP, EVENT, EXECUTE, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT,
SHOW VIEW, TRIGGER, UPDATE
```

Those apply *within the tenant's own database only* — no `CREATE USER`, no `GRANT OPTION`, no ability to reach another database. `DROP` here means dropping tables inside its own database, which migrations need; it does not permit `DROP DATABASE`. Override `PermissionControlledMySQLDatabaseManager::$grants` in a service provider if you ever want to narrow it further.

The `env('TENANCY_MYSQL_MANAGER', ...)` indirection exists so the test suite can fall back to the plain `MySQLDatabaseManager` (Task 30) — creating a MySQL user per test run would require granting the test account `CREATE USER`, which would widen exactly the privilege that task works to keep narrow.

Two things about the tenant connection, verified against the v3.10 source, that the rest of the plan depends on:

- `DatabaseManager::connectToTenant()` calls `setDefaultConnection('tenant')` — the tenant connection name is **hardcoded to `tenant`** in v3 and is not configurable. Anywhere you need the tenant connection by name (notably the test transactions in Task 30), it is `'tenant'`, never `'mysql'`.
- `RevertToCentralContext` sets the default connection back to `tenancy.database.central_connection`, i.e. `central`. So outside tenancy the default connection is `central`, not `mysql`; both point at the same database (Task 2), so this is invisible in practice.

Note there is **no** `queue.connections.database.central` flag set in Task 9. That is intentional: `QueueTenancyBootstrapper::getPayload()` returns an empty payload for connections marked `central`, which would strip the `tenant_id` from every job and break tenant-aware queued work. Pinning the queue *tables* to the central database (Task 9) is a different thing from marking the queue connection `central`.

**Files:** `config/tenancy.php`

### Task 3, Step 1: Replace the entire file

```php
<?php

use App\Tenancy\PrefixCacheBootstrapper;
use App\Tenancy\TenantStorageBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

return [
    'tenant_model' => App\Models\Tenant::class,

    'central_domains' => [],

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        PrefixCacheBootstrapper::class,
        TenantStorageBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => env('DB_CONNECTION', 'mysql'),
        'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_tenant_'),
        'suffix' => '',
        'managers' => [
            'mysql'   => env('TENANCY_MYSQL_MANAGER', Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class),
            'mariadb' => env('TENANCY_MYSQL_MANAGER', Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class),
            'pgsql'   => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
        ],
    ],

    'features' => [],

    'migration_parameters' => [
        '--force'    => true,
        '--path'     => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\Seeders\TenantDatabaseSeeder',
    ],
];
```

### Task 3, Step 2: Commit

```bash
git add config/tenancy.php
git commit -m "feat(tenancy): configure bootstrappers and migration path"
```

---

## Task 4: Create the `Tenant` model

Represents one client company; lives in the central database. The MySQL database name is stored in the JSON `data` column under `tenancy_db_name`. Its subscription — `package_key`, `extra_field_seats`, `extra_office_seats`, `modules`, `price_override_cents`, `storage_limit_gb` — is stored as real columns (declared as custom columns so stancl does not fold them into `data`). Module gating on the backend is `tenancy()->tenant->hasModule('...')`. Price and seat *computation* live in the `TenantSubscription` service (Task 16), not on the model — the model only holds the raw subscription data.

**The tenant's own MySQL credentials are also real columns.** `PermissionControlledMySQLDatabaseManager` (Task 3) generates a username and password per tenant and stores them via stancl's internal keys, which map to the attributes `tenancy_db_username` and `tenancy_db_password`. Declaring them in `getCustomColumns()` promotes them from the `data` JSON blob to real columns, which is what makes the `encrypted` cast usable — the password is then written to disk as ciphertext and decrypted transparently by `getPassword()` when the tenant connection is built. Anyone reading the central database directly sees ciphertext, not a working credential.

Note the cast requires `APP_KEY` to be stable: rotating it without re-encrypting makes every tenant's stored password unreadable and every tenant database unreachable. Treat `APP_KEY` as a backup-critical secret from here on.

**Files:** `app/Models/Tenant.php`, `tests/Feature/TenantModelTest.php`

**Interfaces:**
- Produces: `Tenant` (central-connection Eloquent model) with integer-cast columns `extra_field_seats`, `extra_office_seats`, `price_override_cents` (nullable), `storage_limit_gb`, array-cast `modules`, and `hasModule(string): bool`.

### Task 4, Step 1: Write the failing test

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\TestCase;

class TenantModelTest extends TestCase
{
    public function test_has_module_reads_the_modules_array(): void
    {
        $tenant = new Tenant(['modules' => ['quotes', 'snelstart']]);

        $this->assertTrue($tenant->hasModule('quotes'));
        $this->assertFalse($tenant->hasModule('invoices'));
    }

    public function test_has_module_is_false_when_modules_is_null(): void
    {
        $tenant = new Tenant();

        $this->assertFalse($tenant->hasModule('quotes'));
    }
}
```

### Task 4, Step 2: Run the test to verify it fails

Run: `php artisan test --filter=TenantModelTest`
Expected: FAIL — `Class "App\Models\Tenant" not found`.

### Task 4, Step 3: Create the model

```php
<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $connection = 'central';

    protected $casts = [
        'data'                 => 'array',
        'modules'              => 'array',
        'extra_field_seats'    => 'integer',
        'extra_office_seats'   => 'integer',
        'price_override_cents' => 'integer',
        'storage_limit_gb'     => 'integer',
        'tenancy_db_password'  => 'encrypted',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'package_key',
            'extra_field_seats',
            'extra_office_seats',
            'modules',
            'price_override_cents',
            'storage_limit_gb',
            'tenancy_db_username',
            'tenancy_db_password',
        ];
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
}
```

### Task 4, Step 4: Run the test to verify it passes

Run: `php artisan test --filter=TenantModelTest`
Expected: PASS.

### Task 4, Step 5: Commit

```bash
git add app/Models/Tenant.php tests/Feature/TenantModelTest.php
git commit -m "feat(tenancy): add Tenant model with package, seats, modules and storage"
```

---

## Task 5: Create the `UserTenantLookup` model

A small central table: given an email, which tenant owns it. Queried only at login and password reset.

**Files:** `app/Models/Central/UserTenantLookup.php`

### Task 5, Step 1: Create the file

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class UserTenantLookup extends Model
{
    protected $connection = 'central';
    protected $table = 'user_tenant_lookups';
    protected $primaryKey = 'email';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['email', 'tenant_id'];
}
```

### Task 5, Step 2: Commit

```bash
git add app/Models/Central/UserTenantLookup.php
git commit -m "feat(tenancy): add UserTenantLookup model"
```

---

## Task 6: Create central database migrations

All target the `central` connection. The `user_tenant_lookups.email` primary key is what enforces global email uniqueness across tenants. The catalogue tables (`packages`, `modules`, `module_bundles`, `pricing_settings`) hold the price list and are seeded in the same migration that creates them.

**Generate every migration in this plan with `make:migration`, never by hand.** Artisan timestamps them at the moment you run it, which is by definition after every migration already in the tree. A filename written into a plan is stale the day someone merges a feature branch — this plan carried `2026_07_21_*` for a month and three later migrations quietly overtook it, which would have run the central schema in the wrong order. Nothing downstream depends on the names: Task 8 sorts central from tenant by reading the file contents, not the filename.

```bash
php artisan make:migration create_tenants_table
php artisan make:migration create_user_tenant_lookups_table
php artisan make:migration create_licensing_catalogue_tables
```

Run them **in that order and one at a time** — `user_tenant_lookups` carries a foreign key to `tenants`, so it must sort after it. Artisan's timestamp has one-second resolution, so three commands on one line can land in the same second; the filenames then sort alphabetically, which happens to be right here but is not something to rely on. Confirm before migrating:

```bash
ls database/migrations/*.php | tail -3
```

**Files:** three new central migrations (names generated above), all with `protected $connection = 'central';`

**Interfaces:**
- Produces: central tables `tenants` (columns `id`, `name`, `package_key`, `extra_field_seats`, `extra_office_seats`, `modules`, `price_override_cents`, `storage_limit_gb`, `data`, timestamps), `packages`, `modules`, `module_bundles`, `pricing_settings` — all seeded with the price list below.

### Task 6, Step 1: Create the tenants migration

`storage_limit_gb` defaults to 50 (the included allowance). `package_key` is nullable at the column level so `tenant:setup-existing` (Task 26) can insert a row before the package is assigned; `tenant:create` (Task 21) always sets it.

`tenancy_db_username` and `tenancy_db_password` hold the tenant's own MySQL credentials (Task 4). They are nullable because a tenant registered from an existing database has none until `tenant:setup-existing` (Task 26) creates one. `tenancy_db_password` is `text` rather than `string` because the `encrypted` cast stores ciphertext, which is substantially longer than the plaintext password.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('package_key')->nullable();
            $table->unsignedInteger('extra_field_seats')->default(0);
            $table->unsignedInteger('extra_office_seats')->default(0);
            $table->json('modules')->nullable();
            $table->unsignedInteger('price_override_cents')->nullable();
            $table->unsignedInteger('storage_limit_gb')->default(50);
            $table->string('tenancy_db_username')->nullable();
            $table->text('tenancy_db_password')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
```

### Task 6, Step 2: Create the user_tenant_lookups migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('user_tenant_lookups', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('user_tenant_lookups');
    }
};
```

### Task 6, Step 3: Create the licensing-catalogue migration (create + seed)

Four tables, seeded inline with the price list. All money is integer cents. The free feature toggles are `modules` rows at `price_cents = 0`, so there is one module list and one `hasModule()` check. `included_storage_gb` and `storage_extra_per_gb_cents` are the two storage scalars.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('field_seats');
            $table->unsignedInteger('office_seats');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('extra_field_cents');
            $table->unsignedInteger('extra_office_cents');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('module_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('module_keys');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        Schema::connection('central')->create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('value');
            $table->timestamps();
        });

        $now = now();

        DB::connection('central')->table('packages')->insert([
            ['key' => 'starter',    'name' => 'Starter',    'field_seats' => 1,  'office_seats' => 1, 'price_cents' => 2750,  'extra_field_cents' => 1200, 'extra_office_cents' => 800, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'team',       'name' => 'Team',       'field_seats' => 5,  'office_seats' => 2, 'price_cents' => 8750,  'extra_field_cents' => 1100, 'extra_office_cents' => 750, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'business',   'name' => 'Business',   'field_seats' => 10, 'office_seats' => 4, 'price_cents' => 16000, 'extra_field_cents' => 1000, 'extra_office_cents' => 700, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'field_seats' => 15, 'office_seats' => 6, 'price_cents' => 23000, 'extra_field_cents' => 950,  'extra_office_cents' => 650, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('modules')->insert([
            ['key' => 'quotes',            'name' => 'Offertes',          'price_cents' => 2750, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'invoices',          'name' => 'Facturen',          'price_cents' => 2750, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'snelstart',         'name' => 'SnelStart',         'price_cents' => 0,    'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'google_calendar',   'name' => 'Google Agenda',     'price_cents' => 0,    'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'location_tracking', 'name' => 'Locatie volgen',    'price_cents' => 0,    'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'assistant',         'name' => 'AI-assistent',      'price_cents' => 2250, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('module_bundles')->insert([
            ['name' => 'Offertes + Facturen', 'module_keys' => json_encode(['quotes', 'invoices']), 'price_cents' => 4000, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::connection('central')->table('pricing_settings')->insert([
            ['key' => 'included_storage_gb',        'value' => 50,        'created_at' => $now, 'updated_at' => $now],
            ['key' => 'storage_extra_per_gb_cents', 'value' => 50,        'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ai_allowance_micros',        'value' => 12_500_000, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('pricing_settings');
        Schema::connection('central')->dropIfExists('module_bundles');
        Schema::connection('central')->dropIfExists('modules');
        Schema::connection('central')->dropIfExists('packages');
    }
};
```

### Task 6, Step 4: Run the central migrations and confirm the catalogue seeded

Run: `php artisan migrate --database=central --path=database/migrations --realpath` (or the full `php artisan migrate` once the split in Task 8 is done).
Expected: `packages` has 4 rows, `modules` has 6, `module_bundles` has 1, `pricing_settings` has 3.

**Storingen and Projecten are deliberately not in this list.** They are part of the
product rather than things to subscribe to, and a module row for either would be
actively harmful:
`tenant:create` (Task 21) defaults `--modules` to none, so every new tenant would
start with those screens 403-ing and missing from the menu until somebody remembered a
second command. A gate that everyone must pass is a gate that only ever fails by
accident.

The test to apply before adding any row here: **would a tenant created with no modules
at all be broken without it?** If yes, it is stock and does not belong in this table.
If it ever becomes an upsell, it is one `INSERT` and one `Route::middleware(...)->group()`
— Task 31 makes that a one-line addition per route group, not new plumbing.

`assistant` is the only module here with a non-zero price that is not a feature we have yet to build. It is priced because it costs us money every time it is used: **€22,50** charged against the **€12,50** spend ceiling Task 39 puts behind it, so the margin floor is knowable rather than estimated. `ai_allowance_micros` is that ceiling's default — `12_500_000`, in millionths of a euro — and `tenants.ai_allowance_micros` overrides it per tenant.

Two things follow from those numbers and both belong here rather than in Task 39, because they are pricing decisions rather than implementation ones.

It is the dearest module in the catalogue by a distance — €22,50 against €27,50 for the entire Starter package — so for a one-or-two-person tenant it very nearly doubles the bill. That is the conversation sales will have rather than a technical problem.

The split is **€12,50 of allowance against a €10,00 margin floor**. That sells a generous limit rather than a wide margin, which is the right way round here: an assistant people stop using in week three is worth less than the margin it protects. At today's costs €12,50 is roughly 830 cached questions — enough that a normal tenant never thinks about it, which is the point of a flat fee.

Two consequences to accept knowingly rather than rediscover. The worst case is €12,50 of supplier spend per tenant per month. And the unmetered web-search cost in Task 39 now eats a real slice of the €10,00 floor rather than a token one.

### Task 6, Step 5: Commit

```bash
git add database/migrations/
git commit -m "feat(tenancy): add central DB migrations and licensing catalogue"
```

---

## Task 7: Move the `sessions` table into the central database

The session is read at the very start of every request, before we know the tenant. So the `sessions` table must be in the central database, and the session driver must be pointed at the `central` connection.

The `sessions` table is currently created inside the framework users migration. We remove it from there and create it as its own central migration. Note the central migration keeps `user_id` as a plain indexed column with **no foreign key** — users live in tenant databases.

**Files:**
- `database/migrations/0001_01_01_000000_create_users_table.php` (remove the sessions block)
- a new central sessions migration (`php artisan make:migration create_sessions_table`)
- `.env` / `.env.example`

### Task 7, Step 1: Remove the `sessions` block from the users migration

Open `database/migrations/0001_01_01_000000_create_users_table.php`. It creates three tables: `users`, `password_reset_tokens`, and `sessions`. Delete the entire `Schema::create('sessions', function (Blueprint $table) { ... });` block (and the matching `Schema::dropIfExists('sessions');` in `down()`). Leave `users` and `password_reset_tokens` intact — those belong to the tenant database.

### Task 7, Step 2: Create the central sessions migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sessions');
    }
};
```

### Task 7, Step 3: Point the session driver at the central connection

Add to `.env` and `.env.example`:

```
SESSION_CONNECTION=central
```

`config/session.php` already reads `'connection' => env('SESSION_CONNECTION')` (verified, line 76), so no config edit is needed. `SESSION_DRIVER=database` stays as-is.

### Task 7, Step 4: Commit

```bash
git add database/migrations/0001_01_01_000000_create_users_table.php \
        database/migrations/ \
        .env.example
git commit -m "feat(tenancy): move sessions table to central connection"
```

---

## Task 8: Split migrations into central and tenant directories

After this:
- `database/migrations/` holds only central migrations: cache, jobs, tenants, user_tenant_lookups, sessions, and the licensing catalogue. `php artisan migrate` runs these against the central database.
- `database/migrations/tenant/` holds everything else (about 244 files: the users migration plus every dated one). `php artisan tenants:migrate` runs these against each tenant database. Plain `migrate` does not descend into subdirectories, so these are correctly excluded from the central run.

`assistant_usage` moves the other way, but not here: it is a tenant migration today, and Task 39 replaces it with a central copy plus a tenant drop, *after* the rows have been carried across in the Task 27 window. Leave it in `tenant/` at this point — moving it early would drop the only record of what has been spent.

`0001_01_01_000000_create_users_table.php` (now just users + password_reset_tokens after Task 7) moves to tenant. The cache and jobs framework migrations, and every central migration from Tasks 6–7, stay put.

**Files:** move ~244 migration files (250 total after Tasks 6–7, of which 6 stay central). Counts drift with every feature branch — treat them as a sanity check, not a target.

### Task 8, Step 1: Move the files

**The rule is content, not filename.** Every central migration this plan adds declares `protected $connection = 'central';` — that is what makes it central, so that is what the split reads. The only central migrations *without* that declaration are the two framework ones (`cache`, `jobs`), which run against the default connection and are named identically in every Laravel install, so they are the sole hardcoded exception.

Matching on dates instead would mean maintaining a list of filenames that Artisan generates at implementation time, and a plan cannot know those. It would also silently misfile any migration merged from a feature branch while this work is in flight.

```bash
mkdir -p database/migrations/tenant

for f in database/migrations/*.php; do
  base=$(basename "$f")

  # Framework migrations for the shared cache and queue tables: central, but
  # they use the default connection rather than declaring one.
  if [[ "$base" == "0001_01_01_000001_create_cache_table.php" || \
        "$base" == "0001_01_01_000002_create_jobs_table.php" ]]; then
    continue
  fi

  # Anything that explicitly targets the central connection stays.
  if grep -q "connection = 'central'" "$f"; then
    continue
  fi

  git mv "$f" database/migrations/tenant/
done
```

### Task 8, Step 2: Verify

```bash
# Central: the two framework migrations plus the ones Tasks 6-7 created.
ls database/migrations/*.php

# Every remaining central migration must declare the connection (the two
# framework ones excepted) — anything else here is misfiled.
grep -L "connection = 'central'" database/migrations/*.php

ls database/migrations/tenant/ | wc -l   # ~244

# Nothing in tenant/ should claim the central connection.
grep -l "connection = 'central'" database/migrations/tenant/*.php   # expect no output
```

The two `grep` checks are the ones that matter. The file count only tells you something moved; these tell you the *right* things moved, and they keep working no matter what the migrations are called.

### Task 8, Step 3: Commit

```bash
git add database/migrations/
git commit -m "feat(tenancy): split migrations into central and tenant directories"
```

---

## Task 9: Pin the queue to the central database

Jobs must always be stored centrally so the worker finds them regardless of tenant context. The `QueueTenancyBootstrapper` records the active tenant in each job payload and re-initializes it on the worker, so queued jobs still run in the right tenant.

Everything queued today: Google sync (`app/Jobs/Google/*`), FCM notifications, the customer and supplier imports, `SendStandardEmailJob`, `BulkMoveServiceOrderStageJob`, `SendWebPushNotificationsJob` and `GeocodeMissingCoordinatesJob`.

All of them are dispatched from inside tenant context, so all of them are tagged, and none needs a manual `tenancy()->initialize()`.

`BulkMoveServiceOrderStageJob` is the one to keep an eye on. A bulk stage move of more than 40 orders goes to the queue, and its Eloquent saves fire signals whose listeners write to the database — so the job's tenant tag decides where an entire batch of audit rows lands.

The two newest are worth a second's thought each, and both come out fine. `SendWebPushNotificationsJob` reads `push_subscriptions` — a tenant table — and deletes rows the push service reports as gone, so it must resolve the tenant before it reads anything, which the tag does. `GeocodeMissingCoordinatesJob` writes coordinates back onto `customers`, likewise tenant. Task 20 adds two more (`assistant:prune` and `notifications:missing-times`), and those are the ones that *would* have run against the central database, because a schedule has no tenant to be tagged with.

**Files:** `config/queue.php`

### Task 9, Step 1: Set all three database references to `central`

```php
'database' => [
    'driver'       => 'database',
    'connection'   => 'central',
    'table'        => env('DB_QUEUE_TABLE', 'jobs'),
    'queue'        => env('DB_QUEUE', 'default'),
    'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => true,
],
```

```php
'batching' => [
    'database' => 'central',
    'table'    => 'job_batches',
],
```

```php
'failed' => [
    'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => 'central',
    'table'    => 'failed_jobs',
],
```

### Task 9, Step 2: Commit

```bash
git add config/queue.php
git commit -m "feat(tenancy): pin queue tables to central connection"
```

---

## Task 10: Pin the cache to central, then isolate it by prefix

The `database` cache store does not support tags, so the package's tag-based `CacheTenancyBootstrapper` cannot be used. Instead we set a per-tenant cache key prefix when a tenant is initialized and restore it when tenancy ends. The shared central `cache` table then holds all tenants' entries, isolated by prefix.

**That design does not work with the config as it stands, and the failure is total rather than subtle.** `config/cache.php:43` reads:

```php
'connection' => env('DB_CACHE_CONNECTION'),
```

`DB_CACHE_CONNECTION` is unset, so this is `null`. `CacheManager::createDatabaseDriver` does `$this->app['db']->connection($config['connection'] ?? null)`, and `DatabaseManager::connection(null)` resolves **the default connection**. Inside an initialized tenant the default connection is `tenant` (Task 3). The `cache` table lives only in the central database (Task 8 keeps the framework cache migration central). So every cache read and write inside a tenant request queries `lavoro_tenant_<id>.cache`, which does not exist, and the per-tenant MySQL user has no grant that could reach the real one either.

Line 207 of the same method does it again for locks, via `$config['lock_connection'] ?? $config['connection'] ?? null` — so `cache_locks` breaks identically.

**Setting a prefix does nothing about which database the store talks to.** The prefix and the connection are two independent decisions and this task needs both. Pinning the connection is what makes the shared table exist at all; the prefix is what keeps tenants out of each other's entries once it does.

What breaks without Step 1, verified against the tree today rather than imagined:

| Call site | What it is |
| --- | --- |
| `app/Domain/Tools/ConfirmationToken.php:71` | `Cache::add` — the assistant's **write gate**, the single-operation guard that stops one approval writing twice |
| `app/Services/Google/CalendarSyncService.php:20,70` | `Cache::lock(...)->block(...)` — the locks that stop a calendar event being pushed or pulled twice at once |
| `app/Services/SnelStartClient.php:26` | the cached SnelStart OAuth token |
| `app/Services/Geocoder.php:43-81` | remembered coordinates |
| `app/Jobs/GeocodeMissingCoordinatesJob.php:42` | the geocoding cooldown |

The first of those is the one to think about. `ConfirmationToken::claim()` is what makes "one approval, one write" true — its docblock says so, and it exists because three clicks once made three storingen. A cache that throws turns the assistant's whole write path into an error; a cache that is "fixed" by catching the exception turns the guard off while leaving it looking present.

**Files:** `config/cache.php`, `.env`, `.env.example`, `app/Tenancy/PrefixCacheBootstrapper.php`

### Task 10, Step 1: Pin the cache and its locks to the central connection

Same reasoning as Task 9's queue pinning, and the same fix — written into config rather than left to an env var nobody sets:

```php
'database' => [
    'driver'          => 'database',
    'connection'      => 'central',
    'table'           => env('DB_CACHE_TABLE', 'cache'),
    'lock_connection' => 'central',
    'lock_table'      => env('DB_CACHE_LOCK_TABLE'),
],
```

Hardcoded rather than `env('DB_CACHE_CONNECTION', 'central')`, deliberately. An env default is a value someone can override to something that breaks in a way no test catches, and there is no environment in which this should be anything other than `central` — including the test suite, where `phpunit.xml` (Task 30) points the central connection at `lavoro_test_landlord`.

**This makes `PrefixCacheBootstrapper` the only thing separating tenants in the cache.** That is the design, not a regression — but it changes the consequence of a bug in it from "cache misses" to "one tenant reads another's entries", and one of those entries is a SnelStart OAuth token (Task 32 makes those per-tenant). Treat Step 2 as isolation code, not as a convenience.

### Task 10, Step 2: Create the bootstrapper

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheBootstrapper implements TenancyBootstrapper
{
    protected ?string $original_prefix = null;

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->original_prefix = $this->app['config']->get('cache.prefix');
        $this->app['config']->set('cache.prefix', 'tenant_' . $tenant->getTenantKey());
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
    }

    public function revert(): void
    {
        $this->app['config']->set('cache.prefix', $this->original_prefix);
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
        $this->original_prefix = null;
    }
}
```

`forgetDriver` discards the resolved cache store so it is rebuilt with the new prefix on next use. The prefix is applied to every key by the store regardless of driver — this is what makes the approach driver-agnostic: it works unchanged on the `database` store today and on `redis` if you switch later.

**One cache entry is tenant-independent and now pays for the prefix.** `App\Services\Geocoder` does `Cache::forever` on an address string, keyed by its hash, to remember the coordinates Nominatim gave back. An address is the same place in every tenant, so prefixing that key means every tenant geocodes the same street from scratch — N times the calls to a free service that asks for at most one request per second, and whose usage policy is the only thing standing between us and a block. Two tenants in the same town is already enough to notice.

This does not need solving now, and deliberately is not: leaving it prefixed is *correct* and merely wasteful, whereas an unprefixed shared key needs deciding what happens when one tenant's cached miss becomes another's, and that is a question with no urgency behind it. What it needs is to be written down, so that when someone investigates why geocoding got slower after the fifth tenant they find this paragraph rather than the rate limiter. If it does become a problem, the fix is a second store — `cache.stores.shared`, on the `central` connection and without the prefix — and having `Geocoder` ask for that one by name.

### Task 10, Step 3: Know what `forgetDriver` does not reach

`forgetDriver` clears `CacheManager`'s resolved-store map. It cannot reach a store that something else has already been handed and kept.

One thing in the framework does exactly that: `RateLimiter` is a singleton built as `new RateLimiter($app->make('cache')->driver(config('cache.limiter')))` (`Illuminate\Cache\CacheServiceProvider:34-38`). It captures a `Repository` **once**, at first resolution, and holds it for the life of the container. A later prefix change does not reach it.

Under PHP-FPM this is harmless and needs nothing: each request gets a fresh container, tenancy is initialized by middleware priority *before* `ThrottleRequests` is ever resolved, and the limiter therefore captures a correctly-prefixed store every time. Write that down rather than relying on it silently, because the thing that makes it safe is a middleware ordering three tasks away.

Under a long-lived process — Octane, or anything that resolves `RateLimiter` before switching tenants — it is not harmless, and the failure is specific: `ThrottleRequests::resolveRequestSignature` keys on `$user->getAuthIdentifier()`, a bare per-tenant auto-increment id. With one shared bucket, **user 5 in tenant A and user 5 in tenant B throttle each other** — on the assistant routes above all, since those are the ones carrying `throttle:20,1`. If this application ever adopts Octane, `RateLimiter` needs an adapter alongside `MailerState` — implement `ForgetsTenantState`, tag it, and Task 11's registry clears it at both moments without either call site changing. Until then this paragraph is the record of why it is absent.

### Task 10, Step 4: Commit

```bash
git add config/cache.php .env.example app/Tenancy/PrefixCacheBootstrapper.php
git commit -m "feat(tenancy): pin cache to central and isolate entries by tenant prefix"
```

---

## Task 11: One registry of tenant-scoped state, and the `TenancyServiceProvider`

When a `Tenant` is created, the `TenantCreated` event triggers a job pipeline that creates the database, runs tenant migrations, then seeds. `BootstrapTenancy` / `RevertToCentralContext` are the package listeners that switch the connection in and out of tenant context.

**The container holds state that means something different in each tenant, and it has to be cleared at two different moments.** Those two moments are the reason this task starts with a registry rather than with the provider.

- `ActivityBuffer` folds repeat saves of one record into a single activity entry. It is keyed `subject_type|subject_id|action|actor_type` and holds the activity's **row id**. Those ids are tenant-scoped; the key is not — `App\Models\Customer|1|update|user` names a different row in every tenant.
- `Signals` holds the chain currently being dispatched, its correlation id, and the count of signals raised so far against a per-request budget (`MAX_PER_REQUEST = 1000`). A chain left half-open across a tenant switch mis-attributes correlation ids and spends one tenant's budget on another's work.
- `AssistantContext` holds a `User` **model instance** for the stretch during which the assistant is acting, and `BaseSignal` reads it to decide whether a fact was machine-made and on whose behalf. A `User` carried across a tenant switch is a row from the previous tenant's database being written into this one's audit trail as its actor.
- `TechnicianAvailability` caches a window of the diary — appointments and unavailability for ten or so people over a fortnight — so a planning question costs ten queries instead of several hundred. Answering "who is free next Tuesday" out of another company's diary is the loudest failure of the five.
- `MailManager`'s resolved mailers cache a transport built from the active tenant's Graph credentials (Task 32). A worker that mails for tenant A and then for tenant B sends the second through the first's mailbox.

The two moments are **between two queue jobs** and **on a tenancy switch**. They are not the same event and neither implies the other: a worker processes jobs for many tenants without a switch in between when consecutive jobs share a tenant, and a console command that loops tenants switches many times inside one job. Both need the same things cleared.

**So the list lives in exactly one place — the container — and both moments ask it the same question.** A class that carries tenant state implements one interface and is tagged; nothing else has to be edited, and there is no second list to keep in step with the first.

**Files:** `app/Support/ForgetsTenantState.php`, `app/Support/TenantState.php`, `app/Support/MailerState.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/TenancyServiceProvider.php`, `bootstrap/providers.php`, `tests/Feature/TenantStateTest.php`

**Interfaces:**
- Produces: `App\Support\ForgetsTenantState` — `forgetTenantState(): void`; `App\Support\TenantState::flush(): void`, which clears everything tagged with the interface name.

### Task 11, Step 1: Create the interface, the adapter and the flush

```php
<?php

namespace App\Support;

/**
 * Implemented by anything the container keeps alive across more than one unit of
 * work while it holds state belonging to one tenant.
 *
 * Implementing this is half the job: the class must also be tagged in
 * AppServiceProvider. TenantStateTest fails if the two disagree.
 */
interface ForgetsTenantState
{
    public function forgetTenantState(): void;
}
```

```php
<?php

namespace App\Support;

final class TenantState
{
    /**
     * Everything tagged with the interface name, cleared in one call.
     *
     * There is deliberately no list here. Anything that needs clearing says so by
     * implementing ForgetsTenantState, and the two callers — Queue::before in
     * AppServiceProvider and the tenancy listeners in TenancyServiceProvider —
     * both go through this method, so neither can drift from the other.
     */
    public static function flush(): void
    {
        foreach (app()->tagged(ForgetsTenantState::class) as $state) {
            $state->forgetTenantState();
        }
    }
}
```

`MailManager` is Laravel's and cannot implement the interface, so it gets a two-line adapter rather than an exception to the rule:

```php
<?php

namespace App\Support;

final class MailerState implements ForgetsTenantState
{
    public function forgetTenantState(): void
    {
        app('mail.manager')->forgetMailers();
    }
}
```

### Task 11, Step 2: Implement the interface on the four stateful singletons

Three of them are a rename, not an addition. `ActivityBuffer::reset()`,
`Signals::reset()` and `AssistantContext::reset()` have **exactly one caller each**
— the `Queue::before` block that Step 3 rewrites. Once the flush calls the
interface method, `reset()` has no callers left, so keeping it and adding a
delegate that forwards to it would leave a method whose only purpose is to be
forwarded to.

Rename it instead. `implements ForgetsTenantState`, and `reset()` becomes
`forgetTenantState()` with the body untouched:

```php
// App\Domain\Signals\ActivityBuffer
public function forgetTenantState(): void
{
    $this->entries = [];
}

// App\Domain\Signals\Signals
public function forgetTenantState(): void
{
    $this->chain = [];
    $this->raised = 0;
    $this->correlation_id = null;
}

// App\Domain\Assistant\AssistantContext
public function forgetTenantState(): void
{
    $this->on_behalf_of = null;
    $this->depth = 0;
}
```

Fix the docblock on `AssistantContext` while you are in it — it says the class is
"reset between queue jobs", which stops being the whole truth once a tenancy
switch clears it too.

**`TechnicianAvailability` is the one that keeps both methods, and it needs its
own explicit implementation** — it has no `reset()` to forward to, and pasting the
delegate shape above into it is a fatal:

```php
// App\Domain\Planning\TechnicianAvailability
public function forgetTenantState(): void
{
    $this->forget();
}
```

`forget()` stays because it is a domain operation with four real callers in
`EventObserver`: booking, moving or deleting an appointment invalidates the cached
window, so asking again does not double-book. That has nothing to do with tenancy
and must keep its own name. `forgetTenantState()` is the lifecycle hook. Collapsing
them would make a planning concern look like a lifecycle one and vice versa.

**Why there is no trait here.** Only one class ends up forwarding, so a trait would
have exactly one user. The other three do not share a body at all — each clears its
own fields, and the bodies above are three different pieces of code that merely
happen to have the same signature. A trait can share an implementation; it cannot
share the absence of one. Had the delegate survived on all four, a trait would have
been right. That it is not is the sign that the delegate itself was the mistake.

**Why the interface method is not simply called `reset()`**, which would have let
three classes satisfy it with no edit at all: a name that generic is satisfied *by
accident*. Any class with a `reset()` meaning something unrelated could be tagged
and would silently be called at every tenancy switch. `forgetTenantState()` cannot
be implemented by mistake, and the whole point of this registry is that it is hard
to get wrong. The rename costs three edits, all of them in code with one caller.

### Task 11, Step 3: Tag them in `AppServiceProvider::register()` and route `Queue::before` through the flush

Next to the existing `singleton()` / `scoped()` bindings:

```php
$this->app->tag([
    ActivityBuffer::class,
    Signals::class,
    AssistantContext::class,
    TechnicianAvailability::class,
    MailerState::class,
], ForgetsTenantState::class);
```

Then the existing `Queue::before` block loses its list:

```php
Queue::before(function () {
    /**
     * A job that restores its actor leaves that person signed in for the rest of
     * a long running worker, so the next job would inherit both their name and
     * their permissions. Every job starts with nobody.
     */
    Auth::forgetUser();

    TenantState::flush();
});
```

**`Auth::forgetUser()` stays here and does not move into the flush.** It is the one thing on this list that is treated differently, so it is worth saying why. "Start with nobody" is right for a queue job, which may follow a job that restored an actor. It is wrong for a tenancy switch: on a web request the tenancy middleware runs *before* the auth guard resolves a user, so forgetting is a no-op going in — but `tenancy()->end()` fires on the way out, and any future flow that initializes tenancy while somebody is authenticated would silently sign them out mid-request. Authentication is not tenant-scoped state; it is request-scoped state that happens to be cleared at one of the same moments.

Two things get cleared per queue job that were not before, both improvements rather than regressions. `TechnicianAvailability` was previously saved only by the scoped-binding lifecycle, which is real but incidental. And mailers are now forgotten between jobs, which is precisely the bug Task 32 Step 3 exists to fix — consolidating makes that fix stronger, not weaker.

### Task 11, Step 4: Replace `app/Providers/TenancyServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Support\TenantState;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Support\JobPipeline;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        Event::listen(TenancyInitialized::class, fn () => TenantState::flush());
        Event::listen(TenancyEnded::class, fn () => TenantState::flush());

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class, MigrateDatabase::class, SeedDatabase::class])
                ->send(fn (TenantCreated $event) => $event->tenant)
                ->shouldBeQueued(false)
                ->toListener()
        );
    }
}
```

Listening on both events double-flushes when switching straight from one tenant to another — `TenancyEnded` then `TenancyInitialized`. That is harmless and cheaper than reasoning about which of the two is guaranteed to fire in every path the package takes.

The case this protects is a **console command that loops tenants in one process**: `tenants:migrate`, `tenant:overview`, the per-tenant schedule dispatch in Task 20, any future backfill.

Without the reset, the second tenant's save merges into the activity id remembered from the first, and `RecordActivity::mergeInto()` then rewrites *that* tenant's row in *that* tenant's database. Rare, silent, and it corrupts an audit trail.

The web path is already safe — PHP-FPM gives each request a fresh container. Nothing else covers the console path.

### Task 11, Step 5: Register the provider in `bootstrap/providers.php`

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
];
```

### Task 11, Step 6: Make forgetting to tag a class fail a test, not production

The interface cannot enforce the tag, so a class can implement it and be silently skipped — the same class of bug as the two hand-maintained lists this task replaced, just smaller. Close it with a test that makes the container and the codebase agree:

```php
public function test_every_stateful_class_is_tagged(): void
{
    $tagged = collect(app()->tagged(ForgetsTenantState::class))
        ->map(fn ($state) => $state::class);

    $implementors = collect(\Symfony\Component\Finder\Finder::create()->files()->in(app_path())->name('*.php'))
        ->map(fn ($file) => 'App\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class) => class_exists($class)
            && is_subclass_of($class, ForgetsTenantState::class));

    $this->assertEmpty(
        $implementors->diff($tagged)->all(),
        'These implement ForgetsTenantState but are not tagged in AppServiceProvider.'
    );
}
```

Assert in that direction only. The reverse — tagged but not implementing — is already impossible to get wrong quietly, because `flush()` would fatal on the first tenancy switch.

### Task 11, Step 7: Commit

```bash
git add app/Support/ForgetsTenantState.php app/Support/TenantState.php app/Support/MailerState.php \
        app/Domain/Signals/ActivityBuffer.php app/Domain/Signals/Signals.php \
        app/Domain/Assistant/AssistantContext.php app/Domain/Planning/TechnicianAvailability.php \
        app/Providers/AppServiceProvider.php app/Providers/TenancyServiceProvider.php \
        bootstrap/providers.php tests/Feature/TenantStateTest.php
git commit -m "feat(tenancy): clear tenant-scoped container state from one registry"
```

---

## Task 12: Session-based tenancy middleware (with remember-me cookie fallback)

On every web request, once the session has been read, switch to the tenant stored in it.

If the session has no tenant — a fresh session revived by the remember-me recaller — fall back to the long-lived `tenant_id` cookie set at login (Task 15). That fallback is what keeps `Auth::attempt(..., remember: true)` working: the auth guard resolves the recaller *after* this middleware has already picked a database, so without the cookie there would be nothing to pick.

End tenancy after the response, so the connection is not left switched. That matters for long-running workers such as Octane.

The cookie is encrypted/decrypted automatically by the web group's `EncryptCookies` middleware.

**Files:** `app/Http/Middleware/InitializeTenancyBySession.php`, `bootstrap/app.php`

### Task 12, Step 1: Create the middleware

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyBySession
{
    public function handle(Request $request, Closure $next): mixed
    {
        $initialized_here = false;
        $tenant_id = session('tenant_id') ?: $request->cookie('tenant_id');

        if ($tenant_id && !tenancy()->initialized) {
            $tenant = Tenant::on('central')->find($tenant_id);
            if ($tenant) {
                tenancy()->initialize($tenant);
                $initialized_here = true;
                if (!session('tenant_id')) {
                    session(['tenant_id' => $tenant->id]);
                }
            } else {
                session()->forget('tenant_id');
                cookie()->queue(cookie()->forget('tenant_id'));
            }
        }

        $response = $next($request);

        if ($initialized_here && tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
```

**`$initialized_here` is not defensive padding — without it the test suite breaks.** The naive version ends tenancy unconditionally after the response, including tenancy that something *else* established. In tests (Task 30) the `TestCase` initializes tenancy once in `setUp()` and holds an open transaction on the `tenant` connection; the first `$this->get(...)` in a test would then tear that down on the way out, and every assertion after it — `assertDatabaseHas`, a second request, the rollback in `tearDown` — would run against the central database instead. 30 of the current test files make HTTP requests, so this would have looked like a mass, baffling failure. The same guard keeps the middleware from ending tenancy that `GoogleWebhookController` established for itself (Task 25), since that route lives in the web group too.

### Task 12, Step 2: Add to the web stack in `bootstrap/app.php` — with an explicit priority, not `append`

This is the single most order-sensitive change in the plan. The middleware must run:

- **after** `StartSession` (it reads `session('tenant_id')`) and after `EncryptCookies` (it reads the encrypted `tenant_id` cookie);
- **before** `SubstituteBindings`, or every route-model binding (`{serviceorder}`, `{customer}`, …) resolves against the **central** database and 404s;
- **before** the `auth` middleware and `HandleInertiaRequests`, both of which touch `Auth::user()` and therefore query the tenant database.

`$middleware->web(append: [...])` does **not** achieve this. Laravel 12's default web group is `EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:485-491`).

Appending therefore lands the middleware *after* `SubstituteBindings` — the exact failure described above. And it would not help anyway: the order between group middleware and route middleware is settled by the framework's priority list, so appending decides nothing about where this ends up relative to `auth`.

Register it in the group **and** pin its position in the priority list:

```php
$middleware->web(append: [
    \App\Http\Middleware\InitializeTenancyBySession::class,
    HandleInertiaRequests::class,
]);

$middleware->priority([
    \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \App\Http\Middleware\InitializeTenancyBySession::class,
    \App\Http\Middleware\InitializeTenancyForApi::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
    \Illuminate\Routing\Middleware\ThrottleRequests::class,
    \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
    \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\EnsureTenantHasModule::class,
    \Illuminate\Auth\Middleware\Authorize::class,
]);
```

This is Laravel 12's stock priority array (`Illuminate\Foundation\Http\Kernel::$middlewarePriority`, lines 103–115) with three insertions: the two tenancy initializers right after `StartSession`, and `EnsureTenantHasModule` (Task 31) after `SubstituteBindings` so a module check can rely on a resolved tenant. `InitializeTenancyForApi` (Task 24) and `EnsureTenantHasModule` (Task 31) do not exist yet — either add this block once both classes exist, or add the entries incrementally as each task lands.

### Task 12, Step 3: Verify the resulting order before moving on

Do not take this on faith — a wrong order here fails as mass 404s that look like a routing problem, not a tenancy problem:

```bash
php artisan route:list --path=serviceorders -v | head -40
```

`InitializeTenancyBySession` must appear before `SubstituteBindings` and before `auth` in the listed middleware for the route. Then log in and open a detail page (`/serviceorders/{id}`) — a 404 on a record that exists means the order is still wrong.

Note the Google webhook route lives in the web group too; it carries no session or cookie, so this middleware does nothing there and passes the request straight on (the webhook works out its own tenant, Task 25).

**A second sessionless pair of routes landed on master after this plan was written**, and it is harder than the webhook. `storing/informatie/{token}` (GET and POST, `routes/web.php`) is the page where a customer delivers photos and video for a storing, opened from a link in an e-mail by somebody who has no account and never logs in — so no session, no cookie, no tenant. The link is resolved by the `accesstoken` middleware against `access_tokens`, which is a **tenant** table: without a tenant there is nothing to resolve it against, and the page 404s for every customer of every tenant.

It cannot resolve its tenant the way the webhook does, because the only thing the request carries *is* the token, and the token is in the database it cannot reach yet. The approach that fits this plan is the one Task 5 already uses for users: a **central lookup table**, `access_token_lookups` (`token_hash` unique, `tenant_id`), written alongside every `AccessToken::issue()` and deleted with the row. The public routes then get their own middleware that reads the hash, finds the tenant centrally, calls `tenancy()->initialize()`, and hands over to `accesstoken` as it stands. Two alternatives, both worse: put the tenant id in the URL (leaks which tenant a customer belongs to, and lets a guessed id point the resolver anywhere), or move `access_tokens` to central (a tenant table full of tenant records, with the morph target living in another database).

Whoever executes this must also check `App\Models\AccessToken::issue()` and `revoke()` for the lookup writes, and `App\Http\Middleware\ResolveAccessToken` for the ordering. The feature is designed in `docs/superpowers/specs/2026-08-18-ticket-customer-info-request-design.md`.

**Read Task 41 before building this.** It solves the same problem for a different kind of token — a Sanctum bearer token from an API client — and lands on the same answer: a central table mapping a token hash to a tenant. Two tables would be `access_token_lookups` here and `access_token_tenant_lookups` there, which is one letter apart in the schema and two different things in the head. Decide up front whether they are one table with a `kind` column or two with names nobody can confuse, because the second person to touch this will assume there is only one.

### Task 12, Step 4: Commit

```bash
git add app/Http/Middleware/InitializeTenancyBySession.php bootstrap/app.php
git commit -m "feat(tenancy): initialize tenant from session or remember-cookie on web requests"
```

---

## Task 13: Guard the company Inertia share

`AppServiceProvider` shares company data on every Inertia response, including the login page where no tenant is active. Querying `Company` then hits the central database, which has no `companies` table, and crashes. Return `null` when tenancy is not initialized. The logo URL points at the authenticated file route from Task 14 (not a public `/storage` path), so it resolves per tenant.

**Four further shares in `HandleInertiaRequests` need checking rather than changing.** `auth.can.use_assistant` calls a policy; `pendingAnnouncement` runs `InternalAnnouncement::openFor($user)`; `nav.open_tickets` runs a `Ticket::visibleTo(...)->exists()`; `nav.unread_notifications` counts the user's unread rows. All four query the tenant database on **every Inertia response in the application**, which sounds exactly like the bug this task exists to fix — but each is already wrapped in `$request->user() ? … : default`, and on the login page there is no user, so none of them fires. They are safe as written. `pendingAnnouncement` is additionally a closure, so it only runs when a page is actually rendered rather than on every redirect. Confirm that when you get here; if a future share drops the `$request->user()` guard, it crashes the login page the same way `company` did.

`push.vapid_public_key` reads global config and never touches a database. Leave it.

Neither `nav` value needs gating. Storingen and the notification bell are both stock — every tenant has them — so there is no module to check and nothing here changes.

**Files:** `app/Providers/AppServiceProvider.php` (the share is at the bottom of `boot()`), `app/Http/Middleware/HandleInertiaRequests.php` (read only, to confirm the above)

### Task 13, Step 1: Replace the `Inertia::share('company', ...)` closure

```php
Inertia::share('company', function () {
    if (!tenancy()->initialized) {
        return null;
    }
    $company = Company::where('is_main', true)->first();
    if (!$company) {
        return null;
    }
    $logo_url = $company->logo_path ? url("/files/companies/{$company->id}/logo") : null;
    return [
        'name' => $company->name,
        'logo_url' => $logo_url,
    ];
});
```

### Task 13, Step 2: Commit

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "fix: guard company Inertia share when tenancy not initialized"
```

---

## Task 14: Per-tenant storage isolation + authenticated file serving

Each tenant gets a completely separate storage root: `storage/tenant-<id>/public/...` and `storage/tenant-<id>/local/...`. A custom filesystem bootstrapper repoints the `public` and `local` disk roots into that folder whenever tenancy is initialized.

The consequence worth knowing up front: **code that goes through `Storage::disk(...)` needs no changes.** A call like `->store('uploaded/...', 'public')` lands inside the tenant's folder by itself, and the `path` written to the database stays relative — no tenant prefix is stored anywhere.

**Code that does *not* go through a disk does need changing, and it is easy to miss.** Seven call sites build an absolute path or a public URL by hand. These bypass the disk root entirely, so after this task they would keep pointing at the shared central storage tree — silently, with no error: PDFs would render with missing photos and no logo, and imported images would be written where nothing can read them. Step 7 fixes all seven. Re-run the greps in Step 7 before implementing, in case more have appeared.

**The `local` disk carries customer data too.** Alongside the assistant's parked photos (`assistant-photos/`), borrowed files (`assistant-files/`) and reported conversations (`assistant-reports/` — a full transcript including what every tool was handed and returned), it is where anything private lands. All of it goes through `Storage::disk('local')`, so the bootstrapper below moves it per tenant with no code change at all. What that *does* impose is a precondition on the callers: code touching those folders has to run in tenant context, which is why `assistant:prune` is a Task 20 conversion.

Because files now live outside the web-served `public/storage` symlink, they are no longer reachable by URL. Instead, three small authenticated routes stream them through controllers. Tenant isolation is automatic: a file id from another tenant does not exist in this tenant's database, so route-model binding returns 404.

**The isolation holds; the 404 does not survive to production.** `bootstrap/app.php`'s `respond()` handler converts 404, 500 and 503 into `redirect()->back()` outside local/dev/testing, so in production a cross-tenant file id returns a 302 to the referrer rather than a 404 — and an `<img>` pointed at it follows the redirect and renders an HTML page as a broken image. Nothing leaks either way, which is why this is a diagnosis problem rather than a security one: see Known impact 17 before you spend an afternoon on it. (The APK download route reads `storage_path('app/releases/lavoro.apk')` directly, not through a disk, so it intentionally stays global.)

**Files:**
- `app/Tenancy/TenantStorageBootstrapper.php` (new)
- `app/Http/Controllers/FileController.php` (new)
- `routes/web.php`
- `app/Models/User.php` (avatar accessor, `getAvatarAttribute`)
- `resources/service-worker.js` (exclude `/files/` from caching)
- The 12 Vue/JS files that hardcode `/storage/${...}` (images and company logos)
- The 7 non-disk path builders, in `ServiceOrderController`, `ImageController` (twice), `Company`, `pdf/servicejob.blade.php`, `emails/event/appointment_confirmation.blade.php` and `ViewImagesTool` — **found by the two greps in Step 7, which are the authoritative list**

### Task 14, Step 1: Create the storage bootstrapper

Everything under `storage/` looks like one folder, but two unrelated kinds of thing live there:

```
storage/
  app/public/       ← customer photos, company logos, avatars    ┐ uploaded files:
  app/private/      ← assistant photos, files, reports           ┘ the two disks
  app/releases/     ← lavoro.apk, the Android download           ┐
  framework/views/  ← compiled Blade templates                   │ one per installation,
  framework/cache/                                               │ not per customer
  logs/             ← laravel.log                                ┘
```

The top group belongs to a customer. The bottom group belongs to the installation — one APK for everybody, one set of compiled templates, one log file.

Only the top group is reached through `Storage::disk('public')` and `Storage::disk('local')`, and each of those disks has a `root` in `config/filesystems.php` saying where it starts. **This bootstrapper rewrites those two values and nothing else:**

```
storage/app/public   →   storage/tenant-<id>/public
storage/app/private  →   storage/tenant-<id>/local
```

Then it calls `Storage::forgetDisk()` on each, because Laravel caches a resolved disk object and would otherwise keep handing back one that still points at the old folder. Changing config is not enough once the framework has built the thing — the same reason Task 10 calls `forgetDriver` for the cache and Task 11's `MailerState` calls `forgetMailers`.

The payoff is that every `->store(..., 'public')` in the application starts writing into the active tenant's folder with no code change anywhere.

**Why it does not simply move `storage/` wholesale.** Laravel has a method for that — `useStoragePath()` — which changes what `storage_path()` returns everywhere. Doing that per tenant would give each one its own `logs/`, its own compiled views, its own `app/releases/`, and all three are wrong:

- Errors would land in `storage/tenant-<id>/logs/laravel.log`, so reading a stack trace would mean first working out which customer caused it — and anything failing outside tenant context would go somewhere else again.
- Identical Blade templates would be compiled and cached separately per tenant: N copies of the same file, and a cold cache for every new customer.
- `storage_path('app/releases/lavoro.apk')` (`routes/web.php:569`) would look inside the current tenant's folder, so the Android download 404s — unless you keep a copy of the same APK per customer.

The customer's *files* need separating. The installation's plumbing does not.

**Why the different name.** The package ships a `FilesystemTenancyBootstrapper` that does the `useStoragePath()` version. Seeing that class in the bootstrappers list, you would reasonably assume the whole storage tree moves. `TenantStorageBootstrapper` is named differently on purpose: it is not that class and does not do that.

**The catch this creates** is the rest of this task. Code that goes through a disk needs no change at all; code that builds a path by hand — `storage_path('app/public/' . $image->path)` — keeps pointing at the old shared tree, does not error, and simply finds no file. A PDF renders with missing photos and a smoke test says nothing. Step 7 hunts down all seven such call sites.

```php
<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class TenantStorageBootstrapper implements TenancyBootstrapper
{
    protected array $original_roots = [];

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = 'tenant-' . $tenant->getTenantKey();

        foreach (['local', 'public'] as $disk) {
            $this->original_roots[$disk] = $this->app['config']["filesystems.disks.{$disk}.root"];
            $this->app['config']->set(
                "filesystems.disks.{$disk}.root",
                storage_path("{$suffix}/{$disk}")
            );
            Storage::forgetDisk($disk);
        }
    }

    public function revert(): void
    {
        foreach ($this->original_roots as $disk => $root) {
            $this->app['config']->set("filesystems.disks.{$disk}.root", $root);
            Storage::forgetDisk($disk);
        }
        $this->original_roots = [];
    }
}
```

### Task 14, Step 2: Create the file-serving controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function image(Image $image)
    {
        abort_unless(Storage::disk('public')->exists($image->path), 404);

        return Storage::disk('public')->response($image->path);
    }

    public function avatar(User $user)
    {
        $directory = "users/{$user->id}/avatar";
        $files = Storage::disk('public')->files($directory);
        abort_if(empty($files), 404);

        return Storage::disk('public')->response($files[0]);
    }

    public function companyLogo(Company $company, string $variant = 'main')
    {
        $path = $variant === 'negative' ? $company->logo_negative_path : $company->logo_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
```

**Documents are not in that controller, and deliberately need no work at all.** They are the obvious omission — the documents widget serves more file types than anything else in the app — so here is the check, because "it is already fine" is worth being able to verify rather than assert:

| | Images, avatars, logos | Documents |
| --- | --- | --- |
| How the browser gets them | *was* a raw `/storage/…` URL → now `FileController` | already `DocumentController::download` / `preview` |
| Reaches the file via | `Storage::disk('public')` (after this task) | `Storage::disk('public')` (already) |
| Behind `auth` | yes, once Step 3 lands | yes — inside the group opened at `routes/web.php:77` |
| Cross-tenant id | 404 via route-model binding | 404 via route-model binding |
| Permission check | none | `DocumentViewRequest` → `can('viewAny', Document::class)` |

Documents were built the way this task is *making* images work: an authenticated controller reading through a disk. Because they go through the disk, Step 1's per-tenant root applies to them automatically, and because they resolve a `Document` model they inherit the same 404-on-another-tenant's-id behaviour. Nothing to add, nothing to change — and both `download` and `preview` are covered, not just `download`.

Images needed a new controller only because they had no controller: the frontend built `/storage/${image.path}` by hand and the web server served it with no authentication at all. That is the hole being closed, and documents never had it.

### Task 14, Step 3: Register the routes inside the authenticated web group in `routes/web.php`

Add inside the `auth` middleware group (tenancy is already initialized for these requests by the session middleware):

```php
Route::get('files/images/{image}', [\App\Http\Controllers\FileController::class, 'image'])->name('files.image');
Route::get('files/avatars/{user}', [\App\Http\Controllers\FileController::class, 'avatar'])->name('files.avatar');
Route::get('files/companies/{company}/logo/{variant?}', [\App\Http\Controllers\FileController::class, 'companyLogo'])->name('files.companyLogo');
```

### Task 14, Step 4: Change the `User` avatar accessor to return the route URL

In `app/Models/User.php`, `getAvatarAttribute()` currently ends with `return Storage::url($files[0]);` (line 86). **Change that one line and nothing else:**

```php
// before
return Storage::url($files[0]);
// after
return url("/files/avatars/{$this->id}");
```

The method body is reproduced below for orientation only — do not paste it over the existing one. The real method opens with a `if (!$this->id) { return null; }` guard that this excerpt would silently drop, and without it an unsaved `User` (a factory instance, a failed create) yields `/files/avatars/` — a URL that resolves to the collection route rather than to nothing.

```php
if (!$this->id) {                                    // ← keep this
    return null;
}

$directory = "users/{$this->id}/avatar";

if (!Storage::disk('public')->exists($directory)) {
    return null;
}

$files = Storage::disk('public')->files($directory);

if (empty($files)) {
    return null;
}

return url("/files/avatars/{$this->id}");
```

The two existence checks stay for the reason they were written: `null` is what makes the UI fall back to initials, and an avatar route that 404s instead would render a broken image on every user without a photo.

### Task 14, Step 5: Exclude `/files/` from service-worker caching

The service worker serves same-origin GETs cache-first. Every id in this app is a per-tenant auto-increment, so `/files/images/5` is a *different file* in each tenant; a cached copy could be shown to a user of another tenant on a shared browser, and stale copies would survive image replacement. The file you edit is `resources/service-worker.js` — `public/service-worker.js` is its build output and is not in git.

**`/files/` is not the only route that streams a file through a controller.** The same reasoning covers all of them:

| Route | Origin |
| --- | --- |
| `/files/images/{id}`, `/files/avatars/{id}`, `/files/companies/{id}/logo` | this task |
| `/documents/{id}/download`, `/documents/{id}/preview` | pre-existing |
| `/serviceorders/{id}/export/pdf`, `/servicejobs/{id}/export/pdf` | pre-existing |
| `/planner/export` | pre-existing |

A denylist has to be extended every time such a route is added, and forgetting is silent. Invert it: cache only the things that are genuinely static and tenant-independent, and let everything else go to the network.

```js
// Cache-first applies to static, tenant-independent assets only. Everything
// else — controller-served files, exports, API/Inertia — goes to the network,
// because every id in this app is a per-tenant auto-increment and a cached
// response would otherwise outlive both the file and the tenant session.
const isCacheableAsset =
    url.pathname.startsWith("/build/") ||
    url.pathname.startsWith("/icons/") ||
    url.pathname.startsWith("/img/") ||
    url.pathname === "/manifest.json";

if (
    !isCacheableAsset ||
    event.request.headers.get("X-Inertia")
) {
    return;
}
```

Note `/build/` is in the *cacheable* list here, where the current code has it in the early-return list — Vite's build output is content-hashed and immutable, so caching it is safe and desirable; it was previously excluded only because the browser's own cache already handles it. Keep it excluded if you prefer; the tenancy-relevant half of this change is that nothing dynamic reaches the cache.

`CACHE_NAME` takes care of itself. Existing installs still hold cached responses from before
this change and nothing else evicts them, but the vite plugin `swGitHash` rewrites the constant
to `lavoro-cache-<short hash of HEAD>` as it copies `resources/service-worker.js` into
`public/`. Every build that ships the change therefore also expires the old cache — there is
nothing to bump by hand.

**`resources/service-worker.js` does two unrelated jobs, and the second one has a tenancy problem of its own.**

The first job is caching, which is what everything above is about. The second: it receives push notifications, and when somebody taps one, it decides which page to open. That second job is the problem.

On a tap, the worker opens whatever path the server put in the notification:

```js
const url = data?.url ? data.url : (…) ? `/serviceorders/${data.id}` : '/';
```

`/serviceorders/123` names no company. Ids are per-tenant auto-increments, so werkbon 123 exists in *every* tenant and means something different in each — the path alone resolves to "werkbon 123 of whichever company this browser is signed into right now."

So:

1. A notification arrives for Spee BV about werkbon 123.
2. The person later signs into Jansen BV on the same browser — a support engineer, a shared phone in the van.
3. They tap the old notification.
4. They are shown **Jansen BV's** werkbon 123: a real record belonging to a different company, presented as the thing they were notified about.

Route-model binding cannot catch this. Nothing is wrong with the request — it is a valid page, correctly authorised, reached for the wrong reason.

**Do not fix it in this task.** The fix — putting the tenant in the push payload and checking it before navigating — belongs with the push feature, and is written up in Known impact 14.

So this step changes one thing only: the cache. Keep `/files/` and every other controller-served path out of it, as set out above.

One practical warning while you are in that file. A browser only installs a new service worker when the file's contents change, and bumping `CACHE_NAME` is how you force that. Both features live in this one file, so you cannot ship a cache change without also shipping whatever push code is currently in it. Check you are not about to release half-finished push work along with this fix.

### Task 14, Step 6: Update the frontend to use the file routes instead of `/storage/`

Find every hardcoded reference:

```bash
grep -rn "/storage/" resources/js/
```

**Only one thing needs backfilling, and it is not the images.** `images` has always had both `id` and `path`, so switching the frontend from one column to the other works on every row ever written — old installs included. The files do not move in the database either: Task 27 Step 5 physically moves `storage/app/public/*` into the tenant root, and the stored `path` is relative to the disk root, so it resolves against the new one unchanged.

Company logos are served by company id, avatars by user id, and documents already went through a controller by id. The rich-text editor has no image extension, so no user-typed content contains a `/storage/` URL either.

The exception is `activities.metadata.thumbnail_path` — see the first bullet below.

Apply these conversions across the matching files (12 files, 20 occurrences; re-run the grep first — this list drifts):

| File | Occurrences |
| --- | --- |
| `Pages/Assets/IndexPage.vue` | 1 |
| `Pages/Assets/ShowPage.vue` | 3 |
| `Pages/Products/IndexPage.vue` | 1 |
| `Pages/Products/ShowPage.vue` | 1 |
| `Pages/ServiceOrders/ShowPage.vue` | 1 |
| `Pages/Companies/IndexPage.vue` | 2 |
| `Pages/Companies/Partials/EditCompanyModal.vue` | 2 |
| `Components/Timeline/TimelineComponent.vue` | 1 |
| `Components/CustomerUpcomingActivity.vue` | 2 |
| `Components/ImageUploadComponent.vue` | 3 |
| `Components/ServiceOrders/CloseServiceOrderModal.vue` | 2 |
| `Utilities/Utilities.js:389` | 1 |

Three of these need more than a mechanical swap:

- **`Components/Timeline/TimelineComponent.vue` needs a mapper change and a data backfill.** It binds `event.thumbnailPath`, mapped at line 161 from `a.metadata?.thumbnail_path` — a path, with no image id to build a `/files/images/{id}` URL from.

  Both writers already store the id beside the path: `ImageAttached` and `CustomerInfoUploaded` both return `thumbnail_image_id` from `activityMetadata()`. So going forward this is one line in the Vue mapper.

  **Historical rows predate that key, and they are the actual work.** Backfill them by matching the stored path back to `images.path`. Skip it and every pre-cutover thumbnail breaks the moment `/storage/` stops resolving — on the busiest screen in the app. The cheaper alternative, a path-based file route, reopens the enumeration surface that serving by id exists to close. Budget for the backfill.

- `Utilities/Utilities.js:389` builds `thumbnail_url` from `asset.product.images[0].path` inside a shared mapper — switch it to `.id` and confirm every consumer of `thumbnail_url` still works.
- `Pages/Assets/ShowPage.vue` has three occurrences. Two of them (lines ~576 and ~578) are inside a JS resolver that falls back between an asset's own images and its product's images, not `<img>` bindings — read the function before swapping, since the fallback returns a *path string* that other code may concatenate further.


- Image displays bound to an `Image` model — replace the path build with the id route:

```vue
<!-- before -->
<img :src="`/storage/${image.path}`" />
<!-- after -->
<img :src="`/files/images/${image.id}`" />
```

  Apply the same to `asset.product.images[...]`, `product.main_image[0]`, and any other `Image` model: use its `.id`, not `.path`.

- Company logos — use the company route, with the `negative` variant for the negative logo:

```vue
<!-- before -->
<img :src="`/storage/${company.logo_path}`" />
<img :src="`/storage/${company.logo_negative_path}`" />
<!-- after -->
<img :src="`/files/companies/${company.id}/logo`" />
<img :src="`/files/companies/${company.id}/logo/negative`" />
```

- In `ImageUploadComponent.vue`, a *freshly uploaded* preview may use a local object URL or a path returned from the upload response before an `Image` id exists. Leave object-URL previews as-is; for previews of already-saved images, use `/files/images/${image.id}`. Check each usage in this file specifically. Note the occurrence at line ~525 feeds an image *editor* (`loadImage: { path, name }`), not an `<img>` — verify the editor accepts the route URL.

### Task 14, Step 7: Fix the seven backend path builders that bypass the disk

Each of these constructs an absolute path or a public URL by hand and therefore ignores the per-tenant disk root set in Step 1. All of them fail *silently* — a missing file is treated as "no image" — so they will not surface in a smoke test unless you look at a rendered PDF and an appointment e-mail specifically.

```bash
grep -rn "storage_path('app/\|asset('storage/\|public_path('storage/" app/ resources/views/
grep -rn "Storage::url\|disk('public')->url" app/
```

**Two greps, because there are two ways to bypass the root and only one of them says `storage_path`.** The second catches `Storage::url()` and `->url()`, which build a public `/storage/…` URL from the disk's *configured* `url` key rather than from its root — tenant-aware in neither direction.

**The greps are the list. Do not trust the one below.** These call sites move with
almost every commit — the line numbers in this plan have already drifted three
times — so run the greps, then use the notes below to classify what comes back by
*file*, not by line.

Some hits are deliberately not fixed here:

| File | What to do |
| --- | --- |
| `AppServiceProvider` (the `company` Inertia share) | Rewritten in Task 13 — leave it |
| `AuthController` (the login page logo) | Deleted outright in Task 15 — leave it |
| The three PDF blades' `storage/logo.png` | A static fallback logo, not tenant data — stays global, see the end of this step |
| `User::getAvatarAttribute` | Already rewritten in Step 4 |

Everything else the greps return is yours to fix, and there are six of them at the
time of writing:

1. **`app/Http/Controllers/ServiceOrderController.php:817`** — builds `storage_path('app/public/' . $image->path)` to base64-embed werkbon photos in the PDF. Read through the disk instead, which respects the tenant root:

```php
if (!Storage::disk('public')->exists($image->path)) {
    return null;
}
$contents = Storage::disk('public')->get($image->path);
$mime = Storage::disk('public')->mimeType($image->path);
[$width, $height] = @getimagesizefromstring($contents) ?: [1, 1];
// ...
'data' => 'data:' . $mime . ';base64,' . base64_encode($contents),
```

2. **`app/Models/Company.php:52` (`pdfLogo`)** — same pattern for the company logo on every PDF. Replace `storage_path('app/public/' . $company->logo_path)` with `Storage::disk('public')->exists(...)` / `->get(...)` / `->size(...)`, keeping the existing empty-file and extension checks.

3. **`resources/views/pdf/servicejob.blade.php:232`** — `<img src="{{ storage_path('app/public/' . $img['path']) }}">`. Dompdf reads this straight off disk, so it points at the central tree. Resolve the absolute path in the controller that renders this view (via `Storage::disk('public')->path($img['path'])`, which *is* tenant-aware) and pass it into the view, or pass a data URI like the other PDFs already do.

4. **`app/Http/Controllers/ImageController.php:260`** — `file_put_contents(storage_path('app/public/' . $path) . $filename, ...)` for imported images. Replace the manual `mkdir` + `file_put_contents` with `Storage::disk('public')->put($path . $filename, $image_data)`, which creates directories itself.

5. **`app/Http/Controllers/ImageController.php:57`** — `mkdir(storage_path('app/' . $path))`. **This one is already a latent bug today, independent of tenancy**: it creates `storage/app/uploaded/…` while the very next line stores into the `public` disk at `storage/app/public/uploaded/…`. The `mkdir` has never been doing anything useful — `storePubliclyAs` creates the directory itself. Delete the `$real_path` / `mkdir` block outright rather than porting it.

6. **`resources/views/emails/event/appointment_confirmation.blade.php:102`** — `asset('storage/' . $company->logo_path)`. Once files leave the `public/storage` symlink this URL 404s, and the `/files/` routes are behind `auth`, so an e-mail client can never fetch it. Embed the logo instead, reusing the accessor that already exists for PDFs:

```blade
@php($logo = \App\Models\Company::pdfLogo($company))
@if($logo['data'])
    <img src="{{ $logo['data'] }}" alt="{{ $company->name }}">
@endif
```

7. **`app/Domain/Tools/Read/ViewImagesTool.php:164`** — `Storage::disk('public')->url($image->path)`, handed back to the model as the `url` of a photo it has just looked at, so the answer can link the person to it. This is the only one of the seven the first grep misses, and it fails differently from the rest: not a missing file but a **link that resolves for nobody**, because after this task `/storage/…` is no longer served at all. Return the authenticated route instead, which is what every `<img>` in the frontend is being changed to in Step 6:

```php
'url' => Storage::disk('public')->exists($image->path)
    ? url("/files/images/{$image->id}")
    : null,
```

The `exists()` check stays: the tool is reading images off records that may have lost their file, and a null url is how the answer says so.

The `asset('storage/logo.png')` / `public_path('storage/logo.png')` references in the three PDF blades are a different case: that is a single static fallback logo, not tenant data. It resolves through the untouched `public/storage` symlink and stays global by design — leave it, but be aware it is the same image for every tenant.

### Task 14, Step 8: Commit

```bash
git add app/Tenancy/TenantStorageBootstrapper.php \
        app/Http/Controllers/FileController.php \
        app/Http/Controllers/ImageController.php \
        app/Http/Controllers/ServiceOrderController.php \
        app/Domain/Tools/Read/ViewImagesTool.php \
        app/Models/Company.php \
        routes/web.php \
        app/Models/User.php \
        resources/service-worker.js \
        resources/views/ \
        resources/js/
git commit -m "feat(tenancy): per-tenant storage roots with authenticated file serving"
```

---

## Task 15: Update the login controller

Look up the tenant from the email, switch to its database, then authenticate. Keep `remember: true` (current behavior) and pair it with a forever `tenant_id` cookie so the recaller can find its database on a fresh session (Task 12 reads it). Also remove the `exists:users,email` rule from the form request — it runs before tenancy is initialized and would query the central database, which has no `users` table. Finally, drop the `company` prop from `create()`: `LoginPage.vue` renders the static `/img/logo-neg.svg` and never reads it, and the query would crash against the central database.

**Files:** `app/Http/Controllers/AuthController.php`, `app/Http/Requests/StoreUpdateAuthRequest.php`

### Task 15, Step 1: Remove the `exists` rule

```php
public function rules(): array
{
    return [
        'email'    => 'required|string|email',
        'password' => 'required|string',
    ];
}
```

### Task 15, Step 2: Rewrite `AuthController`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUpdateAuthRequest;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create()
    {
        return inertia('Auth/LoginPage');
    }

    public function store(StoreUpdateAuthRequest $request)
    {
        $lookup = UserTenantLookup::where('email', $request->email)->first();

        if (!$lookup) {
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        $tenant = Tenant::on('central')->findOrFail($lookup->tenant_id);
        tenancy()->initialize($tenant);

        if (!Auth::attempt($request->only('email', 'password'), true)) {
            tenancy()->end();
            throw ValidationException::withMessages(['email' => 'Kon niet inloggen']);
        }

        session(['tenant_id' => $tenant->id]);
        cookie()->queue(cookie()->forever('tenant_id', $tenant->id));
        $request->session()->regenerate();

        return redirect()->intended();
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        cookie()->queue(cookie()->forget('tenant_id'));

        return redirect()->route('login');
    }
}
```

Three details in that method are easy to get wrong, and one is a loose end:

- **`session(['tenant_id' => ...])` before `regenerate()` is safe.** `Store::regenerate()` defaults to `$destroy = false` and migrates the session data to the new id, so the tenant survives the rotation. Do not "tidy" this by moving the write after the regenerate and assuming it makes no difference — it happens not to, and the reason is one framework default deep.
- **`tenancy()->initialize()` here is never ended.** `InitializeTenancyBySession` (Task 12) only ends tenancy it started itself, and on a login POST the session has no `tenant_id` yet, so `$initialized_here` is false and the middleware leaves this alone. Under PHP-FPM the process ends and nothing notices. Under Octane it is a switched connection leaking into the next request, which is the exact failure Task 12's `tenancy()->end()` exists to prevent — so if Octane is ever adopted, this method needs a `finally` and this sentence is the reminder.
- **`findOrFail` is the wrong verb on a login POST.** A lookup row pointing at a deleted tenant throws a 404, which in production the global responder turns into `redirect()->back()` (Known impact 17) — a login form that bounces with no message, forever, for one user. Use `first()` and fall into the same `ValidationException` as a missing lookup: the user cannot act on the difference between "no such email" and "your company's database is gone", and support can read the log.
- **Failing before the password check is an enumeration oracle** — a missing lookup returns without running bcrypt, so a wrong email answers measurably faster than a wrong password. This is not a regression: the `exists:users,email` rule being removed in Step 1 was a louder version of the same thing. Worth knowing it is still there rather than believing the rewrite closed it.

### Task 15, Step 3: Commit

```bash
git add app/Http/Controllers/AuthController.php app/Http/Requests/StoreUpdateAuthRequest.php
git commit -m "feat(tenancy): resolve tenant before authenticating on login"
```

---

## Task 16: Licensing catalogue models and the pricing service

The catalogue tables exist and are seeded (Task 6). This task adds the Eloquent models over them, the `TenantSubscription` service that computes a tenant's monthly total, and the frontend exposure of the tenant's package and modules. The CRUD commands that edit the catalogue and the per-tenant subscription commands come later (Tasks 33–34); nothing before those needs them.

**Files:**
- `app/Models/Central/Package.php`, `Module.php`, `ModuleBundle.php`, `PricingSetting.php` (new)
- `app/Services/TenantSubscription.php` (new)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/Utilities/Utilities.js`
- `tests/Feature/TenantSubscriptionTest.php`, `tests/Feature/PricingCatalogueTest.php` (new)

**Interfaces:**
- Consumes: seeded `packages`, `modules`, `module_bundles`, `pricing_settings` (Task 6); `Tenant` (Task 4).
- Produces:
  - `App\Models\Central\Package` — columns `key`, `name`, `field_seats`, `office_seats`, `price_cents`, `extra_field_cents`, `extra_office_cents`, `sort_order`; central connection; fillable.
  - `App\Models\Central\Module` — `key`, `name`, `price_cents`, `sort_order`; central; fillable.
  - `App\Models\Central\ModuleBundle` — `name`, `module_keys` (array cast), `price_cents`; central; fillable.
  - `App\Models\Central\PricingSetting` — `key`, `value`; central; static `value(string $key, int $default = 0): int`.
  - `App\Services\TenantSubscription` — `__construct(Tenant $tenant)`; `monthlyTotalCents(): int`.

### Task 16, Step 1: Create the four catalogue models

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $connection = 'central';
    protected $fillable = ['key', 'name', 'field_seats', 'office_seats', 'price_cents', 'extra_field_cents', 'extra_office_cents', 'sort_order'];
}
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $connection = 'central';
    protected $fillable = ['key', 'name', 'price_cents', 'sort_order'];
}
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class ModuleBundle extends Model
{
    protected $connection = 'central';
    protected $fillable = ['name', 'module_keys', 'price_cents'];
    protected $casts = ['module_keys' => 'array'];
}
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $connection = 'central';
    protected $fillable = ['key', 'value'];

    public static function value(string $key, int $default = 0): int
    {
        $row = static::on('central')->where('key', $key)->first();
        return $row ? (int) $row->value : $default;
    }
}
```

### Task 16, Step 2: Write the failing pricing-catalogue tests

These read the seeded catalogue. They encode the two rules the price list must satisfy — a future price change that breaks either turns the suite red.

```php
<?php

namespace Tests\Feature;

use App\Models\Central\Package;
use Tests\TestCase;

class PricingCatalogueTest extends TestCase
{
    public function test_expanding_is_cheaper_than_upgrading_to_equivalent_coverage(): void
    {
        $packages = Package::on('central')->orderBy('sort_order')->get();

        for ($i = 0; $i < $packages->count() - 1; $i++) {
            $lower = $packages[$i];
            $upper = $packages[$i + 1];

            $expand_cost = $lower->price_cents
                + ($upper->field_seats - $lower->field_seats) * $lower->extra_field_cents
                + ($upper->office_seats - $lower->office_seats) * $lower->extra_office_cents;

            $this->assertLessThan(
                $upper->price_cents,
                $expand_cost,
                "Expanding {$lower->key} to {$upper->key}'s coverage must cost less than upgrading."
            );
        }
    }

    public function test_add_on_seats_get_cheaper_as_packages_grow(): void
    {
        $packages = Package::on('central')->orderBy('sort_order')->get();

        for ($i = 0; $i < $packages->count() - 1; $i++) {
            $this->assertGreaterThan($packages[$i + 1]->extra_field_cents, $packages[$i]->extra_field_cents);
            $this->assertGreaterThan($packages[$i + 1]->extra_office_cents, $packages[$i]->extra_office_cents);
        }
    }
}
```

### Task 16, Step 3: Run to verify they fail, then pass

Run: `php artisan test --filter=PricingCatalogueTest`
Expected first: FAIL — `Class "App\Models\Central\Package" not found`. After Step 1 is in place and the catalogue migration (Task 6) has run against the test central database, both tests PASS with the seeded numbers.

### Task 16, Step 4: Write the failing `TenantSubscription` tests

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantSubscription;
use Tests\TestCase;

class TenantSubscriptionTest extends TestCase
{
    private function subscription(array $attributes): TenantSubscription
    {
        return new TenantSubscription(new Tenant(array_merge([
            'package_key'        => 'starter',
            'extra_field_seats'  => 0,
            'extra_office_seats' => 0,
            'modules'            => [],
            'storage_limit_gb'   => 50,
        ], $attributes)));
    }

    public function test_bare_package_is_its_base_price(): void
    {
        $this->assertSame(2750, $this->subscription(['package_key' => 'starter'])->monthlyTotalCents());
        $this->assertSame(16000, $this->subscription(['package_key' => 'business'])->monthlyTotalCents());
    }

    public function test_extra_seats_add_at_the_package_rate(): void
    {
        $total = $this->subscription([
            'package_key' => 'business', 'extra_field_seats' => 5, 'extra_office_seats' => 2,
        ])->monthlyTotalCents();

        $this->assertSame(16000 + 5 * 1000 + 2 * 700, $total); // 22400
    }

    public function test_a_module_bundle_replaces_its_members_individual_prices(): void
    {
        $this->assertSame(16000 + 2750, $this->subscription(['package_key' => 'business', 'modules' => ['quotes']])->monthlyTotalCents());
        $this->assertSame(16000 + 4000, $this->subscription(['package_key' => 'business', 'modules' => ['quotes', 'invoices']])->monthlyTotalCents());
    }

    public function test_free_modules_add_nothing(): void
    {
        $this->assertSame(16000, $this->subscription(['package_key' => 'business', 'modules' => ['snelstart', 'google_calendar']])->monthlyTotalCents());
    }

    public function test_extra_storage_bills_the_allowance_above_the_included_amount(): void
    {
        // 120 GB limit, 50 GB included, 50 cents/GB → (120-50)*50 = 3500
        $this->assertSame(16000 + 3500, $this->subscription(['package_key' => 'business', 'storage_limit_gb' => 120])->monthlyTotalCents());
        $this->assertSame(16000, $this->subscription(['package_key' => 'business', 'storage_limit_gb' => 50])->monthlyTotalCents());
    }

    public function test_price_override_replaces_only_the_package_price(): void
    {
        $total = $this->subscription([
            'package_key' => 'business', 'price_override_cents' => 14000,
            'extra_field_seats' => 5, 'modules' => ['quotes', 'invoices'],
        ])->monthlyTotalCents();

        $this->assertSame(14000 + 5 * 1000 + 4000, $total); // 23000
    }
}
```

### Task 16, Step 5: Run to verify they fail

Run: `php artisan test --filter=TenantSubscriptionTest`
Expected: FAIL — `Class "App\Services\TenantSubscription" not found`.

### Task 16, Step 6: Implement the `TenantSubscription` service

```php
<?php

namespace App\Services;

use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Tenant;

class TenantSubscription
{
    public function __construct(private Tenant $tenant)
    {
    }

    public function monthlyTotalCents(): int
    {
        $package = Package::on('central')->where('key', $this->tenant->package_key)->firstOrFail();

        $base = $this->tenant->price_override_cents ?? $package->price_cents;

        $seats = $this->tenant->extra_field_seats * $package->extra_field_cents
            + $this->tenant->extra_office_seats * $package->extra_office_cents;

        return $base + $seats + $this->storageCents() + $this->modulesCents();
    }

    private function storageCents(): int
    {
        $included = PricingSetting::value('included_storage_gb', 50);
        $per_gb   = PricingSetting::value('storage_extra_per_gb_cents', 0);
        $extra_gb = max(0, $this->tenant->storage_limit_gb - $included);

        return $extra_gb * $per_gb;
    }

    private function modulesCents(): int
    {
        $held = $this->tenant->modules ?? [];
        if (empty($held)) {
            return 0;
        }

        $remaining = array_values($held);
        $total = 0;

        $bundles = ModuleBundle::on('central')->get()
            ->map(function (ModuleBundle $bundle) {
                $individual = Module::on('central')->whereIn('key', $bundle->module_keys)->sum('price_cents');

                return ['keys' => $bundle->module_keys, 'price' => $bundle->price_cents, 'saving' => $individual - $bundle->price_cents];
            })
            ->sortByDesc('saving');

        foreach ($bundles as $bundle) {
            $all_held = collect($bundle['keys'])->every(fn ($key) => in_array($key, $remaining, true));
            if ($all_held) {
                $total += $bundle['price'];
                $remaining = array_values(array_diff($remaining, $bundle['keys']));
            }
        }

        return $total + Module::on('central')->whereIn('key', $remaining)->sum('price_cents');
    }
}
```

### Task 16, Step 7: Run the subscription tests to verify they pass

Run: `php artisan test --filter=TenantSubscriptionTest`
Expected: PASS.

### Task 16, Step 8: Share the tenant's package and modules with the frontend

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the array returned by `share()` (next to the existing `auth` key). Seat and storage usage are added to this same prop by their own tasks (Tasks 34 and 35) — keep it to package and modules here.

```php
'tenant' => tenancy()->initialized ? [
    'package' => tenancy()->tenant->package_key,
    'modules' => tenancy()->tenant->modules ?? [],
] : null,
```

### Task 16, Step 9: Add a `hasModule` helper to `resources/js/Utilities/Utilities.js`

Follow the same pattern as the existing `hasPermission` helper (which reads `usePage().props.auth.permissions`):

```js
export const hasModule = (name) => {
    const page = usePage();
    const modules = page?.props?.tenant?.modules;
    return Array.isArray(modules) && modules.includes(name);
}
```

Two deliberate differences from `hasPermission`: it is **not** bypassed for admins (a module is a subscription boundary, not a permission), and it returns `false` rather than throwing when `tenant` is absent — which is the case on the login page, where no tenancy is initialized.

### Task 16, Step 10: Commit

```bash
git add app/Models/Central/Package.php app/Models/Central/Module.php \
        app/Models/Central/ModuleBundle.php app/Models/Central/PricingSetting.php \
        app/Services/TenantSubscription.php \
        app/Http/Middleware/HandleInertiaRequests.php resources/js/Utilities/Utilities.js \
        tests/Feature/TenantSubscriptionTest.php tests/Feature/PricingCatalogueTest.php
git commit -m "feat(tenancy): licensing catalogue models and pricing service"
```

---

## Task 17: Update the password reset flow

Password reset runs before login, so do the same tenant lookup before calling the `Password` facade (which queries `password_reset_tokens` and `users` in the tenant database). Do **not** wrap the new password in `Hash::make()` — the `User` model has a `hashed` cast, so `forceFill` hashes it automatically; wrapping it would double-hash and break login.

**Files:** `app/Http/Controllers/PasswordResetController.php`

### Task 17, Step 1: Rewrite the controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function create()
    {
        return inertia('Auth/ForgotPasswordPage');
    }

    public function store(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $this->switchToTenantForEmail($request->email);

        $status = Password::sendResetLink($request->only('email'));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    public function edit(string $token, Request $request)
    {
        return inertia('Auth/ResetPasswordPage', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->switchToTenantForEmail($request->email);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                event(new PasswordReset($user));
            }
        );

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    private function switchToTenantForEmail(string $email): void
    {
        $lookup = UserTenantLookup::where('email', $email)->first();
        if (!$lookup) {
            return;
        }
        $tenant = Tenant::on('central')->find($lookup->tenant_id);
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
}
```

### Task 17, Step 2: Commit

```bash
git add app/Http/Controllers/PasswordResetController.php
git commit -m "feat(tenancy): switch to tenant DB in password reset flow"
```

---

## Task 18: Keep the central lookup in sync via a `User` observer

When a user is created, changes email, or is deleted in a tenant, mirror it into the central lookup. The `created` hook refuses to hijack an email already registered to a *different* tenant (defense in depth behind the validation in Task 19).

**`User` uses `SoftDeletes`** (see `UserController::restore` and `UserRestoreRequest`). This matters more than it looks:

- Eloquent's `deleted` event fires on a **soft** delete. Dropping the lookup row there would free the email centrally while the row still occupies `users.email` in the tenant — and Laravel's `unique:users,email` rule does **not** exclude trashed rows. The email would then be un-loggable-in, un-recreatable in this tenant, and claimable by another tenant, which is exactly the invariant this table exists to protect.
- The rule is therefore: the central lookup tracks whether the email is **taken in the tenant's `users` table**, trashed or not. Soft delete keeps the row, `forceDeleted` removes it, `restored` re-asserts it.

A soft-deleted user cannot log in (the `SoftDeletingScope` hides them from the auth guard's query), so keeping the lookup row costs nothing.

**Files:** `app/Observers/UserObserver.php`, `app/Providers/AppServiceProvider.php`

### Task 18, Step 1: Create the observer

```php
<?php

namespace App\Observers;

use App\Models\Central\UserTenantLookup;
use App\Models\User;
use RuntimeException;

class UserObserver
{
    /**
     * The refusal happens before the insert, not after it. A user row that
     * exists in the tenant database with no central lookup row can never log
     * in — and cannot be created again either, because unique:users,email now
     * rejects the retry.
     */
    public function creating(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        $existing = UserTenantLookup::on('central')->find($user->email);
        if ($existing && $existing->tenant_id !== $tenant_id) {
            throw new RuntimeException("E-mailadres {$user->email} is al in gebruik bij een andere tenant.");
        }
    }

    public function created(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function updated(User $user): void
    {
        if (!$user->isDirty('email')) {
            return;
        }

        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->where('email', $user->getOriginal('email'))->delete();
        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function restored(User $user): void
    {
        $tenant_id = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;
        if (!$tenant_id) {
            return;
        }

        UserTenantLookup::on('central')->updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => $tenant_id]
        );
    }

    public function forceDeleted(User $user): void
    {
        UserTenantLookup::on('central')->where('email', $user->email)->delete();
    }
}
```

There is deliberately **no `deleted` hook**. On a soft-deleting model `deleted` fires for soft deletes, and the lookup must survive those — see the explanation above. `forceDeleted` fires only on a true `forceDelete()`, which is when the email genuinely becomes free again.

Note that `updated` also fires when a user is soft-deleted, because `deleted_at` changes. The `isDirty('email')` check means nothing happens in that case.

**The split between `creating` and `created` is the whole point of the pair.** Task 19's validation is the friendly guard and this is the backstop, but a backstop that fires *after* the insert leaves the exact wreckage it exists to prevent: a user in the tenant database that cannot log in, cannot be deleted by anyone who does not know it is there, and cannot be recreated because `unique:users,email` now matches it. Refusing in `creating` costs one extra central query per user creation — a few of those a month — and leaves nothing behind.

Two honest limits on it, neither worth more code today:

- **Between the check and the write there is a race.** Two tenants creating the same email at the same instant both pass `creating`, and the second `updateOrCreate` repoints the lookup — no duplicate row, because `email` is the primary key, but the first tenant's user quietly loses the ability to log in. The primary key is the only real arbiter here; if this ever needs to be authoritative rather than merely good, make `created` an insert and catch the duplicate-key `QueryException` into the same `RuntimeException`. At the volume of user creation this application sees, the race is theoretical.
- **`updated` is not similarly protected.** Changing a user's email to one held by another tenant is caught by Task 19's `Rule::unique('central.user_tenant_lookups', 'email')` and by nothing here; the observer's `updated` hook deletes the old row and writes the new one unconditionally. That is deliberate — an observer is a poor place for a second validation layer — but it means Task 19's validation is the only thing guarding the update path.

### Task 18, Step 2: Register it in `AppServiceProvider::boot()` (next to the existing `EventModel::observe` / `Ticket::observe` calls)

```php
\App\Models\User::observe(\App\Observers\UserObserver::class);
```

### Task 18, Step 3: Commit

```bash
git add app/Observers/UserObserver.php app/Providers/AppServiceProvider.php
git commit -m "feat(tenancy): sync central user lookup on user changes"
```

---

## Task 19: Enforce global email uniqueness at user creation/update

The lookup table's email primary key would throw a raw SQL error if an admin created a user whose email already exists in another tenant. Validate it cleanly instead. The routes use `UserStoreRequest` (store) and `UserUpdateRequest` (update **and** `me.update` via `updateSelf`, where there is no `{user}` route parameter — verified at `UserController.php:55,68,120`). `StoreUserRequest`/`UpdateUserRequest` also exist in `app/Http/Requests/` but are not referenced by the user routes; leave those untouched.

Both requests are already written the way this task assumes: `UserStoreRequest::rules()` has the flat `'email' => 'required|email|unique:users,email'` string, and `UserUpdateRequest::rules()` already computes `$route_user` / `$route_user_id` / `$current_user_id` / `$ignore_id` in that order, so the `$ignore_email` snippet below drops in directly after them.

Consistency note tying this to Task 18: `unique:users,email` counts soft-deleted users — the rule queries the table through the presence verifier, which applies no Eloquent global scopes — and the central lookup keeps a row for soft-deleted users. The two checks therefore agree: an email belonging to a trashed user is rejected by both, not one.

**Task 35's `SeatAvailable` rule deliberately disagrees with both, and that is correct.** It counts through `User::where(...)`, i.e. the Eloquent model, so the `SoftDeletes` global scope applies and a trashed user does **not** consume a seat. A tenant should not pay for someone who left. Anyone tempted to make the three consistent should change none of them: an email is claimed until it is force-deleted, a seat is released the moment somebody is deactivated, and those are different questions that happen to be asked of the same table.

The `central.` prefix on the unique rule tells the validator to query the central connection.

**Files:** `app/Http/Requests/UserStoreRequest.php`, `app/Http/Requests/UserUpdateRequest.php`

### Task 19, Step 1: Add a global-uniqueness rule to `UserStoreRequest::rules()`

Replace the current `'email' => 'required|email|unique:users,email',` line:

```php
use Illuminate\Validation\Rule;

// inside rules():
'email' => [
    'required', 'email',
    'unique:users,email',
    Rule::unique('central.user_tenant_lookups', 'email'),
],
```

### Task 19, Step 2: Add the same rule to `UserUpdateRequest`, ignoring the user's current email

The request already derives `$ignore_id` from the route user or the authenticated user; mirror that logic for the email. Inside `rules()`, after the existing `$ignore_id` computation, add:

```php
$ignore_user = is_object($route_user)
    ? $route_user
    : ($route_user ? \App\Models\User::find($route_user) : null);
$ignore_email = $ignore_user?->email ?? optional(request()->user())->email;
```

and extend the email rules:

```php
'email' => [
    'required',
    'email',
    Rule::unique('users', 'email')->ignore($ignore_id),
    Rule::unique('central.user_tenant_lookups', 'email')->ignore($ignore_email, 'email'),
],
```

### Task 19, Step 3: Commit

```bash
git add app/Http/Requests/UserStoreRequest.php app/Http/Requests/UserUpdateRequest.php
git commit -m "feat(tenancy): validate email is globally unique across tenants"
```

---

## Task 20: Make scheduled tasks run per tenant, without per-tenant work blocking the scheduler tick

Loop over all tenants, switch into each, and dispatch **one queued job per tenant per schedule** — the scheduler tick itself must never run a data query or delete inline, only cheap config-swap + single-row `INSERT INTO jobs` work. The jobs are dispatched from tenant context (not `dispatchSync`) — the `QueueTenancyBootstrapper` records the active tenant in the job payload and re-initializes it automatically when the worker picks it up, so the job body needs no manual `tenancy()->initialize()` call of its own.

`routes/console.php` has **six** schedules. If more exist by implementation time, wrap them in the same per-tenant dispatch-only pattern:

| Schedule | Today | Change |
| --- | --- | --- |
| `google-pull-changes` | `Schedule::call` running a `whereHas` query inline | → per-tenant dispatch of `DispatchTenantCalendarPullsJob` |
| `google-renew-watches` | `Schedule::job(new RenewWatchChannelsJob())` | → per-tenant `RenewWatchChannelsJob::dispatch()` inside the loop (a bare `Schedule::job` has no tenant context and would run against the central database) |
| `prune-location-pings` | `Schedule::call` running a synchronous `DELETE` inline | → per-tenant dispatch of `PruneLocationPingsJob` |
| `maintenancecontracts-generate-serviceorders` | `Schedule::command('maintenancecontracts:generate-serviceorders')` calling `MaintenanceContractServiceOrderGenerator::generateAllDue()` inline | → per-tenant dispatch of `GenerateMaintenanceContractServiceOrdersJob` |
| `assistant-prune-questions` | `Schedule::command('assistant:prune')` deleting rows **and** files inline | → per-tenant dispatch of `PruneAssistantQuestionsJob` |
| `notifications-missing-times` | `Schedule::command('notifications:missing-times')` querying events and writing notifications inline | → per-tenant dispatch of `NotifyMissingExecutionTimesJob` |

The maintenance-contract one is the most important of the six to convert: `generateAllDue()` scans every asset on every active contract and **creates service orders**. Left as a plain `Schedule::command`, it would run exactly once per tick against whatever the default connection happens to be — the central database, which has no `maintenance_contracts` table — so contract generation would break outright for every tenant. The existing Artisan command stays (it is useful for manual runs against a chosen tenant); the schedule stops invoking it directly.

**The assistant prune is the most interesting of the six, because it is the only one that deletes files as well as rows.** `PruneAssistantQuestionsCommand` clears `assistant_questions` and `assistant_conversation_facts`, then calls `ConversationPhotos::pruneOlderThan()`, `ConversationFiles::pruneOlderThan()` and deletes the reports — all three through `Storage::disk('local')`, whose root Task 14 has repointed at the active tenant. Run outside tenant context it does two wrong things at once: `DELETE` against a central database that has no such tables, and a directory sweep of a storage root that belongs to nobody. Inside tenant context, both halves land where they should with no change to the command itself.

`notifications:missing-times` is the plainest of the six. It reads `events`, writes `user_notifications` and pushes through `push_subscriptions` — all three tenant tables, so it simply has to run inside a tenant.

**Files:**
- `app/Jobs/Google/DispatchTenantCalendarPullsJob.php` (new)
- `app/Jobs/PruneLocationPingsJob.php` (new)
- `app/Jobs/GenerateMaintenanceContractServiceOrdersJob.php` (new)
- `app/Jobs/PruneAssistantQuestionsJob.php` (new)
- `app/Jobs/NotifyMissingExecutionTimesJob.php` (new)
- `routes/console.php`

> **Precondition, easy to forget:** none of this runs at all until a server-level cron invokes `php artisan schedule:run` — there is currently no crontab entry wired up, and `app/Console/Kernel.php`'s `schedule()` is dead code under Laravel 12's `routes/console.php` setup. Confirm the cron exists on the target server before treating any scheduled behaviour as working.

### Task 20, Step 1: Create the calendar-pull dispatch job

```php
<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTenantCalendarPullsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        GoogleSyncedCalendar::query()
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->pluck('id')
            ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));
    }
}
```

This runs on the worker with tenancy already initialized (tagged by `QueueTenancyBootstrapper` at dispatch time, same as any other tenant-context job), so the `whereHas` query and the `PullCalendarChangesJob` dispatches it makes both land against the correct tenant database automatically.

### Task 20, Step 2: Create the location-ping pruning job

```php
<?php

namespace App\Jobs;

use App\Models\LocationPing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneLocationPingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        LocationPing::where('recorded_at', '<', now()->subDay())->delete();
    }
}
```

### Task 20, Step 3: Create the maintenance-contract generation job

```php
<?php

namespace App\Jobs;

use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMaintenanceContractServiceOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MaintenanceContractServiceOrderGenerator $generator): void
    {
        $generator->generateAllDue();
    }
}
```

Leave `App\Console\Commands\GenerateMaintenanceContractServiceOrders` in place for manual runs; only the schedule changes.

### Task 20, Step 4: Create the two jobs that wrap the newer commands

These two differ from the three above: their logic lives *in the command*, not in a service the job could call. `PruneAssistantQuestionsCommand` holds the retention arithmetic, the three-way file sweep and the dry-run branch; `NotifyMissingExecutionTimes` holds the query, the one-per-appointment-per-person rule and the push. Reimplementing either in a job would be two copies of a retention rule, which is exactly the sort of thing that drifts apart and then deletes the wrong month.

So these jobs invoke the command rather than duplicating it. That is a thinner wrapper than the three above, and deliberately so — the whole job is "run this command with a tenant resolved".

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class PruneAssistantQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Artisan::call('assistant:prune');
    }
}
```

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class NotifyMissingExecutionTimesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Artisan::call('notifications:missing-times');
    }
}
```

`Artisan::call` runs the command in the worker's own process, so the tenant the job was tagged with is still the active one — this is not `Artisan::queue`, which would dispatch a second, untagged job and put us back where we started.

Both commands keep working by hand, and by hand is now the *only* way to reach their options (`--months`, `--dry-run`, `--days`, `--no-push`) — the schedule always takes the defaults. If a per-tenant retention period is ever wanted, it belongs as a column read inside the command, not as an argument threaded through the job.

### Task 20, Step 5: Rewrite `routes/console.php` so every tick only dispatches

`cursor()` replaces `get()` so the central tenant list is streamed rather than loaded into memory in one array — cheap either way at today's tenant count, and it is the cheap part of the chunking that Known impact 6 asks for, so there is no reason not to.

```php
<?php

use App\Jobs\GenerateMaintenanceContractServiceOrdersJob;
use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Jobs\NotifyMissingExecutionTimesJob;
use App\Jobs\PruneAssistantQuestionsJob;
use App\Jobs\PruneLocationPingsJob;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        DispatchTenantCalendarPullsJob::dispatch();
        tenancy()->end();
    });
})->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        RenewWatchChannelsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('google-renew-watches')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        PruneLocationPingsJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('prune-location-pings')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        GenerateMaintenanceContractServiceOrdersJob::dispatch();
        tenancy()->end();
    });
})->hourly()->name('maintenancecontracts-generate-serviceorders')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        PruneAssistantQuestionsJob::dispatch();
        tenancy()->end();
    });
})->dailyAt('03:20')->name('assistant-prune-questions')->withoutOverlapping();

Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        NotifyMissingExecutionTimesJob::dispatch();
        tenancy()->end();
    });
})->dailyAt('07:00')->name('notifications-missing-times')->withoutOverlapping();
```

Every tick body is now: swap config, one `INSERT` into the central `jobs` table, revert config — no query, no delete, nothing whose cost scales with a tenant's data volume. The remaining linear cost is strictly "number of tenants × one INSERT", which is the part `withoutOverlapping` can comfortably absorb even at a few hundred tenants.

Keep the times the schedules already carry (03:20 and 07:00). The 07:00 one is chosen rather than arbitrary — the docblock on the command says why: a reminder about yesterday's unfilled hours has to arrive as this morning's work, not as last night's interruption. Moving it to spread load would change what the notification means.

**Two of these now loop tenants in one process every night, which is precisely the case Task 11's reset exists for.** Before this task there was no such loop outside `tenants:migrate`. Do not implement Task 20 without Task 11 in place.

### Task 20, Step 6: Commit

```bash
git add app/Jobs/Google/DispatchTenantCalendarPullsJob.php app/Jobs/PruneLocationPingsJob.php \
        app/Jobs/GenerateMaintenanceContractServiceOrdersJob.php \
        app/Jobs/PruneAssistantQuestionsJob.php app/Jobs/NotifyMissingExecutionTimesJob.php \
        routes/console.php
git commit -m "feat(tenancy): keep per-tenant scheduler ticks dispatch-only"
```

---

## Task 21: `tenant:create` command (with initial admin)

Creates the tenant record (which fires the create→migrate→seed pipeline), then creates an initial admin user inside the new tenant so the company can actually log in.

The package is validated against the seeded `packages` catalogue (Task 6/16) and defaults to `starter` — the smallest thing that works, so an under-provisioned tenant complains immediately rather than silently costing money. Modules default to none. The derived database name is `'lavoro_tenant_' . Str::slug($name, '_')`, which cannot collide with `lavoro_landlord` — the namespaces do not overlap. It *can* collide with an existing **tenant**: two customers whose names slug identically ("Spee BV" and "Spee B.V." both slug to `spee_bv`) would derive the same database, and the second `tenant:create` would run the tenant migrations straight over the first customer's live data. The guard below therefore refuses any database name that already exists on the server, which covers that case and any other pre-existing schema. Cheap check, unrecoverable failure without it. Creating the user fires the observer, which writes the central lookup row. The admin user is created without an explicit `seat_type`, so it takes the column default `office` (Task 33); the operator can promote it to `field` afterwards.

**This command runs as the provisioner Linux user** (Task 2), because creating a database and a MySQL user is the one thing the web app's credentials deliberately cannot do. It gets there itself — the trait below re-execs under `sudo` when the rule from Task 2 Step 2b is present:

```bash
php artisan tenant:create "Klant BV" admin@klant.nl
```

Without that rule it prints the `sudo -u lavoro_provisioner …` line to run by hand, so both installs work; only one of them makes you type it.

**Files:** `app/Console/Commands/Concerns/RunsAsProvisioner.php` (new), `app/Console/Commands/CreateTenant.php`

### Task 21, Step 1: Create the `RunsAsProvisioner` trait

Provisioning writes the tenant row *and* issues `CREATE DATABASE` / `CREATE USER`, so both must happen on the `provisioner` connection. This trait does two things: it gets the process running as the provisioner Linux user, and it repoints `central` at the provisioner connection for the life of the command.

Call `elevateToProvisioner()` as the **first statement in `handle()`**, before any
output and before any work. It replaces the process, so anything done before it
is done twice.

```php
<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

trait RunsAsProvisioner
{
    protected function elevateToProvisioner(): void
    {
        if ($this->linuxUser() === 'lavoro_provisioner') {
            return;
        }

        $sudo = trim((string) shell_exec('command -v sudo 2>/dev/null'));

        if (!$sudo || !function_exists('pcntl_exec')) {
            return;
        }

        exec($sudo . ' -n -u lavoro_provisioner true 2>/dev/null', $ignored, $status);

        if ($status !== 0) {
            return;
        }

        pcntl_exec($sudo, array_merge(
            ['-n', '-u', 'lavoro_provisioner', PHP_BINARY, base_path('artisan')],
            array_slice($_SERVER['argv'], 1)
        ));
    }

    protected function useProvisionerConnection(): bool
    {
        config(['database.connections.central' => config('database.connections.provisioner')]);
        DB::purge('central');

        try {
            DB::connection('central')->select('select 1');
        } catch (\Throwable $e) {
            $user = $this->linuxUser();

            $this->error("Could not connect as the provisioner (running as Linux user '{$user}').");
            $this->error('Run this command as: sudo -u lavoro_provisioner php artisan ' . $this->getName() . ' ...');
            $this->error('Or install /etc/sudoers.d/lavoro-admin (Task 2, Step 2b) and it will elevate itself.');

            return false;
        }

        return true;
    }

    protected function linuxUser(): string
    {
        return function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
            : 'unknown';
    }
}
```

Five things this shape is buying, none of them optional:

- **`pcntl_exec`, not `passthru` or `system`.** argv passes through as an array, so
  a tenant named `"Spee B.V."` survives without quoting every element through
  `escapeshellarg`. It also *replaces* the process rather than nesting one, so the
  exit code is the real one and `Ctrl-C` reaches the right thing.
- **No loop guard is needed, and adding one would be worse.** After `sudo` the
  euid *is* the provisioner, so the first check short-circuits on the second pass.
  The obvious guard — a sentinel environment variable — does not survive `sudo`
  at all, because sudoers defaults to `env_reset`. It would look like a
  safeguard and be nothing.
- **`sudo -n`.** Non-interactive, so on a machine without the rule it fails in
  milliseconds instead of prompting for a password inside what may be a deploy
  script. Every early `return` falls through to `useProvisionerConnection()`,
  which prints the manual command. Elevation is an optimisation, never a
  precondition.
- **`pcntl_exec` returning means it failed** — there is no success path past it.
  Falling through to the same error is right.
- **`command -v sudo` rather than a hardcoded `/usr/bin/sudo`.** `pcntl_exec` does
  no `PATH` lookup, and the path differs across distributions.

**What this does not do is make the command safe to run unattended.** It removes
typing, not judgement — `tenant:delete` drops a customer's database and now needs
one fewer deliberate act to get there. The `--force`/confirmation prompts on the
destructive commands are load-bearing after this change in a way they were not
before.

### Task 21, Step 2: Create the command

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\Module;
use App\Models\Central\Package;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:create {name} {admin_email} {--database=} {--admin-password=} {--package=starter} {--modules=*}';
    protected $description = 'Create a tenant, its database, its MySQL user, and an initial admin user';

    public function handle(): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        $name     = $this->argument('name');
        $email    = $this->argument('admin_email');
        $database = $this->option('database') ?: 'lavoro_tenant_' . Str::slug($name, '_');
        $password = $this->option('admin-password') ?: Str::password(16);
        $package  = $this->option('package');
        $modules  = $this->option('modules');

        if (!Package::on('central')->where('key', $package)->exists()) {
            $valid = Package::on('central')->orderBy('sort_order')->pluck('key')->implode(', ');
            $this->error("Unknown package '{$package}'. Valid packages: {$valid}");
            return self::FAILURE;
        }

        $valid_modules = Module::on('central')->pluck('key')->all();
        foreach ($modules as $module) {
            if (!in_array($module, $valid_modules, true)) {
                $this->error('Unknown module: ' . $module);
                return self::FAILURE;
            }
        }

        $exists = DB::connection('central')->select(
            'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?',
            [$database]
        );

        if ($exists) {
            $this->error("Refusing to use '{$database}' — that database already exists.");
            $this->error('Pass an explicit --database= if this is a different customer with a similar name.');
            return self::FAILURE;
        }

        $this->info("Creating tenant '{$name}' (database {$database})...");

        $tenant = Tenant::create([
            'id'              => (string) Str::ulid(),
            'name'            => $name,
            'package_key'     => $package,
            'modules'         => array_values(array_unique($modules)),
            'tenancy_db_name' => $database,
        ]);

        tenancy()->initialize($tenant);

        $user = User::create([
            'name'     => 'Beheerder',
            'email'    => $email,
            'password' => $password,
        ]);

        $admin_role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->syncWithoutDetaching([$admin_role->id]);

        tenancy()->end();

        $this->info("Tenant ID: {$tenant->id}");
        $this->info("Package: {$package}");
        $this->info("Database user: {$tenant->tenancy_db_username}");
        $this->info("Admin: {$email}");
        $this->info("Password: {$password}");
        return self::SUCCESS;
    }
}
```

### Task 21, Step 3: Verify the tenant got its own confined MySQL user

```bash
php artisan tenant:create "Test BV" test@test.nl --package=starter

# The generated user exists and reaches only its own database:
mysql -u lavoro_provisioner --protocol=socket -e "SHOW GRANTS FOR '<printed-username>'@'%';"
```

Expected: a single `GRANT ... ON \`lavoro_tenant_<id>\`.*` line — no `*.*` grant, no `CREATE USER`, no `GRANT OPTION`. Then confirm the stored password is not readable in the clear:

```bash
mysql -u lavoro_app -p -e "SELECT tenancy_db_username, LEFT(tenancy_db_password, 24) FROM lavoro_landlord.tenants;"
```

Expected: the password column shows base64-looking ciphertext (`eyJpdiI6...`), not a usable password.

### Task 21, Step 4: Commit

```bash
git add app/Console/Commands/Concerns/RunsAsProvisioner.php app/Console/Commands/CreateTenant.php
git commit -m "feat(tenancy): add tenant:create with per-tenant database user"
```

---

## Task 22: `tenant:delete` command (cleanup / failed-creation recovery)

If tenant creation fails partway, or a tenant is offboarded, this drops the database, **the tenant's MySQL user**, the central lookup rows, and the tenant record. Deleting the `Tenant` fires the package's `DeleteDatabase` job if wired; to be explicit and safe we drop both directly.

Dropping the user matters: leaving it behind accumulates orphaned MySQL accounts that still have grants on a database name that could later be reused, which is exactly the sort of stale privilege that turns into a cross-tenant hole.

Like `tenant:create`, this runs as the provisioner:

```bash
php artisan tenant:delete <id>
```

**Files:** `app/Console/Commands/DeleteTenant.php`

### Task 22, Step 1: Create the command

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:delete {id}';
    protected $description = 'Drop a tenant database, its MySQL user, and its central records';

    public function handle(): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $database = $tenant->getDatabaseName();
        $db_user  = $tenant->tenancy_db_username;

        if (!$this->confirm("Permanently drop database '{$database}' and all its data?")) {
            return self::FAILURE;
        }

        DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

        if ($db_user) {
            DB::connection('central')->statement("DROP USER IF EXISTS `{$db_user}`@`%`");
            $this->info("Dropped MySQL user {$db_user}.");
        }

        UserTenantLookup::on('central')->where('tenant_id', $tenant->id)->delete();
        $tenant->delete();

        $this->info("Deleted tenant {$tenant->id} and database {$database}.");
        return self::SUCCESS;
    }
}
```

### Task 22, Step 2: Verify no orphaned user remains

```bash
mysql -u lavoro_provisioner --protocol=socket -e "SELECT user, host FROM mysql.user WHERE user LIKE 'lavoro%';"
```

Expected: no row for the deleted tenant.

### Task 22, Step 3: Commit

```bash
git add app/Console/Commands/DeleteTenant.php
git commit -m "feat(tenancy): add tenant:delete for cleanup"
```

---

## Task 23: `TenantDatabaseSeeder`

Runs automatically when a new tenant database is created. It seeds only what the tenant migrations do not: the company record, a starting set of service order stages, and the roles with their permissions. Roles and permissions already come from the `seed_*_permissions` migrations and must not be duplicated here.

**Seed a stage for all six flags, not just the three obvious ones.** Three of them are looked up by code that null-guards and returns:

| Flag | Looked up by | If no stage has it |
| --- | --- | --- |
| `is_planning_cancelled_state` | `ServiceOrder::revertToPlanningCancelledStage()` | Deleting an appointment leaves the order sitting in Gepland |
| `is_invoiced_state` | `AdvanceOrderToInvoicedStage` listener | Recording an external invoice number moves nothing |
| `is_incomplete_state` | `ServiceOrderController` (`incompleteStageId` prop) | Marking a werkbon partially complete is dead |

Each of those call sites does `if (!$stage) { return; }`. Nothing throws, nothing logs — the feature simply never happens, and a new customer has three broken things nobody can explain. The names below can be changed afterwards; the flags are what has to exist on day one.

**Two ordering rules, both enforced somewhere and neither obvious:**

- **Gefactureerd must sort after Gesloten.** `ChecksStageOrdering` rejects any edit that puts the invoiced stage before the closed one, so seeding them the wrong way round makes the reorder screen refuse valid changes.
- **Planning geannuleerd must sort before Gepland.** `advanceToPlannedStage()` refuses to move an order whose current stage `order` is `>=` the planned stage's. Put cancelled after planned and a cancelled order can never be re-planned — it just stops moving.

`is_plannable_state` is the **only** one of the six the stage controller does not force to be unique, so more than one stage may carry it. Give it to Planning geannuleerd as well as Nieuw, so a cancelled order returns to the planner's to-plan list instead of disappearing.

**Files:** `database/seeders/TenantDatabaseSeeder.php`

### Task 23, Step 1: Create the seeder

```php
<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ServiceOrderStage;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::firstOrCreate(
            ['is_main' => true],
            ['name' => tenancy()->tenant->name]
        );

        $default_flags = [
            'is_plannable_state'          => false,
            'is_planned_state'            => false,
            'is_closed_state'             => false,
            'is_planning_cancelled_state' => false,
            'is_invoiced_state'           => false,
            'is_incomplete_state'         => false,
        ];

        $stages = [
            ['name' => 'Nieuw',                'order' => 1, 'is_plannable_state' => true],
            ['name' => 'Planning geannuleerd', 'order' => 2, 'is_plannable_state' => true, 'is_planning_cancelled_state' => true],
            ['name' => 'Gepland',              'order' => 3, 'is_planned_state' => true],
            ['name' => 'Niet afgerond',        'order' => 4, 'is_incomplete_state' => true],
            ['name' => 'Gesloten',             'order' => 5, 'is_closed_state' => true],
            ['name' => 'Gefactureerd',         'order' => 6, 'is_invoiced_state' => true],
        ];

        foreach ($stages as $stage) {
            ServiceOrderStage::firstOrCreate(
                ['name' => $stage['name']],
                array_merge($default_flags, $stage)
            );
        }
    }
}
```

### Task 23, Step 2: Seed the roles and attach their permissions

Permissions themselves come from the `seed_*_permissions` migrations — the seeder
must never create one. It creates **roles** and attaches existing permissions
**by name**.

The role list lives in **one file**, `database/seeders/data/tenant_roles.php`, which
maps each role to the file holding its permissions. Adding a role is a line there
and a file beside it, and nothing else changes:

```php
$roles = include base_path('database/seeders/data/tenant_roles.php');

foreach ($roles as $name => $slug) {
    $role = Role::firstOrCreate(['name' => $name]);

    $names = include base_path("database/seeders/data/{$slug}_permissions.php");

    $role->permissions()->syncWithoutDetaching(
        Permission::whereIn('name', $names)->pluck('id')
    );
}
```

The eleven roles it starts with come from a live install. Two look wrong and are
not: `admin` holds only `assistant.use`, because admins bypass every other check
and that is the one permission `AssistantPolicy` deliberately withholds from them;
`technisch beheer` holds only `technical.management`, the `explicitPermission` in
`useMenu.js` that admins likewise do not inherit. Those two roles exist to grant
the app's two deliberate exceptions to admin-sees-everything.

`Monteur` uses `serviceorder.read_own` rather than `serviceorder.read`, so a
mechanic sees their own werkbonnen instead of the whole company's.

**Look up by name and let unknown names fall through.** Permission ids differ
between installs, and the set drifts as migrations add to it — `whereIn` on names
simply attaches what exists. A role referencing a permission this installation
does not have gets it silently skipped, which is the right outcome: the
alternative is a failed `tenant:create` because one data file is a week ahead of
one migration.

`syncWithoutDetaching` rather than `sync`, so re-running the seeder never strips
a permission an operator added by hand.

### Task 23, Step 3: Check a fresh tenant has all six

```bash
php artisan tenant:create "Seed Test BV" seed@test.nl --admin-password=secret123
```

Then, in that tenant, confirm each flag is held by exactly one stage — except `is_plannable_state`, which two stages hold:

```sql
SELECT name, `order`, is_plannable_state, is_planned_state, is_planning_cancelled_state,
       is_incomplete_state, is_closed_state, is_invoiced_state
FROM service_order_stages ORDER BY `order`;
```

Task 43's `tenancy:doctor` is the place for this check long-term; do it by hand here.

### Task 23, Step 4: Commit

```bash
git add database/seeders/TenantDatabaseSeeder.php database/seeders/data/
git commit -m "feat(tenancy): seed stages, roles and permissions for a new tenant"
```

---

## Task 24: API routes — tenant from the session

The API uses stateful Sanctum: `bootstrap/app.php` calls `$middleware->statefulApi()`, and there are **no bearer tokens anywhere** (`createToken` is never called) — the SPA and the Android app both authenticate with session cookies. `EnsureFrontendRequestsAreStateful` runs the session middleware for requests from stateful origins *before* route middleware, so by the time our middleware runs, `session('tenant_id')` is available. That means API tenancy works exactly like web tenancy, with **no frontend changes at all**.

Applied as a named route middleware rather than a global prepend, so any future public API endpoint can skip it.

**There is deliberately no `X-Tenant-ID` header fallback**, and one should not be added later either.

A header is set by whoever sends the request. Accept it and the client picks which company's database we read.

Here is what that looks like. Somebody has a working API token for company A. They send it with `X-Tenant-ID: B`. We switch to company B's database, Sanctum looks for their token there, does not find it, and returns 401. No data comes back, so nothing leaks.

But look at what already happened before that 401. We opened a connection to company B's database. We counted the request against company B's rate limit. We wrote company B into the log. And the only thing that stopped it was Sanctum checking afterwards — so the day someone adds a route that does not use Sanctum, or a middleware that runs before it, the header is in charge and nobody notices.

When bearer tokens do arrive, work out the company **from the token** instead, the same way login works it out from the e-mail address. That is Task 41, which adds one branch to this middleware. Until then the rule here is simple: no session, no tenant, 400.

**Files:** `app/Http/Middleware/InitializeTenancyForApi.php`, `bootstrap/app.php`, `routes/api.php`

### Task 24, Step 1: Create the middleware

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyForApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant_id = $request->hasSession()
            ? $request->session()->get('tenant_id')
            : null;

        if (!$tenant_id) {
            return response()->json(['message' => 'Tenant kon niet worden bepaald.'], 400);
        }

        $tenant = Tenant::on('central')->find($tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'Onbekende tenant.'], 400);
        }

        $initialized_here = false;
        if (!tenancy()->initialized) {
            tenancy()->initialize($tenant);
            $initialized_here = true;
        }

        $response = $next($request);

        if ($initialized_here) {
            tenancy()->end();
        }

        return $response;
    }
}
```

Same `$initialized_here` guard as Task 12, for the same reason — several API tests drive these routes with tenancy already established by the `TestCase`.

### Task 24, Step 2: Register the alias in `bootstrap/app.php`

```php
$middleware->alias([
    'admin'      => EnsureUserIsAdmin::class,
    'tenant.api' => \App\Http\Middleware\InitializeTenancyForApi::class,
]);
```

### Task 24, Step 3: Apply it to the authenticated API group in `routes/api.php`

The whole file is currently one `Route::group(['middleware' => 'auth:sanctum'], ...)`; add `tenant.api` before it:

```php
Route::group(['middleware' => ['tenant.api', 'auth:sanctum']], function () {
    // all existing routes unchanged
});
```

**Ordering matters here for the same reason as Task 12.** The `api` group also ends in `SubstituteBindings`, and many of these routes bind models (`events/{event}`, `images/{image}`, `projects/{project}`, `plan-groups/{group}`, `users/{user}`, …). Listing `tenant.api` first in the array is not sufficient on its own, because Laravel priority-sorts the combined group + route middleware list. `InitializeTenancyForApi` is already included in the Task 12 priority array — confirm it is there before relying on this, and verify with:

```bash
php artisan route:list --path=api/events -v | head -20
```

`InitializeTenancyForApi` must appear before both `SubstituteBindings` and `auth:sanctum`.

### Task 24, Step 4: Commit

```bash
git add app/Http/Middleware/InitializeTenancyForApi.php bootstrap/app.php routes/api.php
git commit -m "feat(tenancy): resolve tenant from session on API requests"
```

---

## Task 25: Route the Google webhook to the right tenant

The webhook arrives from Google with no session and no cookie. The existing code already round-trips a random secret: `RenewWatchChannelsJob` and `BackfillCalendarJob` both store a `Str::random(40)` token on the calendar row, and `GoogleWebhookController` rejects notifications whose `X-Goog-Channel-Token` fails `hash_equals` against it. **Keep that check.** We only prepend the tenant key to the token — `"<tenant_key>|<random>"` — so the controller can pick the database before looking the channel up; the stored token is the full prefixed string, so the `hash_equals` comparison still covers the entire value Google echoes back.

Channels created before this change carry unprefixed tokens and cannot be routed; they self-heal on renewal (Task 27 Step 6 forces it), and the 5-minute polling schedule covers the gap.

**Files:** `app/Jobs/Google/RenewWatchChannelsJob.php`, `app/Jobs/Google/BackfillCalendarJob.php`, `app/Http/Controllers/GoogleWebhookController.php`

### Task 25, Step 1: Prefix the token in `RenewWatchChannelsJob`

In `handle()`, the loop currently builds `$token = Str::random(40);`. Change it to:

```php
$token = tenancy()->tenant->getTenantKey() . '|' . Str::random(40);
```

### Task 25, Step 2: Prefix the token in `BackfillCalendarJob::registerWatch()`

Same change — replace `$token = \Illuminate\Support\Str::random(40);` with:

```php
$token = tenancy()->tenant->getTenantKey() . '|' . \Illuminate\Support\Str::random(40);
```

Both jobs are always dispatched from tenant context (scheduler loop or a tenant web request), so `tenancy()->tenant` is set when they run on the worker via the `QueueTenancyBootstrapper`.

### Task 25, Step 3: Initialize tenancy from the token prefix in `GoogleWebhookController::handle()`

After the four `$request->header(...)` reads and the `if (!$channel_id || !$resource_id)` guard, and before the `GoogleSyncedCalendar::where('watch_channel_id', $channel_id)->first()` lookup, add:

```php
$token_parts = explode('|', (string) $channel_token, 2);
if (count($token_parts) !== 2) {
    return response('Unknown channel', 404);
}

$tenant = \App\Models\Tenant::on('central')->find($token_parts[0]);
if (!$tenant) {
    return response('Unknown channel', 404);
}

tenancy()->initialize($tenant);
```

The rest of the method (channel lookup, full-token `hash_equals`, resource-id check, `PullCalendarChangesJob::dispatch`) stays exactly as it is — the dispatch happens inside tenant context, so the queue bootstrapper tags the job with the right tenant.

### Task 25, Step 4: Commit

```bash
git add app/Jobs/Google/RenewWatchChannelsJob.php \
        app/Jobs/Google/BackfillCalendarJob.php \
        app/Http/Controllers/GoogleWebhookController.php
git commit -m "feat(tenancy): route Google webhook to tenant via prefixed channel token"
```

---

## Task 26: `tenant:setup-existing` — register a pre-tenancy database

Registers an already-existing, already-migrated database as a tenant, **gives it its own MySQL user**, and copies its user emails into the central lookup. Uses a direct insert to skip the `TenantCreated` pipeline, since the database already exists and is already migrated. The package and modules for this tenant are set afterwards with `tenant:package` and `tenant:modules` (Task 34).

**Rename the database to `lavoro_tenant_<something>` before you run this.**

The provisioner account can only touch databases whose name starts with `lavoro_tenant_`, plus the landlord database (Task 2). Point this command at a database that still has its old name — `lavoro_fsm`, `spee_production` — and it gets as far as creating the tenant's MySQL user and then stops, because the provisioner is not allowed to grant that user rights on a database outside the prefix.

That is why Tasks 27 and 29 restore the old dump *into* a `lavoro_tenant_<slug>` database instead of registering the original where it stands.

You will get a clear error, not quiet corruption. But you will get it halfway through the deployment, with the app already down and users locked out while you work out what to do. Sort the name out first.

**Provisioning the MySQL user is not optional here.** After Task 2, `lavoro_app` can reach only the landlord database. A tenant registered without its own credentials is therefore unreachable by the web app — every request for it fails with an access-denied error. So this command runs as the provisioner (elevating itself, Task 21) and creates the tenant's MySQL user in the same breath:

```bash
php artisan tenant:setup-existing "Naam" lavoro_tenant_acme
```

**You do not have to create the MySQL login first — this command does it.** `handle()` calls `TenantDbUserProvisioner` itself (Step 1). The separate `tenant:provision-db-user` command in Step 3 is only for afterwards: rotating a password, or repairing a login that was deleted.

**Note the `User::withTrashed()` on the `pluck` below — deleted users are included on purpose.**

Only e-mail addresses are copied, never user rows. People stay in their own company's database. `user_tenant_lookups` is just a list of which company owns which e-mail address, one row per address.

A soft-deleted user still has their address sitting in `users.email`, and `unique:users,email` still refuses to reuse it — the validator queries the table directly and ignores the "deleted" flag. So the address is still taken here.

Copy only the live users and that address goes missing from the central list while still being unusable in this company. Another company can then claim it. And now the first company can never bring that person back, because logging in works out the company **from** the e-mail address, and the address now points somewhere else.

It gets worse: `$emails` is also what the `$conflicts` check below reads. So the one thing that would have caught the clash never sees it, and onboarding reports success.

The Task 18 observer keeps the lookup row through a soft delete for the same reason.

`tenant:setup-existing` gets run twice in this project: once on the main install's database during the cutover (Task 27), and again for each dedicated-subdomain install absorbed later (Task 29).

**Files:** `app/Console/Commands/SetupExistingTenant.php`

### Task 26, Step 1: Create the command

The tenant is registered on the `starter` package (the safe default; raise it with `tenant:package` once you know the customer's real subscription). `extra_field_seats`, `extra_office_seats` and `storage_limit_gb` fall to their column defaults (0, 0, 50) on the raw insert. Seat-type counts are reported only once the tenant migrations have added `users.seat_type`; on a legacy database that column may not exist yet, so the command detects it and otherwise reminds the operator to migrate first.

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SetupExistingTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:setup-existing {name} {database}';
    protected $description = 'Register an existing pre-tenancy database as a tenant and give it its own MySQL user';

    public function handle(): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        if ($this->argument('database') === config('database.connections.central.database')) {
            $this->error('Refusing to register the landlord database as a tenant.');
            return self::FAILURE;
        }

        $already_registered = DB::connection('central')->table('tenants')
            ->whereJsonContains('data->tenancy_db_name', $this->argument('database'))
            ->exists();

        if ($already_registered) {
            $this->error("Database '{$this->argument('database')}' is already registered to a tenant.");
            return self::FAILURE;
        }

        $id = (string) Str::ulid();

        DB::connection('central')->table('tenants')->insert([
            'id'          => $id,
            'name'        => $this->argument('name'),
            'package_key' => 'starter',
            'modules'     => json_encode([]),
            'data'        => json_encode(['tenancy_db_name' => $this->argument('database')]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $tenant = Tenant::on('central')->findOrFail($id);

        // Give the tenant its own confined MySQL user before anything tries to
        // reach its database — lavoro_app cannot, by design.
        app(TenantDbUserProvisioner::class)->provision($tenant);
        $this->info("Created MySQL user {$tenant->fresh()->tenancy_db_username} for {$this->argument('database')}.");

        tenancy()->initialize($tenant->fresh());

        $emails = User::withTrashed()->pluck('email');

        $conflicts = UserTenantLookup::on('central')
            ->whereIn('email', $emails)
            ->where('tenant_id', '!=', $id)
            ->pluck('email');

        if ($conflicts->isNotEmpty()) {
            tenancy()->end();
            DB::connection('central')->table('tenants')->where('id', $id)->delete();
            $this->error('Aborted: these emails already belong to another tenant:');
            $conflicts->each(fn ($email) => $this->error("  - {$email}"));
            $this->error('Resolve the duplicates in the source database first, then rerun.');
            return self::FAILURE;
        }

        $emails->each(function (string $email) use ($id) {
            UserTenantLookup::on('central')->updateOrCreate(
                ['email' => $email],
                ['tenant_id' => $id]
            );
        });

        $this->info("Registered '{$this->argument('name')}' as tenant: {$id}");
        $this->info("Populated {$emails->count()} email lookups.");

        if (Schema::connection('tenant')->hasColumn('users', 'seat_type')) {
            $field  = User::withTrashed()->where('seat_type', 'field')->count();
            $office = User::withTrashed()->where('seat_type', 'office')->count();
            $this->info("Seat usage: {$field} field / {$office} office — review against the package limits.");
        } else {
            $this->warn("seat_type not present yet. Run `php artisan tenants:migrate --tenants={$id}` (backfills every user to office), then mark the field staff.");
        }

        tenancy()->end();

        $this->warn("Set the package with: php artisan tenant:package {$id} <key>");
        $this->warn("Now migrate existing files into storage/tenant-{$id}/ — see Task 27 Step 5 / Task 29 Step 5.");
        return self::SUCCESS;
    }
}
```

### Task 26, Step 2: Create the `TenantDbUserProvisioner` service

This is the one place that creates a tenant's MySQL user, so `tenant:setup-existing`, the standalone command below, and any future rotation all behave identically. It reuses the package's own generators and grant list rather than duplicating them, so a tenant provisioned here is indistinguishable from one created by `tenant:create`.

```php
<?php

namespace App\Services;

use App\Models\Tenant;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

class TenantDbUserProvisioner
{
    public function provision(Tenant $tenant): void
    {
        $config = $tenant->database();

        $username = (DatabaseConfig::$usernameGenerator)($tenant);
        $password = (DatabaseConfig::$passwordGenerator)($tenant);

        $tenant->tenancy_db_username = $username;
        $tenant->tenancy_db_password = $password;
        $tenant->save();

        $manager = $config->manager();
        if (!$manager instanceof PermissionControlledMySQLDatabaseManager) {
            throw new \RuntimeException('The configured MySQL manager does not manage database users.');
        }

        if ($manager->userExists($username)) {
            $manager->deleteUser($tenant->database());
        }

        $manager->createUser($tenant->database());
    }
}
```

`createUser()` takes no username or password. It reads them off the tenant row, which is what `$tenant->database()` builds a fresh config from — so the row has to be saved first. Save it afterwards instead and you create a MySQL login with the previous password, or with none at all.

Saving first also makes a failure recoverable. If MySQL refuses, the row already holds the username and password, so running the command again finishes the job. The other way round leaves MySQL with a login the application has no record of.

### Task 26, Step 3: Create the standalone `tenant:provision-db-user` command

Creates the MySQL login a tenant uses to reach its own database, grants it access to that database only, and stores the username and encrypted password on the tenant's row.

**You never need to run this before `tenant:setup-existing` or `tenant:create`.** Both create the login themselves, and both are the normal way a tenant comes into existence — including the first one during the cutover (Task 27).

This standalone version is for afterwards, and there are only two reasons to reach for it: rotating a tenant's password, or repairing a tenant whose MySQL login was deleted or has stopped working.

```php
<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Tenant;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Console\Command;

class ProvisionTenantDbUser extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:provision-db-user {id}';
    protected $description = 'Create or rotate the dedicated MySQL user for a tenant';

    public function handle(TenantDbUserProvisioner $provisioner): int
    {
        if (!$this->useProvisionerConnection()) {
            return self::FAILURE;
        }

        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        $provisioner->provision($tenant);

        $this->info("Tenant '{$tenant->name}' now uses MySQL user {$tenant->fresh()->tenancy_db_username}.");
        $this->warn('Restart the queue workers so they pick up the new credentials.');
        return self::SUCCESS;
    }
}
```

Rotating a live tenant's password briefly breaks in-flight connections; do it in a quiet window and restart workers afterwards.

### Task 26, Step 4: Verify

```bash
php artisan tenant:provision-db-user <id>
mysql -u lavoro_provisioner --protocol=socket -e "SHOW GRANTS FOR '<printed-username>'@'%';"
```

Expected: grants on that tenant's database only.

### Task 26, Step 5: Commit

```bash
git add app/Console/Commands/SetupExistingTenant.php app/Console/Commands/ProvisionTenantDbUser.php \
        app/Services/TenantDbUserProvisioner.php
git commit -m "feat(tenancy): provision a dedicated MySQL user per tenant"
```

---

## Task 27: The cutover script

**Read Task 44 first.** If you can stand up a fresh multi-tenant installation on
production and import the existing customers into it, do that instead — the old
install keeps running throughout and rollback is a DNS change. This task is the
in-place alternative, for when a second installation is not practical.

The one-time move of the live install into a tenant. **Build this as a script here
and rehearse it locally before it goes anywhere near production.**

Restore a production dump onto your machine, run the script against it end to end,
throw the result away, fix what broke, and repeat until it runs clean. A cutover
you have watched succeed three times locally is a different thing from a list of
commands you are reading for the first time with the app offline.

The steps below are what the script does, in order. Keep them as separate
functions with their own output so a failure names the step it happened in.

Destructive and irreversible on the machine it runs against — which is why the
machine is your own until it isn't. On production: take a full backup first, run
it in a maintenance window, and expect every user to be logged out.

**Prerequisites:** full MySQL dump taken; the `lavoro_app` account exists granted on `lavoro_landlord` only, and the `lavoro_provisioner` Linux user and its `auth_socket` MySQL account exist (Task 2, Steps 1–3); `.env` has `DB_CONNECTION=mysql`, `DB_DATABASE=lavoro_landlord`, `DB_USERNAME=lavoro_app`, `DB_PROVISIONER_SOCKET=/var/run/mysqld/mysqld.sock`, `SESSION_CONNECTION=central`.

**This is less destructive than it looks.** Both new databases — the landlord registry and the tenant copy — are *new names*, so nothing is dropped or recreated in place. The existing database is copied to the tenant name and then simply **left alone** as a rollback artefact until the smoke test passes.

Read the existing database's name off the server rather than assuming it; every step below refers to it as `$EXISTING`:

```bash
grep '^DB_DATABASE=' .env
```

> **Do not run `./deploy.sh` between this cutover and Task 38.** The current script backs up only the database named in `DB_DATABASE` (now the small central registry) and runs only central migrations — both silently. Until Task 38 lands, a routine deploy would rotate your real backups out and skip every tenant migration without printing an error. This cutover runs its own script; `deploy.sh` is not involved.

### Task 27, Step 0: Take the app down and stop everything that writes

**Use `php artisan app:maintenance`, not `php artisan down`.** The project has its own toggle that renders a proper page with a message and an expected end time, served before Composer loads. `down` on its own gives customers a bare framework error page.

Step 1 drops and recreates the database the running application is connected to. Anything still writing during that window loses data silently, and a queue worker holding a booted container will keep serving stale config afterwards.

```bash
php artisan down

# Stop the queue worker and the scheduler cron (adjust to your process manager)
sudo systemctl stop lavoro-worker      # or: supervisorctl stop lavoro-worker:*
sudo crontab -l                        # confirm/comment the schedule:run entry
```

Confirm nothing is connected before continuing:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW PROCESSLIST;"
```

### Task 27, Step 1: Copy the existing database to the tenant name, and create an empty central

MySQL has no rename-database command, so copy via dump/restore. The existing database is **not** modified — it stays exactly as it is, untouched, as the fastest possible rollback.

Run the dump as an admin account: the existing database predates tenancy, so its name almost certainly does not start with `lavoro_tenant_` and the provisioner cannot read it. The creates and the restore then run as **`lavoro_provisioner`** — `lavoro_app` is confined to the landlord database after Task 2 and cannot create databases or write to a tenant database.

```bash
EXISTING=<paste from the .env check above>   # the current production database, left intact
TENANT_DB=lavoro_tenant_acme                 # rename to match the customer
CENTRAL_DB=lavoro_landlord

mysqldump -u root -p --single-transaction --routines --triggers "$EXISTING" > /tmp/tenant_backup.sql

sudo -u lavoro_provisioner mysql --protocol=socket -e "CREATE DATABASE $TENANT_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo -u lavoro_provisioner mysql --protocol=socket "$TENANT_DB" < /tmp/tenant_backup.sql
sudo -u lavoro_provisioner mysql --protocol=socket -e "CREATE DATABASE $CENTRAL_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

`--protocol=socket` is required: over TCP the account would be `lavoro_provisioner@127.0.0.1`, which does not exist. `--single-transaction` keeps the dump consistent without locking; `--routines --triggers` are there because a plain `mysqldump` silently omits both, which is an easy way to lose behaviour you did not know the schema had.

Confirm all three databases now exist and the copy is complete before continuing:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW DATABASES LIKE 'lavoro%';"
sudo -u lavoro_provisioner mysql --protocol=socket -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema IN ('$EXISTING','$TENANT_DB') GROUP BY table_schema;"
```

The two table counts must match.

### Task 27, Step 2: Clear every cached config, then run the central migrations

`.env` gained `SESSION_CONNECTION=central` and `config/database.php`, `config/queue.php`, `config/tenancy.php` all changed. A cached config bundle from before the deploy would quietly override all of it — including pointing sessions and the queue at the wrong database.

```bash
php artisan optimize:clear
php artisan migrate --force
```

Creates `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `tenants`, `user_tenant_lookups`, `migrations` in the central database.

Sanity-check that `migrate` did **not** pick up tenant migrations (Task 8 moved them into a subdirectory that plain `migrate` does not descend into) — the central `migrations` table should hold one row per file in `database/migrations/*.php` (6 as this plan stands), not 200+:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e "SELECT COUNT(*) FROM lavoro_landlord.migrations;"
```

### Task 27, Step 3: Drop the now-unused `sessions` table from the tenant copy (optional tidy)

The restored tenant database still contains a `sessions` table from before the split. It is unused (sessions are central now) and harmless; drop it if you want a clean schema:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket "$TENANT_DB" -e "DROP TABLE IF EXISTS sessions;"
```

### Task 27, Step 4: Register the existing database as tenant #1 and set its package/modules

This both registers the tenant and creates its dedicated MySQL user, so it must run as the provisioner Linux user (Task 2):

```bash
php artisan tenant:setup-existing "Naam van het bedrijf" lavoro_tenant_acme
```

Confirm the tenant can actually be reached with its own credentials before going further — `lavoro_app` deliberately cannot reach it, so a missing user shows up as a site-wide access-denied error after `php artisan up`:

```bash
mysql -u lavoro_provisioner --protocol=socket \
  -e "SELECT tenancy_db_username FROM lavoro_landlord.tenants;"     # must be non-empty
```

Record the printed tenant ID — call it `TENANT_ID` for the next steps. Then:

```bash
php artisan tenant:package "$TENANT_ID" business
php artisan tenant:seats "$TENANT_ID" --field=+2 --office=+1
php artisan tenant:modules "$TENANT_ID" --add=snelstart --add=google_calendar
php artisan tenant:storage "$TENANT_ID" --limit=100
```

(Pick the package, extra seats, modules and storage limit that match the customer's actual subscription. Seed the seats and storage limit from their real usage so they do not start over limit — `tenant:setup-existing` printed the seat counts, and `du -sh storage/tenant-$TENANT_ID` shows the storage.)

### Task 27, Step 5: Move existing uploaded files into the tenant storage root

Task 14 puts each tenant's files under `storage/tenant-<id>/public/...`. Existing files currently sit in `storage/app/public/...` (locally: `uploaded/` and `company-logos/`; production may also have `users/` avatar folders). Move them into the new root. **No database path rewrite is needed** — the stored `path` values are relative to the disk root, which is exactly what the per-tenant disk root now resolves against. The `app/releases/` folder (APK) stays where it is.

```bash
TENANT_ID=<paste-from-step-4>
cd storage

mkdir -p "tenant-$TENANT_ID/public"
# Move every existing public upload dir into the tenant's public root
for d in app/public/users app/public/uploaded app/public/company-logos; do
  [ -e "$d" ] && mv "$d" "tenant-$TENANT_ID/public/"
done

# If anything was stored on the private/local disk, move it too:
if [ -d app/private ] && [ -n "$(ls -A app/private 2>/dev/null)" ]; then
  mkdir -p "tenant-$TENANT_ID/local"
  mv app/private/* "tenant-$TENANT_ID/local/"
fi
```

Do not skim the `app/private` branch. That directory holds `assistant-photos/`, `assistant-files/` and `assistant-reports/`, and the last of those is a full transcript of somebody's working day, tools and all. The wildcard moves them — no change to the script — but check the branch actually ran rather than assuming, because leaving those behind puts one tenant's conversations in a directory that after this deployment belongs to nobody, and the nightly prune will never find them again.

After this, e.g. an image whose stored `path` is `uploaded/customer/5/documents/x.jpg` resolves to `storage/tenant-<id>/public/uploaded/customer/5/documents/x.jpg`, served via `/files/images/<id>`.

### Task 27, Step 6: Force-renew the Google watch channels

Existing watch channels carry tokens without the tenant prefix (Task 25), so the webhook cannot route them. Expire them so the hourly renewal recreates them with prefixed tokens on the next run (the 5-minute polling schedule keeps sync working meanwhile):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket "$TENANT_DB" \
  -e "UPDATE google_synced_calendars SET watch_expires_at = NOW() WHERE watch_channel_id IS NOT NULL;"
```

### Task 27, Step 7: Build front-end assets, bring everything back up, and verify

```bash
npm run build

# Restart workers so they pick up the new config, then lift maintenance mode
php artisan queue:restart
sudo systemctl start lavoro-worker     # or: supervisorctl start lavoro-worker:*
sudo crontab -e                        # restore the schedule:run entry
php artisan up

sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW TABLES IN lavoro_landlord;"
sudo -u lavoro_provisioner mysql --protocol=socket -e "SELECT id, name, package_key, storage_limit_gb, modules FROM lavoro_landlord.tenants;"
sudo -u lavoro_provisioner mysql --protocol=socket -e "SELECT COUNT(*) FROM lavoro_landlord.user_tenant_lookups;"
sudo -u lavoro_provisioner mysql --protocol=socket -e "SHOW TABLES IN lavoro_tenant_acme;" | head
```

### Task 27, Step 7b: Land the CLAUDE.md and handleiding.md updates (Task 42)

Tenancy is live from this point, so the rules in Task 42 become true from this
point. Ship them now rather than as a follow-up: the window between "tenancy is
live" and "the conventions are written down" is exactly when somebody adds a
migration to the wrong directory.

### Task 27, Step 7c: Run `php artisan tenancy:doctor` (Task 43)

Before the manual smoke test, not instead of it. The doctor checks what a smoke
test cannot see — that every tenant's password still decrypts, that no lookup
row points at a dead tenant, that the storage roots are writable — and it does
it for every tenant rather than the one you happen to click through.

**The scheduler heartbeat will be absent on this run, and that is expected.**
You restored the crontab a minute ago and the heartbeat is written every five.
Ignore it here, and **re-run the doctor fifteen minutes later** for that one
check. If it is still absent then, the crontab entry is not firing — which is
the failure this whole deployment is most likely to leave behind unnoticed,
because every scheduled task fails by simply never happening.

### Task 27, Step 8: Smoke test

Log in as an existing user, then walk this list. The first four are the failure modes most likely to survive to production, because each of them fails *silently* rather than with an error page:

- [ ] The dashboard loads, and a **detail page opens** (`/serviceorders/{id}`, `/customers/{id}`). A 404 on a record you can see in the index means the Task 12 middleware ordering is wrong and route-model binding is hitting the central database.
- [ ] An existing customer's documents and images display, and a previously uploaded avatar shows.
- [ ] **Export a werkbon to PDF** and confirm the photos *and* the company logo appear — this is the check for the Task 14 Step 7 path builders. A PDF that renders with everything except images means one of them was missed.
- [ ] **Send an appointment confirmation e-mail** and confirm the logo renders in the received message (Task 14 Step 7, item 6).
- [ ] Upload a new image and confirm it lands under `storage/tenant-<id>/public/…`, not `storage/app/public/`.
- [ ] Open the Planner and confirm its API calls return this tenant's events (`tenant.api` resolving from the session).
- [ ] Watch the log for a few minutes after the queue worker restarts — confirm no job fails with "table does not exist", which would mean a job ran without tenant context.
- [ ] Close the browser and reopen — remember-me should log you straight back in (session + tenant cookie).
- [ ] **Ask the assistant a question and check the meter moved.** One question exercises more of this plan at once than anything else on the list: a tenant table read (Task 8), the allowance ceiling on the central connection (Task 39), the module gate (Task 31) and — ask it to look at a photo — the per-tenant `local` disk (Task 14). If the answer arrives but `assistant_usage` gained no row, the central write is silently failing and the ceiling is not a ceiling.
- [ ] **Run `php artisan assistant:prune --dry-run` for the tenant** and confirm it reports counts rather than zero across the board. Zero everywhere means it is looking at the central database and an empty storage root, which is what a missed Task 20 conversion looks like from the outside.
- [ ] Open the bell and the notification-subscription page; confirm the unread count matches. If push is configured, subscribe a browser and send yourself one.

Existing sessions are gone (the central `sessions` table is fresh), so everyone re-logs in once — expected.

### Task 27, Step 9: Rollback plan

If the smoke test fails in a way you cannot fix inside the maintenance window, roll back rather than debug in production. **No database restore is involved** — `$EXISTING` was never modified, so rolling back is only a code and config revert plus moving the files back.

**Restore the old credentials, not just the old database name.** `lavoro_app` is granted on `lavoro_landlord` and nothing else (Task 2 Step 1), so pointing `DB_DATABASE` back at `$EXISTING` while leaving `DB_USERNAME=lavoro_app` produces an access-denied error on every request — a broken rollback in the middle of an incident. Put the pre-tenancy `DB_USERNAME` / `DB_PASSWORD` back too. Keep them to hand *before* you start the cutover; the pre-tenancy `.env` in your backup is the copy that matters.

```bash
php artisan down

git checkout <pre-tenancy-tag>

# .env: DB_DATABASE back to $EXISTING, remove SESSION_CONNECTION,
# and restore the pre-tenancy DB_USERNAME / DB_PASSWORD

# Files back out of the tenant root (Step 5 in reverse)
cd storage
mv "tenant-$TENANT_ID/public/"* app/public/ 2>/dev/null
[ -d "tenant-$TENANT_ID/local" ] && mv "tenant-$TENANT_ID/local/"* app/private/ 2>/dev/null
cd ..

php artisan optimize:clear && npm run build && php artisan up
```

Everyone re-logs in again (sessions were in `lavoro_landlord`, which the reverted code no longer reads) — that is the only user-visible cost.

Leave `lavoro_tenant_acme`, `lavoro_landlord`, and `/tmp/tenant_backup.sql` in place; they cost nothing and let you retry. Drop `$EXISTING` only once the customer has run on the new setup for a week or two — and take a final dump of it before you do.

---

## Task 28: Verify isolation with a second tenant

### Task 28, Step 1: Create a second tenant with an admin

```bash
php artisan tenant:create "Tweede Klant BV" admin@tweede.nl --admin-password=secret123 --package=team --modules=google_calendar
```

Confirm it prints a tenant ID, package, admin email, and password, and does not error. If it hangs, check the MySQL user has `CREATE DATABASE` and that no queue worker is needed (the pipeline runs inline via `shouldBeQueued(false)`).

### Task 28, Step 2: Confirm web isolation

Log in as `admin@tweede.nl`. Confirm you see an empty data set (only the seeded stages), not the first tenant's data. Upload an image and confirm it lands under `storage/tenant-<second-id>/public/...` and displays via `/files/images/<id>`. Confirm that requesting another tenant's image id returns 404.

### Task 28, Step 3: Confirm the API resolves the tenant from the session

While logged in as each tenant's user in the browser, open `/api/events?start=...&end=...` (or watch the Planner's own XHR calls) and confirm each tenant only sees their own events. Then confirm the fallback path:

```bash
# Unauthenticated, no session, no header — 400 from tenant.api:
curl http://localhost/api/events -H "Accept: application/json"
```

### Task 28, Step 4: Confirm a queued job runs in the right tenant

```bash
php artisan queue:work --once --verbose
```

Trigger a Google sync (or any queued import) from one tenant and confirm the data lands in that tenant's database, not the other's or the central one.

### Task 28, Step 4a: Confirm the audit trail follows the tenant

The signal layer writes on nearly every action, so a tenancy mistake here shows up as another tenant's history rather than as an error. Two checks, one per path:

```bash
php artisan queue:work --once --verbose
```

1. **In-request.** As each tenant, change a service order's stage, then confirm the new `activities` row (and its `activity_changes` rows) exist in that tenant's database and are absent from the other's and from `lavoro_landlord`.
2. **On the worker.** As one tenant, bulk-move more than 40 orders so `BulkMoveServiceOrderStageJob` queues, run the worker, and confirm the same. This is the path that exercises the `ActivityBuffer` reset from Task 11 together with the queue tenant tag from Task 9.

### Task 28, Step 5: Confirm package/module data round-trips

```bash
php artisan tenant:overview                            # the row shows Team, 1/5 field, 1/2 office, google_calendar
php artisan tenant:modules <second-id>                 # prints: google_calendar
```

And in the browser as the second tenant's user, check the Inertia page props include `tenant: { package: 'team', modules: ['google_calendar'] }`.

---

## Task 29: Migrate a dedicated-subdomain install into the multi-tenant app

Some customers run their own standalone copy of Lavoro on a dedicated subdomain — currently one at `spee.lavorofsm.nl` — each with its own database, storage, and `.env`. This task absorbs such an install into the multi-tenant app at `app.lavorofsm.nl` as a new tenant. It is a **repeatable runbook**: run it once per legacy install, in a maintenance window agreed with that customer. The steps below use the Spee install as the concrete example; substitute names for the next customer.

**Build this as a script too, and rehearse it the same way.** Restore the legacy
install's dump onto your machine alongside a local copy of the multi-tenant app,
run the absorption end to end, and check the result before doing it for real. This
one runs once per customer being absorbed, so unlike Task 27 you get to reuse it —
which is exactly why it should not be a list of commands somebody retypes.

The steps below are its contents.

**Prerequisites:**
- Task 27 is complete: `app.lavorofsm.nl` is live and multi-tenant.
- The legacy install is upgraded to the **latest pre-tenancy release** so its schema matches the files now in `database/migrations/tenant/`. Verify: `php artisan migrate:status` on the legacy host must show every migration Ran and none Pending.
- SSH access to the legacy host, and the central MySQL user has `CREATE DATABASE`.

### Task 29, Step 1: Freeze the legacy install and take a final backup

On the legacy host:

```bash
php artisan down
mysqldump -u "$OLD_DB_USER" -p"$OLD_DB_PASS" "$OLD_DB_NAME" > /tmp/spee_final.sql
```

Also stop the legacy queue worker and scheduler cron so nothing writes to the database after the dump.

### Task 29, Step 2: Transfer the dump and restore it as a tenant database

On the central server:

```bash
scp legacy-host:/tmp/spee_final.sql /tmp/spee_final.sql
sudo -u lavoro_provisioner mysql --protocol=socket -e "CREATE DATABASE lavoro_tenant_spee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_tenant_spee < /tmp/spee_final.sql
```

### Task 29, Step 3: Drop the infrastructure tables from the copy

Sessions are central now; the queue/cache tables in the copy are unused (jobs live centrally). Only `sessions` matters — the rest is optional tidying:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_tenant_spee \
  -e "DROP TABLE IF EXISTS sessions, cache, cache_locks, jobs, job_batches, failed_jobs;"
```

### Task 29, Step 4: Check for email collisions, then register the tenant

Every user email must be globally unique across tenants. Check up front — and include **soft-deleted** users, because their emails still occupy `users.email` and are still copied into the lookup by `tenant:setup-existing` (see Task 26):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -e \
  "SELECT u.email, u.deleted_at FROM lavoro_tenant_spee.users u
   JOIN lavoro_landlord.user_tenant_lookups l ON l.email = u.email;"
```

A collision on a *soft-deleted* row on either side is the easy case: force-delete that user in whichever database it is dead in, rather than renaming a live account. A collision between two live accounts (the same person working for two customers, or a shared `info@` address) needs a conversation with the customer — one of the two has to change.

If this returns rows, resolve them with the customer first (change the email in the source database). Then register — the command aborts and rolls back by itself if a collision slipped through:

```bash
php artisan tenant:setup-existing "Spee" lavoro_tenant_spee
```

(As provisioner — this also creates Spee's own MySQL user, without which the app cannot reach the imported database.)

Record the printed tenant ID as `TENANT_ID`, then set the subscription:

```bash
php artisan tenant:package "$TENANT_ID" business
php artisan tenant:seats "$TENANT_ID" --field=+7 --office=+1
php artisan tenant:modules "$TENANT_ID" --add=google_calendar
php artisan tenant:storage "$TENANT_ID" --limit=100
```

Seed the seats and storage from Spee's real usage so they do not import over limit — `tenant:setup-existing` printed the seat counts (after Step 5 migrates `seat_type`), and `du -sh storage/tenant-$TENANT_ID` shows the storage.

### Task 29, Step 5: Copy the uploaded files into the tenant storage root

**This must happen before Step 6 migrates the schema.** Migrations are not guaranteed to be pure schema changes — some read the filesystem to backfill a column. `2026_07_24_000002_add_category_size_and_user_to_documents_table.php` is exactly that: it walks every `documents` row and calls `Storage::disk('public')->size($document->path)` to populate the new `size` column. Run it against a tenant whose files are not in place yet and every row silently gets `NULL` — no error, no failed migration, just a storage quota (Task 36) that under-counts every imported document forever. Copy first, migrate second.

On the central server, pull the legacy public disk into `storage/tenant-<id>/public/` (paths in the database are relative, so no rewrite is needed — same principle as Task 27 Step 5):

```bash
mkdir -p "storage/tenant-$TENANT_ID/public" "storage/tenant-$TENANT_ID/local"
rsync -av legacy-host:/path/to/lavoro/storage/app/public/ "storage/tenant-$TENANT_ID/public/"
rsync -av legacy-host:/path/to/lavoro/storage/app/private/ "storage/tenant-$TENANT_ID/local/"
```

### Task 29, Step 6: Bring the imported schema up to date

If the central app has gained tenant migrations newer than the legacy release, apply them (already-run migrations are recorded by filename in the imported `migrations` table, so only the new ones execute):

```bash
php artisan tenants:migrate --tenants="$TENANT_ID"
```

This works because Task 8 preserved every migration filename when moving files into `database/migrations/tenant/` — the imported `migrations` table matches on basename, not path. Two things to check before trusting the result:

```bash
# The imported migrations table still lists the three now-central migrations.
# Harmless (their tables are simply unused in the tenant copy) — do not delete the rows,
# or a later `tenants:migrate` will try to recreate sessions/cache/jobs in the tenant DB.
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_tenant_spee \
  -e "SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;"
```

Then diff against the first tenant to catch a legacy install that was further behind than `migrate:status` suggested:

```bash
sudo -u lavoro_provisioner mysql --protocol=socket -N -e "SHOW TABLES IN lavoro_tenant_acme;" | sort > /tmp/a.txt
sudo -u lavoro_provisioner mysql --protocol=socket -N -e "SHOW TABLES IN lavoro_tenant_spee;" | sort > /tmp/b.txt
diff /tmp/a.txt /tmp/b.txt
```

Expect the only differences to be the central-infrastructure tables dropped in Step 3.

Then spot-check that any filesystem-reading migration actually found the files (see the warning in Step 5):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_tenant_spee \
  -e "SELECT COUNT(*) AS total, COUNT(size) AS sized FROM documents;"
```

`sized` should equal `total`, minus however many rows genuinely point at a missing file. A `sized` of 0 means Step 5 was skipped or rsynced to the wrong path.

### Task 29, Step 7: Re-home the Google Calendar integration

The imported `google_synced_calendars` rows hold watch channels registered against `https://spee.lavorofsm.nl/google/webhook` with unprefixed tokens. Expire them so the hourly renewal re-registers them against the central webhook URL with tenant-prefixed tokens (5-minute polling covers the gap):

```bash
sudo -u lavoro_provisioner mysql --protocol=socket lavoro_tenant_spee \
  -e "UPDATE google_synced_calendars SET watch_expires_at = NOW() WHERE watch_channel_id IS NOT NULL;"
```

The stored OAuth refresh tokens keep working — they are not domain-bound. But if the legacy install used its **own** Google Cloud OAuth client, reconnecting later from `app.lavorofsm.nl` requires the central OAuth client instead; in that case expect the customer to redo the Google connection once (Beheer → Google koppeling) and tell them so up front.

### Task 29, Step 8: Redirect the old subdomain

Keep `spee.lavorofsm.nl` DNS and its TLS certificate alive, but replace the vhost with a permanent redirect so bookmarks, the installed PWA, and password-reset links in old emails all land correctly:

```nginx
server {
    listen 443 ssl;
    server_name spee.lavorofsm.nl;
    # existing ssl_certificate lines stay

    return 301 https://app.lavorofsm.nl$request_uri;
}
```

### Task 29, Step 9: Verify

- Log in at `app.lavorofsm.nl` with a Spee user's existing email and password (passwords migrate as-is — same hashes). Confirm their customers, service orders, images, and documents all show.
- Confirm `https://spee.lavorofsm.nl` redirects to `https://app.lavorofsm.nl`.
- Confirm a calendar event created in the Planner still syncs to Google.
- Confirm the first tenant's data is untouched and Spee users see none of it.

### Task 29, Step 10: Decommission the legacy install (after a grace period)

Once the customer confirms everything works — suggest two weeks — remove the legacy app directory, drop its database on the old host, and remove its cron entries and queue worker. Keep the redirect vhost indefinitely; it costs nothing.

**Client-side caveats to communicate to the customer:**

- Everyone logs in again once — sessions and remember-me cookies do not carry over between domains.
- A PWA installed from `spee.lavorofsm.nl` is bound to that origin. The redirect keeps it functional, but users should remove it and install the PWA fresh from `app.lavorofsm.nl`.
- **Check the Android APK's base URL before migrating.** If the build the customer uses points at `spee.lavorofsm.nl`, plan an app update targeting `app.lavorofsm.nl` and have users reinstall and log in again; do not rely on the HTTP client following the 301 with cookies intact. FCM device tokens themselves are app-instance-bound, not domain-bound, so push notifications resume after re-login.

---

## Task 30: Tenant-aware test suite — MySQL only, and it must be impossible to run against a live database by accident

`phpunit.xml` currently pins tests to SQLite `:memory:`, which is fast, isolated by default (a throwaway in-process database per run), and — critically — physically cannot be a live database. Multi-database tenancy does not work on SQLite (see Prerequisites), so tests must move to MySQL. Moving to MySQL removes the "physically cannot be live" guarantee SQLite gave us for free, so this task rebuilds that guarantee explicitly, in three independent layers, rather than trusting a correctly-set env var:

1. **Distinct database names.** The central test database is `lavoro_test_landlord`, never `lavoro` (the dev/prod name from Task 2). Tenant test databases get their own prefix, `lavoro_test_tenant_` (Task 3 set `lavoro_tenant_`), configured via a new env var so it can differ from the runtime prefix without touching `config/tenancy.php` again per environment.
2. **A hard runtime assertion.** The test bootstrap refuses to run — throws before a single query executes — if the resolved central database name doesn't contain `test`. This is the layer that survives someone fat-fingering `.env` or copy-pasting production values into `phpunit.xml` later.
3. **A distinct MySQL user with narrow grants (operational, done once outside the app).** Create a MySQL user that only has privileges on `` `lavoro\_test\_%` `` — which covers both `lavoro_test_landlord` and every `lavoro_test_tenant_*` database, and nothing else. Even a fully wrong config in (1) and a bypassed assertion in (2) still cannot reach `lavoro_landlord` or any `lavoro_tenant_<id>` database, because the user has no grant on them. Document this as a required local/CI setup step; it is not something the application can enforce in code.

**How the `RefreshDatabase` test files change:** one shared test tenant is created once per test run (not once per test — creating a MySQL database per test would make the suite very slow), central and tenant migrations run once, and each individual test is wrapped in a transaction on *both* the `central` and `tenant` connections that rolls back after the test — the same isolation guarantee `RefreshDatabase` gave per-test, just spanning two connections instead of one. This logic moves from the per-file `RefreshDatabase` trait into the shared `TestCase`, so `RefreshDatabase` comes out of every file that used it. **94 test files** use it as of `2172ea1` (2026-08-11), out of 101 — re-run the grep in Step 5 to confirm the current set. That was 47 out of 50 two weeks earlier: the assistant and planning work doubled the suite, and this task's mechanical half doubled with it.

Two consequences of transaction-rollback isolation that `RefreshDatabase`'s truncate-and-remigrate did not have, and that may surface as test failures during this task:

- `AUTO_INCREMENT` counters are **not** reset between tests, because a rollback does not reclaim them. Any test asserting a literal id (`assertSame(1, $model->id)`) or relying on a predictable id ordering will start failing. Fix those tests to use the actual model's id.
- Code under test that issues DDL, an explicit `DB::beginTransaction()`, or `DB::unprepared` can implicitly commit and defeat the wrapper. `ProjectFinancialNotesMigrationTest` is worth checking first, since it exercises a data migration.

**Files:**
- `phpunit.xml`
- `tests/Concerns/RefreshesTenantDatabase.php` (new — `tests/Concerns/` already exists and holds `CreatesAuthenticatedUsers`)
- `tests/TestCase.php`
- The 94 existing test files using `RefreshDatabase`

### Task 30, Step 0: Record the baseline before changing anything

The success criterion for this task is "the same tests pass as before, on MySQL". That is only checkable against a recorded baseline — do this on the pre-tenancy commit, before Task 1:

```bash
git stash list                              # ensure a clean tree
php artisan test 2>&1 | tail -3 > /tmp/test-baseline.txt
cat /tmp/test-baseline.txt
```

Baseline on pre-tenancy `master`: **714 passed, 2202 assertions, ~28s** across 101 test files, 94 of which use `RefreshDatabase`. **Measure your own number** — that one is a reading, not a target, and the suite grows weekly. Expect the MySQL run to be noticeably slower than SQLite `:memory:`; that is normal and not a regression. What must not change is the pass count.

Budget the task by the file count you measure, not by the size the change sounds. Converting 94 files is where a mechanical edit stops being an afternoon.

The assistant tests are the one group with a wrinkle of their own: they bind a fake `TalksToModel` rather than calling a supplier, so they cost nothing and need no network — but several exercise `Storage::disk('local')` for parked photos, files and reports (`ConversationPhotosTest`, `AskWithFilesTest`, `ConversationReportTest`). Those interact with Task 14's per-tenant disk roots, so run them early rather than at the end of the conversion.

**Settle any existing flake before you start, or you will misread it as tenancy fallout.** `tests/Feature/Location/LocationDeletionTest.php:50` has been seen failing roughly one run in four and passing in isolation — order- or random-data-dependent, on SQLite, with nothing from this plan applied. Track that down first. Otherwise the first red run after the MySQL switch sends you hunting through transaction isolation and `AUTO_INCREMENT` behaviour for a bug that was already there, and the "pass count must equal the baseline" gate stops meaning anything once the baseline is a range.

### Task 30, Step 1: Confirm the tenant database prefix is env-overridable

Already handled — `config/tenancy.php` in Task 3 sets `'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_tenant_')`. Nothing to change here; just verify it reads from the env var before continuing, since Step 3's guard depends on it.

### Task 30, Step 2: Point `phpunit.xml` at a dedicated, clearly-named MySQL test database

Replace the sqlite block:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

with:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_DATABASE" value="lavoro_test_landlord"/>
<env name="DB_USERNAME" value="lavoro_test"/>
<env name="DB_PASSWORD" value="lavoro_test"/>
<env name="TENANCY_DB_PREFIX" value="lavoro_test_tenant_"/>
<env name="SESSION_CONNECTION" value="central"/>
<env name="TENANCY_MYSQL_MANAGER" value="Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager"/>
```

**Tests deliberately use the plain `MySQLDatabaseManager`, not the permission-controlled one** (Task 3 made this env-overridable for exactly this reason). Creating a MySQL user per test run would require granting `lavoro_test` the `CREATE USER` privilege — server-wide, since MySQL will not scope it to a database pattern — which directly undermines the narrow grant this task exists to establish. The test tenant therefore connects with the `lavoro_test` credentials rather than its own.

The cost, stated plainly: the per-tenant credential path is **not** covered by the suite. If `TenantDbUserProvisioner` or the encrypted-password cast breaks, tests stay green and you find out on the server. The Task 21 Step 3 and Task 26 Step 4 manual verifications are the compensating control — run them after any change to tenant provisioning.

Also remove `SESSION_DRIVER` value `array` is fine to keep — the session table itself is never touched by tests that don't explicitly exercise auth, and `SESSION_CONNECTION=central` only matters when the `database` driver is used. Leave `SESSION_DRIVER=array` as-is.

### Task 30, Step 3: Create the tenancy test-setup trait

```php
<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait RefreshesTenantDatabase
{
    protected static ?Tenant $testTenant = null;

    protected function setUpTenancy(): void
    {
        $central_db = config('database.connections.central.database');
        if (!str_contains($central_db, 'test')) {
            throw new RuntimeException(
                "Refusing to run tests against central database '{$central_db}' — " .
                "its name must contain 'test'. Check phpunit.xml's DB_DATABASE."
            );
        }

        $prefix = config('tenancy.database.prefix');
        if (!str_contains($prefix, 'test')) {
            throw new RuntimeException(
                "Refusing to run tests with tenant database prefix '{$prefix}' — " .
                "it must contain 'test'. Check phpunit.xml's TENANCY_DB_PREFIX."
            );
        }

        if (!static::$testTenant) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            static::$testTenant = Tenant::create(['id' => 'test-tenant', 'name' => 'Test Tenant', 'package_key' => 'enterprise']);
        }

        tenancy()->initialize(static::$testTenant);

        DB::connection('central')->beginTransaction();
        DB::connection('tenant')->beginTransaction();
    }

    protected function tearDownTenancy(): void
    {
        DB::connection('tenant')->rollBack();
        DB::connection('central')->rollBack();
        tenancy()->end();
    }
}
```

**The connection name is `tenant`, not `mysql`.** stancl's `DatabaseManager::connectToTenant()` creates a connection literally named `tenant` and calls `setDefaultConnection('tenant')`; the name is hardcoded in v3 and not configurable (see Task 3). Using `DB::connection('mysql')` here would begin a transaction on a *different, non-tenant* connection — tenant writes would commit for real and leak into every subsequent test, while the rollback silently succeeded against an untouched connection. Getting this wrong produces order-dependent test failures that look like flakiness.

The two `str_contains(..., 'test')` checks are layer (2) from the description above — they run before any migration or query, on every single test, and throw rather than silently proceeding. `Tenant::create()` runs the full `TenantCreated` pipeline from Task 11 synchronously (`shouldBeQueued(false)`). So the very first test of a run creates a real `lavoro_test_tenant_test-tenant` MySQL database, migrates it with the tenant migrations from Task 8, and seeds it with `TenantDatabaseSeeder` (Task 23).

That database is left behind when the run ends. Keeping it is cheap: `migrate:fresh` on the next run only rebuilds the central schema, so the stale tenant database is simply reused, and its migrations already match because `MigrateDatabase` tracks what it has run.

If the tenant migrations change and you want a clean slate, drop `lavoro_test_tenant_test-tenant` by hand. It is a throwaway.

### Task 30, Step 4: Use the trait in the base `TestCase`

Replace `tests/TestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }
}
```

### Task 30, Step 5: Remove `RefreshDatabase` from the existing test files

Tenant-database refresh is now handled centrally by `TestCase`, so the per-file trait is redundant (and would try to migrate/refresh the single default connection using Laravel's normal single-connection logic, which doesn't know about the `central` connection at all).

```bash
grep -rl "RefreshDatabase" tests/   # 47 files
```

In each matching file, remove the `use Illuminate\Foundation\Testing\RefreshDatabase;` import and the `use RefreshDatabase;` trait line inside the test class. Leave everything else in those files untouched.

Expect to run the suite iteratively here rather than in one pass — see the two isolation caveats at the top of this task. Convert the files, run `composer test`, and fix fallout in the tests rather than weakening the isolation in `TestCase`.

### Task 30, Step 6: Set up the local/CI test database and user (operational, run once)

```sql
CREATE DATABASE IF NOT EXISTS lavoro_test_landlord CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lavoro_test'@'127.0.0.1' IDENTIFIED BY 'lavoro_test';
GRANT ALL PRIVILEGES ON `lavoro\_test\_%`.* TO 'lavoro_test'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The escaped underscores in `lavoro\_test\_%` matter — an unescaped `_` is a MySQL wildcard that would also match e.g. `lavoroXtest_evil`. This user cannot create, read, or drop `lavoro` or any `lavoro_tenant_<id>` database — that is layer (3) from the description above, and it holds even if every application-level check in Step 3 were somehow bypassed.

### Task 30, Step 7: Run the suite and verify against the baseline

```bash
php artisan test 2>&1 | tail -3 | tee /tmp/test-after.txt
diff <(grep -o '[0-9]* passed' /tmp/test-baseline.txt) <(grep -o '[0-9]* passed' /tmp/test-after.txt)
```

The pass count must match the Step 0 baseline (it moved by 53 tests in the three days before this line was written). Duration will be higher on MySQL — ignore it.

Then confirm the isolation layers actually held:

```bash
# No test run ever created or touched a live database
mysql -u lavoro_test -p -e "SHOW DATABASES LIKE 'lavoro%';"
```

You should see only `lavoro_test_landlord` and `lavoro_test_tenant_test-tenant`. `lavoro_landlord` and any `lavoro_tenant_<slug>` database must be **absent from this list entirely** — not merely untouched, but invisible, because the `lavoro_test` user has no grant on them. If you can see them here, the grant in Step 6 is too wide; fix it before trusting any of the above.

### Task 30, Step 7a: Work through failures in this order

Some churn is expected — these are the causes to check first, roughly in likelihood order. Fix the *test*, not the isolation.

1. **Everything after the first HTTP request in a test fails.** The `$initialized_here` guard in the Task 12 / Task 24 middleware is missing, so the request ended the tenancy `TestCase` set up. 30 test files make requests, so this presents as mass failure.
2. **Hardcoded id assertions.** Transaction rollback does not reset `AUTO_INCREMENT`. Assert against `$model->id`, not `1`.
3. **Tests asserting on `users` uniqueness or user deletion.** `UserSoftDeleteTest`, `UserSoftDeleteVisibilityTest`, `UserDeletionAuthorizationTest` and `UserHistoricalReferenceTest` now also exercise the Task 18 observer, which writes to the central `user_tenant_lookups` table on create, restore and force-delete.

28 test files create users via factory. If a factory ever generates a duplicate email, the observer throws a `RuntimeException` instead of failing validation — make the factory email unique if that shows up.
4. **`ProjectFinancialNotesMigrationTest`.** Exercises a data migration; DDL implicitly commits in MySQL and escapes the transaction wrapper. May need `RefreshDatabase`-style handling of its own.
5. **`tests/Feature/Signals/ModelHistoryTest::test_work_rolled_back_leaves_no_trace`.** The only test in the suite that deliberately rolls a transaction back, to prove the audit trail rolls back with it. Under this task's wrapper it becomes a *nested* transaction, which Laravel implements as a savepoint — supported on MySQL, so it should pass unchanged, but it is the first thing to check if the signal tests go red, because a rollback that escaped to the wrapper would take the whole test's isolation with it.
6. **The rest of `tests/Feature/Signals/`.** These assert on rows in `activities` and `activity_changes` — both tenant tables, so they only pass if the `TestCase` really did switch to the tenant connection. A signal test failing with "table not found" means tenancy did not initialize, not that the signal broke. `ControllerDispatchTest` and `EventApiSignalsTest` use `Event::fake()`, which suppresses `RecordActivity` but not the tenancy listeners in Task 11 — faking events does not un-initialize a tenant. `SignalLoopGuardTest` asserts on the `Signals` singleton's chain and per-request counter, which the Task 11 reset clears on every tenancy switch; if it goes red, check that nothing in the test path re-initializes a tenant mid-chain.
7. **MySQL strict-mode differences from SQLite.** SQLite is permissive about types, string lengths, and invalid dates; MySQL is not. A test that passed on SQLite with an over-long string or a zero date will now fail legitimately — that is a real bug the old suite was hiding, so fix the code, not the test.
8. **Timezone assertions.** `StandardEmailRenderingTest` already asserts Amsterdam wall-clock times . Confirm the MySQL session timezone does not shift these.

### Task 30, Step 7b: Add one test that would have caught the ordering bug

The most expensive failure mode in this plan (Task 12) is invisible to the existing suite, because tests initialize tenancy directly rather than through the middleware. One test closes that gap permanently:

```php
public function test_a_bound_model_route_resolves_against_the_tenant_database(): void
{
    $user = User::factory()->create();
    $order = ServiceOrder::factory()->create();

    $this->actingAs($user)
        ->withSession(['tenant_id' => static::$testTenant->getTenantKey()])
        ->get("/serviceorders/{$order->id}")
        ->assertOk();
}
```

If `InitializeTenancyBySession` ever drifts after `SubstituteBindings` in the priority list, this goes red with a 404 instead of the whole app going quietly broken in production.

### Task 30, Step 8: Commit

```bash
git add phpunit.xml tests/Concerns/RefreshesTenantDatabase.php tests/TestCase.php tests/
git commit -m "test(tenancy): run the suite against isolated, clearly-named MySQL test databases"
```

> **Do not close out this plan with a red or skipped suite.** Task 30 is the gate for "tests still work after multi-tenancy" — the pass count must equal the Step 0 baseline, with no tests deleted or marked skipped to get there. If a test cannot be made to pass, that is a finding about the implementation, not about the test.

---

## Task 31: Enforce module subscriptions on gated features

Task 16 built the data model (`tenants.modules`), the `Tenant::hasModule()` check, the shared `tenant` Inertia prop, and the `hasModule()` JS helper — but nothing consumed them, so a tenant without e.g. the `tickets` module could still use every ticket route. This task wires the actual gates: a backend route middleware (authoritative — this is what actually blocks access) plus frontend nav/UI hiding using the existing helper (a UX nicety, not the security boundary).

CLAUDE.md says authorization belongs in Form Requests and policies rather than in ad-hoc controller checks. Module gating is neither: it asks whether the *company* pays for a feature, which sits a layer above whether *this user* may use it.

So it is route middleware, the same pattern as `tenant.api` in Task 24. It runs **on top of** the existing `auth` group and permission checks, never instead of them.

**Files:**
- `app/Http/Middleware/EnsureTenantHasModule.php` (new)
- `bootstrap/app.php`
- `routes/web.php`, `routes/api.php`
- `app/Http/Controllers/ServiceOrderController.php:352` (the only remaining `snelStartEnabled` flag)
- `resources/js/Composables/useMenu.js` (`menu.json` itself needs no change — see Step 5)
- `resources/js/Components/GoogleCalendarSection.vue`
- `resources/js/Pages/Admin/GeneralSettingsPage.vue`

> **Line numbers below are indicative only — this file moves constantly.** Locate each route by its name/controller rather than by line number.

### Task 31, Step 1: Create the middleware

```php
<?php

namespace App\Http\Middleware;

use App\Models\Central\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantHasModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Module::on('central')->where('key', $module)->exists(), 500, "Onbekende module '{$module}'.");

        abort_unless(
            tenancy()->initialized && tenancy()->tenant->hasModule($module),
            403,
            'Deze functie is niet beschikbaar in uw abonnement.'
        );

        return $next($request);
    }
}
```

**That message will not reach the user on a web route.** `bootstrap/app.php` registers a global `$exceptions->respond(...)` handler whose first branch is:

```php
if ($response->getStatusCode() === 403 && ! $request->expectsJson()) {
    return redirect()->back()->with('error', 'U heeft geen toestemming om deze actie uit te voeren.');
}
```

`abort_unless(..., 403, $message)` throws an `HttpException`, not an `AuthorizationException`, so it misses the `render()` hook above it and lands here. The status becomes a 302, and the subscription-specific wording is replaced by the generic permission wording — which is precisely the wrong sentence, because the user has the permission and does not have the subscription.

On API routes `expectsJson()` is true, the branch is skipped, and the message survives. So the middleware behaves differently on the two route files it is applied to, which is worth knowing before debugging it.

Three options, in the order I would consider them:

1. **Leave it.** The gate holds; only the wording is lost. Cheapest, and defensible while the module set is internal.
2. **Add a branch to the existing `respond()` handler** that passes a 403 through when the exception carries a message of its own. Small, and it fixes every future `abort(403, '…')` too.
3. **Throw a dedicated exception** from the middleware and render it in `bootstrap/app.php`. Most precise, most code.

Pick one deliberately. What is not acceptable is writing the message, believing it is shown, and discovering otherwise from a support call about a permissions error nobody can grant.

### Task 31, Step 2: Register the alias in `bootstrap/app.php`, next to `tenant.api` from Task 24

```php
$middleware->alias([
    'admin'        => EnsureUserIsAdmin::class,
    'tenant.api'   => \App\Http\Middleware\InitializeTenancyForApi::class,
    'tenant.module' => \App\Http\Middleware\EnsureTenantHasModule::class,
]);
```

### Task 31, Step 3: Apply it to the module-gated route groups in `routes/web.php`

Inside the existing `auth` group:

**Not the ticket routes.** Storingen is stock — every tenant has it — so there is no
module to gate it on; see Task 6. Leave those three routes exactly as they are.

SnelStart — there are exactly **two** SnelStart routes (lines ~230 and ~243). They are not adjacent, so either wrap each individually or apply the middleware inline:

```php
Route::post('imports/snelstart/materials', [SnelStartImportController::class, 'importMaterials'])
    ->middleware('tenant.module:snelstart')
    ->name('imports.snelstart.materials');

Route::post('serviceorders/{serviceorder}/send-snelstart', [ServiceOrderController::class, 'sendToSnelStart'])
    ->middleware('tenant.module:snelstart')
    ->name('serviceorders.sendToSnelStart');
```

Note SnelStart *customer* import now happens through the generic Excel import (`CustomerImportController::looksLikeSnelStartExport`, auto-detecting a SnelStart export format from the file header). That is offline file parsing with no SnelStart API involvement, so it is deliberately **not** module-gated — gating it would block a plain spreadsheet upload.

**Not the project routes either.** Projecten is stock, like Storingen; see Task 6.
Leave them as they are.

Google Calendar (lines ~343-348):

```php
Route::middleware('tenant.module:google_calendar')->group(function () {
    Route::get('google/oauth/start', [GoogleOAuthController::class, 'start'])
        ->name('google.oauth.start');
    Route::get('google/oauth/callback', [GoogleOAuthController::class, 'callback'])
        ->name('google.oauth.callback');
    Route::delete('google/integration', [GoogleOAuthController::class, 'destroy'])
        ->name('google.integration.destroy');
});
```

The assistant — **fourteen routes**, all in the `auth` group, each already carrying its own `throttle`. Wrap the lot; the throttles survive, because route middleware composes:

```php
Route::middleware('tenant.module:assistant')->group(function () {
    // assistant.ask, assistant.continue, assistant.confirm, assistant.report,
    // assistant.history, assistant.conversation, assistant.prompts{,.store,.update,.destroy},
    // assistant.photos.keep, assistant.photos.discard
});
```

This is the one module where not having it actually saves us money rather than just withholding a feature, because every route in it spends real money at a supplier. Gate all fourteen, not just `ask`: `confirm` carries out a write that a previous `ask` proposed, `report` hands back a transcript, and `history` and `prompts` are the conversation's own furniture. A half-gated module is a module that still costs and still leaks.

Note the assistant does **not** appear in `menu.json` — it is a panel, not a page — so Step 5 has nothing to add for it. Its visibility comes from `auth.can.use_assistant`, handled in Step 6b.

Location tracking — inside the nested `admin` group (line ~354), wrap the settings route at lines ~381-384:

```php
Route::put('admin/settings/location-tracking', [GeneralSettingsController::class, 'updateLocationTracking'])
    ->middleware('tenant.module:location_tracking')
    ->name('admin.settings.location-tracking');
```

(Use the controller/action already on that route — copy it from the current lines 381-384 rather than retyping the signature from scratch, since the exact method name should match what's there today.)

**Gating only `routes/web.php` leaves the module wide open.** The SPA does most of its real work through `routes/api.php`, so a web-only gate blocks the page but not the data behind it. Every module with an API surface needs the same middleware there, inside the `tenant.api` group from Task 24:

```php
Route::get('google/integration/status', GoogleIntegrationStatusController::class)
    ->middleware('tenant.module:google_calendar');

Route::post('location/pings', [LocationPingController::class, 'store'])
    ->middleware('tenant.module:location_tracking');
```

`POST /api/location/pings` shows why the API routes need gating too. It is the Android app's ping endpoint. Gating only the *settings* route in `routes/web.php` stops an admin switching tracking on — but does nothing about a phone that is already sending pings, so an unsubscribed tenant carries on accumulating location data.

The same trap waits for every module with an API surface. When Offertes and Facturen are built, whatever they expose under `routes/api.php` needs gating in the same commit as their web routes — the SPA reads the API directly, so a web-only gate hides the page and serves the data.

Check `routes/api.php` for module-owned routes each time a new module is added; the file is where the gate is easiest to forget.

### Task 31, Step 4: Gate the SnelStart UI at the source — extend the existing `snelStartEnabled` flag

There is exactly **one** `snelStartEnabled` producer, `ServiceOrderController.php:352`:

```php
'snelStartEnabled' => filled(config('services.snelstart.client_key')),
```

Change it to also require the module:

```php
'snelStartEnabled' => filled(config('services.snelstart.client_key'))
    && tenancy()->initialized
    && tenancy()->tenant->hasModule('snelstart'),
```

This reuses the exact prop `ServiceOrders/ShowPage.vue` already gates its SnelStart button on (`v-if="snelStartEnabled && hasPermission('snelstart.send_serviceorder')"`, line 448) — no frontend changes needed for SnelStart. The materials-import button is gated by the route middleware from Step 3 alone.

**Task 32 Step 8 revises this line again**, dropping the `config()` clause once the client key is per-tenant and there is no global one left to check. If you are implementing both tasks in sequence, write the Task 32 version directly and skip the intermediate form.

### Task 31, Step 5: Teach the menu about modules — no entry needs one yet

**Navigation is declarative.** Its shape lives in **`resources/js/Navigation/menu.json`** — a tree of sections and items, each carrying the permission it needs — and `resources/js/Composables/useMenu.js` turns that into the tree the signed-in user may actually see. Six components share it. A module is therefore one more key in the JSON rather than an argument threaded through a list of component imports.

**Every top-level menu item today is stock**, so no `"module"` key is added in this task. That is the correct outcome rather than a gap: Storingen and Projecten are part of the product, and the remaining modules are not screens — SnelStart is a button (Step 4), Google Agenda and Locatie volgen are settings sections (Step 6), and the assistant is a panel (Step 6b).

Add the `maySee` branch anyway. It is three words of code, and without it the JSON key is silently ignored — so the first person to write `"module": "quotes"` when Offertes ships gets a menu entry everyone can see and no error to explain why. The mechanism should exist before its first user, not because of them.

```js
import { hasAnyPermission, hasModule, hasPermission, initials as getInitials } from '@/Utilities/Utilities'

const maySee = (item) => {
    if (item.module && !hasModule(item.module)) return false
    if (item.adminOnly) return isAdmin.value
    if (item.explicitPermission) return (page.props.auth?.permissions || []).includes(item.explicitPermission)
    if (item.anyPermission) return hasAnyPermission(item.anyPermission)
    if (item.permission) return hasPermission(item.permission)
    return true
}
```

The module check goes **first**, before the `adminOnly` branch: a module is a subscription boundary, not a permission, so an admin of a tenant that does not pay for a feature must not see it either. (Contrast `hasPermission`, which deliberately returns `true` for admins, and `explicitPermission`, which deliberately does not.)

`maySee` is called by `resolve`, which walks the tree recursively, so this covers nested entries too — and `resolve` already drops a parent whose children have all disappeared and which has no page of its own, so a module that owns a whole submenu will need nothing extra. Extend the existing import on line 3 rather than adding a second one.

### Task 31, Step 6: Gate the Google Calendar section and location-tracking settings

In `resources/js/Components/GoogleCalendarSection.vue`, import `hasModule` from `@/Utilities/Utilities` and wrap the section's root template element in `v-if="hasModule('google_calendar')"`.

In `resources/js/Pages/Admin/GeneralSettingsPage.vue`, do the same around the location-tracking settings block, using `hasModule('location_tracking')`.

### Task 31, Step 6b: Fold the module into the assistant's shared verdict

`HandleInertiaRequests` shares `auth.can.use_assistant`, and the frontend opens the assistant panel on it. The comment above that share is worth reading before changing it: it exists so that permission reasoning happens in one place and the frontend never assembles a verdict of its own. Adding the module check to the *share* keeps that promise; adding it to the Vue side would break it.

```php
'use_assistant' => ($request->user()?->can('use', Assistant::class) ?? false)
    && tenancy()->initialized
    && tenancy()->tenant->hasModule('assistant'),
```

The route middleware from Step 3 is still what actually blocks access. This only stops a tenant without the module being shown a box that would answer every question with a 403.

### Task 31, Step 7: Verify

- As a tenant without the `google_calendar` module: the OAuth start route is refused and the Google Calendar section does not render. **Assert on the refusal, not on the status code** — unless you took option 2 or 3 above, a web request comes back as a 302 with a flash message, not a 403 (see Step 1). `assertForbidden()` will fail on a working gate. In a feature test, `$this->withoutExceptionHandling()` restores the underlying 403 and is the cleaner assertion.
- `php artisan tenant:modules <id> --add=google_calendar`, reload: the route works and the section appears.
- Same pattern for `snelstart` and `location_tracking`, and for `location_tracking` check `POST /api/location/pings` directly — that is the one a device keeps hitting regardless of what the settings page says.
- As a tenant with **no modules at all**: Storingen and Projecten still work, with their menu entries and the Storingen dot. That is the check that stock features have not been gated by accident, and it is the one worth keeping in the suite.
- As a tenant without the `assistant` module: the assistant panel does not open, and `POST /assistant/ask` returns 403 rather than spending anything at a supplier. Check that 403 with a direct request rather than through the UI — the point of the route gate is that it holds when the UI is bypassed.

### Task 31, Step 8: Commit

```bash
git add app/Http/Middleware/EnsureTenantHasModule.php bootstrap/app.php routes/web.php routes/api.php \
        app/Http/Controllers/ServiceOrderController.php app/Http/Middleware/HandleInertiaRequests.php \
        resources/js/Composables/useMenu.js \
        resources/js/Components/GoogleCalendarSection.vue \
        resources/js/Pages/Admin/GeneralSettingsPage.vue
git commit -m "feat(tenancy): enforce module subscriptions on gated routes and UI"
```

---

## Task 32: Per-tenant integration credentials (Microsoft Graph mail + SnelStart)

Two integrations authenticate as *somebody*, and after tenancy that somebody has to be the customer, not Lavoro. Microsoft Graph sends mail from an Azure app registration and a mailbox; SnelStart reads and writes a bookkeeping administratie. Both currently take their credentials from global env vars, so every tenant would share one mailbox and one set of books.

They share storage, encryption, and one settings screen, so they are one task. They differ in exactly one respect, and it is the important one:

| | Microsoft Graph | SnelStart |
| --- | --- | --- |
| Per-tenant keys | `graph_azure_tenant_id`, `graph_client_id`, `graph_client_secret`, `graph_user_id` | `snelstart_client_key`, `snelstart_subscription_key` |
| Stays global | `graph_endpoint` | `snelstart_auth_url`, `snelstart_api_base` |
| Unconfigured tenant | **Falls back to the shared env credentials** | **Fails closed** |

**Why the fallback differs.** Sending a tenant's mail from Lavoro's own mailbox is a reasonable default — the mail goes out, the customer sees a generic sender. Writing a tenant's invoices into whichever administratie `SNELSTART_CLIENT_KEY` happens to point at puts one customer's financial data into another customer's books. There is no safe default for that, so a SnelStart call without tenant credentials must refuse to run.

**Files:**
- a new tenant migration widening `general_settings.value` (`php artisan make:migration widen_general_settings_value --path=database/migrations/tenant`)
- `app/Models/GeneralSetting.php`
- `app/Services/SnelStartClient.php`
- `app/Exceptions/SnelStartNotConfigured.php` (new)
- `app/Providers/AppServiceProvider.php`, `app/Providers/TenancyServiceProvider.php`, `bootstrap/app.php`
- `app/Console/Commands/FetchSnelStartArtikelen.php`, `FetchSnelStartRelaties.php`
- `app/Http/Controllers/Admin/IntegrationSettingsController.php` (new), `app/Http/Requests/IntegrationSettingsRequest.php` (new)
- `resources/js/Pages/Admin/IntegrationSettingsPage.vue` (new), `routes/web.php`
- `tests/Feature/IntegrationCredentialsTest.php` (new)

**Interfaces:**
- Produces: `GeneralSetting::get`/`set` transparently encrypting the keys in `GeneralSetting::SECRET_KEYS`; `SnelStartClient` resolving per tenant and throwing `SnelStartNotConfigured` when unconfigured; `admin/settings/integrations` behind `auth` + `admin`.

### Task 32, Step 1: Widen `general_settings.value` and encrypt the secret keys

The column is `varchar(255)` today. A Laravel-encrypted 40-character key serialises to roughly 220–260 characters of base64 — at the ceiling, and over it for a longer key. Storing ciphertext there would truncate silently on a non-strict server and error on a strict one, so widen it first. No index exists on `value`, so this is a plain `MODIFY`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE general_settings MODIFY value TEXT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE general_settings MODIFY value VARCHAR(255) NOT NULL');
    }
};
```

Then put encryption inside the model, so no caller changes:

```php
public const SECRET_KEYS = [
    'graph_client_secret',
    'snelstart_client_key',
    'snelstart_subscription_key',
];

public static function get(string $key, mixed $default = null): mixed
{
    $row = static::where('key', $key)->first();

    if (!$row) {
        return $default;
    }

    if (!in_array($key, self::SECRET_KEYS, true)) {
        return $row->value;
    }

    try {
        return Crypt::decryptString($row->value);
    } catch (DecryptException) {
        return $default;
    }
}

public static function set(string $key, mixed $value): void
{
    static::updateOrCreate(['key' => $key], [
        'value' => in_array($key, self::SECRET_KEYS, true)
            ? Crypt::encryptString((string) $value)
            : (string) $value,
    ]);
}
```

Do not remove the `DecryptException` catch. After an `APP_KEY` rotation every stored secret becomes undecryptable; returning the default turns that into "not configured" — a settings screen asking to re-enter the credentials — instead of a 500 on every page that touches mail or SnelStart. See Known impact 11: `APP_KEY` was already backup-critical for tenant database passwords, and this widens what it protects.

### Task 32, Step 2: Rewrite the `Mail::extend('graph', ...)` closure — fall back as a set, not per key

The obvious implementation resolves each key independently, falling back to env per key. That produces a state that cannot work. `GraphTransport.php:52` is `$user = $this->userId ?: $this->fromAddress;`, and it posts to `/users/{$user}/sendMail`. So a tenant that configures its own `graph_client_id` and `graph_client_secret` but leaves the mailbox unset authenticates against **its own** Azure app registration and then asks it to send as `MAIL_FROM_ADDRESS` — a mailbox that exists in *Lavoro's* Azure tenant and not in theirs. Every send fails with an unhelpful Graph error.

So the tenant either supplies the whole set or none of it:

```php
Mail::extend('graph', function () {
    $tenant_id     = tenancy()->initialized ? GeneralSetting::get('graph_azure_tenant_id') : null;
    $client_id     = tenancy()->initialized ? GeneralSetting::get('graph_client_id') : null;
    $client_secret = tenancy()->initialized ? GeneralSetting::get('graph_client_secret') : null;
    $user_id       = tenancy()->initialized ? GeneralSetting::get('graph_user_id') : null;

    $tenant_configured = filled($tenant_id) && filled($client_id)
        && filled($client_secret) && filled($user_id);

    if ($tenant_configured) {
        return new GraphTransport(
            tenantId: $tenant_id,
            clientId: $client_id,
            clientSecret: $client_secret,
            fromAddress: GeneralSetting::get('mail_from_address', $user_id),
            userId: $user_id,
            graphEndpoint: config('services.graph.endpoint'),
            dispatcher: app('events'),
            logger: app('log')->channel()
        );
    }

    return new GraphTransport(
        tenantId: config('services.graph.tenant_id'),
        clientId: config('services.graph.client_id'),
        clientSecret: config('services.graph.client_secret'),
        fromAddress: config('mail.from.address'),
        userId: config('services.graph.user_id'),
        graphEndpoint: config('services.graph.endpoint'),
        dispatcher: app('events'),
        logger: app('log')->channel()
    );
});
```

Note `graph_user_id` is **required** in the tenant branch, where the pre-tenancy design called it optional. Once credentials are per tenant, the mailbox must belong to the same Azure tenant as the credentials that authenticate to it; there is no coherent "own app registration, shared mailbox" configuration. The from-address defaults to the mailbox itself rather than the global `MAIL_FROM_ADDRESS`, for the same reason.

The setting is named `graph_azure_tenant_id`, not `graph_tenant_id`. In a codebase where "tenant" now means a customer, a key called `graph_tenant_id` holding an *Azure* directory id is a bug report waiting to happen.

Add `use App\Models\GeneralSetting;` to the file's imports.

### Task 32, Step 3: Forget the cached mailer whenever tenancy switches

`Illuminate\Mail\MailManager` caches a resolved `graph` transport for the lifetime of the container. In PHP-FPM that is harmless — one tenant per request. On a queue worker processing jobs for several tenants without restarting, the *first* tenant's credentials get cached and silently reused for a later tenant's queued mail (`SendStandardEmailJob`).

**Task 11 already built this.** `App\Support\MailerState` is the adapter that clears the mailers, it is tagged `ForgetsTenantState`, and `TenantState::flush()` runs it on both the tenancy switch and the queue-job boundary. There is nothing to add here — verify it, do not re-register it:

```bash
php artisan test --filter=TenantStateTest
```

The registry exists precisely so this task does not have to remember. Anything the container caches for the lifetime of a process, while it holds tenant-specific state, implements `ForgetsTenantState` and gets tagged; both moments then clear it without either call site being edited. When adding a singleton to this app, that is the question to ask about it — and the answer is one interface, not a listener.

**`SnelStartClient` needs no equivalent.** It reads config in its constructor and is not bound as a singleton, so every injection (`handle(SnelStartClient $client)`, `sendToSnelStart(ServiceOrder $order, SnelStartClient $client)`) builds a fresh instance against the current tenant. Verify that stays true if anyone ever adds a `singleton()` binding for it.

### Task 32, Step 4: Resolve `SnelStartClient` per tenant, failing closed

```php
public function __construct()
{
    $cfg = config('services.snelstart');
    $this->authUrl = $cfg['auth_url'];
    $this->apiBase = $cfg['api_base'];

    $this->clientKey       = (string) GeneralSetting::get('snelstart_client_key', '');
    $this->subscriptionKey = (string) GeneralSetting::get('snelstart_subscription_key', '');

    if ($this->clientKey === '' || $this->subscriptionKey === '') {
        throw new SnelStartNotConfigured();
    }
}
```

`SNELSTART_CLIENT_KEY` and `SNELSTART_SUBSCRIPTION_KEY` come out of `config/services.php`, `.env` and `.env.example` entirely — leaving them there invites exactly the fallback this step exists to prevent. `auth_url` and `api_base` stay.

The constructor throws, which means it throws during container resolution, before the controller body runs. Render it centrally in `bootstrap/app.php`, next to the existing `AuthorizationException` and `QueryException` handlers, which already do this shape:

```php
$exceptions->render(function (SnelStartNotConfigured $e, Request $request) {
    $message = 'De SnelStart-koppeling is nog niet ingesteld. Ga naar Beheer → Koppelingen.';

    if ($request->expectsJson()) {
        return response()->json(['message' => $message], 422);
    }

    return redirect()->back()->with('error', $message);
});
```

### Task 32, Step 5: Fingerprint the SnelStart token cache key

`getAccessToken()` caches under the flat key `snelstart.token`. Task 10's `PrefixCacheBootstrapper` already namespaces that per tenant, so cross-tenant reuse is handled. The remaining problem is *within* a tenant: rotating the client key leaves a valid cached token for up to 58 minutes, so a wrong key appears to work and the real failure surfaces later, somewhere else.

```php
$fingerprint = substr(hash('sha256', $this->clientKey), 0, 12);

return Cache::remember('snelstart.token.' . $fingerprint, now()->addSeconds(3500), function () { ... });
```

The `snelstart.land.*` reference lookups need no change — they are already per-tenant by prefix, and duplicating a country list per tenant costs nothing.

### Task 32, Step 6: Make the SnelStart fetch commands tenant-aware

`FetchSnelStartArtikelen` and `FetchSnelStartRelaties` are manual commands today and would run against whatever connection happens to be default. Give both a `{--tenant=}` option. Without it, iterate every tenant using the Task 20 pattern, skipping any tenant that lacks the module or the credentials:

```php
$tenants = $this->option('tenant')
    ? Tenant::on('central')->where('id', $this->option('tenant'))->cursor()
    : Tenant::on('central')->cursor();

foreach ($tenants as $tenant) {
    if (!$tenant->hasModule('snelstart')) {
        continue;
    }

    tenancy()->initialize($tenant);

    try {
        $this->syncFor(app(SnelStartClient::class));
    } catch (SnelStartNotConfigured) {
        $this->warn("Skipping {$tenant->name}: SnelStart not configured.");
    } finally {
        tenancy()->end();
    }
}
```

### Task 32, Step 7: Build the integration settings screen

One page with a section per integration, at `admin/settings/integrations`, registered inside the existing `auth` → `admin` group in `routes/web.php`. Do **not** gate the whole page on `tenant.module:snelstart` — the Graph section belongs to every tenant. Gate the SnelStart *section* on `hasModule('snelstart')` in the template, and the SnelStart fields in `IntegrationSettingsRequest::rules()` with `required_if` on the same condition.

`IntegrationSettingsRequest::authorize()` calls the policy, per CLAUDE.md; validation lives in `rules()`; the frontend renders `form.errors` only.

**The page must never receive the stored secrets.** An Inertia prop carrying a client secret ships it to every browser that loads the settings page and into every browser devtools session. Send status, not values:

```php
return inertia('Admin/IntegrationSettingsPage', [
    'graph' => [
        'configured'      => filled(GeneralSetting::get('graph_client_secret')),
        'azure_tenant_id' => GeneralSetting::get('graph_azure_tenant_id'),
        'client_id'       => GeneralSetting::get('graph_client_id'),
        'user_id'         => GeneralSetting::get('graph_user_id'),
    ],
    'snelstart' => [
        'configured'       => filled(GeneralSetting::get('snelstart_client_key')),
        'client_key_hint'  => $this->hint(GeneralSetting::get('snelstart_client_key')),
    ],
]);
```

where `hint()` returns the last four characters or `null`. Identifiers (`client_id`, `user_id`, the Azure directory id) are not secret and are sent in full so the form can show what is set. Secrets come back only as `configured` plus a hint, and an empty submitted secret means "leave unchanged" rather than "clear it" — otherwise every save of an unrelated field wipes the credential.

### Task 32, Step 8: Update the `snelStartEnabled` prop

Task 31 Step 4 set it to `filled(config('services.snelstart.client_key')) && tenancy()->initialized && tenancy()->tenant->hasModule('snelstart')`. There is no global client key any more, so drop that clause and check the tenant's own credentials instead:

```php
'snelStartEnabled' => tenancy()->initialized
    && tenancy()->tenant->hasModule('snelstart')
    && filled(\App\Models\GeneralSetting::get('snelstart_client_key')),
```

A tenant that subscribes to the module but has not entered credentials now correctly sees no SnelStart button, rather than a button that throws.

### Task 32, Step 9: Tests

```php
public function test_two_tenants_resolve_different_snelstart_credentials(): void
public function test_snelstart_without_credentials_throws_rather_than_falling_back(): void
public function test_graph_falls_back_to_env_only_when_no_tenant_key_is_set(): void
public function test_a_partially_configured_graph_tenant_does_not_mix_in_env_values(): void
public function test_the_settings_endpoint_response_contains_no_secret(): void
```

The fourth is the regression test for the bug in Step 2, and the fifth greps the rendered Inertia props for the stored secret string.

### Task 32, Step 10: Verify by hand

- Set Graph credentials for the Task 28 second tenant, send a test mail, confirm it authenticates against that tenant's Azure app registration (check the Entra sign-in log, or deliberately break the secret and confirm the failure is that tenant's, not a silent success via the global credentials).
- Dispatch two queued `SendStandardEmailJob`s for two tenants with different credentials back-to-back on one worker; confirm each uses its own. Without Task 11's `MailerState` the second silently reuses the first's transport — this is the end-to-end check that the registry is wired, not just tagged.
- Confirm a `mysqldump` of a tenant database shows ciphertext for `snelstart_client_key`, not the key.

### Task 32, Step 11: Commit

```bash
git add database/migrations/tenant/ \
        app/Models/GeneralSetting.php app/Services/SnelStartClient.php \
        app/Exceptions/SnelStartNotConfigured.php \
        app/Providers/AppServiceProvider.php app/Providers/TenancyServiceProvider.php bootstrap/app.php \
        app/Console/Commands/ app/Http/Controllers/Admin/ app/Http/Requests/ \
        resources/js/Pages/Admin/IntegrationSettingsPage.vue routes/web.php \
        config/services.php .env.example tests/Feature/IntegrationCredentialsTest.php
git commit -m "feat(tenancy): resolve Graph and SnelStart credentials per tenant"
```

---

## Task 33: Add `seat_type` to users (migration, backfill, factory, form field)

Every user is a `field` (buitendienst) or `office` (kantoor) seat. This is a tenant-database column. The column carries a DB-level default of `office` — the cheaper seat — as a safety net, but the create form still **requires** an explicit choice so a human never bills the wrong bucket by omission. The backfill sets every existing user to `office` and forces `plannable = false`; field staff are marked by hand afterwards (this empties the planner until that is done — an accepted, one-time cost).

**Files:**
- a new tenant migration adding `users.seat_type` (`php artisan make:migration add_seat_type_to_users_table --path=database/migrations/tenant`)
- `database/factories/UserFactory.php`
- `app/Http/Requests/UserStoreRequest.php`, `app/Http/Requests/UserUpdateRequest.php`
- `resources/js/Pages/Users/EditPage.vue`

**Interfaces:**
- Produces: `users.seat_type` (`field`|`office`, default `office`); `UserFactory` default `seat_type = 'office'` with a `field()` state; `seat_type` required (`in:field,office`) in the user store/update requests.

### Task 33, Step 1: Create the tenant migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('seat_type')->default('office')->after('plannable');
        });

        // Backfill: everyone office (the column default already did this) and not plannable.
        // Field staff are promoted by hand afterwards.
        DB::table('users')->update(['plannable' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('seat_type');
        });
    }
};
```

### Task 33, Step 2: Give the factory a seat type

In `database/factories/UserFactory.php`, add `'seat_type' => 'office'` to the `definition()` array, and add a state below it:

```php
public function field(): static
{
    return $this->state(fn (array $attributes) => ['seat_type' => 'field']);
}
```

### Task 33, Step 3: Require `seat_type` in the store request

In `UserStoreRequest::rules()`, add:

```php
'seat_type' => 'required|in:field,office',
```

### Task 33, Step 4: Require `seat_type` in the update request — inside the admin branch, not the flat rules

`UserUpdateRequest` serves **three** routes: `users.update`, and also `me.update` via `UserController::updateSelf` (verified at `UserController.php:55,68,120`). That is why `role_ids` and `plannable` are added only inside the `isAdmin()` branch of `rules()` rather than to the base `$rules` array. `seat_type` must follow exactly the same pattern, for two reasons:

- A flat `'seat_type' => 'required|…'` makes the field **required on every self-profile save**, and the profile form does not send it — so `me.update` starts failing validation for every non-admin.
- Seat type is what a seat *costs* and what a user is *allowed to do* (Task 35). A user who can set their own is a user who can grant themselves a field seat. That is a licensing boundary, not a profile preference.

So add it to the existing admin-only block:

```php
$request_user = request()->user();
if ($request_user && method_exists($request_user, 'isAdmin') && $request_user->isAdmin()) {
    $rules['role_ids'] = 'sometimes|array';
    $rules['role_ids.*'] = 'integer|exists:roles,id';
    $rules['plannable'] = 'sometimes|boolean';
    $rules['seat_type'] = 'required|in:field,office';
}
```

And mirror the existing defensive strip in `UserController::updateSelf`, which already does this for roles:

```php
$data = $request->validated();
unset($data['role_ids'], $data['seat_type']);
```

(The `unset` is belt-and-braces — a non-admin's `seat_type` never reaches `validated()` because it was never a rule — but `role_ids` is stripped the same way, and matching that pattern means the next person to touch either does not have to re-derive why one is stripped and the other is not.)

### Task 33, Step 5: Add the seat-type field to the user form, admin-only

`resources/js/Pages/Users/EditPage.vue` renders **both** the admin user form and the self-profile form; it already distinguishes them with an `isSelfEdit` computed. Add `seat_type` to the `useForm({...})` call:

```js
seat_type: props.user?.seat_type || 'office',
```

and render the select next to the `plannable` checkbox, gated the same way `plannable` and the role picker already are (`v-if` on the admin/not-self condition those use — copy it, do not invent a second one). Options `Buitendienst` → `field` and `Kantoor` → `office`, plus the standard `form.errors.seat_type` line:

```vue
<select v-model="form.seat_type" class="...">
    <option value="field">Buitendienst</option>
    <option value="office">Kantoor</option>
</select>
<div v-if="form.errors.seat_type" class="text-xs text-red-600 mt-1">{{ form.errors.seat_type }}</div>
```

### Task 33, Step 6: Run the suite — existing user tests must still pass

Run: `php artisan test --filter=User`
Expected: PASS. The factory now supplies `seat_type`, so inserts satisfy the column; the store/update requests now require it, so any test that POSTs a user must include `seat_type` (update those tests to pass `'seat_type' => 'office'`).

### Task 33, Step 7: Commit

```bash
git add database/migrations/tenant/ \
        database/factories/UserFactory.php \
        app/Http/Requests/UserStoreRequest.php app/Http/Requests/UserUpdateRequest.php \
        resources/js/Pages/Users/EditPage.vue
git commit -m "feat(tenancy): add seat_type to users with form field and backfill"
```

---

## Task 34: Licensing CLI — catalogue CRUD and per-tenant subscription commands

The commands that manage the price catalogue and each tenant's subscription. All run in central context. Money arguments are in cents. `set` commands upsert. Catalogue-delete commands refuse while a tenant still references the row.

**Files (new):**
- `app/Console/Commands/Licensing/PackageCommand.php`, `ModuleCommand.php`, `BundleCommand.php`, `PricingCommand.php`
- `app/Console/Commands/Licensing/TenantPackageCommand.php`, `TenantSeatsCommand.php`, `TenantModulesCommand.php`, `TenantStorageCommand.php`, `TenantOverrideCommand.php`, `TenantOverviewCommand.php`
- `tests/Feature/LicensingCommandsTest.php`

**Interfaces:**
- Consumes: `Package`, `Module`, `ModuleBundle`, `PricingSetting`, `TenantSubscription` (Task 16); `Tenant` (Task 4).

### Task 34, Step 1: Write the failing referential-integrity test

```php
<?php

namespace Tests\Feature;

use App\Models\Central\Package;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LicensingCommandsTest extends TestCase
{
    public function test_package_delete_refuses_while_a_tenant_uses_it(): void
    {
        Tenant::on('central')->where('id', '!=', 'test-tenant')->delete();
        Tenant::create(['id' => 'acme', 'name' => 'Acme', 'package_key' => 'business']);

        $code = Artisan::call('package:delete', ['key' => 'business']);

        $this->assertSame(1, $code); // FAILURE
        $this->assertTrue(Package::on('central')->where('key', 'business')->exists());
    }

    public function test_package_set_updates_an_existing_price(): void
    {
        Artisan::call('package:set', ['key' => 'business', '--price' => 17000, '--no-interaction' => true]);

        $this->assertSame(17000, (int) Package::on('central')->where('key', 'business')->value('price_cents'));
    }
}
```

### Task 34, Step 2: Run to verify it fails

Run: `php artisan test --filter=LicensingCommandsTest`
Expected: FAIL — command `package:delete` / `package:set` not defined.

### Task 34, Step 3: Implement `package:list|set|delete`

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use Illuminate\Console\Command;

class PackageCommand extends Command
{
    protected $signature = 'package:set {key}
        {--name=} {--field-seats=} {--office-seats=} {--price=} {--extra-field=} {--extra-office=}';
    protected $description = 'Create or update a package';

    public function handle(): int
    {
        $map = array_filter([
            'name'               => $this->option('name'),
            'field_seats'        => $this->option('field-seats'),
            'office_seats'       => $this->option('office-seats'),
            'price_cents'        => $this->option('price'),
            'extra_field_cents'  => $this->option('extra-field'),
            'extra_office_cents' => $this->option('extra-office'),
        ], fn ($v) => $v !== null);

        $package = Package::on('central')->firstOrNew(['key' => $this->argument('key')]);
        $affected = Tenant::on('central')->where('package_key', $package->key)->count();

        if ($package->exists && isset($map['price_cents']) && $affected > 0) {
            $this->warn("This re-prices {$affected} tenant(s) on '{$package->key}'.");
            if (!$this->confirm('Continue?', false)) {
                return self::FAILURE;
            }
        }

        $package->fill($map);
        $package->name ??= ucfirst($package->key);
        $package->save();

        $this->info("Package '{$package->key}' saved.");
        return self::SUCCESS;
    }
}
```

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use App\Models\Tenant;
use Illuminate\Console\Command;

class PackageDeleteCommand extends Command
{
    protected $signature = 'package:delete {key}';
    protected $description = 'Delete a package unless a tenant uses it';

    public function handle(): int
    {
        $package = Package::on('central')->where('key', $this->argument('key'))->first();
        if (!$package) {
            $this->error('Package not found.');
            return self::FAILURE;
        }

        $tenants = Tenant::on('central')->where('package_key', $package->key)->pluck('name');
        if ($tenants->isNotEmpty()) {
            $this->error("Refusing to delete '{$package->key}' — used by: " . $tenants->implode(', '));
            return self::FAILURE;
        }

        $package->delete();
        $this->info("Package '{$package->key}' deleted.");
        return self::SUCCESS;
    }
}
```

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\Package;
use Illuminate\Console\Command;

class PackageListCommand extends Command
{
    protected $signature = 'package:list';
    protected $description = 'List packages';

    public function handle(): int
    {
        $rows = Package::on('central')->orderBy('sort_order')->get()
            ->map(fn ($p) => [
                $p->key, $p->name, $p->field_seats, $p->office_seats,
                number_format($p->price_cents / 100, 2, ',', '.'),
                number_format($p->extra_field_cents / 100, 2, ',', '.'),
                number_format($p->extra_office_cents / 100, 2, ',', '.'),
            ])->all();

        $this->table(['key', 'name', 'field', 'office', 'prijs', 'extra field', 'extra office'], $rows);
        return self::SUCCESS;
    }
}
```

### Task 34, Step 4: Implement `module:list|set|delete` and `bundle:list|set|delete`

Mirror the package commands. `module:set {key} {--name=} {--price=}` upserts a `Module`; `module:delete {key}` refuses while any tenant's `modules` JSON contains the key:

```php
$in_use = Tenant::on('central')->whereJsonContains('modules', $key)->pluck('name');
if ($in_use->isNotEmpty()) {
    $this->error("Refusing to delete module '{$key}' — used by: " . $in_use->implode(', '));
    return self::FAILURE;
}
```

`bundle:set {name} {--modules=} {--price=}` upserts a `ModuleBundle` (splitting `--modules=quotes,invoices` on commas into the `module_keys` array); `bundle:delete {name}` deletes by name.

### Task 34, Step 5: Implement `pricing:list|set` for the storage scalars

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Central\PricingSetting;
use Illuminate\Console\Command;

class PricingCommand extends Command
{
    protected $signature = 'pricing:set {key} {value}';
    protected $description = 'Set a pricing scalar (included_storage_gb, storage_extra_per_gb_cents)';

    public function handle(): int
    {
        $allowed = ['included_storage_gb', 'storage_extra_per_gb_cents'];
        if (!in_array($this->argument('key'), $allowed, true)) {
            $this->error('Unknown key. Allowed: ' . implode(', ', $allowed));
            return self::FAILURE;
        }

        PricingSetting::on('central')->updateOrCreate(
            ['key' => $this->argument('key')],
            ['value' => (int) $this->argument('value')]
        );

        $this->info("Set {$this->argument('key')} = {$this->argument('value')}.");
        return self::SUCCESS;
    }
}
```

### Task 34, Step 6: Implement the per-tenant subscription commands

`tenant:package {id} {key}` validates the key against `packages` and sets `package_key`. `tenant:seats {id} {--field=} {--office=}` accepts signed deltas (`+5`, `-2`) or absolute values and clamps at 0. `tenant:modules {id} {--add=*} {--remove=*}` validates against `modules` and edits the JSON. `tenant:storage {id} {--limit=}` sets `storage_limit_gb`. `tenant:override {id} {--price=} {--clear}` sets or nulls `price_override_cents`. Each prints the tenant's new monthly total via `(new TenantSubscription($tenant))->monthlyTotalCents()`.

Example — `tenant:storage`:

```php
<?php

namespace App\Console\Commands\Licensing;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantStorageCommand extends Command
{
    protected $signature = 'tenant:storage {id} {--limit=}';
    protected $description = 'Show or set a tenant storage limit (GB)';

    public function handle(): int
    {
        $tenant = Tenant::on('central')->find($this->argument('id'));
        if (!$tenant) {
            $this->error('Tenant not found.');
            return self::FAILURE;
        }

        if ($this->option('limit') !== null) {
            $tenant->update(['storage_limit_gb' => max(0, (int) $this->option('limit'))]);
        }

        $this->info("Tenant '{$tenant->name}' storage limit: {$tenant->fresh()->storage_limit_gb} GB");
        return self::SUCCESS;
    }
}
```

### Task 34, Step 7: Implement `tenant:overview`

For each tenant it switches into tenant context, counts users by `seat_type` and reads `storage_used_bytes` (default 0 until Task 36 populates it), then computes the monthly total in central context. Follows the scheduler's per-tenant pattern (Task 20). Flags a `⚠` when usage exceeds a limit.

```php
public function handle(): int
{
    $rows = [];
    $total = 0;

    Tenant::on('central')->cursor()->each(function (Tenant $tenant) use (&$rows, &$total) {
        $package = \App\Models\Central\Package::on('central')->where('key', $tenant->package_key)->first();

        tenancy()->initialize($tenant);
        $field  = \App\Models\User::where('seat_type', 'field')->count();
        $office = \App\Models\User::where('seat_type', 'office')->count();
        $used_gb = (int) round(\App\Models\GeneralSetting::get('storage_used_bytes', 0) / (1024 ** 3));
        tenancy()->end();

        $field_limit  = ($package->field_seats ?? 0) + $tenant->extra_field_seats;
        $office_limit = ($package->office_seats ?? 0) + $tenant->extra_office_seats;
        $monthly = (new \App\Services\TenantSubscription($tenant))->monthlyTotalCents();
        $total += $monthly;

        $rows[] = [
            $tenant->name,
            $package->name ?? '—',
            $field . '/' . $field_limit . ($field > $field_limit ? ' ⚠' : ''),
            $office . '/' . $office_limit . ($office > $office_limit ? ' ⚠' : ''),
            $used_gb . '/' . $tenant->storage_limit_gb . ' GB',
            implode(',', $tenant->modules ?? []) ?: '—',
            '€' . number_format($monthly / 100, 2, ',', '.'),
        ];
    });

    $this->table(['Naam', 'Pakket', 'Field', 'Office', 'Opslag', 'Modules', '/mnd'], $rows);
    $this->info('Totaal: €' . number_format($total / 100, 2, ',', '.'));
    return self::SUCCESS;
}
```

### Task 34, Step 8: Run the command tests to verify they pass

Run: `php artisan test --filter=LicensingCommandsTest`
Expected: PASS.

### Task 34, Step 9: Commit

```bash
git add app/Console/Commands/Licensing/ tests/Feature/LicensingCommandsTest.php
git commit -m "feat(tenancy): licensing CLI for catalogue and tenant subscriptions"
```

---

## Task 35: Enforce seat limits and seat-type capability

Two enforcement layers, both validation. **Seat limits:** a `SeatAvailable` rule blocks creating/promoting a user into a full seat type, while never touching existing users. **Capability:** an office user cannot be made plannable and cannot be assigned as an executing user on an event — this is what makes a seat type mean something and stops the limit being gamed. Seat usage is also shared to the frontend so the user page can show "5 van 10".

**Files:**
- `app/Rules/SeatAvailable.php` (new)
- `app/Http/Requests/UserStoreRequest.php`, `UserUpdateRequest.php`, `UserRestoreRequest.php`
- `app/Http/Requests/UpdateUserPlannableRequest.php`, `EventStoreRequest.php`, `EventUpdateRequest.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `tests/Feature/SeatLimitTest.php`, `tests/Feature/SeatCapabilityTest.php`

**Interfaces:**
- Consumes: `Package` (Task 16), `users.seat_type` (Task 33), `tenancy()->tenant` (Task 4/12).
- Produces: `App\Rules\SeatAvailable` — `new SeatAvailable(?int $ignore_user_id = null)`. The seat type it checks is the attribute value the validator passes in, not a constructor argument.

### Task 35, Step 1: Write the failing seat-limit test

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class SeatLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin the test tenant to Starter (1 field / 1 office) for these tests.
        Tenant::on('central')->where('id', 'test-tenant')->update([
            'package_key' => 'starter', 'extra_field_seats' => 0, 'extra_office_seats' => 0,
        ]);
    }

    public function test_creating_a_field_user_beyond_the_limit_fails(): void
    {
        $admin = User::factory()->field()->create();
        User::factory()->field()->create(); // fills the single field seat

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nieuw', 'email' => 'nieuw@x.nl', 'password' => 'secret12',
            'seat_type' => 'field',
        ])->assertSessionHasErrors('seat_type');

        $this->assertSame(2, User::where('seat_type', 'field')->count());
    }

    public function test_a_soft_deleted_user_frees_a_seat(): void
    {
        $admin = User::factory()->office()->create();
        $worker = User::factory()->field()->create();
        $worker->delete(); // soft delete frees the field seat

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nieuw', 'email' => 'nieuw@x.nl', 'password' => 'secret12',
            'seat_type' => 'field',
        ])->assertSessionHasNoErrors();
    }
}
```

(Add an `office()` factory state alongside `field()` in Task 33's factory edit, or use the default.)

### Task 35, Step 2: Run to verify it fails

Run: `php artisan test --filter=SeatLimitTest`
Expected: FAIL — a third field user is allowed because no rule enforces the limit.

### Task 35, Step 3: Implement the `SeatAvailable` rule

```php
<?php

namespace App\Rules;

use App\Models\Central\Package;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SeatAvailable implements ValidationRule
{
    public function __construct(private ?int $ignore_user_id = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== 'field' && $value !== 'office') {
            return; // the in: rule reports an invalid value
        }

        if (!tenancy()->initialized) {
            return;
        }

        $package = Package::on('central')->where('key', tenancy()->tenant->package_key)->first();
        if (!$package) {
            return;
        }

        $base  = $value === 'field' ? $package->field_seats : $package->office_seats;
        $extra = $value === 'field' ? tenancy()->tenant->extra_field_seats : tenancy()->tenant->extra_office_seats;
        $limit = $base + $extra;

        $query = User::where('seat_type', $value);
        if ($this->ignore_user_id) {
            $query->whereKeyNot($this->ignore_user_id);
        }

        if ($query->count() >= $limit) {
            $label = $value === 'field' ? 'buitendienst' : 'kantoor';
            $fail("Uw licentie staat {$limit} {$label}gebruikers toe. Neem contact op om uit te breiden.");
        }
    }
}
```

### Task 35, Step 4: Apply the rule in the user requests

In `UserStoreRequest::rules()`, change the `seat_type` line (added in Task 33) to:

```php
'seat_type' => ['required', 'in:field,office', new \App\Rules\SeatAvailable()],
```

In `UserUpdateRequest::rules()`, extend the line **inside the admin branch** (Task 33 Step 4 — it is not in the flat `$rules` array, and must not be moved there), passing the ignored user so an unchanged office→office or field→field edit does not count the user against itself:

```php
$rules['seat_type'] = ['required', 'in:field,office', new \App\Rules\SeatAvailable($ignore_id)];
```

(`$ignore_id` is already computed in that request for the email rule.) In `UserRestoreRequest`, add an `after` validation hook or a `rules()` entry that runs `SeatAvailable` against the trashed user's `seat_type`, so restoring into a full seat type is refused.

### Task 35, Step 5: Write and pass the capability test

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class SeatCapabilityTest extends TestCase
{
    public function test_an_office_user_cannot_be_made_plannable(): void
    {
        $admin  = User::factory()->office()->create();
        $office = User::factory()->office()->create();

        $this->actingAs($admin)
            ->patch("/api/users/{$office->id}/plannable", ['plannable' => true])
            ->assertStatus(422);
    }
}
```

### Task 35, Step 6: Enforce capability in the requests

In `UpdateUserPlannableRequest`, reject `plannable = true` when the target user (the route `{user}`) is an office seat:

```php
public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        $user = $this->route('user');
        if ($this->boolean('plannable') && $user && $user->seat_type === 'office') {
            $validator->errors()->add('plannable', 'Een kantoorgebruiker kan niet ingepland worden.');
        }
    });
}
```

In `EventStoreRequest` and `EventUpdateRequest`, reject any office user in the executing-user list (the field that carries executing user ids — reuse the exact field name already validated there):

```php
$validator->after(function ($validator) {
    $office_ids = \App\Models\User::whereIn('id', (array) $this->input('executing_user_ids', []))
        ->where('seat_type', 'office')->pluck('id');
    if ($office_ids->isNotEmpty()) {
        $validator->errors()->add('executing_user_ids', 'Kantoorgebruikers kunnen niet worden ingepland op een afspraak.');
    }
});
```

Confirm the executing-user field name against the current request (Task interfaces note it may be `executing_user_ids` or nested) before wiring.

### Task 35, Step 7: Share seat usage with the frontend

Extend the `tenant` share added in Task 16 (`HandleInertiaRequests`) with seat usage, so the user page can show "5 van 10":

```php
'tenant' => tenancy()->initialized ? [
    'package' => tenancy()->tenant->package_key,
    'modules' => tenancy()->tenant->modules ?? [],
    'seats'   => [
        'field'  => ['used' => \App\Models\User::where('seat_type', 'field')->count(),  'limit' => $field_limit],
        'office' => ['used' => \App\Models\User::where('seat_type', 'office')->count(), 'limit' => $office_limit],
    ],
] : null,
```

where `$field_limit` / `$office_limit` are `package.field_seats + tenant.extra_field_seats` (look the package up once from `tenancy()->tenant->package_key`). Show these counts on `Users/IndexPage.vue` next to the "add user" button.

### Task 35, Step 8: Run all seat tests to verify they pass

Run: `php artisan test --filter=Seat`
Expected: PASS. Fix any pre-existing user/event/planner test broken by the new rules by giving its users the correct seat type (`->field()` for anyone made plannable or assigned to an event).

### Task 35, Step 9: Commit

```bash
git add app/Rules/SeatAvailable.php app/Http/Requests/ app/Http/Middleware/HandleInertiaRequests.php \
        resources/js/Pages/Users/IndexPage.vue tests/Feature/SeatLimitTest.php tests/Feature/SeatCapabilityTest.php
git commit -m "feat(tenancy): enforce seat limits and seat-type capability"
```

---

## Task 36: Per-tenant storage quota

Each tenant has a `storage_limit_gb` allowance (default 50, Task 6). A `StorageQuota` service enforces it as a `WithinStorageQuota` rule on the upload paths. New uploads over the limit are blocked; existing files are never deleted.

**Usage is files plus the database**, and the two are measured differently:

- **Files** are a running counter in the tenant's `general_settings`, incremented on upload and corrected nightly from disk.
- **The database** is queried live from `information_schema`. No counter — a tenant's row growth has no single place to hook.

Counting the database matters because the file total is not the whole bill. `activities`, `activity_changes`, `location_pings` and `assistant_questions` all grow on their own, without anybody uploading anything, and a busy tenant's audit trail is not small.

**Files:**
- `app/Services/StorageQuota.php` (new)
- `app/Rules/WithinStorageQuota.php` (new)
- `app/Jobs/ReconcileStorageUsageJob.php` (new)
- `routes/console.php`
- upload requests: `app/Http/Requests/ImageStoreRequest.php` (or the image controller's inline validation), `DocumentStoreRequest.php`, `UserStoreRequest.php`/`UserUpdateRequest.php` (avatar), company-logo request
- `app/Http/Controllers/Api/ImageController.php`, `DocumentController.php` and the other upload paths (call `add()`/`subtract()`)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `tests/Feature/StorageQuotaTest.php`

**Interfaces:**
- Produces: `App\Services\StorageQuota` — `usedBytes(): int`, `limitBytes(): int`, `remainingBytes(): int`, `hasRoomFor(int $bytes): bool`, `add(int $bytes): void`, `subtract(int $bytes): void`, `reconcile(): int`. Backed by `GeneralSetting` key `storage_used_bytes` and `tenancy()->tenant->storage_limit_gb`.

### Task 36, Step 1: Write the failing service test

```php
<?php

namespace Tests\Feature;

use App\Models\GeneralSetting;
use App\Models\Tenant;
use App\Services\StorageQuota;
use Tests\TestCase;

class StorageQuotaTest extends TestCase
{
    public function test_limit_bytes_follows_the_tenant_limit(): void
    {
        Tenant::on('central')->where('id', 'test-tenant')->update(['storage_limit_gb' => 50]);
        tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

        $this->assertSame(50 * (1024 ** 3), (new StorageQuota())->limitBytes());
    }

    public function test_add_and_subtract_move_the_file_counter(): void
    {
        $quota = new StorageQuota();
        $quota->add(1000);
        $quota->add(500);
        $quota->subtract(200);

        $this->assertSame(1300, (int) GeneralSetting::get('storage_used_bytes', 0));
        $this->assertSame(1300, $quota->fileBytes());
    }

    public function test_used_bytes_includes_the_database(): void
    {
        $quota = new StorageQuota();
        $quota->add(1000);

        $this->assertGreaterThan(1000, $quota->usedBytes());
    }

    public function test_has_room_for_respects_the_limit(): void
    {
        Tenant::on('central')->where('id', 'test-tenant')->update(['storage_limit_gb' => 0]);
        tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

        $this->assertFalse((new StorageQuota())->hasRoomFor(1));
    }
}
```

### Task 36, Step 2: Run to verify it fails

Run: `php artisan test --filter=StorageQuotaTest`
Expected: FAIL — `App\Services\StorageQuota` not found.

### Task 36, Step 3: Implement the `StorageQuota` service

```php
<?php

namespace App\Services;

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Storage;

class StorageQuota
{
    public function usedBytes(): int
    {
        return $this->fileBytes() + $this->databaseBytes();
    }

    public function fileBytes(): int
    {
        return (int) GeneralSetting::get('storage_used_bytes', 0);
    }

    /**
     * Cached for five minutes: this runs on every upload validation, and the
     * number moves slowly. The cache key is per-tenant already (Task 10).
     */
    public function databaseBytes(): int
    {
        return Cache::remember('storage_database_bytes', 300, function () {
            $connection = DB::connection('tenant');

            $row = $connection->selectOne(
                'SELECT SUM(data_length + index_length) AS bytes
                 FROM information_schema.tables WHERE table_schema = ?',
                [$connection->getDatabaseName()],
            );

            return (int) ($row->bytes ?? 0);
        });
    }

    public function limitBytes(): int
    {
        return (int) tenancy()->tenant->storage_limit_gb * (1024 ** 3);
    }

    public function remainingBytes(): int
    {
        return max(0, $this->limitBytes() - $this->usedBytes());
    }

    public function hasRoomFor(int $bytes): bool
    {
        return $this->usedBytes() + $bytes <= $this->limitBytes();
    }

    public function add(int $bytes): void
    {
        GeneralSetting::set('storage_used_bytes', $this->fileBytes() + max(0, $bytes));
    }

    public function subtract(int $bytes): void
    {
        GeneralSetting::set('storage_used_bytes', max(0, $this->fileBytes() - max(0, $bytes)));
    }

    public function reconcile(): int
    {
        $total = 0;
        foreach (['public', 'local'] as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $file) {
                $total += Storage::disk($disk)->size($file);
            }
        }
        GeneralSetting::set('storage_used_bytes', $total);

        return $total;
    }
}
```

### Task 36, Step 4: Run the service test to verify it passes

Run: `php artisan test --filter=StorageQuotaTest`
Expected: PASS.

### Task 36, Step 5: Implement the `WithinStorageQuota` rule and apply it to the upload requests

```php
<?php

namespace App\Rules;

use App\Services\StorageQuota;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class WithinStorageQuota implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        $files = is_array($value) ? $value : [$value];
        $incoming = collect($files)
            ->filter(fn ($f) => $f instanceof UploadedFile)
            ->sum(fn (UploadedFile $f) => $f->getSize());

        if (!(new StorageQuota())->hasRoomFor((int) $incoming)) {
            $fail('Uw opslaglimiet is bereikt. Neem contact op om uit te breiden.');
        }
    }
}
```

**The array-level rules this attaches to do not exist yet — add them.** `ImageStoreRequest` and `DocumentStoreRequest` validate only the per-file wildcard (`'images.*' => 'required|image|…'`, `'documents.*' => 'required|file|…'`). Attaching `WithinStorageQuota` to the wildcard would run it once per file, each time asking "does the *whole* remaining quota fit this *one* file?" — so a tenant with 1 MB left could upload twenty 900 KB files in a single request and every check would pass. The rule is written to sum an array, so it needs an array-level key to receive one:

```php
// ImageStoreRequest::rules()
'images'    => ['required', 'array', new \App\Rules\WithinStorageQuota()],
'images.*'  => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',

// DocumentStoreRequest::rules()
'documents'   => ['required', 'array', new \App\Rules\WithinStorageQuota()],
'documents.*' => 'required|file|mimes:' . self::ALLOWED_MIMES . '|max:102400',
```

**Copy the `max:` from the file, do not copy it from here.** It is **102400** — 100 MB
per document — and it is worth pausing on, because a single upload can consume 0,2%
of a 50 GB allowance. That puts the quota within reach of ordinary use rather than
only of abuse. It also means PHP itself has to be raised to let one through, which production does in nginx and FPM config, not in the app (see the
`PHP_INI_SCAN_DIR` block in `AppServiceProvider::boot`, which handles only
`artisan serve` locally). A quota rule that passes while `upload_max_filesize`
rejects the request is a confusing failure, and it is not the app's to fix.

Adding `required|array` to `images` is a small behaviour change beyond the quota: `ImageController::store` currently hand-rolls that check (`if (! $request->hasFile('images')) { return redirect()->back()->withErrors(...); }`). Once the rule exists, that branch is dead — remove it, per the project convention that validation lives in the Form Request and the frontend only renders `form.errors`.

For the single-file paths (`avatar` on the user requests, the company-logo request), attach the rule directly to the file field — the rule already wraps a non-array value in an array.

### Task 36, Step 6: Account for stored bytes on upload and delete

After a successful store in each upload path, add the bytes; on delete, subtract. In `ImageController::store`, after `storePubliclyAs`:

```php
app(\App\Services\StorageQuota::class)->add($image->getSize());
```

In `ImageController::destroy` / `DocumentController::destroy`, before deleting the file, capture its size and `subtract()` it. `documents` carries a `size` column, so the document paths can read it off the row instead of stat-ing the disk. Do the same for avatar replacement and company-logo upload/replace.

**The counter will always run a little low.** `GeneralSetting::set` reads the current value, adds to it and writes it back. There is no lock and no atomic increment, and the browser uploads images several at a time, so two `add()` calls can read the same starting number and one of them is lost. It only ever undercounts, never overcounts, which is the safe direction for a limit.

Two things follow:

- The nightly job (Step 7) is not just a safety net for call sites somebody forgot to update. It is the only thing that makes the number right. Do not delete it to save a query.
- Never bill anyone from the running counter. Bill from what the nightly job measured.

If drift ever becomes visible to customers, the fix is an atomic `UPDATE general_settings SET value = value + ? WHERE key = ?` rather than a lock — but that requires the column to be numeric, so it is a migration, not a one-liner. Not worth doing pre-emptively.

**Two things about the database half that will otherwise surprise somebody.**

`data_length` and `index_length` come from InnoDB's cached table statistics, so
they are an estimate. Good enough to enforce a quota; not good enough to bill on.
The same warning as the file counter: bill from a measurement, not from this.

And **deleting rows does not shrink it.** InnoDB keeps the freed pages for reuse
inside the tablespace, so a tenant who clears a year of location pings sees no
change until the table is rebuilt with `OPTIMIZE TABLE`. Anyone told "delete some
data to get under your limit" will report that as a bug, so either say so in the
message or run the rebuild for them.

One consequence to accept: a tenant near the limit can now be blocked from
uploading by growth they did not cause — the audit trail and the ping table grow
whether or not anybody touches a file. The nightly ping prune (Task 20) is what
keeps the largest of those bounded.

**A fourth writer of tenant storage deliberately does not call `add()`.** The assistant parks photos and files a question carried (`assistant-photos/`, `assistant-files/`) and writes reported conversations (`assistant-reports/`) on the `local` disk. None of these paths goes through an upload Form Request, so there is nothing to attach `WithinStorageQuota` to, and none of them calls `add()`.

Leave it that way. All three are short-lived by design — `assistant.photo_days` defaults to 7 and `assistant:prune` sweeps them — so charging a tenant's allowance for a photo that will be gone within the week bills them for their own working memory. The nightly reconcile counts whatever is genuinely still there, which is the honest number. What this does mean is that `reconcile()` and `usedBytes()` can disagree by more than they used to, in the direction of the counter being low; that is the same direction the concurrency drift already goes, so nothing new is needed to handle it.

The one case that *does* need `add()` is `ConversationPhotos::keep()`, which copies a parked photo from `local` onto the `public` disk as a real `Image` record. That is a deliberate decision to keep a file for good, it goes through the same storage as any other image, and it should cost the same.

### Task 36, Step 7: Add the nightly reconcile job and schedule it per tenant

```php
<?php

namespace App\Jobs;

use App\Services\StorageQuota;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileStorageUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        (new StorageQuota())->reconcile();
    }
}
```

In `routes/console.php`, add a per-tenant dispatch alongside the existing scheduled blocks (same pattern as Task 20):

```php
Schedule::call(function () {
    Tenant::on('central')->cursor()->each(function (Tenant $tenant) {
        tenancy()->initialize($tenant);
        \App\Jobs\ReconcileStorageUsageJob::dispatch();
        tenancy()->end();
    });
})->dailyAt('03:30')->name('reconcile-storage-usage')->withoutOverlapping();
```

### Task 36, Step 8: Share storage usage with the frontend

Extend the `tenant` share (Task 16/35) with storage, so a usage bar can be shown:

```php
'storage' => [
    'file_bytes'     => $quota->fileBytes(),
    'database_bytes' => $quota->databaseBytes(),
    'used_bytes'     => $quota->usedBytes(),
    'limit_bytes'    => $quota->limitBytes(),
],
```

### Task 36, Step 9: Write the enforcement test and confirm the suite is green

```php
public function test_an_upload_over_the_limit_is_rejected(): void
{
    \Illuminate\Support\Facades\Storage::fake('public');
    Tenant::on('central')->where('id', 'test-tenant')->update(['storage_limit_gb' => 0]);
    tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

    $rule = new \App\Rules\WithinStorageQuota();
    $failed = false;
    $rule->validate('images', [\Illuminate\Http\UploadedFile::fake()->image('x.jpg')], function () use (&$failed) {
        $failed = true;
    });

    $this->assertTrue($failed);
}
```

Run: `php artisan test --filter=StorageQuotaTest`
Expected: PASS.

### Task 36, Step 10: Commit

```bash
git add app/Services/StorageQuota.php app/Rules/WithinStorageQuota.php \
        app/Jobs/ReconcileStorageUsageJob.php routes/console.php \
        app/Http/Requests/ app/Http/Controllers/ app/Http/Middleware/HandleInertiaRequests.php \
        tests/Feature/StorageQuotaTest.php
git commit -m "feat(tenancy): per-tenant storage quota with nightly reconcile"
```

---

## Task 37: Landlord admin sub-app

A small internal admin for managing the catalogue and every tenant's subscription in a browser. **Built under `/beheer` on the app's own host, not on a separate subdomain** -- one certificate, one vhost, nothing extra to arrange at install time. The separation lives in the middleware rather than in DNS. It runs **central-only** — its routes never carry the tenancy middleware — with its own `landlord` guard and `landlord_users` table. It is a thin visual layer over the Task 34 logic and the `TenantSubscription` service; controllers hold no pricing logic.

Built last: it depends on the catalogue (Task 6/16), the commands' logic (Task 34), seat counting (Task 35) and the storage counter (Task 36).

**Files:**
- a new central migration for `landlord_users` (`php artisan make:migration create_landlord_users_table`)
- `app/Models/Central/LandlordUser.php`
- `config/auth.php` (landlord guard + provider)
- `app/Console/Commands/CreateLandlordUser.php`
- `routes/landlord.php`, `bootstrap/app.php`
- `app/Http/Controllers/Landlord/` (auth, tenants, packages, modules, bundles, pricing)
- `resources/js/Pages/Landlord/**`, a `LandlordLayout.vue`
- `tests/Feature/Landlord/LandlordAccessTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `App\Models\Central\LandlordUser`; `landlord` auth guard; routes under the `/beheer` prefix.

### Task 37, Step 1: Create the `landlord_users` central migration and model

```php
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('landlord_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landlord_users');
    }
};
```

```php
<?php

namespace App\Models\Central;

use Illuminate\Foundation\Auth\User as Authenticatable;

class LandlordUser extends Authenticatable
{
    protected $connection = 'central';
    protected $table = 'landlord_users';
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password' => 'hashed'];
}
```

### Task 37, Step 2: Register the `landlord` guard

In `config/auth.php`, add a guard and provider:

```php
'guards' => [
    // ...existing...
    'landlord' => ['driver' => 'session', 'provider' => 'landlord_users'],
],

'providers' => [
    // ...existing...
    'landlord_users' => ['driver' => 'eloquent', 'model' => App\Models\Central\LandlordUser::class],
],
```

### Task 37, Step 3: Add the `landlord:user` command

```php
protected $signature = 'landlord:user {email} {--name=Beheer} {--password=}';

public function handle(): int
{
    $password = $this->option('password') ?: \Illuminate\Support\Str::password(16);
    \App\Models\Central\LandlordUser::on('central')->updateOrCreate(
        ['email' => $this->argument('email')],
        ['name' => $this->argument('name'), 'password' => $password]
    );
    $this->info("Landlord {$this->argument('email')} created. Password: {$password}");
    return self::SUCCESS;
}
```

### Task 37, Step 4: Register the landlord route file under /beheer, without tenancy middleware

In `bootstrap/app.php`, load `routes/landlord.php` in `withRouting` via a `then:` closure, wrapping it in the `web` group **minus** `InitializeTenancyBySession`, scoped to the `beheer` domain:

```php
->withRouting(
    web: __DIR__ . '/../routes/web.php',
    api: __DIR__ . '/../routes/api.php',
    commands: __DIR__ . '/../routes/console.php',
    health: '/up',
    then: function () {
        Route::middleware('web')
            ->prefix('beheer')
            ->group(base_path('routes/landlord.php'));
    },
)
```

No extra configuration: the routes live under the `/beheer` prefix on the app's own host. Because they are registered in the `web` group but **not** appended with `InitializeTenancyBySession` (Task 12 appends that only to the main web group), they never initialize tenancy. Moving to a dedicated host later means changing this one `prefix()` call and adding a vhost; nothing else assumes a host. Add a feature test asserting the default connection stays `central` through a landlord request.

### Task 37, Step 5: Write the failing access test

```php
<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\LandlordUser;
use App\Models\User;
use Tests\TestCase;

class LandlordAccessTest extends TestCase
{
    public function test_a_tenant_user_cannot_authenticate_as_landlord(): void
    {
        $tenant_user = User::factory()->create(['email' => 'worker@acme.nl']);

        $this->assertFalse(
            auth('landlord')->attempt(['email' => 'worker@acme.nl', 'password' => 'password'])
        );
    }

    public function test_a_landlord_can_authenticate(): void
    {
        LandlordUser::on('central')->create(['name' => 'Ops', 'email' => 'ops@lavoro.nl', 'password' => 'secret123']);

        $this->assertTrue(
            auth('landlord')->attempt(['email' => 'ops@lavoro.nl', 'password' => 'secret123'])
        );
    }
}
```

### Task 37, Step 6: Build the login screen and guard the group

Landlord `login`/`logout` controllers authenticate against the `landlord` guard; every other landlord route sits behind `auth:landlord`. Reuse the app's Inertia setup with a distinct `LandlordLayout.vue` (no tenant branding, no `company` share). Routes:

```php
Route::middleware('auth:landlord')->group(function () {
    Route::get('/', [TenantOverviewController::class, 'index'])->name('landlord.tenants');
    Route::get('/tenants/{tenant}', [TenantController::class, 'edit'])->name('landlord.tenant.edit');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('landlord.tenant.update');
    Route::resource('packages', PackageController::class)->except('show');
    Route::resource('modules', ModuleController::class)->except('show');
    Route::resource('bundles', BundleController::class)->except('show');
    Route::get('/pricing', [PricingController::class, 'edit'])->name('landlord.pricing');
    Route::put('/pricing', [PricingController::class, 'update'])->name('landlord.pricing.update');
});
```

### Task 37, Step 7: Build the tenant overview and tenant-edit screens

The overview reuses the exact computation from `tenant:overview` (Task 34 Step 7) — extract that loop into a shared method (e.g. a `TenantOverviewController` calling a small helper) so the CLI and the UI produce identical figures. The tenant-edit screen posts package, extra seats, storage limit, modules and price override; on a catalogue price edit that re-prices tenants, show the same blast-radius list the CLI shows and require a confirm.

### Task 37, Step 8: Build the catalogue screens

Package/module/bundle/pricing screens are thin CRUD over the Task 16 models, reusing `ComboBox`/`TextInput`/`ModalDialog`. All money shown and entered in euros, stored in cents.

### Task 37, Step 9: Run the landlord tests and the full suite

Run: `php artisan test --filter=Landlord` then `composer test`
Expected: PASS, including the assertion that a landlord request never initializes tenancy.

### Task 37, Step 10: Operational notes

No DNS record or vhost is needed: the panel lives under `/beheer` on the existing host and shares the codebase and central database. Create the first landlord with `php artisan landlord:user ops@lavoro.nl`.

### Task 37, Step 11: Commit

```bash
git add database/migrations/ \
        app/Models/Central/LandlordUser.php config/auth.php config/app.php \
        app/Console/Commands/CreateLandlordUser.php routes/landlord.php bootstrap/app.php \
        app/Http/Controllers/Landlord/ resources/js/Pages/Landlord/ resources/js/Layouts/LandlordLayout.vue \
        tests/Feature/Landlord/
git commit -m "feat(tenancy): landlord admin sub-app for licensing management"
```

---

## Task 38: Update the deploy script for multi-tenancy

`deploy.sh` predates tenancy and breaks in two ways that produce **no error output** — the deploy looks entirely successful while doing half its job:

1. **The backup silently shrinks to almost nothing.** It dumps a single database read from `DB_DATABASE`, which after Task 2 is `lavoro_landlord` — the small central registry. Every customer's actual business data stops being backed up. The script still prints "Backup saved to …" and exits 0.
2. **Tenant schemas stop being migrated.** `php artisan migrate --force` runs only the central migrations; after the Task 8 split, everything in `database/migrations/tenant/` needs `php artisan tenants:migrate`. Every future feature migration would land in central and never reach a customer database, so the code expects columns the tenant databases do not have.

A third, narrower problem bites exactly once: `migrate` runs **before** `composer install`. On the first deploy that introduces tenancy, `config/tenancy.php` references `Stancl\Tenancy\*` classes that are not installed yet, so booting Artisan fails before any migration runs.

**Files:** `deploy.sh`

### Task 38, Step 1: Rewrite `deploy.sh`

Changes from the current script: dependencies install before migrations; the backup enumerates every tenant database and dumps each one; backups rotate as timestamped *sets* rather than individual files; tenant migrations run after central ones. Tenant dumps use the provisioner over the socket, so no password is read from `.env` for them.

```bash
#!/usr/bin/env bash
set -euo pipefail

BACKUP_ROOT="$(dirname "$0")/storage/backups/db"
STAMP="$(date +%Y-%m-%d_%H-%M-%S)"
BACKUP_DIR="$BACKUP_ROOT/$STAMP"
mkdir -p "$BACKUP_DIR"

DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2-)
DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2-)
DB_PASSWORD=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2-)
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2-)
DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d '=' -f2-)

DUMP_OPTS="--single-transaction --routines --triggers"

echo "==> Backing up central database ($DB_DATABASE)..."
MYSQL_PWD="$DB_PASSWORD" mysqldump $DUMP_OPTS \
    -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" \
    "$DB_DATABASE" | gzip > "$BACKUP_DIR/central.sql.gz"

echo "==> Backing up tenant databases..."
TENANT_DBS=$(MYSQL_PWD="$DB_PASSWORD" mysql -N -B \
    -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" \
    -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$.tenancy_db_name')) FROM \`$DB_DATABASE\`.tenants;")

if [ -z "$TENANT_DBS" ]; then
    echo "!!! No tenant databases found. Refusing to continue — this would be a backup of nothing."
    exit 1
fi

for db in $TENANT_DBS; do
    echo "    - $db"
    sudo -u lavoro_provisioner mysqldump --protocol=socket $DUMP_OPTS "$db" \
        | gzip > "$BACKUP_DIR/$db.sql.gz"
done
echo "    Backup set saved to $BACKUP_DIR"

echo "==> Pruning old backup sets (keeping 5)..."
ls -1dt "$BACKUP_ROOT"/*/ 2>/dev/null | tail -n +6 | xargs -r rm -rf --
echo "    Done pruning."

echo "==> Pulling latest from master..."
git fetch origin master
git reset --hard origin/master

echo "==> Updating Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Updating NPM dependencies..."
npm ci

echo "==> Running central migrations..."
php artisan migrate --force

echo "==> Running tenant migrations..."
php artisan tenants:migrate

echo "==> Building frontend assets..."
npm run build

echo "==> Clearing caches..."
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

echo "==> Restarting queue workers..."
php artisan queue:restart

echo "==> Done."
```

Notes on the specifics:

- `set -euo pipefail` replaces `set -e`. The original would not fail on an unset variable or a broken pipe, so a `mysqldump | gzip` where the dump failed still wrote a valid-looking empty gzip and carried on.
- The **empty tenant list is a hard failure**, not a warning. A backup run that finds no tenants means the query or credentials are wrong; continuing would rotate a good backup set out and replace it with an empty one.
- `php artisan tenants:migrate` needs no `--force`: `config/tenancy.php` already supplies `'--force' => true` through `migration_parameters` (Task 3).
- `cache:clear` truncates the shared cache table, which clears every tenant's entries at once (they share the table, separated by key prefix — Task 10). That is correct and intended on deploy.

### Task 38, Step 2: Grant the deploy user permission to become the provisioner

The tenant dumps run as `lavoro_provisioner`. Allow exactly that, and nothing else:

```
# /etc/sudoers.d/lavoro-deploy
<deploy-user> ALL=(lavoro_provisioner) NOPASSWD: /usr/bin/mysqldump
```

`NOPASSWD` is scoped to a single binary as one specific user, so an unattended deploy can back up but cannot use this entry to create databases or users.

**Keep this separate from `/etc/sudoers.d/lavoro-admin` (Task 2, Step 2b), which
grants a named human `NOPASSWD` on the PHP binary so provisioning commands can
elevate themselves.** That is a grant of everything as the provisioner. Merging
the two files, or widening this one to `/usr/bin/php`, hands an unattended
process the ability to create and drop tenant databases — and because
`RunsAsProvisioner` elevates for whoever holds a rule, nothing in the
application would report the change.

### Task 38, Step 3: Verify against a real run

```bash
./deploy.sh
ls -la storage/backups/db/*/
```

Expected: a timestamped directory containing `central.sql.gz` **plus one `.sql.gz` per tenant**, each a non-trivial size. Confirm a tenant dump actually contains data:

```bash
zcat storage/backups/db/*/lavoro_tenant_acme.sql.gz | grep -c "INSERT INTO"
```

Expected: a number in the thousands, not 0. Then confirm tenant migrations ran:

```bash
php artisan tenants:migrate --dry-run 2>/dev/null || php artisan tenants:list
```

### Task 38, Step 4: Commit

```bash
git add deploy.sh
git commit -m "chore(deploy): back up and migrate every tenant database"
```

---

## Task 39: Per-tenant AI allowance

The assistant costs real money per question and the bill lands on us, not on the
tenant. This bills it as a fixed monthly amount with a spend ceiling behind it,
rather than metering: the tenant pays a flat fee, the ceiling guarantees what is
left over, and nobody has to read an itemised token invoice.

The numbers, measured on the read-only tools in July 2026 on Sonnet:

| | cost |
|---|---|
| one question, one tool round, cold cache | €0,0237 |
| the same with prompt caching | €0,0150 |
| a follow-up inside the five-minute window | €0,0030 |

So a €12,50 ceiling is roughly 830 questions a month at today's shape, or about 530
if none of them hit the cache. That is a genuinely generous limit — deliberately, so
that a normal tenant never meets it — and it moves.

**Those figures describe one shape of question and there are several.** Four things
move the number, and they do not all push the same way:

- **Writes are longer than reads.** Creating an appointment, storing, werkbon,
  product or asset is a propose-confirm-carry-out conversation, so it costs several
  times a lookup.
- **Eight models across five suppliers**, with `ModelPicker` buying the cheapest
  that clears the question's rating. Most questions land *below* the table above: a
  lookup routed to Haiku costs a third of the same lookup on Sonnet, and Deepseek or
  Qwen less again. There is no "cost of a question", only a distribution.
- **A sorter call precedes every question.** Sixteen tokens out, but the question
  itself goes in, so it is a real second call — cheap, and never zero.
- **Photos and files ride along.** `max_images: 4` at `max_image_kb: 4000`, or two
  documents at 4 MB. A pdf of twenty pages is tens of thousands of input tokens.
  This is by far the largest single mover and it is entirely user-driven: the same
  question with a photo attached can cost more than a hundred without one.

Set the ceiling from `assistant_usage` rather than from the table above, and read it
**grouped by model** — a mean across eight models priced from €0,05 to €25 per
million tokens describes no question anybody actually asked.

The ceiling is a spend limit first and a product feature second. Its job is that
one tenant with a runaway script cannot quietly hand us a four-figure Anthropic
bill; the tenant-facing meter is a consequence of having it, not the reason for
it.

**Prerequisite already in the tree:** `assistant_usage` and `App\Domain\Assistant\UsageCost`
exist and record every call in millionths of a euro, with all four token counts
and the rates applied. This task moves that table to central and puts a ceiling
on it. Read the migration's docblock before changing any of it. Both the unit and the
four separate token counts are there for reasons that are not obvious.

`UsageCost` is supplier-neutral: it takes a `Contracts\TokenUsage`
rather than Anthropic's own `Usage`, and looks rates up through `App\Domain\Assistant\Pricing`
rather than `config('assistant.pricing.' . $model)`. **Use `Pricing::forModel()` in
anything this task adds.** The config path form is not a style preference — model
ids contain dots (`gpt-4.1`), the framework reads a dot as a nesting separator, and
so every OpenAI model reports as unpriced, is sorted last by the picker, and is
recorded at nought if it ever does run. That bug has been fixed once already.

### What the meter does not see

The ceiling is only as good as the number it counts, and `TokenUsage` has four
counts in it. Two real costs are not among them:

1. **Server-side web search.** `ANTHROPIC_WEB_SEARCH` is on by default with
   `web_search_max_uses: 3`. Anthropic bills these per search — about a cent each,
   on top of the tokens of whatever gets read — and reports the count in a field
   `TokenUsage` does not carry. So a single question that searches three times can
   run **three cents of cost the meter records as zero**. That is twice what an
   entire cached question costs, and it is invisible. Against a €12,50 ceiling a
   tenant who searches on every question can overrun by roughly a quarter before
   anything notices — which comes straight out of the €10,00 margin floor.
2. **The sorter call.** Priced and recorded like any other, so this one is only a
   warning not to forget it when reasoning about "cost per question" — a question
   is at minimum two calls, never one.

Fix the first as part of this task, not after it. `AnthropicModel` already knows
whether search was enabled and the response reports how many searches ran; carry
that count on `TokenUsage` as a fifth field, price it from a new
`assistant.web_search_price_per_use` config key (list price today: $0,01), and add
it in `UsageCost`. Alternatively set `ANTHROPIC_WEB_SEARCH=false` and be honest
that the feature is off — what is not acceptable is a ceiling that a tenant can
walk past three cents at a time.

Everything else the assistant does is already priced: photos and documents arrive
as input tokens, tool results as input tokens, thinking as output tokens.

**Files:**
- `database/migrations/…_add_ai_allowance_to_tenants_table.php` (new — central migrations stay in the root directory, Task 8)
- `database/migrations/…_create_assistant_usage_table.php` (new — the central copy)
- `database/migrations/tenant/…_drop_assistant_usage_table.php` (new — after the copy across)
- `app/Models/Central/AssistantUsage.php` (new; replaces `App\Models\AssistantUsage`)
- `app/Services/AssistantAllowance.php` (new)
- `app/Domain/Assistant/AllowanceGate.php`, `TenantAllowanceGate.php` (new)
- `app/Domain/Assistant/AssistantLoop.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `tests/Feature/AssistantAllowanceTest.php` (new)

**Interfaces:**
- Consumes: `Tenant` (Task 4), `PricingSetting` (Task 16), `UsageCost` (already in tree).
- Produces:
  - `tenants.ai_allowance_micros` — unsigned big integer, default from `pricing_settings.ai_allowance_micros` (12_500_000 = €12,50).
  - `App\Services\AssistantAllowance` — `spentMicros(): int`, `allowanceMicros(): int`, `remainingMicros(): int`, `hasRoom(): bool`, `record(UsageCost $cost, int $user_id): void`.
  - `App\Domain\Assistant\AllowanceGate` — `hasRoom(): bool`, `record(UsageCost $cost, int $user_id): void`. The loop depends on this rather than on the service, so it stays testable without a tenant.

### Why the rows move to central

`assistant_usage` currently lives in the tenant database because that is the only
one there is. It belongs in central for three reasons, and each of them is a
separate failure if ignored: a restore of a tenant database would otherwise
rewrite what that tenant owes; totalling spend across tenants would be a query
per database instead of one; and a ceiling enforced from data the tenant's own
database holds is a ceiling the tenant can edit.

Rows carry `tenant_id`, and one thing does have to change: **the `user_id` foreign
key cannot come with them.** The tenant migration declares
`foreignId('user_id')->constrained()->cascadeOnDelete()`, and `users` is a tenant
table — MySQL cannot hold a foreign key across databases, so the central copy takes
`user_id` as a plain indexed `unsignedBigInteger`. Two consequences, both worth
accepting deliberately rather than discovering:

- **`user_id` is no longer unique on its own.** It is only meaningful with
  `tenant_id` beside it, because user 7 exists in every tenant. Anything reading
  these rows must filter on both, and the composite index below is what makes that
  cheap as well as correct.
- **Deleting a user no longer deletes their usage.** The cascade goes with the key.
  That is the right outcome — spend that has been billed should not vanish because
  somebody left the company — but it means a row can outlive the user it names, so
  anything joining back to `users` has to tolerate a miss.

Same columns otherwise, same units.

### Task 39, Step 1: Add the allowance column and the central table

```php
Schema::connection('central')->table('tenants', function (Blueprint $table) {
    $table->unsignedBigInteger('ai_allowance_micros')->nullable()->after('storage_limit_gb');
});
```

Nullable so a tenant can fall back to the catalogue default rather than being
pinned to whatever it was worth on the day they signed up. `AssistantAllowance`
resolves `tenant->ai_allowance_micros ?? PricingSetting::value('ai_allowance_micros', 12_500_000)`.

The central `assistant_usage` is the tenant table plus `tenant_id`, minus the
`user_id` foreign key (see above), indexed `['tenant_id', 'created_at']` — that
index is what makes the monthly sum one seek rather than a scan, and the sum runs
on every call. Keep a second index on `['tenant_id', 'user_id']`: "who is spending
it" is the first question anyone asks after "how much is left", and the tenant
column has to lead both.

### Task 39, Step 2: Copy existing rows across, then drop the tenant table

In the Task 27 deployment window, per tenant: read `assistant_usage`, insert into
central with the tenant id, verify counts match, then drop. Do not drop before
verifying — the rows are the only record of what has been spent, and there is no
way to recompute them from anything else.

### Task 39, Step 3: The gate the loop asks

The loop must not reach for `tenancy()`. It takes an `AllowanceGate`, and the
binding decides what that means — a real ceiling in the app, a permissive one in
tests that have no tenant.

```php
interface AllowanceGate
{
    public function hasRoom(): bool;

    public function record(UsageCost $cost, int $user_id): void;
}
```

### Task 39, Step 4: Check before every call, not once per question

In `AssistantLoop::ask`, inside the loop and before `$this->model->send(...)`:

```php
if (!$this->allowance->hasRoom()) {
    throw new AllowanceExhausted(...);
}
```

Before each call rather than once at the start, because a question can take up to
`max_rounds` calls and checking only at the front lets a single question overrun
by all of them. Checking every time bounds the overshoot at one call.

**One call is not the cent and a half it was when this was written.** A call
carrying four photos at 4 MB, or two 4 MB pdfs, is tens of thousands of input
tokens on whichever model `sees_images` forced it onto — currently Anthropic,
the dearest of the eight. The worst-case overshoot is therefore set by
`max_image_kb × max_images` and the priciest model's input rate, not by an
average question. Work it out from those two config values rather than quoting
a number here, and if it comes out uncomfortably large against a €12,50 ceiling the
lever is `max_images`, not the check.

Still bounded, still one call, and still the right trade: reserving an estimate
up front would swap a bounded overshoot for permanently under-using the
allowance.

The overshoot cannot be removed entirely: what a call costs is only known once it
has returned.

### Task 39, Step 5: Show it before they hit it

Share `used`, `allowance` and `remaining` through `HandleInertiaRequests` so the
assistant panel can show "€3,20 van €12,50 verbruikt" and warn at 80%. A hard stop
nobody saw coming reads as a bug; the same stop after two warnings reads as a
limit.

### Task 39, Step 6: Tests

Cover the ceiling holding (spend at the limit, next call refused), the month
boundary (last month's spend does not count), the fallback to the catalogue
default when the column is null, and one tenant's spend never counting against
another's. That last one is the whole point of the tenant column and is the
easiest to get wrong.

Add one more that the multi-provider world needs: **spend on two different models
sums into one allowance.** The picker routes an easy question to Haiku and a hard
one to Sonnet within the same month, at rates differing by 3×, and each row carries
the rates it was billed at. A ceiling that reads only one model's rows, or that
re-prices old rows at today's rate, is wrong in a way no single-model test can see.

The assistant suite already binds a fake `TalksToModel` (see Task 30), so these
tests cost nothing and need no supplier.

### Task 39, Step 7: Price it

`ai_allowance_micros` is seeded in `pricing_settings` by Task 6 and the `assistant`
module is in the catalogue at **€22,50** — so `TenantSubscription::monthlyTotalCents()`
already picks the charge up as an ordinary module line, and there is no separate
subscription line to fold in. What is left here is to confirm that: a tenant with
`assistant` in `modules` is billed €22,50 for it, and one without pays nothing and
cannot reach the routes (Task 31).

At €22,50 charged against a €12,50 ceiling the margin floor is **€10,00**, and it is
a floor rather than an estimate: the ceiling is what makes the worst case knowable.

**Check the arithmetic of that against the web-search gap before treating it as
settled.** Until searches are metered, the floor is €10,00 minus whatever a tenant
spends searching, and at `web_search_max_uses: 3` a heavy questioner can run three
cents a question past the meter. The unmetered half scales with question count
rather than with the ceiling, so a generous ceiling makes it worse rather than
better: the metered half stops later, and the searching carries on. This is why
closing the gap is a step of this task and not a follow-up — at this split it is
the difference between a €10,00 floor and an unbounded one.

**The ratio is a deliberate product choice and worth stating so nobody "corrects"
it.** €12,50 of allowance against €10,00 of margin sells a generous limit rather
than a wide margin. `ModelPicker` buys the cheapest model that clears a question,
so most tenants will spend a fraction of the ceiling and the realised margin will
sit well above the floor — the floor describes the worst tenant, not the median
one. A limit nobody reaches is the intended outcome: the reason to charge a flat
fee rather than meter is that the customer never has to think about it, and a
ceiling they bump into in week three defeats the entire arrangement.

What the ceiling is still for, at any number: one tenant with a runaway script
cannot quietly hand us a four-figure supplier bill. €12,50 caps that at €12,50 per
tenant per month. That is the whole guarantee, and it is why the number matters
more than the margin it happens to leave.

Whatever number is chosen later, change it in `pricing_settings` rather than in
code, and leave `tenants.ai_allowance_micros` for the tenant who genuinely needs
more — that column exists so a heavy user can be accommodated without moving the
default for everybody, and moving the default moves the worst case for all of them
at once.

---

## Task 40: Bind `APP_KEY`-encrypted payloads to the tenant

`APP_KEY` is one key for the whole installation. Record ids are per-tenant
auto-increments. Put those two facts together and any self-contained encrypted
payload that identifies something by id is valid in **every** tenant, because the
only things it proves are "this application minted it" and "it names id N" — and
both are true in all of them at once.

There is one such payload in the tree today, and it guards writes.

`App\Domain\Tools\ConfirmationToken` is what stands between the assistant
proposing a write and carrying one out. `encoded()` is
`Crypt::encryptString(json_encode(['tool', 'arguments', 'user_id', 'expires_at']))`;
`decode()` accepts it if it decrypts, has not expired, and
`(int) $payload['user_id'] === $user->id`. Nothing in it names a tenant.

So a token minted for user 5 of tenant A decrypts cleanly in tenant B, matches
user 5 of tenant B, and `POST /assistant/confirm` executes tenant A's arguments
as a write **in tenant B's database**. No supplier is involved and no model runs —
`confirm` deliberately carries out something already agreed to — so every guard
that makes the assistant safe has already been passed by the time this happens.

Two things make this narrower than it sounds and neither makes it acceptable: the
attacker needs a session in the second tenant, and the ids have to coincide. Ids
coinciding is not a coincidence — the first admin of every tenant is user 1.

**Files:** `app/Domain/Tools/ConfirmationToken.php`, `tests/Feature/Assistant/WriteToolGateTest.php`

### Task 40, Step 1: Put the tenant in the payload

In `encoded()`, add `'tenant' => tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null`.

### Task 40, Step 2: Check it in `decode()`, and refuse anything that does not match

Beside the existing `user_id` check:

```php
$tenant = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;

if (($payload['tenant'] ?? null) !== $tenant) {
    return null;
}
```

Compare strictly against the tenant that is active right now. `null` on both sides
is the only way a token minted outside any tenant passes outside any tenant.

Do not write it as `isset($payload['tenant']) && ...`. Tokens issued before this
change have no `tenant` key at all, so a check that skips when the key is missing
waves through exactly the tokens it was added to stop. Tokens last fifteen
minutes, so the old ones all expire within the hour — which is why this can
simply reject them instead of needing a migration.

`decode()` already returns `null` for everything wrong and the caller already
treats `null` as "no approval at all", so there is no new failure path to handle.

### Task 40, Step 3: Test the replay directly

The existing `WriteToolGateTest` holds the gate across every tool. Add the case it
cannot currently express: mint a token as user 1 of tenant A, switch to tenant B,
and assert `decode()` returns `null` for user 1 there. Assert on `decode()` rather
than on the route, so the test says *why* it is refused rather than only that a
403 came back.

### Task 40, Step 4: Keep the rule, not just the fix

The rule is worth more than this one class: **anything encrypted or signed with
`APP_KEY` that names a record must also name the tenant.** Applies to
`Crypt::encrypt*`, to Laravel's signed URLs, and to any future token.

Two places already use `Crypt` and both are fine. It is worth knowing why, so
nobody "fixes" those as well. `GoogleCalendarIntegration` encrypts its Google
access and refresh tokens (`:49-64`), and `Tenant` encrypts each tenant's database
password (Task 4). Both sit in a database column, and to read either one you must
already be inside that database. The encryption protects the value where it is
stored; it is not what decides whether a request is allowed.

`ConfirmationToken` is different because we hand it to a browser and later accept
it back as proof. That is why it needs the tenant check.

### Task 40, Step 5: Commit

```bash
git add app/Domain/Tools/ConfirmationToken.php tests/Feature/Assistant/WriteToolGateTest.php
git commit -m "fix(tenancy): bind assistant confirmation tokens to the tenant that minted them"
```

---

## Task 41: Resolve a bearer token's tenant from a central lookup

**Do this task when the first `createToken()` call is written, not before.** Nothing
calls it today; Task 24 covers every client that exists. What Task 24 must *not* do
in the meantime is keep a placeholder that guesses — see its note on `X-Tenant-ID`.

### The problem

A bearer token arrives with no session and no cookie. To validate it, Sanctum reads
`personal_access_tokens` — a **tenant** table, correctly so, because a token belongs
to one tenant's user. So the token cannot be validated until the tenant is known, and
the tenant cannot be learned from the token, because the only thing in the token that
looks like an identifier is `personal_access_tokens.id`, a per-tenant auto-increment.
Token id 5 exists in every tenant.

This is the same problem as logging in — an email whose tenant is unknown until it is
looked up — and it gets the same answer. `user_tenant_lookups` is the least bad
solution for e-mail because the two alternatives are worse. Searching every tenant
database in turn means one query per company on every single login, and the time it
takes tells an attacker whether an address exists. And letting the client tell us
which company it belongs to is not a lookup — it is just believing whatever it says.

### The design

A central table maps **the hash of the token's plaintext** to a tenant.

Sanctum hands out `{id}|{plaintext}` and stores `hash('sha256', $plaintext)` in
`personal_access_tokens.token`. That hash is the one thing about a token that is
globally unique — 40 random characters — and it is already computed and already
stored, so the central table introduces no new secret material and no second hashing
scheme. Given a token, the middleware hashes the plaintext, seeks one indexed row,
and knows which database to ask.

**The property that makes this safe, and the one to keep in mind when changing it:**

> The lookup decides which database to ask. The tenant database decides whether the
> token is valid.

A missing, stale or outright wrong lookup row can only ever produce a 401 — tenancy
initializes somewhere, Sanctum fails to find a matching hash there, authentication
fails. The lookup is a routing hint, never an authorization record. That is what lets
the sync below be best-effort without being dangerous, and it is the sentence to
re-read before anyone is tempted to trust the lookup for anything else.

**Files:**
- `database/migrations/…_create_access_token_tenant_lookups_table.php` (new — central migrations stay in the root directory, Task 8)
- `app/Models/Central/AccessTokenTenantLookup.php` (new)
- `app/Observers/PersonalAccessTokenObserver.php` (new)
- `app/Support/AccessTokens.php` (new)
- `app/Http/Controllers/Api/ApiAuthController.php` (new)
- `app/Http/Middleware/InitializeTenancyForApi.php`, `app/Providers/AppServiceProvider.php`, `routes/api.php`
- `tests/Feature/BearerTokenTenancyTest.php` (new)

**Interfaces:**
- Produces: central table `access_token_tenant_lookups` (`token_hash` char(64) primary key, `tenant_id`, `created_at`); `App\Models\Central\AccessTokenTenantLookup`; `App\Support\AccessTokens::revokeAll(User): void`.

### Task 41, Step 1: The central table

```php
Schema::connection('central')->create('access_token_tenant_lookups', function (Blueprint $table) {
    $table->char('token_hash', 64)->primary();
    $table->string('tenant_id');
    $table->timestamp('created_at')->nullable();

    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
});
```

`char(64)` because a SHA-256 written in hex is always exactly 64 characters; as the primary
key it makes the lookup a single seek and enforces global uniqueness at the same time.

**The foreign key is real here, and it is worth noticing why**, because Task 39 could
not have one. Both tables are central, so MySQL can enforce it — and the cascade means
`tenant:delete` (Task 22) drops a tenant's token routes without a line of code.
`assistant_usage.user_id` points at a *tenant* table from central, which is why that
one is a bare indexed column instead.

This is the seventh central migration; adjust the counts in Task 8 when it lands.

### Task 41, Step 2: The model

```php
<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class AccessTokenTenantLookup extends Model
{
    protected $connection = 'central';
    protected $table = 'access_token_tenant_lookups';
    protected $primaryKey = 'token_hash';
    protected $keyType = 'string';
    public $incrementing = false;
    public const UPDATED_AT = null;

    protected $fillable = ['token_hash', 'tenant_id'];
}
```

### Task 41, Step 3: Keep it in sync from an observer on Sanctum's model

```php
<?php

namespace App\Observers;

use App\Models\Central\AccessTokenTenantLookup;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalAccessTokenObserver
{
    public function created(PersonalAccessToken $token): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        AccessTokenTenantLookup::on('central')->updateOrCreate(
            ['token_hash' => $token->token],
            ['tenant_id' => tenancy()->tenant->getTenantKey()],
        );
    }

    public function deleted(PersonalAccessToken $token): void
    {
        AccessTokenTenantLookup::on('central')->where('token_hash', $token->token)->delete();
    }
}
```

`$token->token` is already the SHA-256 — Sanctum hashes on the way in, so the observer
never sees or stores a plaintext.

Register it in `AppServiceProvider::boot()` beside the `User` observer from Task 18:

```php
\Laravel\Sanctum\PersonalAccessToken::observe(\App\Observers\PersonalAccessTokenObserver::class);
```

### Task 41, Step 4: Route revocation through a helper, because the documented way skips the observer

`$user->tokens()->delete()` is Sanctum's documented revoke-all, and it is a **query
builder** delete: no model events, so no `deleted` hook, so the central rows survive
their tokens. Every mass delete on a relation has this property; it is not a Sanctum
quirk.

```php
<?php

namespace App\Support;

use App\Models\User;

final class AccessTokens
{
    /**
     * Revoke every token a user holds.
     *
     * Deliberately not `$user->tokens()->delete()`. That is a query-builder delete,
     * which fires no model events, so PersonalAccessTokenObserver never runs and the
     * central routing rows outlive the tokens they point at. Slower by one query per
     * token, on an operation that happens when somebody leaves the company.
     */
    public static function revokeAll(User $user): void
    {
        $user->tokens->each->delete();
    }
}
```

A stale row is inert rather than dangerous — it routes to a tenant whose database no
longer holds the hash, and the request 401s. So this helper is hygiene, not the
security boundary. Prune anything that still slips through on the same schedule as the
other per-tenant housekeeping (Task 20): for each tenant, delete central rows whose
`token_hash` is absent from that tenant's `personal_access_tokens`.

### Task 41, Step 5: Teach the API middleware to resolve from the token

In `InitializeTenancyForApi::handle`, after the session lookup and **instead of** the
`X-Tenant-ID` fallback Task 24 removes:

```php
$tenant_id = $request->hasSession()
    ? $request->session()->get('tenant_id')
    : null;

if (!$tenant_id && $bearer = $request->bearerToken()) {
    $plain = str_contains($bearer, '|') ? Str::after($bearer, '|') : $bearer;

    $tenant_id = AccessTokenTenantLookup::on('central')
        ->where('token_hash', hash('sha256', $plain))
        ->value('tenant_id');
}
```

The `str_contains` branch mirrors `PersonalAccessToken::findToken` exactly: Sanctum
accepts both `{id}|{plaintext}` and a bare token, and in both cases the stored column
is the SHA-256 of the plaintext part. Deriving the hash any other way works until
somebody issues a token in the other format.

Session first, token second. A stateful SPA request carries no bearer token, and a
token client carries no session, so in practice they never both appear; when they do,
the session is the already-authenticated path and Sanctum's guard will use it anyway.

No `hash_equals` here, and that is not an oversight: this is an indexed equality
lookup on a hash to pick a database, not a comparison of a secret against a stored
one. Sanctum does the same before its own `hash_equals`, which happens afterwards in
the tenant database where the actual decision is made.

### Task 41, Step 6: A login endpoint that issues the token, outside `tenant.api`

It cannot be inside the group — there is no token yet, so there is nothing to resolve
a tenant from. It resolves the tenant the way the web login does (Task 15), from
`user_tenant_lookups`:

```php
Route::post('login', [ApiAuthController::class, 'store'])->middleware('throttle:5,1');
```

`store()` looks the email up in `UserTenantLookup`, initializes tenancy, verifies the
credentials, calls `$user->createToken(...)` — which fires the observer and writes the
central row — and returns **only** the plain-text token.

**The client is never told its tenant id and never sends one.** The token is the
credential, and the token is what tells us the company. If instead we ask the client
which database to read, the client is deciding what it gets to see.

Throttled hard because it is an unauthenticated endpoint that runs a password hash and
a central lookup.

### Task 41, Step 7: Tests, including the one that encodes the security property

```php
public function test_a_token_resolves_its_own_tenant(): void;
public function test_an_unknown_token_is_refused(): void;
public function test_revoking_a_token_removes_the_central_row(): void;
```

And the one that matters most, because it is what stops someone later "optimising" the
lookup into an authorization check:

```php
public function test_a_lookup_row_pointing_at_the_wrong_tenant_still_fails(): void
{
    // Point tenant A's token hash at tenant B in the central table, then use it.
    // Tenancy initializes on B, Sanctum finds no matching hash there, 401.
    // Routing is not authorization.
}
```

### Task 41, Step 8: Commit

```bash
git add database/migrations/ app/Models/Central/AccessTokenTenantLookup.php \
        app/Observers/PersonalAccessTokenObserver.php app/Support/AccessTokens.php \
        app/Http/Controllers/Api/ApiAuthController.php \
        app/Http/Middleware/InitializeTenancyForApi.php app/Providers/AppServiceProvider.php \
        routes/api.php tests/Feature/BearerTokenTenancyTest.php
git commit -m "feat(tenancy): resolve a bearer token's tenant from the central lookup"
```

---

## Task 42: Write the rules down where the next person will read them

Every task before this one changes the code. This one changes what anyone — a
colleague, or Claude in a later session — is told about the code, because most
of what tenancy imposes is **invisible from reading it**.

Nothing in `ImageController` says a hand-built `storage_path()` will silently
write outside the tenant's folder. Nothing in `AppServiceProvider` says a new
singleton holding a `User` needs an interface and a tag. Nothing in
`routes/console.php` says a schedule that queries inline will run against the
central database. Each of those reads as ordinary Laravel and fails without an
error.

**Do this in the same commit as Task 27 Step 7**, when tenancy actually goes
live. Earlier and the rules are false — a `database/migrations/tenant/`
instruction is wrong until Task 8 has run. If the migration is going to span
weeks, land the section early with a one-line "not live until <date>" marker
rather than leaving it out.

**Files:** `CLAUDE.md`, `docs/handleiding.md`

### Task 42, Step 1: Add a `## Multi-tenancy` section to `CLAUDE.md`

Place it after `## Coding rules`. Match that section's register — terse
imperatives, no rationale. The reasoning lives in this plan, and the last line
points at it.

The admission test for a rule here: **does breaking it fail silently?** A rule
whose violation throws does not need writing down; the exception says it. These
do not throw.

```markdown
## Multi-tenancy

-   Two databases: central (`lavoro_landlord`) and one per customer. `App\Models\Central\*` set `protected $connection = 'central'`; every other model uses the default connection, which is switched per request.
-   New migrations go in `database/migrations/tenant/` (`make:migration --path=database/migrations/tenant`). A migration belongs in `database/migrations/` only if it declares `protected $connection = 'central';`.
-   Never build a path to an uploaded file by hand. `storage_path('app/public/…')` and `Storage::url()` bypass the per-tenant disk root and fail silently — a missing file reads as "no image". Use `Storage::disk('public'|'local')`, and serve files through an authenticated controller by id, never a `/storage/` URL.
-   A container singleton that holds tenant state must implement `App\Support\ForgetsTenantState` and be tagged in `AppServiceProvider`. `TenantStateTest` fails if you implement it without tagging.
-   Scheduled tasks never query inline. Loop tenants in `routes/console.php`, dispatch one job per tenant, and do the work in the job.
-   Queued jobs are tagged with the active tenant at dispatch. Dispatch from tenant context or the job runs against central.
-   Anything encrypted or signed with `APP_KEY` that names a record must also name the tenant. Record ids are per-tenant auto-increments and `APP_KEY` is global, so an id alone is valid in every tenant.
-   Never take a tenant id from the client — no header, query parameter or body field. Resolve it from the session, or from a central lookup keyed on a credential.
-   Email addresses are unique across all tenants, not just within one (`user_tenant_lookups`).
-   A feature is only a module if a customer could reasonably not have it. `tenant:create` defaults to no modules, so gating a stock feature breaks every new customer.
-   Tests run on MySQL, never SQLite. The shared `TestCase` creates one tenant per run and wraps each test in transactions on both connections — do not add `RefreshDatabase`.

Full reasoning: `docs/superpowers/plans/2026-06-09-multi-database-tenancy.md`.
```

### Task 42, Step 2: Update the chapters of `docs/handleiding.md` that describe a limit

**The manual must not explain multi-tenancy.** It is written for somebody using
one company's Lavoro, and that person does not know they are a tenant, should
not have to, and gains nothing from the word. What changes for them is not the
architecture — it is that some things now have a ceiling.

| Chapter | What to add |
| --- | --- |
| `## De AI-assistent` | The monthly allowance, the meter, the warning at 80%, and what happens when it runs out |
| `## Documenten, foto's en opmerkingen` | That an upload is refused when the storage limit is reached, and who to contact to extend it |
| `## Gebruikers, rollen en rechten` | Buiten- and binnendienst seats, what happens when one is full, and that a binnendienst user cannot be made plannable |
| `## Instellingen en beheer` | Where storage and seat usage are shown |
| `## Koppelingen` | SnelStart and mail credentials are now entered per company under Beheer → Koppelingen |
| `## Navigatie en zoeken` | One line: a feature not in the subscription is simply absent from the menu |

**The assistant chapter is the one that pays for itself.** The assistant answers
questions from this file through its `read_manual` tool. Leave the allowance
undocumented and the first person to hit the ceiling asks the assistant why it
has stopped working — and it cannot tell them, because the only description of
the limit is in a config file it cannot read. Documenting it is what makes the
feature able to explain its own refusal.

### Task 42, Step 3: Commit

```bash
git add CLAUDE.md docs/handleiding.md
git commit -m "docs: record the tenancy rules and the limits customers can hit"
```

---

## Task 43: `tenancy:doctor` — check the things git does not hold

`scripts/tenancy/verify-mysql.sh` (Task 2) checks the MySQL identity layer: the
socket plugin, the Linux user, the databases the provisioner can and cannot reach, the app
account's confinement, and each tenant account's grants. Run as root, before or
independently of the app.

It cannot check anything above that line, because those need the app: Eloquent,
`APP_KEY`, the config. And those are the failures that do not announce
themselves — a tenant whose password no longer decrypts looks fine until
somebody tries to log in.

One command, read-only, non-zero exit on any failure so it can gate a deploy.

**It must never fix anything.** A doctor that repairs is a doctor whose output
people stop reading, and every repair here — recreating a database, rewriting a
lookup row — is destructive if the diagnosis was wrong.

**Files:** `app/Console/Commands/TenancyDoctor.php` (new), `routes/console.php`

### Task 43, Step 1: The checks

Global, once:

| Check | Why it matters if it fails |
| --- | --- |
| Central connection reachable and pointing at `lavoro_landlord` | Nothing works |
| Central tables present (`tenants`, `user_tenant_lookups`, `cache`, `jobs`, `sessions`, catalogue) | `migrate` was never run against central |
| `SESSION_CONNECTION=central` | Sessions land in whichever database is active; login breaks intermittently |
| Cache write-then-read succeeds | Catches the Task 10 misconfiguration directly |
| Age of the oldest pending row in `jobs` | A stopped worker looks exactly like a quiet day |
| Crontab contains `schedule:run` (best effort) | Direct answer when it works, see Step 2 |
| Age of the scheduler heartbeat (below) | Authoritative: nothing scheduled has actually run |
| `public/storage` symlink present | The static fallback logo on PDFs |
| `storage/logs` and `storage/framework` writable by **both** `www-data` and `lavoro_provisioner` | Provisioning commands run as the provisioner (Task 21). One provisioner-owned `laravel.log` and every web request that logs starts throwing |

Per tenant, from `Tenant::on('central')->cursor()`:

| Check | Why it matters if it fails |
| --- | --- |
| Database exists | A half-finished `tenant:create` leaves a row with no database |
| `tenancy_db_password` decrypts | An `APP_KEY` rotation makes every tenant unreachable (Known impact 11) |
| Can connect with the tenant's own credentials | The two above, proven together rather than inferred |
| No pending tenant migrations | `tenants:migrate` was missed after a deploy |
| `storage/tenant-<id>/public` and `/local` exist, are group-writable and setgid | Uploads fail, or land where nothing serves them. Not `is_writable()` — see Step 1b |
| Every user has a `user_tenant_lookups` row | That user cannot log in, and there is no error anywhere |
| A stage exists for each of the six `service_order_stages` flags | Three features null-guard and silently do nothing (Task 23) |

And two orphan checks, which is where a botched `tenant:create` or `tenant:delete` shows up:

- lookup rows naming a tenant id that no longer exists
- `lavoro_tenant_*` databases on the server with no matching `tenants` row

### Task 43, Step 1b: Check storage by ownership and mode, not `is_writable`

Two identities write into `storage/`. `www-data` serves every request, and
`lavoro_provisioner` runs `tenant:create`, `tenant:delete` and
`tenant:setup-existing` — and will run them far more often once Task 21
elevates to it automatically. Both write logs, both compile views, both touch
the cache directory.

`is_writable()` cannot check that. It answers for the process that asks: run the
doctor as root and every storage check passes no matter what `www-data` can do;
run it as the provisioner and it answers for the provisioner. The question is
whether **both** accounts can write, and no single-identity probe reaches it.

So read the mode and the group instead:

```php
$stat  = stat($path);
$group = posix_getgrgid($stat['gid'])['name'] ?? null;
$group_writable = ($stat['mode'] & 0020) !== 0;
$setgid         = ($stat['mode'] & 02000) !== 0;
```

Check `storage/logs`, `storage/logs/laravel.log` when it exists,
`storage/framework/cache/data` and `storage/framework/views`. PASS when the path
is group-writable **and** both `www-data` and `lavoro_provisioner` are members of
that group (`posix_getgrnam($group)['members']`, plus each account's primary
group from `posix_getpwnam`). FAIL naming both accounts, the group and the
missing half — the remedy is `usermod -aG` and `chmod g+w`, and an operator
should not have to derive that from a bare boolean.

**Check the setgid bit on the directories separately, and fail without it.** A
directory that is group-writable but not setgid passes the first check and still
breaks: a file the provisioner creates there takes the provisioner's primary
group, and the next `www-data` write to it fails. The install would read healthy
today and break on the next `tenant:create` — which is exactly the class of
failure this command exists to catch before it happens.

This belongs here rather than in `verify-mysql.sh` because it is about the
application's own storage tree, which that script deliberately knows nothing
about — and because the account it concerns is the one Task 21 made easy to
run as without noticing.

### Task 43, Step 2: Check the scheduler two ways, because neither is sufficient alone

**Neither check answers "is cron configured correctly". Say so in the output
rather than implying otherwise.**

*Directly, best effort.* Shell out to `crontab -l -u <web user>` and grep for
`schedule:run`. When it finds the entry, you have an immediate answer at deploy
time. When it does not, that is **not** a failure: the entry may live in
`/etc/cron.d`, in root's crontab, or in a systemd timer, and the command may not
be permitted to read it at all. Report it as PASS or SKIP, never FAIL.

*Authoritatively, after the fact.* Have the scheduler prove it is running by
writing a timestamp:

```php
Schedule::call(fn () => cache()->forever('scheduler_heartbeat', now()->timestamp))
    ->everyFiveMinutes()
    ->name('scheduler-heartbeat');
```

Central context and one cache write, so it costs nothing and needs no tenant
loop. A heartbeat older than fifteen minutes means the scheduler is not running,
which is worth knowing because **nothing else in this application tells you** —
every scheduled task simply stops happening.

Three limits on the heartbeat, all worth printing beside it:

- It lags. Cron dies now, this reports in fifteen minutes.
- It cannot say *why*. A missing entry, a dead daemon, the wrong user and a PHP
  error all look the same.
- One manual `php artisan schedule:run` makes it read healthy while cron is
  still dead.

An **absent** heartbeat is different from a stale one: it means the scheduler has
never run here. On a fresh install that is expected, not broken — report it as
such rather than as a failure.

### Task 43, Step 3: Report like `verify-mysql.sh` does

PASS / FAIL / **SKIP**, and a skip is never counted as a pass. Half these checks
are negative ("cannot reach", "no orphans"), and a negative check is satisfied
automatically when the connection failed — so a run against a broken install
must report skips, never a clean sheet. That distinction is the whole value of the
script version, and it is worth copying rather than reinventing.

End with a summary line and `exit(1)` if anything failed.

### Task 43, Step 4: Commit

```bash
git add app/Console/Commands/TenancyDoctor.php routes/console.php
git commit -m "feat(tenancy): add tenancy:doctor for the checks git cannot hold"
```

---

## Task 44: `import-install.sh` — move one old installation into a fresh multi-tenant install

Tasks 27 and 29 describe two different moves. This one turns the second into a
script with parameters, and in doing so makes the first optional.

### Two routes onto production

| | Task 27, in place | Fresh install + import |
| --- | --- | --- |
| What happens | The live database is converted where it stands | A new multi-tenant install is set up beside it, and each old install is imported |
| Old install during the move | Down | Up and serving customers |
| If it goes wrong | Restore code, config and files from the rollback step | Point DNS back at the old install; nothing was touched |
| Rehearsals | One, on a copy | As many as you like |
| Cost | None | A second install running for a while |

**The second is safer and it is the one to prefer.** The old installation is never
modified — you take a dump, import it, and the only irreversible moment is the DNS
switch. Task 27 exists for the case where standing up a second install is not
practical; if you can, skip it.

This script is what makes the second route routine rather than a project per
customer.

**Files:** `scripts/tenancy/import-install.sh` (new)

### Task 44, Step 1: The parameters

Run it from the multi-tenant install, pointing at the old one's directory:

```bash
cd /home/lavoro/app

scripts/tenancy/import-install.sh \
    --from /home/spee/lavorofsm \
    --name "Spee Totaaltechniek" \
    --slug spee \
    --package business \
    --modules google_calendar,snelstart \
    --storage-gb 100 \
    --dry-run
```

`--from` is the old installation's root directory, and it is the only thing that
needs to be worked out by hand. The script reads `<from>/.env` for its database
name and credentials, and takes its files from `<from>/storage/app`. Nothing is
written to that directory at any point — it is read and left alone.

`--slug` becomes `lavoro_tenant_spee`. `--dry-run` prints every command and changes
nothing, the same as `setup-mysql.sh`.

**Both installations are on one machine, so there is no transfer step.** The dump
is written to a temporary file and restored on the spot. Task 29 covers the harder
case of a legacy install on its own host, where the dump has to travel; if you ever
have that, its steps are the same with `scp` in the middle.

### Task 44, Step 2: What it does, in order

1. **Preflight, before anything is written.** Refuse if `lavoro_tenant_<slug>` already exists, if the dump is unreadable, if the provisioner cannot connect, or if `--package` is not in the catalogue. A typo in the package name should stop the run in the first second, not after the restore.
2. **Create the database and restore the dump.**
3. **Drop the tables that are central now** — `sessions`, and optionally `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.
4. **Check for e-mail collisions** against `user_tenant_lookups`, including soft-deleted users on both sides. **Stop here and print the clashes if there are any.** This is the last point at which nothing has been registered centrally, so it is the cheapest place to fail.
5. **`tenant:setup-existing`** — registers the tenant, creates its MySQL login, copies the e-mail addresses into the central lookup. Capture the printed tenant id.
6. **`tenants:migrate`** for that tenant, to bring the imported schema up to the current one.
7. **Copy the files** into `storage/tenant-<id>/public` and `/local`.
8. **Set the subscription** — package, seats, modules, storage limit. After the migrate, not before: seat counts need the `seat_type` column that migration adds.
9. **Expire the Google watch channels** so they are recreated with a tenant-prefixed token (Task 25).
10. **`tenancy:doctor`** for the new tenant, and stop non-zero if it complains.

### Task 44, Step 3: Who runs what

The script needs `sudo`, but it must not run everything as root. Three different
identities, for three different reasons:

| Part | Runs as | Why |
| --- | --- | --- |
| Reading `/home/spee/lavorofsm/.env` and `storage/app` | root | Another user's home directory, and `.env` is `0600` |
| Creating the database, restoring, creating the tenant's MySQL login | `lavoro_provisioner` | The only account allowed to create `lavoro_tenant_*` databases and grant on them (Task 2) |
| Every `php artisan` command | the web user (`www-data`) | Artisan writes to `storage/logs` and `bootstrap/cache` |

```bash
sudo scripts/tenancy/import-install.sh --from /home/spee/lavorofsm …
```

**Run artisan as root once and the install is subtly broken afterwards.** Laravel
writes a log line and a cached view as root, `www-data` cannot then write to those
same files, and the app starts failing on things unrelated to this import. The
symptom arrives days later and looks like nothing to do with tenancy.

**The copied files must be chowned.** `cp` as root produces root-owned files under
`storage/tenant-<id>/`, and uploads into those directories then fail. End the copy
step with:

```bash
chown -R www-data:www-data "storage/tenant-${TENANT_ID}"
```

Check the actual user your PHP-FPM pool runs as rather than assuming `www-data`;
`ps aux | grep php-fpm` settles it.

Have the script refuse to start if it is not root (`[ "$(id -u)" -eq 0 ]`), so it
fails on the first line instead of halfway through a restore.

### Task 44, Step 4: The one thing the script cannot do for you

A dump taken with `--single-transaction` is internally consistent, so the old
install does not have to be down while you take it. But anything written to the
old install **after** the dump is lost, because the tenant is now a copy.

So the real run is: take the old install down (`php artisan down` in
`/home/spee/lavorofsm`), run the import, check it, then point the domain at
`/home/lavoro/app`. The window is however long the import takes, which is why you
want to have timed it beforehand.

Rehearsing does not need the downtime. Dump the live install as often as you like
and import it into a throwaway tenant — the old install never notices.

### Task 44, Step 5: Rehearse it, repeatedly

Restore a customer's dump on your own machine, run the script, look at the result,
drop the database, run it again. The point of a script over a runbook is that the
tenth run is identical to the first; the point of rehearsing is that the first run
on production is the eleventh.

Test the failure paths too, because they are the ones nobody exercises: a dump
containing an e-mail that already exists centrally, and a slug whose database is
already there. Both should stop cleanly and leave nothing behind.

### Task 44, Step 6: Commit

```bash
git add scripts/tenancy/import-install.sh
git commit -m "feat(tenancy): script the import of an existing installation"
```

---

## Known impact and follow-up work

1. **The test suite moves off SQLite entirely** (Task 30). `phpunit.xml` moves from SQLite `:memory:` to a dedicated MySQL test database — `lavoro_test_landlord`, plus a `lavoro_test_tenant_`-prefixed tenant database. Two independent things then stop a misconfigured run reaching `lavoro` or a real customer database: a hard runtime assertion, and a MySQL user granted on nothing else. Vitest frontend tests are unaffected.

2. **Login page shows no per-tenant branding.** It already renders the static Lavoro logo today, so nothing regresses; but per-tenant branding before login would require a two-step login (email → resolve tenant → branded password step).

3. **File access is authenticated but not permission-scoped.** Task 14 serves files only to logged-in users of the owning tenant (cross-tenant ids 404 via model binding), which closes the world-readable hole. It does not apply per-resource permission checks, and the two file paths are gated differently: `FileController` (images, avatars, logos) checks only that you are signed in, while documents additionally require `can('viewAny', Document::class)` via `DocumentViewRequest`. Neither checks the *individual* record, so any user who clears the coarse gate can fetch any file id in that tenant. Adding policy checks in `FileController` is a reasonable follow-up if finer-grained access is required. Relatedly, `Storage::response()` sends no cache-control headers; if browser caching of served files ever becomes a concern, add `Cache-Control: private` in `FileController`.

4. **SnelStart and Microsoft Graph credentials are per-tenant** (Task 32), stored encrypted in the tenant's `general_settings` and edited from Beheer → Koppelingen. Graph falls back to the shared env credentials for tenants that haven't configured a mailbox; SnelStart fails closed, because there is no safe default administratie to write someone else's invoices into.

   **Firebase (FCM) is still global**, and unlike the other two that is probably correct: the FCM credential identifies the *Lavoro app* to Google, not the customer, and device tokens are app-instance-bound rather than tenant-bound. Revisit only if tenants ever ship their own branded builds — at which point the Task 32 pattern applies directly.

   **The VAPID keypair (`config/webpush.php`) and the AI supplier keys (`config/assistant.php`) are global for the same reason and stay that way.** VAPID identifies this installation to a push service; the Anthropic, Deepseek, Mistral, Qwen, Moonshot and OpenAI keys identify *us* to a supplier we hold the account with. Per-tenant AI keys would move the bill to the customer, which is the opposite of what Task 39 is built for. VAPID has a further reason: its public key is baked into every browser subscription ever handed out, so rotating it per tenant would invalidate every subscription a shared browser holds.

5. **Module subscriptions are enforced by route middleware** (Task 31). `tenant.module` gates SnelStart imports/send, Google Calendar OAuth, the location-ping endpoint and the fourteen assistant routes; the `snelStartEnabled` and `auth.can.use_assistant` Inertia props are gated the same way on the frontend. Stock features — Storingen and Projecten among them — are deliberately ungated, and `menu.json` carries no `module` key today because every screen in it is stock. Extending the same middleware to further routes as new module-gated features are added is a one-line addition per route group, not new plumbing: the assistant's fourteen routes needed exactly one `Route::middleware(...)->group()` and one clause on a shared prop.

6. **Scheduler cost scales with tenant count, not with tenant data** (Task 20). Every scheduled tick dispatches one queued job per tenant (a config swap plus a single `INSERT` into the central `jobs` table) rather than running a query or delete inline per tenant, so tick cost tracks tenant *count* only, which is cheap. If tenant count itself grows into the hundreds and the dispatch loop alone becomes the bottleneck, chunking the central tenant list (already using `cursor()` rather than `get()`) or splitting the loop across multiple scheduled entries are the next levers.

7. **Middleware ordering matters and nothing shows you when it is wrong.** Task 12 pins the tenancy initializers into `$middleware->priority()`. Nothing enforces that a future middleware addition preserves it, and getting it wrong presents as mass 404s that look like a routing bug. If this bites twice, a cheap feature test — hit a bound-model route as a tenant user and assert 200 — is worth more than a comment.

8. **`storage_path()` will keep catching people out.** Task 14 fixes the six current offenders, but nothing prevents new code from writing `storage_path('app/public/…')` again, and the failure is silent (a missing file reads as "no image"). Consider a Pint/PHPStan rule or a grep in CI over `app/` and `resources/views/` for `storage_path('app/` once tenancy is live.

9. **Test isolation works differently after this.** Task 30 swaps `RefreshDatabase`'s truncate-and-remigrate for transaction rollback across two connections. Auto-increment ids no longer reset between tests, and any code under test that commits (DDL, explicit transactions) escapes the wrapper. Expect some churn across the converted test files.

10. **Per-tenant database credentials are not exercised by the test suite.** Tests run on the plain `MySQLDatabaseManager` (Task 30) so the narrow test grant stays narrow, which means `TenantDbUserProvisioner` and the `encrypted` password cast are only verified manually (Task 21 Step 3, Task 26 Step 4). Re-run those after touching provisioning.

11. **`APP_KEY` is backup-critical.** Tenant database passwords are stored encrypted with it (Task 4). Losing or rotating `APP_KEY` without re-encrypting makes every tenant database unreachable. Rotation means: decrypt with the old key, re-run `tenant:provision-db-user` per tenant, or keep the old key in `APP_PREVIOUS_KEYS`.

12. **The provisioner is tied to this machine.** `auth_socket` authenticates by Unix socket, so it only works while MySQL runs on the same host as the app. Moving the database to its own server breaks provisioning and requires a different mechanism (client certificates, or a root-readable credentials file). Ordinary tenant traffic is unaffected — those users authenticate by password over TCP.

13. **Tenant MySQL users are created as `user@%`, not `user@localhost`.** That is the package's behaviour (`PermissionControlledMySQLDatabaseManager::createUser`), so a tenant credential leaked off-box could be used from anywhere the MySQL port is reachable. Keep MySQL bound to localhost/private network. Tightening this means overriding the manager's `createUser`.

14. **The service worker is shared across tenants, and it does two jobs rather than one.**

    *Caching.* Task 14 Step 5 narrows the cache to static assets, which closes the file routes. What it does not change: the cache itself is one bucket per browser origin, and top-level navigations are still cached (network-first, so only served when offline). On a shared browser, tenant B could be shown tenant A's cached page shell while offline. Bumping `CACHE_NAME` on login, or keying the cache by tenant, would close it if this ever matters.

    *Push notifications — the worse of the two.* Tapping a notification makes the worker open `data.url`, a bare path like `/serviceorders/123`. That path names no company, and ids are per-tenant auto-increments, so werkbon 123 exists in every tenant. If the person has signed into a different company on that browser since the notification arrived, the tap opens **that** company's werkbon 123 — a real record belonging to someone else, shown as the thing they were notified about. Nothing errors and nothing is unauthorised; the page is simply the wrong one, reached for the wrong reason. Task 14 Step 5 walks through it.

    Two things have to line up for it to happen: one person using two companies on one browser, and an old notification still sitting there when they switch. Neither is exotic — a support engineer, a phone left signed in in the van — so treat this as unlikely rather than prevented.

    Closing it means carrying the tenant on the notification and checking it before navigating: put `tenant_id` in the push payload when `SendWebPushNotificationsJob` builds it, expose the current tenant to the worker (a `/whoami` fetch, or a value written into the cache at login), and fall back to `/` on a mismatch rather than opening the wrong record. That is a self-contained piece of work, it belongs with the push feature rather than with any task in this plan, and it should be done before push is enabled for a second tenant.

    One nearby thing that looks wrong and is not: `push_subscriptions.endpoint` is `unique()` within each tenant database, so the same browser can hold one subscription row per company it is signed into. That is correct — each company's job encrypts to its own row. Leave it alone.

15. **Bearer-token API clients are designed but not built.** No `createToken()` call exists today — all API auth is stateful Sanctum cookies, which Task 24 covers via the session. **Task 41** specifies the whole path for when that changes: a central `access_token_tenant_lookups` table keyed on the SHA-256 Sanctum already stores, an observer keeping it in step, and one extra branch in `InitializeTenancyForApi`. It is written up rather than built because nothing calls it yet and dead code that cannot be exercised end-to-end is its own liability — but the *decision* is made, so nobody reaches for a caller-supplied header under deadline.

16. **The assistant sends tenant data outside the tenant boundary, and no task in this plan can fix it.**

    Everything this plan builds — database isolation, per-tenant MySQL users, per-tenant storage roots — stops at the moment a question is answered. Answering it means sending customer names, addresses, service history and sometimes photographs to a third-party model provider.

    Three specific routes out, named rather than left implied:

    - **The supplier.** Whichever of the five `config/assistant.php` providers is active receives whatever the tools returned. `ASSISTANT_PROVIDER` is a single global setting, so a tenant cannot choose, cannot see which supplier answered them, and cannot decline. Anthropic, Deepseek, Mistral, Qwen, Moonshot and OpenAI are not the same jurisdiction, the same retention policy or the same processing agreement.
    - **Web search.** `ANTHROPIC_WEB_SEARCH` defaults to on. The query the model composes may contain a model number, a fault description, and whatever else it thought relevant.
    - **The reports mailbox.** `ASSISTANT_REPORTS_MAIL` defaults to `info@majorlabel.nl` — ours. A reported conversation is a full transcript *including what every tool was handed and returned*, so it is the richest single export of a tenant's data the application produces, and it lands in the vendor's inbox by design, from every tenant, into one place.

    None of this is a bug, and none of it should be "fixed" here. It is a set of facts that a single-tenant install could leave undocumented and a multi-tenant one cannot: the processing agreement is now with us, rather than about the customer's own machine.

    The follow-ups, in the order they will be asked for:

    1. Name the sub-processors in the terms.
    2. Make `ASSISTANT_PROVIDER` a per-tenant setting. The Task 32 pattern applies directly, and unlike the API keys, the *choice* of supplier genuinely is the tenant's.
    3. Either keep reports on disk under a per-tenant retention, or make the mail opt-in rather than on by default. `assistant:prune` already deletes reports on the same clock as the transcripts (Task 20); the copy that has been e-mailed is the one nothing sweeps.

17. **The global exception responder hides the status codes this plan diagnoses by.** `bootstrap/app.php` ends with a `respond()` handler that rewrites 403 into a redirect with a fixed message, and — outside local/dev/testing only — rewrites 404, 500 and 503 into `redirect()->back()` with a friendly sentence.

    That is a reasonable thing for a human-facing app to do and it should stay. What it costs is every diagnostic this plan phrases as a status code, and the affected instructions have been corrected in place rather than left to mislead: Task 12 Step 3 ("a 404 on a record that exists means the middleware order is wrong") is a *local* check, where the rewrite does not apply, and is sound as written. Task 31's module gate and Task 14's cross-tenant file ids are the two that change shape in production.

    The practical rule while cutting over: **read `storage/logs/laravel.log`, not the screen.** A tenancy misconfiguration in production looks like redirects and friendly Dutch, not like errors — and a redirect loop is what "every page 404s" looks like from the outside. If Task 27's smoke test goes wrong in a way that makes no sense, the first move is `php artisan down` and tailing the log, not clicking further.

    Worth considering independently of tenancy: exclude `/files/` and the other controller-served paths from the 404 rewrite. A redirect is a sensible answer for a person who mistyped a URL and a nonsensical one for an `<img>` tag.
