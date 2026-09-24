# The demo customer

A customer called **Demo** holds a complete, credible installation for showing
the app: a climate company with thirteen people (one per role, all with a
face), a product catalogue with a picture on every product, some 150
customers with their installations, five weeks of planning around today,
open work waiting for a date, tickets in every state, and five projects --
running, not started and finished -- with milestones, a budget sheet and a
work order per phase, whose days show up in the planner.

```
php artisan demo:install
```

The first time is by hand; it elevates itself to the provisioner, like
`tenant:create`, and takes a minute or two. After that the scheduler throws it
away and rebuilds it **every night at 04:00**, on the provisioning worker, so
every demo starts clean, with the planning around the current week and nothing
left over from the day before. Delete the Demo tenant in the admin panel and
the nightly rebuild stops with it.

| Login | Password | Shows |
|---|---|---|
| `demo@lavorofsm.nl` | `demo` | Sanne de Vries, admin |
| `mark@lavorofsm.nl` | `demo` | the planner |
| `lisa@lavorofsm.nl` | `demo` | the service desk |
| `jeroen@lavorofsm.nl` | `demo` | a mechanic |

Every other demo user logs in the same way: first name `@lavorofsm.nl`, password
`demo`. An address points at one tenant, so none of these may be a real login
elsewhere; the install stops if one is. No mail reaches them: the tenant has no
mail settings, and without those it sends nothing.

- **Never invoiced.** The demo gets a fresh start date every night; invoicing
  it would spend a real number from the invoice series each time.
- **The AI assistant is on**, as part of the demo. What it can spend is capped
  by the Business package's monthly allowance.
- **Faces are photos, of nobody.** They are generated (thispersondoesnotexist)
  and live in `database/seeders/data/demo/photos/users/<login>.jpg`, named by the
  part of the login before the `@`: `mark.jpg`, and `demo.jpg` for Sanne.
  Products are drawn by the seeder unless a photo is put in
  `database/seeders/data/demo/photos/products/<brand-model>.jpg` (the slug of
  brand and model, e.g. `daikin-perfera-ftxm25r.jpg`). The next rebuild uses it.
- **No registered times.** Past appointments are finished on the work order,
  but nobody has clocked them: a mechanic's registered times grey an
  appointment out, and a planner full of grey shows nothing.
- **A real customer called Demo** is never touched: the install refuses to
  overwrite a tenant of that name that is not the demo.

The local installation builds the same demo: see
[getting started](../development/getting-started.md).
