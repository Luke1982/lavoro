# Lavoro documentation

Lavoro is a field service application: it keeps track of customers, their
installations, work orders, jobs, appointments, projects and invoicing. One
installation serves several companies at once, each with its own database.

## Where to start

| If you want to | Read |
| --- | --- |
| use the application | [the user manual](guide/handleiding.md) (Dutch) |
| install it on a new server | [installing a server](install/server.md) |
| move an existing Lavoro installation into this one | [taking over an installation](install/import-existing.md) |
| run and maintain a server | [the runbook](operations/runbook.md) |
| work on the code | [getting started](development/getting-started.md) |

## All pages

### guide — for the people who use the application

- [handleiding.md](guide/handleiding.md) — the user manual, in Dutch. The
  built-in AI assistant answers questions using this file, so it is part of the
  product: when the application changes, this file changes with it.

### install — from nothing to a running installation

- [server.md](install/server.md) — setting up a new server step by step, with a
  check after each step
- [import-existing.md](install/import-existing.md) — turning an existing
  single-company Lavoro into a customer of this installation
- [google-calendar.md](install/google-calendar.md) — the Google settings needed
  for calendar synchronisation, once per installation
- [fail2ban.md](install/fail2ban.md) — blocking repeated failed logins
- [android.md](install/android.md) — building and releasing the Android app

### operations — running it day to day

- [runbook.md](operations/runbook.md) — what has to be running, managing
  customers and subscriptions, invoices, migrations, deploying
- [backup-restore.md](operations/backup-restore.md) — what to back up, and how
  to restore it
- [troubleshooting.md](operations/troubleshooting.md) — what to do when
  something is wrong
- [demo.md](operations/demo.md) — the demo customer, rebuilt every night

### development — working on the code

- [getting-started.md](development/getting-started.md) — a local installation in
  one command
- [architecture.md](development/architecture.md) — how the application is put
  together
- [multi-tenancy.md](development/multi-tenancy.md) — how one installation serves
  several companies, and where the boundaries are
- [testing.md](development/testing.md) — running the test suite, and what it
  covers
- [risks.md](development/risks.md) — what can break, and how you would notice
- [assistant-tests.md](development/assistant-tests.md) — the fixed list of
  questions used to check the AI assistant

### archive

[Notes from building it](archive/README.md), kept for reference. Not maintained.

The rules for anyone writing code here, person or AI, are in
[`CLAUDE.md`](../CLAUDE.md) in the root of the repository.
