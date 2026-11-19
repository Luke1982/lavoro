# Integrations: one funnel for outside systems

Lavoro exchanges customers, materials and werkbonnen with bookkeeping systems
(SnelStart, e-Boekhouden.nl, Business Central). All of them go
through one layer in `app/Domain/Integrations`, the way Laravel puts mail
behind a mailer: the rest of the app says "sync customers" or "send this
werkbon", and a **connector** translates that to one system's API.

## The parts

| Part | Where | What it is |
|---|---|---|
| Connector | `Connector`, `Connectors/<System>/` | Translates the standard shapes to one API and back. Knows nothing about models, links or sync rules. |
| Capabilities | `Capability`, `Contracts/` | What a connector can do. Each capability is an interface; a connector has it by implementing it. |
| Standard shapes | `Data/` | `CustomerData`, `MaterialData`, `SalesDocumentData` (+ `SalesLineData`), `ExternalRecord`, `PushedDocument`, `ChangeNotice`. |
| Registry | `Connectors` | Every connector by key. Every customer can use every connector: a connector is not a module. |
| Connection | `IntegrationConnection` (tenant DB) | One system a customer uses: encrypted credentials, per-area settings, last sync, last error. One per connector. |
| Link | `Integrationable` / `integrationables` | Which record here is which record there, plus a fingerprint per field of both sides at the last sync. |
| Run log | `IntegrationRun` | Every pull, and every push that did something or failed. Shown on the settings page, pruned after 90 days. |
| Engine | `Sync/Synchronizer`, `Sync/Merge`, `Sync/*Mapping` | The sync rules, once, for every connector. `Merge` is the per-field decision, pure and unit-tested. |
| Werkbonnen | `Sync/ServiceOrderSender` | "Send to the administration": to every connection that takes werkbonnen. |
| Webhooks | `Sync/Webhooks`, `IntegrationWebhook`, `Central\IntegrationWebhookLookup` | Subscribing, renewing and receiving change notices. |

## Capabilities

| Capability | Contract | SnelStart | e-Boekhouden | Business Central |
|---|---|---|---|---|
| Customers read | `PullsCustomers` | full | full | delta |
| Customers write | `PushesCustomers` | yes | yes | yes |
| Materials read | `PullsMaterials` | full | full (active only) | delta (not blocked) |
| Werkbon out | `ReceivesSalesDocuments` | sales order | draft invoice, final invoice | sales order, draft invoice, posted invoice |
| Webhooks | `ReceivesWebhooks` | no | no | customers, items |

Every area a connection reads is compared in full every night: the only way
to notice a record removed there, and the safety net under webhooks. A system
with `ChangeDetection::Delta` ("what changed since") is also asked every 15
minutes. Webhooks are a capability of their own, not a way of detecting
changes: they only make a change arrive sooner.

## Two-way sync

Every link keeps `local_fingerprints` and `remote_fingerprints`: per field, a
hash of the record's standard shape on each side at the last sync, over only
the fields that connector carries (`Connector::customerFields()`). Case and
spacing don't count as changes, and no data is stored twice: only hashes.
`Merge::decide()` compares them per field:

- a field only they changed is taken;
- a field only we changed is kept, and sent when the connection writes;
- a field both changed is settled by `Leading`, per area (`external` or
  `lavoro`), unless both ended on the same value;
- without a last sync (a record seen for the first time, or a link migrated
  from the old `snelstart_id`), every field that differs counts as a clash.

So Lavoro changing a city and the bookkeeping changing a phone number both
survive. A change we just took is equal on both sides afterwards, so it is
never sent back: no loop guard is needed. A kept field holds on to its old
baseline, so the send that follows still sees it as ours.

Before sending, when the connection also reads the area, their version is
fetched first; if it moved, the two are merged (`take()`), and what remains of
ours is sent from there.

A record seen for the first time is matched to an unlinked record here before a
new one is made (`Mapping::MATCH_ON`): customers by KvK number, VAT number, or
name plus postcode; materials by code. Only a single exact match counts, and
an unconfirmed link (a made-up SnelStart id) does not stand in the way.

The other way round, nothing new is sent to a connection that reads the area
until that area has been pulled in full once (`Synchronizer::pulledInFull`):
otherwise a customer that is already there would be created a second time.
`StartFirstPull` runs that pull as soon as a connection starts reading; a send
before it finishes gets `IntegrationUnavailable` and is retried.

After a complete pull, a link whose record was not in it is either dropped
(`confirmed_at` null: it never existed there, like the made-up SnelStart ids
from before) or gets `missing_at` (it was removed there; the record is left
alone from then on). A send that finds the record gone (`RecordNotFound`)
marks it the same way. An empty answer marks nothing: a system that suddenly
has no records at all is likelier broken than emptied.

A pull runs in one transaction, so a failure halfway changes nothing. What it
sends out goes on the queue and only leaves after the commit.

Syncs of one connection and area never run side by side (`Synchronizer::exclusively`,
a cache lock): otherwise a customer created there could come back through its
webhook before its link is saved, and be made here twice. The lock is
re-entrant within one process, because a pull may send on through a queue that
runs jobs at once. A send that waits too long gets `IntegrationUnavailable`
and is retried.

## How things flow

- **Scheduled:** `routes/console.php` dispatches `SyncIntegrationsJob` per
  tenant nightly at 03:00 (every area, in full) and every 15 minutes (delta
  areas only, changes only). It dispatches one `PullAreaJob` per connection
  and area.
- **By hand:** `POST /integrations/{id}/pull/{area}` runs the same pull while
  you wait (the buttons on the customers and materials lists, and "Nu
  ophalen").
- **Out:** `SendChangesToIntegrations` listens to `ModelChanged` and queues a
  `PushRecordJob` per connection that writes that area. Nothing that saves a
  customer needs to know a linked system exists.
- **Werkbon:** `POST /serviceorders/{id}/send-to-administration` →
  `ServiceOrderSender`, synchronous, so the person reads the result, and under
  a lock per werkbon with the checks repeated inside it, so a second press
  cannot book a second invoice. It raises `ServiceOrderSentToAdministration`
  (`integration.serviceorder_sent`) per system; `RecordNumberOfFinalInvoice`
  turns a final invoice's number into the werkbon's external invoice number,
  which raises `ServiceOrderInvoiceRecorded`.
- **Webhooks:** `KeepWebhooksInStep` subscribes and unsubscribes when a
  connection's settings change; `RenewIntegrationWebhooksJob` renews hourly.
  The address is `/integrations/webhook/{token}`. The token's hash is looked up
  in the central `integration_webhook_lookups` table to find the tenant;
  nothing else in the request is trusted. Each notice becomes a
  `PullRecordJob`, which fetches the record itself. The route is limited per
  token (`integration-webhooks` in `AppServiceProvider`), not per address:
  Business Central calls from Microsoft's shared addresses.

The run log tells its kinds apart (`IntegrationRun::PULL`, `PULL_CHANGES`,
`NOTICE`, `PUSH`): only full and changes-only pulls count as "the last pull"
that a delta asks from, because one record fetched after a webhook says
nothing about the others.

## Errors

Connectors throw `App\Exceptions\Refusal` with what the other side said, so
the button, the run log and the settings page all show the reason.
`IntegrationUnavailable` (a refusal too) means "unreachable, 429 or 5xx": jobs
retry it later instead of giving up. `RecordNotFound` means the record is gone
there. `IntegrationRun::track()` and `fail()` write a failure to the run and to
`last_error` on the connection. Only refusals are caught on purpose; a fault in
our own code always surfaces.

## Business Central

The customer registers an app in its own Microsoft Entra tenant, adds it in
Business Central under *Microsoft Entra-toepassingen* with the permission sets
it needs, and enters tenant id, client id and secret. Lavoro logs in with OAuth
client credentials (`BusinessCentralApi`), so it works from a queue at night.
Options: the environment (`production` by default) and the company id; "test"
without a company lists the companies.

- Paths are `/v2.0/{tenant}/{environment}/api/v2.0/companies({id})/...`. Ids
  are pasted into them only after a GUID check. `@odata.nextLink` is only
  followed within the same environment, so the token never goes elsewhere.
- A werkbon is a header, then its lines one by one, then for a final invoice
  `Microsoft.NAV.post`. If a step before posting fails, the header is deleted
  again; if that fails too, the refusal says something was left behind.
- Webhooks: `POST /subscriptions` with the webhook token as `clientState`;
  renewal reads the subscription for its ETag and PATCHes it. Business Central
  first calls the address with `?validationToken=`, which `handshake()` echoes.
  Notices with a different `clientState` are ignored; `collection` means "pull
  what changed in the whole area".

## Adding a connector

1. A class extending `Connector` in `Connectors/<System>/`, with `key()`
   (stored, never rename it), `label()`, `description()`,
   `credentialFields()`, optional `optionFields()`, and `test()`.
2. Implement the contracts it can honour. Override `customerFields()` /
   `materialFields()` when it carries fewer fields.
3. Add it to `Connectors::BUILT_IN`.
4. Validation rules on any field that ends up in an address (`Field::$rules`).
5. A test against a fake of its API, like `BusinessCentralConnectorTest`.
   `ConnectorContractTest` checks the rest for every connector by itself: stored
   keys, fields, capabilities, defaults that pass validation.

The settings screen, validation, sync, scheduling and run log pick it up from
there.

## Adding a capability

A case in `Capability`, an interface in `Contracts/`, and the code that uses it:
a `Mapping` for a synced area, or a sender like `ServiceOrderSender` for a
document. Validation and the settings screen read capabilities from the
connector, so a switch for something a connector can't do is never offered or
stored.

## Not built yet

- Materials out (`PushesMaterials`), products, quotes, and payment status
  (e-Boekhouden's `GetOpenPosten` and Business Central's customer ledger would
  feed it).
- Business Central on-premises: other login, other address per customer.
- VAT per material: every line goes out at 21%, as the SnelStart send always did.
