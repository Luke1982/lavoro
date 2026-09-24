# How the application is put together

Lavoro is a Laravel 12 application with a Vue 3 front end, connected by Inertia.

Inertia means there is no separate API: a controller returns a page name and an
array of data, and Inertia renders the matching Vue component with that data as
its props. So routes, permissions and validation all stay in PHP, and the Vue
side only renders it. If you have worked with Laravel and Vue separately,
this is the part to read up on first.

The application currently holds about 840 PHP classes, 220 Vue components, 270
migrations and 150 test files.

How one installation serves several companies is described separately in
[multi-tenancy](multi-tenancy.md). This page covers everything else.

## How a request is handled

```
routes/web.php ──► Form Request ──► Controller ──► inertia('Page', [...]) ──► Vue page
                   authorize()      keeps it short
                   rules()
```

Route files:

- **`routes/web.php`** — everything behind the login.
- **`routes/api.php`** — the planner app, authenticated with Sanctum.
- **`routes/landlord.php`** — the admin panel at `/beheer`. It uses the shared
  database and never belongs to a customer.
- **`routes/console.php`** — the scheduled tasks.

**Form Requests** hold two things: `authorize()` asks a policy whether this user
may do this, and `rules()` validates the input. Controllers do neither. The
front end does not validate anything either; it only displays `form.errors`.

**Controllers stay short**: fetch the data, hand the work to a service or an
action, return a page. Anything that deserves a name lives in `app/Services`,
`app/Actions` or `app/Domain`.

## Where the code lives

| Folder | What is in it |
| --- | --- |
| `app/Models` | Eloquent models. Behaviour shared by several models is in `app/Models/Traits`: `HasOwner`, `HasExecutingUsers`, `HasActivities`, `HasCustomFields`, `RemarkableTrait` |
| `app/Domain/Signals` | the signal layer: business facts that have happened, which other code reacts to. Explained below |
| `app/Domain/Assistant`, `app/Domain/Tools` | the AI assistant: the conversation loop, and the tools it is allowed to call. Each tool is a class with a description that the model reads |
| `app/Domain/Planning`, `Access`, `Search`, `Tickets` | planning calculations, links for customers without an account, the search bar, ticket handling |
| `app/Services` | PDFs, email, the SnelStart accounting integration, invoicing, creating customers |
| `app/Jobs` | everything that runs in the background; per customer unless stated otherwise |
| `app/Support` | small helpers with no better home: `Tenancy`, `Money`, `PageTitle`, `QueuedWork` |
| `app/Tenancy` | the code that makes a request, a background job and the file storage belong to one customer |

## The signal layer

This is the main reason controllers here stay short, so it is worth
understanding before you add one.

A controller does the one thing it is for, and then says what happened:

```php
$image->delete();

Signals::dispatch(new ImageRemoved($image));
```

Everything that follows from that fact lives elsewhere, in a listener: writing
the activity trail, sending mail, syncing an appointment to Google, cleaning up
files that no record points at any more. The controller does not know those
exist, and adding another consequence later does not touch the controller again.

**A signal is a fact, not a command.** `ImageRemoved`, `AppointmentRescheduled`,
`ContractAssetDetached`: something that has already happened, named the way a
person would say it. If you find yourself naming one `SendInvoiceMail`, that is
a job, not a signal.

**Writing one.** Extend `BaseSignal` in `app/Domain/Signals/<area>/` and
implement what the `Signal` interface asks: a `key()` that is stored in the
activity trail and must never be renamed or reused, the Dutch sentence a person
reads, and `coveredFields()` for the model fields this signal reports itself —
without that, the generic trail records the same change a second time.

`ModelChanged` is the generic one: `RecordsHistory` emits it on every create,
update, delete and restore, with one entry per changed field. Write a signal of
your own only when the fact has a name of its own.

**Listening.** A listener is a class in `app/Listeners` with a `handle()` method,
and the type hint decides what it hears. Laravel's listener discovery registers
it; there is no list to maintain:

```php
public function handle(ImageRemoved $signal): void    // this one signal
public function handle(Signal $signal): void          // every signal there is
```

The second form is how `RecordActivity` writes the trail for every present and
future signal without being touched.

**When they run.** A signal fires immediately, inside the transaction that
caused it, so a listener that writes to the database is atomic with the work it
reacts to: a rollback takes its rows with it. A listener that leaves the
database — mail, a queued job, an API — implements
`ShouldHandleEventsAfterCommit`, because an email cannot be rolled back.

**Failure.** A listener carrying a business rule lets its exceptions escape, so
the whole operation fails with it. The audit trail is the exception: it catches
and logs its own errors, because a broken trail must not break the work it
describes.

**The one door.** Always raise through `Signals::dispatch()`, never `event()`.
That class is what makes the layer safe to use: a listener may cause further
signals, which is the point, but that also means a cascade can loop back into
itself. It refuses a repeat of the same fact about the same record in one chain,
caps the chain at ten deep and a thousand signals per request, and reports each
of those loudly instead of failing silently. It also gives every signal in one
cascade the same correlation id, so an entire chain of consequences can be read
back as one story.

## The front end

`resources/js/Pages/**` mirrors the routes. `app.js` finds the right page and
wraps it in `Layouts/MainLayout.vue`.

Shared building blocks are in `Components/UI`: `ComboBox`, `TextInput`,
`DrawerComponent`, `ModalDialog`, `EditableGridComponent` and others. Helper
functions used across screens (dates, permissions, badge colours) are in
`Utilities/Utilities.js`.

The user interface is in Dutch. The code, the comments and this documentation
are in English.

`landlord.js` is a second, separate Inertia application for `/beheer`. It runs
on the shared database and never has a customer, so it does not share the menu,
permissions and page data of the customer application. Sharing those would mean
every screen having to ask whether a customer is present.

## Work that does not come from a request

- **Background jobs.** Two workers: a normal one, and one running as
  `lavoro_provisioner` that creates and deletes customers. Each job records
  which customer it belongs to.
- **Scheduled tasks** (`routes/console.php`). Each entry loops over the
  customers and starts one job per customer. It never runs one query across all
  customers at once.
- **Android app.** A Capacitor wrapper around the same web application, adding
  GPS and push notifications. See [building it](../install/android.md).

## Conventions worth knowing

The full list is in [`CLAUDE.md`](../../CLAUDE.md) in the root of the
repository. These are the ones people most often get wrong:

- PHP variable names use `snake_case`.
- No comments inside methods. Use a docblock when the reason for something is
  not obvious from the names.
- Selecting in the interface works as a toggle: clicking the selected item
  clears it. Never add a separate X or clear button.
- New forms open in a drawer, never inline on the page.
- The `userables` table with its `type` column holds every link between a person
  and a record (`owner`, `executing`). Do not add separate tables for that.
- Permissions are named `{resource}.{action}` and are created in migrations.

## Next

- [How one installation serves several companies](multi-tenancy.md) — read this
  before changing anything that queues a job, stores a file or signs a link
- [Testing](testing.md) — how the suite works and what it covers
- [What can go wrong](risks.md) — the parts that fail without an error message
