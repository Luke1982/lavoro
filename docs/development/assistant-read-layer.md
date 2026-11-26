# The assistant's read layer

The assistant reads what the person asking may read, through the same rules the
screens use. Nothing about access is decided in the assistant: it asks the
policies and uses the models' own visibility scopes.

## Who may read what

Every model a person can open somewhere has a policy with two read methods:

- `viewAny(User $user): bool` — may this person read records of this kind at all;
  which ones is the scope's business.
- `view(User $user, Model $record): bool` — may this person open this one record.
- `list(User $user): bool`, only where the overview page is stricter than
  reading: a monteur may read the machines on his own werkbonnen but not open
  the machine overview. Without `list`, the overview page's rule is `viewAny`.
  The assistant only links to an overview page the person may open.

A policy without `view` has `viewAny` plus the scope as its whole rule.

Some records open without any permission, by design, because the person is
there to do the work. Whoever is planned for a werkbon (uitvoerder on it, or on
one of its afspraken that is not cancelled: `ServiceOrder::scopeAssignedTo`)
opens that werkbon and its keuringen. While the werkbon is open
(`ServiceOrder::scopeWorkedOnBy`) they also open the keuringen's machines, and
open and edit its storingen (`TicketPolicy::worksOn`). Adding a keuring still
takes the right to read machines. Whoever sees a machine sees its product. A
record opens with its photos and with the project photos shared to it
(`HangsOffARecord`); there is no right to see photos of its own.

When `view` depends on the record (own records only, through a werkbon, not the
super admin), the model has `scopeVisibleTo(Builder $query, User $user)` that
selects exactly the records `view` allows, and `view` is written as
`Model::visibleTo($user)->whereKey($record->getKey())->exists()` or an
equivalent that provably agrees. The overview page uses the same scope. A rule
never lives in a controller or a view.

The read Form Requests call `$this->user()->can('list' or 'viewAny', Model::class)` (or
`can('view', $record)`), never `hasPermission` directly.

## What is readable

`App\Domain\Assistant\Reading\RecordKinds` lists the kinds: every kind in
`RecordKinds::NOUNS` whose policy has `viewAny` is readable, except the ones in
`RecordKinds::UNREADABLE` with a reason (credentials, sessions, the assistant's
own records, ...).

Who reads a readable kind (`RecordKinds::mayRead`): `view` is not always within
`viewAny` — a monteur with only `customer.read` opens the werkbon and the
afspraak he is assigned to. So a kind with a visibility scope
(`RecordKinds::hasVisibilityScope`, the one check for it, also used by
`HangsOffARecord`) is open to anyone and the scope decides the rows; `viewAny`
alone gates a kind without one. That is only safe while every scope gives
nothing to somebody without any rights, which `ReadLayerLeakTest` holds for
every kind. The overview link (`mayList`) stays `list`, or `viewAny`. The kinds
offered by name when the model asks for an unknown one are the `viewAny` ones. Each kind has one Dutch noun (`werkbon`, `klant`, ...), the
same one the history search and the page context use — `RecordKinds` is the one
place those nouns live. A new policy therefore needs its model's noun in
`NOUNS`, or a line in `UNREADABLE`.

`tests/Feature/Assistant/ReadLayerCoverageTest` fails when a model with a policy
is neither readable nor excluded with a reason. It finds those models by
loading every file in `app/Models`; at runtime only `NOUNS` is walked.

`RecordKinds::SCREENS` is the one list of the pages the assistant knows, by
path: the kind each shows, how the page context names it, whether
`/<path>/<id>` is one record's page, and whether the overview takes a filter.

## Which fields

A record is returned with its attributes minus `$hidden`, minus every field in
the model's `$activity_permissions` the person lacks the permission for, with
foreign keys named through `$activity_relations`. These are the same maps the
timeline uses, so the timeline and the assistant never disagree about a field.

Two refinements, both in `App\Domain\Assistant\Reading\Fields`:

- `password`/`remember_token` and anything cast `encrypted` (also through
  Eloquent's `AsEncrypted*` casts) is never read, whatever permission someone
  holds.
- Otherwise a column in `$activity_permissions` is decided by that permission
  alone, also when the model lists it in `$hidden`: `$hidden` only keeps it out
  of JSON that travels to pages without the check (a werkbon's
  `financial_comments`). Every other hidden column is never read.
- `$activity_relations` also holds formatters (`[Money::class, 'euros']`); only
  an entry whose first element is a relation of the model names a record.

`tests/Feature/Assistant/ReadLayerLeakTest` makes rows of every readable kind
(through its factory, or through the record it hangs off) and holds each to: no
scope and no `viewAny` → refused; for everyone `mayRead` lets in, the count and
the record reader give exactly the rows `view` allows (or `viewAny` where the
policy has no `view`), compared on the rows it made; nobody without rights gets
a row, a record or an entry of history of any kind; a gated field → absent
without the permission; MajorLabel's account and role are neither counted nor
grouped. A readable kind it never compared fails the run.
`ReadLayerParityTest` holds the cases where the first version took away what
the history search gave before, or gave beyond what the screens show.

## History

`search_activity` returns an entry when it hangs off a record the person may
read, by the same `mayRead` and scope. Where the scope keeps nothing from this
person (`RecordKinds::narrowsFor` is false: it adds no condition), every entry of
the kind is theirs, also of a record deleted for good — "wie heeft klant X
verwijderd?". Where it narrows, the entries of the records it lets through
(soft-deleted ones included); a record no longer in the table is past any scope,
so its history is only for whoever has the right to every record of the kind.
The roles' history is read by whoever may hand out roles (`RolePolicy::viewAny`
takes `user.assign_roles`); the roles screen itself stays behind the admin
middleware. Whoever may see every storing on the overview (`ticket.see_all`)
reads every storing too.

## The read tool

`read_records` takes a kind and one of:

- `id` — one record, if `view` allows it.
- `where` — conditions (below), answered as a count, optionally `sum` of one
  numeric field, optionally grouped by one field, computed in the database over
  every visible matching record. Never a list of rows in the chat.
- `link: true` with `where` — a link to the kind's overview page showing exactly
  those records (below), plus the count.

`id` together with `where`, `sum`, `group` or `link` is refused rather than half
answered. The result speaks Dutch, like the tool: `soort`, `aantal`,
`totaal_<veld>`, `groepen`, `velden`, `namen`, `link`, `samenvatting`. A kind the
assistant never reads (a koppeling, an instelling) is explained, not answered
with "no rights"; an unknown kind or relation is answered with the ones this
person may read.

## Conditions

A fixed shape, never method names:

```json
[{"field": "order_date", "op": "<", "value": "2026-10-25"},
 {"has": "events", "not": true},
 {"field": "customer.city", "op": "=", "value": "Tiel"}]
```

- `field` is a column of the model, or `relation.column` one level deep.
  Columns are checked against the table; a hidden or permission-gated column is
  refused for a person who may not read it.
- `op` is one of `= != < > <= >= in like empty filled`.
- `has` names a relation method of the model; `not: true` makes it "has none".
- Dates are `JJJJ-MM-DD`, read on the local clock.
- `like` means "contains" (`%value%`, wildcards escaped). `!=` also matches an
  empty column, `empty` is null (or `''` for text).
- A relation is a public method of the model returning one of Eloquent's
  relation builders (checked in its source or return type before it is ever
  called), not `morphTo`, to a kind this person may read; that kind's own
  visibility is applied inside the `whereHas`.
- `group` takes one of the model's own columns (not a moment, and not a unique
  one: that is a list, and a list is a link), largest 50 groups; a foreign key
  comes back with the name through `$activity_relations`, only for related
  records this person may see and only when they may read the name's column.
- A text column is compared as text, also when the value is a number: MySQL
  would otherwise compare both as numbers, and `'Tiel' = 0` is true there.
- A yes/no is `true`/`false`, `1`/`0` or `ja`/`nee`, for a field and for `not`
  alike.
- A scope used inside a `whereHas` qualifies its columns (`whereKey`,
  `qualifyColumn`): the relation may join a pivot with an `id` of its own.

`App\Domain\Assistant\Reading\Conditions` turns them into a query on
`Model::visibleTo($user)` (or the plain query when `viewAny` is the whole rule).
The count, the sum and the grouping run that query; the overview page applies
the same conditions on top of its own query, which follows the same policy and
scope. With `?filter=` an overview drops the defaults that would narrow it
further (the projects list shows every status, the quotes list too; a status
chosen on top of that is sent along on every visit, `defaultStatusWith`), and the
pages that bring back filters remembered in the browser (werkbonnen,
storingen, producten, machines) skip that (`resources/js/Utilities/assistantFilter.js`).
A search or a change of filter on the page keeps `filter` in the URL and narrows
further; the bar then says so.

## One-time filters on overview pages

`assistant_filters` (tenant table): `uuid`, `user_id`, `kind` (the model's
class name, like every `_type` column here), `conditions` (json), `summary` (the
Dutch sentence shown above the list), timestamps. The summary is made from the
conditions by `Conditions::describe`, never written by the model, so the bar
cannot claim something the filter does not do.

A link is `/<overview>?filter=<uuid>`. The overview controller applies its own
rules as always, then `AssistantFilter::applyTo($query, $request)` narrows it
further. A filter can only narrow what the page already shows. The person who
opens the link sees their own share of the records, whoever made the filter. The
conditions are compiled again with the opener's permissions; a condition they
may not use empties the list, and the bar says why.
The main layout shows the summary with one click to drop the filter. The
summary is described again for whoever opens the link, so it names only records
they may see; when a condition is closed to them the bar says only that they
cannot use this filter, because the summary would name the field and the value.
A condition that no longer fits the records (a field dropped or renamed since)
empties the list with "Dit filter klopt niet meer — vraag het de assistent
opnieuw": only a refusal of a field behind a permission or a relation to a kind
closed to the opener (`ClosedToThisPerson`) is put down to their rights.
A link to a filter that no longer exists, or to one for another list, shows
nothing (not everything) with "Dit filter bestaat niet meer — vraag het de
assistent opnieuw". Filters are kept as long as the assistant's conversations
(`assistant.keep_months`, six when that is empty or not a whole number) and
removed nightly, one job per tenant (`PruneAssistantFiltersJob`), and by
`assistant:prune`, with the same `--months` as the conversations. The overviews that
take a filter are the `SCREENS` entries with `filter`; each calls
`$query->tap(AssistantFilter::from($request))` in its index action and keeps the
query string on its paginator. The users overview shows no deleted users with a
filter, since the count covers the active ones.

The filter is compiled once per request: `applyTo` records whether it applied or
was refused, and the bar reads that instead of compiling it again.
