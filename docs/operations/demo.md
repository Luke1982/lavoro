# The demo customer

There is a customer called **Demo** that contains a complete, realistic company,
meant for showing the app to people. It is a climate control company with:

- thirteen employees, one for each role, each with a photo;
- a product catalogue with a picture on every product;
- around 150 customers with their installations;
- five weeks of planning around today, plus jobs that still need a date;
- support tickets in every stage;
- five projects (running, not started and finished) with milestones, a budget
  and a work order per phase.

## Creating it

```bash
php artisan demo:install
```

Run this once by hand. It takes a minute or two. Like `tenant:create`, it
switches to the database account that is allowed to create databases, so you do
not need to do anything else.

After that it rebuilds itself **every night at 04:00**: the old demo is deleted
and a new one is built. That way every demonstration starts with clean data and
with the planning around the current week. If you delete the Demo customer in
the admin panel, the nightly rebuild stops as well.

## Logging in

| Email | Password | You see |
|---|---|---|
| `demo@lavorofsm.nl` | `demo` | Sanne de Vries, administrator |
| `mark@lavorofsm.nl` | `demo` | the planner |
| `lisa@lavorofsm.nl` | `demo` | the service desk |
| `jeroen@lavorofsm.nl` | `demo` | a mechanic |

Every other demo employee logs in the same way: their first name, then
`@lavorofsm.nl`, with password `demo`.

An email address can belong to only one customer in the whole installation. So
none of these addresses may already be in use by a real customer. If one is,
`demo:install` stops and says so.

The demo customer has no mail settings, so it cannot send email to anyone.

## Things to know

- **The demo is never invoiced.** It gets a new start date every night, and
  invoicing it would use up a real invoice number each time.
- **The AI assistant is switched on** for the demo. What it may spend per month
  is limited by the Business package.
- **The faces are not real people.** They are computer-generated
  (thispersondoesnotexist) and stored in
  `database/seeders/data/demo/photos/users/<login>.jpg`, named after the part of
  the email address before the `@`. So `mark.jpg`, and `demo.jpg` for Sanne.
- **Product pictures** are drawn automatically unless you put a photo in
  `database/seeders/data/demo/photos/products/<brand-model>.jpg`, for example
  `daikin-perfera-ftxm25r.jpg`. The next nightly rebuild picks it up.
- **Nobody has clocked their hours in the demo.** Appointments in the past are
  completed on the work order, but without registered times. Registered times
  turn an appointment grey in the planner, and a fully grey planner is not much
  of a demonstration.
- **A real customer named Demo is never touched.** If a customer with that name
  exists and it is not the demo, `demo:install` refuses to run.

A local development installation builds the same demo; see
[getting started](../development/getting-started.md).
