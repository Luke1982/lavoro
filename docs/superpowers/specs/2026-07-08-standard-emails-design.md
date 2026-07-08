# Standard e-mails design

## Goal

Let admins define reusable e-mail templates ("Standaard e-mails") with a subject, a rich-text
body (TipTap), and a set of reusable attachments ("Standaard bijlagen"). Templates can be sent
to an event's customer either manually (from the event edit modal, or as a resend from history)
or automatically based on configurable triggers tied to the event lifecycle (created / updated /
deleted), with per-trigger control over how much user interaction is required before sending.

## Data model

### New tables

- `standard_emails` (soft-deletes)
  - `name` string
  - `subject` string
  - `body` longtext (HTML produced by TipTap)
- `standard_email_triggers`
  - `standard_email_id` FK → `standard_emails`, cascade delete
  - `trigger` string enum: `event_created` | `event_updated` | `event_deleted`
  - `trigger_type` string enum: `background` | `confirm` | `allowedit`
  - A `StandardEmail` has zero or many triggers. The same e-mail can react to multiple
    lifecycle events, each with its own `trigger_type`.
- `standard_attachments` (soft-deletes)
  - `name` string
  - `path` string (storage disk path)
  - `original_filename` string
  - `mime_type` string
  - `size` unsigned integer (bytes)
- `standard_email_standard_attachment` (plain pivot, no extra columns)
  - `standard_email_id` FK, `standard_attachment_id` FK, both cascade delete

### No new "sent e-mail" table

`Event` gains the `HasActivities` trait (`app/Models/Traits/HasActivities.php`) — it doesn't use
it today; `ServiceOrder`/`Ticket` already do. Every actual send calls:

```php
$event->logActivity(
    "Standaard e-mail '{$standardEmail->name}' verzonden aan {$to}",
    metadata: [
        'standard_email_id' => $standardEmail->id,
        'trigger' => $trigger, // nullable, null for manual sends
        'to' => $to,
        'subject' => $renderedSubject,
    ],
);
```

The description contains "e-mail" + "verzonden", which the existing `logActivity` category
inference already buckets into `category: 'email'`. The event's "Verzonden e-mails" history and
"resend" both read `$event->activities()->where('category', 'email')`. Nothing about a pending
`confirm`/`allowedit` decision is ever persisted — it only exists as an in-memory list returned
in the same HTTP response as the event save, because `EventObserver` runs synchronously, before
the controller builds its response. If the user cancels, nothing is written anywhere.

### Enums (`app/Enums/`, using existing `EnumComboBoxArrayTrait` shape)

- `EventTrigger`: `event_created`, `event_updated`, `event_deleted`
- `StandardEmailTriggerType`: `background`, `confirm`, `allowedit`

## Backend flow

### Trigger evaluation

- `EventObserver::created/updated/deleted` (existing file, already dispatches `PushEventJob`)
  gains a call that queries
  `StandardEmailTrigger::where('trigger', $trigger)->where('trigger_type', 'background')->with('standardEmail')`
  and dispatches a new `SendStandardEmailJob` (ShouldQueue) per match — mirrors the existing
  `PushEventJob::dispatch($event->id)` pattern exactly. This is fire-and-forget, matching how
  Google Calendar sync already behaves from this observer.
- `EventApiController::store/update/destroy` separately query the same table filtered to
  `trigger_type IN ('confirm', 'allowedit')` and include the matches as
  `pending_standard_emails: [{ standard_email_id, name, trigger, trigger_type }]` in the JSON
  response. `destroy()` resolves this *before* the event is actually deleted, since it needs
  live event data to render placeholders later.
- Both the observer's background dispatch and the controller's pending-list query live in one
  new `App\Services\StandardEmailTriggerResolver` (`matching(Event $event, EventTrigger $trigger, array $types): Collection<StandardEmailTrigger>`)
  so the query logic isn't duplicated.

### Rendering / placeholders

New `App\Services\StandardEmailRenderer`:

- `placeholders(): array` — single source of truth for the 7 supported tokens, returned as
  `[{ token: '{{event_start_date}}', label: 'Startdatum' }, ...]`:
  - `{{event_start_date}}`, `{{event_start_time}}`, `{{event_end_date}}`, `{{event_end_time}}`
  - `{{event_name}}`, `{{event_location}}`
  - `{{customer_name}}`
  Exposed to the frontend via Inertia props on the StandardEmail create/edit page (for the
  TipTap Mention suggestion list).
- `render(string $html, Event $event): string` — plain `str_replace` of tokens with real values.
  Customer resolution matches the existing precedent in
  `EventApiController.php:128` / `:366`: `$event->serviceOrders->first()?->customer ?? $event->customers->first()`.
  Default recipient e-mail is that customer's `email` field (per your answer, shown editable in
  the UI with `invoice_email`/`quotes_email` as alternatives, main `email` pre-selected).

### Sending

- New `App\Mail\StandardEmailMail` (plain `Mailable`, same shape as `AppointmentConfirmationMail`):
  constructor takes rendered subject/body plus a `Collection<StandardAttachment>`; `build()` sets
  subject, renders a small wrapper Blade view (`resources/views/emails/standard_email.blade.php`)
  that echoes the body HTML, and attaches each `StandardAttachment` from storage.
- New `App\Jobs\SendStandardEmailJob` (ShouldQueue): loads event + template, renders via
  `StandardEmailRenderer`, sends via `Mail::send(new StandardEmailMail(...))`, logs the activity.
  Used both for `background` auto-sends and as the actual send step once a `confirm`/`allowedit`
  flow is approved by a user (called synchronously in that case so the UI gets immediate
  success/failure feedback, rather than queued).
- Attachments are always "fixed from template" — pulled fresh from the `StandardEmail`'s
  `standardAttachments()` relation at send time, never snapshotted per send.
- Placeholders are always re-rendered from live event data at send time — including for resend —
  never frozen/snapshotted.

### New API routes (`routes/api.php`)

- `GET /events/{event}/standard-emails` — active templates for the manual-send picker
  (`id`, `name`, `subject`).
- `GET /events/{event}/standard-emails/{standardEmail}/preview` — rendered `{ subject, body, to }`
  for populating the send/preview modal (used for manual send, `allowedit`/`confirm` follow-ups,
  and resend).
- `POST /events/{event}/standard-emails/send` — body: `standard_email_id`, `to`, `subject`,
  `body`, `trigger` (nullable — populated when this call resolves a pending `confirm`/`allowedit`
  match, null for manual sends and resends). Sends immediately and logs the activity.
- `GET /events/{event}/email-history` — past `category: 'email'` activities on the event, for the
  "Verzonden e-mails" list and resend.

All four are authorized by a new `EventSendStandardEmailRequest` calling
`$this->user()->can('update', $this->route('event'))` — reuses `EventPolicy::update()`
(`app/Policies/EventPolicy.php:32`) as-is. No new permission is introduced for this part.

### Standard e-mail / attachment CRUD (Instellingen)

- `app/Http/Controllers/Admin/StandardEmailController.php` — full resource controller
  (`index/store/update/destroy`), Inertia page `Admin/StandardEmails/IndexPage.vue`.
- `app/Http/Controllers/Admin/StandardAttachmentController.php` — full resource controller,
  Inertia page `Admin/StandardAttachments/IndexPage.vue`.
- New `App\Policies\StandardEmailPolicy` and `App\Policies\StandardAttachmentPolicy`
  (`viewAny/create/update/delete`, each `$user->isAdmin() || $user->hasPermission('standardemail.xxx')`
  / `'standardattachment.xxx'`). Form Requests (`StandardEmail{Store,Update,Destroy}Request`,
  `StandardAttachment{Store,Update,Destroy}Request`) call `$this->user()->can(...)` against these
  policies — never `hasPermission` directly, per existing convention.
- Permissions seeded: `standardemail.read/create/update/delete`,
  `standardattachment.read/create/update/delete`.
- Routes live inside the `admin` middleware group in `routes/web.php`, alongside
  `admin/settings`, since that's where "Instellingen" lives today.

## Frontend

- **Nav** (`resources/js/Layouts/MainLayout.vue`, both the mobile block ~L103-134 and desktop
  block ~L253-280): two new sibling `<Link>`s next to the existing Instellingen link —
  "Standaard e-mails" → `/admin/standard-emails`, "Standaard bijlagen" → `/admin/standard-attachments`.
  Same `isAdmin` gate as the existing block.
- **`Pages/Admin/StandardEmails/IndexPage.vue`**: table list (name, subject, active trigger
  badges in `lavoro-green`) + `ModalDialog`-based create/edit form:
  - `name`, `subject` (plain text input with an "invoegen" placeholder dropdown using `ComboBox`)
  - `body`: new `TipTapEditor.vue` component (`@tiptap/vue-3` + `@tiptap/starter-kit` +
    `@tiptap/extension-mention`, configured against `StandardEmailRenderer::placeholders()`
    passed down as a prop) — typing `{{` or clicking a toolbar button opens a suggestion popup;
    picking one inserts a Mention chip.
  - Repeatable triggers list: pairs of `ComboBox` (trigger, trigger_type), add/remove row buttons.
  - `StandardAttachments` multi-select via `ComboBox` (`multiple`).
- **`Pages/Admin/StandardAttachments/IndexPage.vue`**: list + upload form (name + file input),
  new `StandardAttachmentUploadComponent.vue` patterned visually on
  `DocumentUploadComponent.vue`'s upload/list/inline-rename interaction, but as a standalone list
  (no `documentableId`/polymorphic scoping — these are top-level templates).
- **New shared `TipTapEditor.vue`** (`resources/js/Components/UI/`): `modelValue` (HTML),
  optional `placeholders` prop — when present, Mention is enabled; when absent, plain
  `starter-kit` only. Used both by the admin template editor and the event-send preview editor.
- **`Components/Planner/EventEditModal.vue`**, new "E-mail" section, visible only once the event
  has an id (i.e. not during the initial create-before-save step):
  - "Verstuur e-mail" button → template picker (`ComboBox`, from `GET .../standard-emails`) →
    `GET .../standard-emails/{id}/preview` → opens shared `EmailPreviewModal.vue` (subject/to
    text inputs + `TipTapEditor` body, no `placeholders` prop since content is already rendered)
    → Send calls `POST .../standard-emails/send`.
  - "Verzonden e-mails" list (from `GET .../email-history`), each row with a "Opnieuw versturen"
    button that reopens `EmailPreviewModal` pre-filled from a fresh `preview` call (live
    re-render, per your answer) for that same `standard_email_id`.
  - After any create/update save, if the response carries non-empty `pending_standard_emails`,
    walk through them in order: `trigger_type: confirm` → a small read-only preview dialog
    (subject/body/to, Send/Cancel, no editing); `trigger_type: allowedit` → the same
    `EmailPreviewModal` as manual send. `background` entries never appear in this list — they're
    already queued server-side.
  - The planner page (wherever event `destroy` is called from) handles the same
    `pending_standard_emails` shape from the destroy response.

### Colors

- `lavoro-blue` (`#2563ff`) for primary actions (Save, Send, focus rings) — matches existing
  `GeneralSettingsPage.vue` conventions.
- `lavoro-green` (`#c6ff00`) as an accent for state badges — trigger `trigger_type` badges on the
  StandardEmail list, "actief" indicators.

## Out of scope / assumptions

- Multiple customers per event: not handled specially — resolution follows the existing
  single-customer precedent (`serviceOrders.first()?.customer ?? customers.first()`).
- No CC/BCC support in this iteration.
- No queueing for `confirm`/`allowedit` sends (synchronous, for immediate UI feedback); only
  `background` auto-sends are queued.
- No snapshotting: attachments and placeholder values are always resolved fresh at send time,
  including for resend.
