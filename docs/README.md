# Lavoro documentation

Find yourself in the first column.

| You are | Start at |
| --- | --- |
| using Lavoro | [the manual](guide/handleiding.md) (Dutch) |
| putting it on a server | [installing a server](install/server.md) |
| moving an existing Lavoro in | [taking over an installation](install/import-existing.md) |
| running a server | [the runbook](operations/runbook.md) |
| writing code | [getting started](development/getting-started.md) |

## Everything there is

**guide** — for the people who work with it

- [handleiding.md](guide/handleiding.md) — the user manual, in Dutch. The
  assistant answers questions from this file, so it is part of the product:
  when behaviour changes, the chapter changes with it.

**install** — from nothing to running

- [server.md](install/server.md) — a new server, step by step, with a check
  after every step
- [import-existing.md](install/import-existing.md) — a single-customer Lavoro
  becomes a customer of this one
- [google-calendar.md](install/google-calendar.md) — the Google project, once
  per installation
- [android.md](install/android.md) — building and releasing the app

**operations** — running it

- [runbook.md](operations/runbook.md) — what has to run, customers,
  subscriptions, invoices, migrations, deploying
- [backup-restore.md](operations/backup-restore.md) — what to keep, and how to
  put it back
- [troubleshooting.md](operations/troubleshooting.md) — when the doctor is not
  enough
- [demo.md](operations/demo.md) — the demo customer, rebuilt every night

**development** — writing code

- [getting-started.md](development/getting-started.md) — a local installation in
  one command
- [architecture.md](development/architecture.md) — how the application is put
  together
- [multi-tenancy.md](development/multi-tenancy.md) — one installation, many
  customers: the model and its boundaries
- [testing.md](development/testing.md) — running the suite, and what it walks
- [risks.md](development/risks.md) — where this breaks and how you would notice
- [assistant-tests.md](development/assistant-tests.md) — the fixed list of
  questions for the AI assistant

**archive** — [build notes](archive/README.md), kept for reference, not
maintained.

The rules for whoever writes code here, person or model, are in
[`CLAUDE.md`](../CLAUDE.md) at the root of the repository.
