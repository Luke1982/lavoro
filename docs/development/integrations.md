# Integrations: one funnel for outside systems

Lavoro exchanges customers, materials, ledger accounts and werkbonnen with
bookkeeping systems
(SnelStart, e-Boekhouden.nl, Business Central, Exact Online, AFAS Profit,
Moneybird). All of them go through one layer in `app/Domain/Integrations`, the way Laravel puts mail
behind a mailer: the rest of the app says "sync customers" or "send this
werkbon", and a **connector** translates that to one system's API.

## The parts

| Part | Where | What it is |
|---|---|---|
| Connector | `Connector`, `Connectors/<System>/` | Translates the standard shapes to one API and back. Knows nothing about models, links or sync rules. |
| Capabilities | `Capability`, `Contracts/` | What a connector can do. Each capability is an interface; a connector has it by implementing it. |
| Standard shapes | `Data/` | `CustomerData`, `MaterialData`, `LedgerAccountData`, `SalesDocumentData` (+ `SalesLineData`), `ExternalRecord`, `PushedDocument`, `ChangeNotice`. |
| Registry | `Connectors` | Every connector by key. Every customer can use every connector: a connector is not a module. |
| Connection | `IntegrationConnection` (tenant DB) | One system a customer uses: encrypted credentials, per-area settings, last sync, last error. One per connector. |
| Link | `Integrationable` / `integrationables` | Which record here is which record there, plus a fingerprint per field of both sides at the last sync. |
| Run log | `IntegrationRun` | Every pull, and every push that did something or failed. Shown on the settings page, pruned after 90 days. |
| Engine | `Sync/Synchronizer`, `Sync/Merge`, `Sync/*Mapping` | The sync rules, once, for every connector. `Merge` is the per-field decision, pure and unit-tested. |
| Werkbonnen | `Sync/ServiceOrderSender` | "Send to the administration": to every connection that takes werkbonnen. |
| Webhooks | `Sync/Webhooks`, `IntegrationWebhook`, `Central\IntegrationWebhookLookup` | Subscribing, renewing and receiving change notices. |

## Capabilities

| Capability | Contract | SnelStart | e-Boekhouden | Business Central | Exact Online | AFAS Profit | Moneybird |
|---|---|---|---|---|---|---|---|
| Login | | keys | codes | app in customer's Entra | OAuth, MajorLabel's app | token | OAuth, MajorLabel's app |
| Customers read | `PullsCustomers` | full | full | delta | delta | delta | delta |
| Customers write | `PushesCustomers` | yes | yes | yes | yes | no | yes |
| Materials read | `PullsMaterials` | full | full (active only) | delta (not blocked) | delta (sellable, not ended) | delta (not blocked) | full |
| Ledger accounts read | `PullsLedgerAccounts` | no | full (category VW, costs included) | delta (Income, posting, direct posting, not blocked) | delta (type 110 revenue, not blocked) | no | full (active revenue, allowed on sales invoices) |
| Ledger accounts write | `PushesLedgerAccounts` | no | no | no (API is read-only) | yes, as revenue account | no | no (needs an RGS code) |
| Line on its account | `SalesLineData::$ledger_account_id` | no | by number (`TegenrekeningCode`) | lines without item (`Account` line) | invoice lines (`GLAccount`) | no | all lines (`ledger_account_id`) |
| Werkbon out | `ReceivesSalesDocuments` | sales order | draft, final invoice | sales order, draft, posted invoice | sales order, draft, processed invoice | sales order | draft, sent invoice |
| Webhooks | `ReceivesWebhooks` | no | no | customers, items | customers (`Accounts`), items (with app webhook secret) | no | contacts, products |

Every area a connection reads is compared in full every night: the only way
to notice a record removed there, and the safety net under webhooks. A system
with `ChangeDetection::Delta` ("what changed since") is also asked every 15
minutes. Webhooks are a capability of their own, not a way of detecting
changes: they only make a change arrive sooner.

## Ledger accounts

`LedgerAccount` holds the revenue accounts ("8000 Omzet") materials and products
are booked on (`ledger_account_id` on both). It is a synced area like customers
(`Area::LedgerAccounts`, `LedgerAccountMapping`, matched by code), so the
connection's read, write and "who wins" switches apply to it unchanged. An
account can be made here (`Koppelingen → Grootboekrekeningen`, under
`integration.manage`) or fetched; one made here gets a number, one from
Moneybird may lack it.

Each connector fetches only what a sales line can be booked on, as far as that
system tells (see the table). e-Boekhouden only tells balance sheet from profit
and loss, so its cost accounts come along and the person picks. Writing is only built for Exact: its
API documents the fields of a new revenue account. Business Central's account
API is read-only, Moneybird requires an RGS code per new account, and
e-Boekhouden's `AddGrootboekrekening` takes a group whose values are not
documented. SnelStart and AFAS have no ledger capability: their lines are
booked through the article.

On a werkbon, `ServiceOrderSender` puts the material's account on the line as
its id in that system (`SalesLineData::$ledger_account_id`), when the
connection exchanges accounts and the account is linked there; an account that
is not linked there is named in a warning. A connection that no longer
exchanges accounts gets none: the field is hidden then, and a choice nobody can
see must not book anything. Without one, each system books the line as it
always did: on the article's account, or the connection's default. An item line
in Business Central is booked by its posting groups and takes no account; an
Exact sales order line has no account field, so only invoices carry it.

The field on materials and products shows only while an active connection
exchanges ledger accounts (`LedgerAccount::inUse()`; `choices()` returns null
otherwise), and so do the link on that connection's card and the menu entry:
`"feature": "ledger_accounts"` in `menu.json`, which `useMenu` checks against
the `features` prop that `HandleInertiaRequests` shares. Products carry the account for when
products themselves are invoiced: a werkbon sends materials only, so nothing
reads a product's account yet. An account still chosen on a material or
product cannot be removed (`LedgerAccountController::destroy`, and a
restricting foreign key).

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

Nothing talks to the other system inside a database transaction: a pull
fetches everything first and only then takes it in one transaction. A token
renewed on the way is saved at once; inside the transaction a rollback would
throw away the only refresh token Exact still accepts.

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
  you wait (the buttons on the customers, materials and ledger account lists,
  and "Nu ophalen").
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

## Connecting through a login (OAuth)

Exact Online and Moneybird are connected by logging in there: the connector
implements `ConnectsWithOAuth`, asks for no credentials, and the card shows
"Koppelen met …". The app on the other side is MajorLabel's own, one
registration for every customer, so its id and secret are in config
(`services.exact`, `services.moneybird`); what is per customer are the tokens,
stored encrypted with the connection's credentials.

- `IntegrationOAuthController::start` changes nothing: it keeps a random
  state with the connector key and the tenant in the session (one entry per
  state, so two logins in two tabs do not trip over each other) and sends the
  person off. `callback` takes that entry by the state it gets back -- the
  customer comes from the session, never from the request -- exchanges the
  code, lets the connector pick the administration or division with the new
  tokens, and only then makes or saves the connection. A login that does not
  come back complete leaves nothing behind. That save starts the first full
  pull and the webhooks.
- One return address for every system and customer:
  `https://<app domain>/integrations/oauth/callback`. That is what is
  registered with Exact and Moneybird.
- `OAuthTokens` renews a token shortly before it runs out, under a lock per
  connection, and re-reads the tokens once it has the lock. Exact hands out a
  new refresh token on every renewal and kills the old one; two workers
  renewing at once would otherwise leave one with a dead token. A refresh
  token Exact has not seen for 30 days dies too, which the nightly sync
  prevents.
- A refusal in the callback is shown on the Koppelingen page, not "back":
  back would be the other system's login page. A 429 or 5xx from a token
  address is `IntegrationUnavailable` (try later), not "connect again".
- Saving the settings only rewrites the credentials when one was filled in:
  encryption makes every write differ, and writing them back as read would
  restore a refresh token that was renewed meanwhile.

The administration, division or company a connection works in is a scope
option (`Field::$scope`). Every link is an id within it, so it cannot change
while anything is linked: another one is a new connection. A webhook
remembers the scope it was made for (`IntegrationWebhook::$scope`, from
`ReceivesWebhooks::webhookScope()`) and is made again when that differs.

### Registering MajorLabel's apps

**Exact Online** (once, for NL):
1. Log in to the Dutch Exact Online App Center with MajorLabel's Exact
   account. That user needs Exact's "Manage apps" right; an app that connects
   other companies' administrations generally needs a developer subscription.
2. Under "Manage my apps", register an app ("Lavoro") with redirect URI
   `https://<app domain>/integrations/oauth/callback`: exactly that, HTTPS
   (Exact refuses http and localhost).
3. Copy the Client ID and Client secret -- the secret may be shown only once --
   and the app's webhook secret into `EXACT_CLIENT_ID`, `EXACT_CLIENT_SECRET`
   and `EXACT_WEBHOOK_SECRET`, then `config:cache` and restart the workers.
   Without the webhook secret Lavoro asks for no webhooks and relies on the
   quarter-hourly changes.
4. Until Exact has reviewed the app it only works for MajorLabel's own
   administrations; submit it for review in the App Center before customers
   connect. An app registered for NL cannot connect administrations in another
   country.

**Moneybird**:
1. Log in at moneybird.com with MajorLabel's account and register an external
   application ("Lavoro") at moneybird.com/user/applications/new.
2. Callback URL: `https://<app domain>/integrations/oauth/callback`, exactly
   (protocol and trailing slash count).
3. Copy the Client ID and Client secret into `MONEYBIRD_CLIENT_ID` and
   `MONEYBIRD_CLIENT_SECRET`, `config:cache`, restart the workers. Lavoro asks
   for the scopes `sales_invoices settings`.

**AFAS Profit** has no app to register: each customer makes an app connector
in Profit and gives Lavoro a token (see the manual, chapter Koppelingen).

## AFAS Profit

Profit has no standard GetConnectors, so the customer defines two and gives
their fields the ids Lavoro reads (`AfasConnector::DEBTOR` and `ARTICLE`, plus
`DebtorId`, `Blocked` and `ModifiedDate`). "Test" checks both through
`/metainfo/get/<name>` and names every missing field id: a missing field would
otherwise read as empty and empty it in Lavoro. Customers are not sent to
AFAS: a debtor there carries mandatory parts (payment terms, VAT duty, a postal
address) that are the administration's to decide. A werkbon goes out as a
sales order through `FbSales` with article lines (`VaIt` 2, `ItCd`, `QuUn`,
`Upri`). Profit explains a refusal in the base64 header `X-Profit-Error`; a
500 from Profit is such a business-rule refusal, not an outage.

## Adding a connector

1. A class extending `Connector` in `Connectors/<System>/`, with `key()`
   (stored, never rename it), `label()`, `description()`,
   `credentialFields()`, optional `optionFields()`, and `test()`.
2. Implement the contracts it can honour. Override `customerFields()`,
   `materialFields()` or `ledgerAccountFields()` when it carries fewer fields.
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

- Materials out (`PushesMaterials`), products on invoices, quotes, and payment status
  (e-Boekhouden's `GetOpenPosten` and Business Central's customer ledger would
  feed it).
- Business Central on-premises: other login, other address per customer.
- Customers to AFAS (`KnSalesRelationOrg`), and the werkbon reference on an
  AFAS order: the field codes are not confirmed in AFAS's public pages.
- Twinfield, Yuki, Visma eAccounting.
- VAT per material: every line goes out at 21%, as the SnelStart send always did.
- A material's ledger account taken from its article (Exact's `GLRevenue`,
  Moneybird's product `ledger_account_id`): the account is chosen in Lavoro.
- Webhooks for ledger accounts: they change seldom, and the nightly pull (and
  Exact's and Business Central's 15-minute delta) keeps them current.
