# The signal layer everywhere — design

Date: 2026-11-20. Status: approved ("we need to incorporate signal -> listener -> record activity as much as possible") and being built.

Calls marked **(my call)** were made while writing this and have not been confirmed by the product owner.

## Intent

Every change a person or the system makes to a customer's records is announced as a signal. `RecordActivity` writes it to the trail, and the trail is read in two places: the timeline on a record's page, and the assistant's `search_activity`. A change that is not announced does not exist for either. "Who changed this, and what was it before?" then gets the answer "I don't know", which the user can't tell apart from "nothing happened".

An audit of the whole application on 2026-11-20 found that the layer's core was sound but its reach was not. The gaps had five causes:

1. **Attached to nothing.** History about records without a timeline (machines, tasks, inspections, ledger accounts, connections, and anything hard-deleted) was written but attached to nothing, so nobody could read it.
2. **No history.** Whole areas never had any: users and roles, company, settings, standard e-mails, contacts, locations, products, suppliers.
3. **Writes that skip model events.** Pivot writes, query-builder writes and quiet saves changed records in silence.
4. **Side effects in controllers.** Controllers still did the follow-up work themselves (files, Google, flags, statuses).
5. **Double logging.** Some named signals and the generic trail reported the same field.

## Goals

- Every business change on a tenant's records leaves an entry that is attached to a record with a timeline, and that a person and the assistant can read there.
- Generic history lands on the records a person would look on: a machine's on the machine and its customer, a task's and an inspection's on the work order (an inspection's also on the machine), and the creation and removal of a work order, project, contract, ticket or quote also on the customer (and project). An entry that hangs off another record names what it is about, by the name it had at that moment.
- Relation and bulk changes are recorded, either by saving through Eloquent or by a named signal.
- Consequences live in listeners. Consequences that leave the database run after commit.
- Each field is logged once.
- The assistant can read the history of every record type that has a timeline, within the same permissions as the screens.
- Guards that fail when any of this regresses.

## Non-goals

- Landlord / central data (`app/Models/Central`, `/beheer`). It has no tenant trail.
- Technical tables: tokens, device registrations, location pings, push subscriptions, reminders bookkeeping, assistant transcripts and usage, tour progress, Google mapping rows, `activityables` themselves.
- Admin setup records with no screen to show history on (task templates, checks, checklists, macros, product types, brands, units, lost reasons, quote templates). They are recorded where cheap, but get no timeline of their own. **(my call)**
- A trail of who viewed what.
- A home for the removal of a standalone setup or catalogue record: a product without suppliers or parts lists, a supplier without links, a template without a formula, a plan group, an event type. Its "Verwijderd" entry is written, but there is no other record whose timeline it naturally belongs on. **(my call)**

## Anti-goals: failures even if everything technically works

- A super admin (MajorLabel's own account or role) appearing anywhere in a customer's trail.
- A secret setting value (API keys, passwords, tokens) written into the trail. "Changed" is all the trail may say.
- Sensitive values (purchase prices, retail prices, IBAN, home address) readable by someone without the permission the screen asks for.
- A change shown twice, or a timeline flooded with every edit of every work order on the customer.
- A consequence that used to happen no longer happening, or happening before the commit (a mail or a Google push for a save that rolls back).
- A deleted record whose history disappears with it.

## Design

### Where generic history goes

`RecordsHistory` gains two overridable methods:

- `historyTimelines(string $action): array`. The other records this model's history also belongs on. `ModelChanged::activityContext()` returns them, and `RecordActivity` attaches the entry to each of them that has a timeline and still exists (deduplicated). The action is passed in so a model can put its creation and removal on a wider timeline than its edits.
- `historyLabel(): ?string`. What the record is called on another record's timeline. It is copied into `metadata.subject_label` at the moment of the change. `TimelineComponent` shows it under the entry when the entry's subject is not the page's own record (prop `subject-type`), with a link when the record still exists. `search_activity` returns it as `about`.

| Model | Timelines | Label |
| --- | --- | --- |
| Asset (now has its own timeline + page section) | resolved customer | brand model (serienummer …) |
| ServiceJob | work order and machine (creation: machine only, `ServiceJobAdded` tells the order) | Keuring machine … |
| ServiceOrderTaskInstance | work order | Taak "…" |
| ServiceOrder | created/deleted: customer, project | Werkbon WB-0042 |
| Project, MaintenanceContract, Ticket | created/deleted: customer | Project / Contract / Storing … |
| Quote | created/deleted: customer, project | Offerte … |
| Contact, Location, and the models added below | their customer(s) / owner | … |

A deleted record's own pivot rows go with it (some `deleting` hooks remove them deliberately). Its "Verwijderd" entry now survives on the records it names.

A migration (`2026_11_22_100000_attach_orphaned_history`) attaches existing entries the same way. It skips entries whose only changes are fields a named signal already tells, and it names task and inspection entries on the work order.

### Who may read it

A field gated with `$activity_permissions` keeps its gate. A model whose very existence is sensitive also declares `historyPermission()`, used for every entry that has no gate of its own. Quote (`quote.read`), MaintenanceContract (`maintenancecontract.read`) and Project (`project.read`) do this, because their creation and removal land on the customer's timeline, and someone who may open the customer but not its quotes must not learn that a quote exists.

### Volume

Past the per-request ceiling, `Signals` skips every listener, not only the trail. Work that is large by nature runs inside `Signals::asBatch()`, which gives it a queued job's ceiling for its duration, while the chain still catches loops. That covers a by-hand bookkeeping pull, the product bulk edit and deleting a location. Upgrading contracts to a new formula version already counts each chunk of contracts as its own unit of work (`LinkContractPriceFormulaAction::executeMany`). `RecordActivity` attaches an entry to all its timelines in one insert (in chunks of 1000), so a fact that lands on thousands of products costs one query, not thousands.

### Values

Dates read `01-01-2026`, and moments are converted from UTC to local time (`Clock::toLocal`) before formatting. Foreign keys read as names through `$activity_relations`. Sensitive fields are gated with `$activity_permissions`, and the gate must match the permission the screen uses.

### One source per field

A field reported by a named signal is in the model's `$activity_ignore` and in the signal's `coveredFields()`, but only when every path that changes the field raises that signal. Otherwise the generic entry stays and the named signal adds the sentence, or is silent (`ServiceOrderInvoiceRecorded`, `ContractCreated`: the generic entry is the complete record).

### Areas

Each area follows the same rules: history where there is a screen, named signals for relation changes and named facts, consequences in listeners.

- **Work orders and tasks.** Contract unlink (replaces the last hand-written `logActivity`, which is removed from `HasActivities`: an entry written by hand skips every rule of the layer), customer change (bug: wrong previous customer), materials (all through `Signals::dispatch`, stock once, lines of a deleted task announced), inspections completed/reopened (the machine's next service date moves in a listener), inspection results per checkpoint, "sent to customer" flags in a listener, stages (history, deleting a stage in use is refused, flag moves recorded), task visibility, bundle quantities, extra checks, tickets of a deleted work order, tickets without a machine.
- **Customers, machines, contracts, tickets, documents.** Contacts and locations (history, link/unlink signals, delete through Eloquent), ticket bulk status per ticket, ticket and contract facts on the customer, documents attached/changed (file delete after commit), remarks added, images renamed/replaced/imported (and `ImageRemoved` subject from the image, not the client), custom field values, `ContractFieldChanged` removed in favour of the generic trail, machine transferred / attached to / detached from a parent, child machines deleted through Eloquent, ticket status after a customer upload or info request in listeners.
- **People and planning.** Users (history, roles, password changed without values, home address gated), roles (members, permissions), appointment crew and customers, Google: appointment imported, change reverted, pushes in an after-commit listener, standard e-mail sent (metadata the resend button reads), company (logos, main company), settings (`SettingChanged`, secrets masked, counters skipped), standard e-mails and attachments, calendar access granted/revoked (Google sync after commit), unavailability, plan groups, plan roles, event types, announcement recipients.
- **Catalog, quotes, bookkeeping.** Product history (prices gated), bulk edit per product, brand delete refused while it has products (the database would cascade through products into customer machines), product components, supplier links, suppliers, materials' category and unit by name, signed quote confirmation mailed, quote links withdrawn in one listener, records linked to / gone from a bookkeeping system.

### Security fixes found on the way

- `ImageStoreRequest`, `ImageImportFromUrlRequest` and `ImageSetMainRequest` took `imageable_type` from the client, and the controller instantiated it. They now accept only `Image::ATTACHABLE_TO`.
- `ImageSetMainRequest` authorized the photo but not the record it named, so anyone allowed to edit one photo could clear the main photo of any record. The record must now be one the photo hangs off.
- Reordering and deleting a checkpoint's answers had no Form Request at all, and adding or changing them allowed everyone. All four now require `servicecheck.update`.
- Deleting a product, editing or deleting a brand, deleting a storing (`ticket.delete`), deleting a material (`material.delete`, refused while it is booked on a werkbon) and deleting a plan group had no authorization at all; the supplier links on products and materials authorized and validated inside the controller. All now go through Form Requests.
- `DocumentStoreRequest` and `RemarkCreateRequest` instantiated a client-named class like the image requests did; they now accept only `Document::ATTACHABLE_TO` / `Remark::ATTACHABLE_TO` (one `AttachableType` rule), and a remark needs sight of the record it is placed on.
- `CalendarGrantStoreRequest` accepted any user id, including MajorLabel's accounts and people who have left; it now only accepts users the customer can see.
- Not fixed here: 29 of the 399 Form Requests return `true` from `authorize()`. Some are deliberate (public pages, token links). The rest need their own review, because gating inspection screens wrongly would lock technicians out.

### The assistant

`search_activity` gains a subject type for every new timeline. Each is gated like its screen:

| Type | Model | Gate |
| --- | --- | --- |
| materiaal | Material | `material.read` |
| grootboekrekening, koppeling | LedgerAccount, IntegrationConnection | `integration.manage` |
| leverancier | Supplier | `viewAny` Supplier |
| contractsjabloon | MaintenanceContractTemplate | `viewAny` template |
| fase | ServiceOrderStage | `serviceorderstage.read` |
| medewerker | User | `viewAny` User, and only users the asker can see (`HidesSuperAdmins`), deleted ones included |
| rol | Role | `assignRoles` |
| standaardmail, standaardbijlage | StandardEmail, StandardAttachment | `manage` |
| plangroep | UserPlanGroup | `manage` UserPlanGroup |
| planrol | UserRole | `viewAny` UserRole |
| afspraaktype | EventType | `eventtype.read` |
| bedrijf, instelling | Company, GeneralSetting | admin |

Contracts get the visibility branch they were missing. An entry's `about` is its `subject_label`. The tool budget in `ToolSchemaBudgetTest` grows once for this (41000 → 41200), with its reason.

Work done by a super admin is credited to "MajorLabel" (`BaseSignal::MAJORLABEL`), not to the person: the customer sees that support did it, but never the account, which is hidden from them everywhere else. **(my call)** The same goes for anyone signed in who is not one of the customer's users (the panel's landlord account), who also keeps no `actor_id`, because that id would point at an unrelated user. A migration (`2026_11_22_100100_credit_super_admin_work_to_majorlabel`) credits older entries the same way, and `TenantSuperAdmins::adopt()` does it for a user who becomes a super admin. Who it was stays known through `user_id`.

## Guards

- `HistoryHasAPlaceTest`: every model with history has a timeline or `historyTimelines()`; every model that lands on another timeline has a label; every signal that writes an entry is about a record with a timeline or names one in `activityContext()`.
- `NoSilentWritesTest`: every pivot write, query-builder write, `DB::table()` write and quiet save in `app/` either sits in a method that raises a signal, or is listed in `ACCOUNTED_FOR` with the reason it needs none. A stale entry also fails.
- `ActivityLabelsTest`: now covers every model with history (found, not listed).
- `NoDoubleLoggingTest`, `SignalShapeTest`, `SignalContractTest`: unchanged rules, over the larger catalogue.

## Open / deferred

- Pulled changes (Google, bookkeeping) and imports are credited to "system". An "acting for" context, like the assistant's, would credit the person or calendar owner with `via` set. It needs a change to `BaseSignal`. **(my call: deferred)**
- `LinkContractPriceFormulaAction` reprices directly rather than through a listener. A listener would change behaviour (it also reprices ended contracts when nothing changed). Left as is.
