# Maintenance mode — design

**Date:** 2026-08-27
**Status:** implemented

## Problem

There is no way to take Lavoro offline for planned work. A deploy that migrates
the database, or an hour of work on the mail integration, happens under live
traffic: monteurs keep submitting werkbonnen against a schema that is mid-change,
and whatever breaks does so as a 500 in somebody's face.

Laravel ships the mechanism — `php artisan down` writes `storage/framework/down`,
and `public/index.php` already checks for `storage/framework/maintenance.php`
before the autoloader runs. What is missing is a page worth showing and a command
worth remembering.

## Goal

One command that toggles, and a page that looks like the rest of the product.

## Decisions (locked)

- **Toggle, not two commands.** `app:maintenance` flips whichever way the app is
  not currently in. Passing `--message` or `--until` always means *down* (or
  *update the page*), so composing a message can never re-open the site.
- **Bypass by secret link**, Laravel's built-in `--secret`. The command prints the
  URL; opening it sets a cookie and that browser works normally while everyone
  else sees the page.
- **Fixed Dutch copy**, with an optional extra line and an optional expected
  return time. No mode in which the operator must compose prose to take the site
  down.
- **Real 503 with `Retry-After`**, derived from `--until` when given.
- **No admin UI.** Taking the app down from inside the app is a control that stops
  working exactly when it is needed.
- **No tests.** Per project convention; the behaviour is Laravel's, verified by
  running it.

## Architecture

Three moving parts, and the important one is where the page is rendered.

```
php artisan app:maintenance --message=… --until=…
  │
  ├─ View::share(maintenance_message, maintenance_until)
  │
  └─ Artisan: down --render=errors::503 --secret=… --retry=…
       │
       └─ DownCommand::prerenderView()          ← renders errors/503.blade.php
            │                                      in THIS process
            └─ storage/framework/down            { template: "<!DOCTYPE html>…" }
               storage/framework/maintenance.php ← stub copied by DownCommand

next HTTP request
  │
  └─ public/index.php
       └─ requires storage/framework/maintenance.php
            └─ echo $data['template']; exit;    ← before vendor/autoload.php
```

**The page is rendered once, at the moment you take the site down, and stored as a
string.** Every consequence below follows from that:

- The Blade view must be **self-contained** — inline `<style>`, no `@vite`, no
  compiled Tailwind. The asset manifest may not exist, and nothing is loaded to
  read it anyway.
- Only static files may be referenced (`/img/bg.png`, `/img/logo-neg.svg`), since
  the web server serves those without touching `index.php`.
- Message and expected time cannot be passed as `down` options — `prerenderView()`
  passes only `retryAfter`. They reach the view through `View::share()`, which
  works because the render happens in the same process as our command. This is
  the one non-obvious coupling in the design and is commented in the code.

## Components

### `app/Console/Commands/ToggleMaintenanceMode.php`

```
app:maintenance
    {--message=  : Extra regel op de onderhoudspagina}
    {--until=    : Verwachte eindtijd, bijvoorbeeld "16:00"}
```

| Invocation | App is live | App is down |
| --- | --- | --- |
| bare | goes down | comes up |
| `--message` / `--until` | goes down | updates the page, stays down |

- **Secret is reused while down.** Rewriting the message must not invalidate the
  bypass cookie of whoever is already testing. Read through
  `maintenanceMode()->data()` (the contract), not by parsing
  `storage/framework/down`, so a non-file maintenance driver still works.
- **`--until` is parsed with Carbon.** A bare clock time rolls forward to its next
  occurrence — `"09:00"` typed in the afternoon means tomorrow morning, which is
  what you meant. A *full* datetime in the past is rejected instead of nudged: a
  mistyped `"2026-08-20 09:00"` is an error to report, not a date to quietly move.
  `"+2 hours"` works too, for free.
- **The label carries its own date when it needs one.** `"16:00"` today,
  `"morgen 09:00"`, otherwise `"30-08-2026 09:00"` — because "verwacht weer online
  om 09:00" is a lie when 09:00 is tomorrow. Rendered after a colon so all three
  read correctly in the sentence.
- **`Retry-After` has a one-minute floor.** A window closing in ten seconds tells a
  client nothing by the time it reads the header.
- **Dropping an ETA is announced.** Re-running with only `--message` while a window
  was set clears it, on both the page and the header. That is consistent, but it is
  silent, so the command says so.
- **Exit codes propagate.** `down` and `up` can fail (unwritable `storage/`); the
  command returns their status rather than reporting success over the top.

### `resources/views/errors/503.blade.php`

Visual language taken from `Pages/Auth/LoginPage.vue`: background photograph,
dark translucent card, `logo-neg.svg`, Sora, blue→green gradient on the accent
word.

- `<picture>` with a `media` query, not two `<img>` tags with `display:none` —
  the latter downloads both backgrounds on every device.
- The pulsing status dot is suppressed under `prefers-reduced-motion`.
- **The page reloads itself.** A `HEAD` request to the current path every 15
  seconds; anything other than 503 means the app is back, so reload. Paused while
  the tab is hidden. This is why "je hoeft niets te doen" in the copy is true.
- `$maintenance_message` / `$maintenance_until` are read with `empty()`, because
  this view is also what a plain `abort(503)` renders, where neither is shared.
- A `<noscript>` block replaces the "ververst zichzelf" promise with an instruction
  to reload, since without JavaScript the promise is false.

### Health endpoint

No change required. `withRouting(health: '/up')` already registers `/up` as
excluded from maintenance (`ApplicationBuilder::166`), so monitoring stays
reachable during a planned window without adding a middleware line.

## Interaction with the global exception responder

`bootstrap/app.php` turns a 503 into `redirect()->back()` in production. That
never fires for maintenance mode — the prerendered page exits before the framework
boots — but it does mean this Blade view is effectively unreachable in production
by any other route. That is acceptable: it exists for this command.

## Out of scope

- Scheduling a window in advance
- Draining the queue or stopping workers (`down` blocks HTTP only)
- Per-tenant maintenance (see the multi-tenancy plan; this is install-wide)
