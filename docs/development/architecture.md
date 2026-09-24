# How the application is put together

Laravel 12 serving Inertia pages rendered by Vue 3 — one application, no
separate API for the front end. Roughly 840 PHP classes, 220 Vue components,
270 migrations and 150 test files.

The tenancy model has [a page of its own](multi-tenancy.md); this is everything
else.

## The request

```
routes/web.php ──► Form Request ──► Controller ──► inertia('Page', [...]) ──► Vue page
                   authorize()      thin
                   rules()
```

- **`routes/web.php`** — everything behind the login. **`routes/api.php`** is
  the planner app over Sanctum, **`routes/landlord.php`** is `/beheer` (our own
  panel, central database, no customer), **`routes/console.php`** is the
  scheduler.
- **Form Requests** carry both halves of the decision: `authorize()` asks a
  policy, `rules()` validates. Controllers do neither, and the front end
  validates nothing — it only shows `form.errors`.
- **Controllers** stay thin: fetch, hand to a service or an action, return a
  page. Anything worth a name lives in `app/Services`, `app/Actions` or
  `app/Domain`.

## Where the work lives

| | |
| --- | --- |
| `app/Models` | Eloquent, with the cross-cutting behaviour in `app/Models/Traits` (`HasOwner`, `HasExecutingUsers`, `HasActivities`, `HasCustomFields`, `RemarkableTrait`) |
| `app/Domain/Signals` | what happened, as an event others may answer: a deleted record takes its photos, a closed work order writes its trail |
| `app/Domain/Assistant`, `app/Domain/Tools` | the AI assistant: the loop, and the tools it may call. Every tool is a class with a description the model reads |
| `app/Domain/Planning`, `Access`, `Search`, `Tickets` | planning maths, access links for customers without an account, the spotlight, ticket flow |
| `app/Services` | PDFs, mail, SnelStart, the invoicer, the tenant provisioner |
| `app/Jobs` | everything queued; per customer unless it says otherwise |
| `app/Support` | small things with no better home (`Tenancy`, `Money`, `PageTitle`, `QueuedWork`) |
| `app/Tenancy` | the bootstrappers that make a request, a job and the disks belong to one customer |

## The front end

`resources/js/Pages/**` mirrors the routes; `app.js` resolves them and wraps
every page in `Layouts/MainLayout.vue`. Shared primitives live in
`Components/UI` (`ComboBox`, `TextInput`, `DrawerComponent`, `ModalDialog`,
`EditableGridComponent`, …) and the helpers every screen uses in
`Utilities/Utilities.js` — dates, permissions, badge colours.

The interface is Dutch. Code, comments and these documents are English.

`landlord.js` is a second, deliberately separate Inertia app for `/beheer`: it
runs on the central database and never has a customer, so sharing the
customer app's menu, permissions and shared props would mean every screen
asking "is there a customer?".

## Work that is not a request

- **Queue** — two workers: the ordinary one, and one running as
  `lavoro_provisioner` for creating and deleting customers. Jobs carry their
  customer in the payload.
- **Scheduler** — `routes/console.php`. Every entry loops the customers and
  dispatches one job each; it never queries inline.
- **Android** — a Capacitor shell around the same web app, with GPS and push.
  See [building it](../install/android.md).

## Conventions that are not obvious

The full list is in [`CLAUDE.md`](../../CLAUDE.md) at the root — it is what
whoever writes code here, person or model, is held to. The ones that catch
people out:

- PHP variables are `snake_case`.
- No inline comments; a docblock when the *why* is not obvious from the name.
- Selecting in the interface toggles: clicking the selected item clears it.
  Never a separate X.
- New forms open in a drawer, never inline.
- `userables` with a `type` column carries every person-to-record link
  (`owner`, `executing`). No parallel pivot tables.
- Permissions are `{resource}.{action}` and seeded in migrations.
