# Aanvullende informatie opvragen bij de klant (storingen) — design

## Goal

From a ticket, a colleague sends the customer an e-mail asking for extra information about the
fault. The mail carries a button to a public page in this app, opened by a one-time token, where
the customer uploads photos, video and documents and optionally types a note. Everything they
send lands on the ticket as images, documents and a remark. Colleagues who follow the ticket —
or who subscribe to the fact type in general — get a notification. Every step writes an activity.

The token machinery is deliberately generic: nothing in it knows what a ticket is, so the next
"send the customer a link to do X" feature adds an enum case and a controller, not a table.

## 1. Access tokens (generic subsystem)

### Table `access_tokens`

| column | type | notes |
|---|---|---|
| `tokenable_type` / `tokenable_id` | morph | what the link is about (`App\Models\Ticket`) |
| `purpose` | string | `AccessTokenPurpose` value, e.g. `ticket.customer_upload` |
| `token_hash` | string, unique | sha256 of the plaintext; the plaintext exists only in the mail |
| `recipient` | string, nullable | the address it was sent to; becomes the actor name on activities |
| `payload` | json, nullable | purpose-specific; here `{"requested":["photos","videos"]}` |
| `expires_at` | timestamp | |
| `last_used_at` | timestamp, nullable | |
| `use_count` | unsigned int, default 0 | |
| `revoked_at` | timestamp, nullable | |
| `revoked_by_id` / `created_by_id` | FK users, nullable, nullOnDelete | |

Indexes: unique on `token_hash`; `[tokenable_type, tokenable_id, purpose]` for "the links outstanding
on this record"; `expires_at` for a future prune command.

Only the hash is stored, so a database dump does not hand out working links. sha256 rather than
bcrypt because the token is looked up by its own value and carries ~285 bits of entropy — the
same reasoning Laravel applies to personal access tokens.

### `App\Enums\AccessTokenPurpose`

```php
case ticket_customer_upload = 'ticket.customer_upload';

public function label(): string;        // 'Klant levert informatie aan bij storing'
public function routeName(): string;    // 'public.ticket.upload'
public function ttlDays(): int;         // config('customerupload.token_days'), default 14
```

Adding a purpose is adding a case plus answering these three, exactly as `UserNotificationType`
works today.

### `App\Models\AccessToken`

```php
public static function issue(
    Model $tokenable,
    AccessTokenPurpose $purpose,
    ?string $recipient = null,
    array $payload = [],
): IssuedAccessToken;

public static function resolve(string $plaintext, AccessTokenPurpose $purpose): ?self;

public function isExpired(): bool;
public function isUsable(): bool;      // not revoked and not expired
public function markUsed(): void;      // last_used_at = now, use_count++
public function revoke(?User $by = null): void;
public function scopeOutstanding($query);
```

`issue()` returns a small `IssuedAccessToken` value object holding the row and the plaintext, with
`url()` on it — the URL can only be built at issue time, because afterwards only the hash is left.

Issuing a second link for the same record does **not** revoke the first. Both point at the same
record with the same rights, and silently breaking a link a customer is already looking at buys
nothing.

### Resolution: `ResolveAccessToken` middleware

```php
Route::get('storing/informatie/{token}', ...)->middleware('accesstoken:ticket.customer_upload');
```

The middleware hashes the route parameter, looks it up **scoped to the purpose named in its own
argument**, and substitutes the resolved `AccessToken` into the route so controllers type-hint the
model and never touch a raw token. Outcomes:

- no row, wrong purpose, or revoked → **404**. A 403 would confirm the link once existed.
- expired → the `Public/LinkExpiredPage` (HTTP 410), because whoever holds the token already had
  the mail and a bare 404 there just reads as "your app is broken".

Both the show and the upload route carry `throttle`, so the URL space cannot be swept.

## 2. Ticket status

`TicketStatusses` gains `wacht_op_klant = 'Wacht op terugkoppeling klant'`. Statuses are stored as
their string value, so there is nothing to migrate. Three places assume the old set of three and
must move with it:

- `TicketController::index()` counts open/pending/closed by hand and feeds four stat props. A
  fourth count and a fourth `StatCard` are added; without it the new status is in no card at all.
- The badge colour maps in `Pages/Tickets/ShowPage.vue` and `Pages/Tickets/IndexPage.vue`.
- `TicketObserver` clears `closed_on`/`closed_by_id` when a ticket leaves `Gesloten` — unchanged,
  it already keys on the literal `'Gesloten'`.

## 3. Requesting information (staff side)

### Permission and policy

Migration seeds `ticket.request_customer_info` ("Klant om aanvullende informatie vragen"), in the
established shape of `2025_10_08_000001_add_ticket_permissions.php`. `TicketPolicy` gains
`requestCustomerInfo(User $user, Ticket $ticket)`. The Form Request only calls `can()`.

### `TicketInfoRequestRenderer`

```php
public const REQUESTABLE = [
    'photos' => "foto's van de storing",
    'videos' => "video's van de storing",
    'other'  => 'andere aanvullende informatie',
];

public static function subject(): string;                  // 'Wij ontvangen graag extra informatie over uw storing'
public static function body(Ticket $ticket): string;       // greeting + paragraph, no list, no link
public static function defaultRecipient(Ticket $ticket): ?string;
public static function options(): array;                   // [{key,label}] for the toggle buttons
```

`body()` fills the customer name, the machine (brand + model, falling back to the asset name) and
the serial number into the agreed Dutch text. It renders **no list and no link**: the list is
composed in the modal, the link belongs to the mail template.

### Mail

`TicketInfoRequestMail(string $subject, string $body_html, string $upload_url)` renders
`resources/views/emails/ticket_info_request.blade.php`: company logo and name, the body as typed,
a table-based CTA button ("Informatie aanleveren") and the bare URL underneath for clients that
strip buttons.

Sent with `Mail::to($to)->send(...)` synchronously, so a refused address surfaces in the modal
rather than in a queue log. **The sent-folder copy needs no new code**: `CopyMailToSentFolder`
already listens on every `MessageSent`, skips itself unless `mail.default === 'smtp'`, and Graph
files its own copy.

Keeping the token out of the editable body is deliberate. The colleague cannot break, shorten or
paste-over the link, and no token has to be issued for a mail that is never sent.

### Routes and controller

```
GET  /api/tickets/{ticket}/info-request   TicketInfoRequestController@defaults
POST /api/tickets/{ticket}/info-request   TicketInfoRequestController@send
```

Under `auth:sanctum` in `routes/api.php`, matching how `EmailPreviewModal` already talks to
`EventStandardEmailController`.

`defaults()` returns `{to, subject, body, options, requested}` with `requested` pre-selected as
`['photos','videos']`.

`send()` validates `to`, `subject`, `body`, `requested[]` (each in `REQUESTABLE`, at least one),
then in one transaction:

1. `AccessToken::issue($ticket, ticket_customer_upload, $to, ['requested' => $requested])`
2. send the mail
3. `Signals::dispatch(new CustomerInfoRequested($ticket, $to, $requested, $expires_at))`
4. `$ticket->update(['status' => TicketStatusses::wacht_op_klant->value])`, which trips
   `TicketObserver` and so writes its own status activity and notifications

The posted `body` is TipTap HTML from an authenticated colleague holding a dedicated permission,
mailed as-is — the same trust level and the same treatment as the existing standard e-mails.

### Frontend: `Components/Tickets/InfoRequestModal.vue`

`ModalDialog` (the user asked for a modal explicitly; that outranks the house rule that new forms
live in a drawer). Contents: recipient, subject, three toggle buttons, `TipTapEditor`.

The toggles maintain a single `<ul data-info-request>` node inside the editor HTML. On every
change the node is rewritten from the selected labels and spliced back into the body — text the
colleague typed around it survives. Clicking an active toggle deselects it, per house rule; there
is no clear button. Deselecting the last one is allowed by the UI but the send button disables,
because the mail would then ask for nothing.

Button **Informatie opvragen** sits in the ticket header, left of *Werkbon openen*, behind
`hasPermission('ticket.request_customer_info')`.

## 4. The customer page

```
GET  /storing/informatie/{token}   CustomerUploadController@show
POST /storing/informatie/{token}   CustomerUploadController@store
```

Outside the `auth` group, both behind `accesstoken:ticket.customer_upload` and `throttle`.
`Public/TicketUploadPage.vue` with `EmptyLayout`, like the login pages.

Shows: company logo and name (the global `Inertia::share('company')` already reaches guests), the
machine and serial number, the checklist of what was asked for, a drop zone, an optional note
field, what this link already sent, and the expiry date. It never shows the ticket's description,
internal remarks, prices or colleagues' names — the page is a letterbox, not a customer portal.

`show()` refuses with a friendly page when the ticket is closed: uploading into a closed fault
helps nobody, and that check belongs to this purpose, not to the token system.

`store()`, per file, splits on kind:

| kind | lands as | extra |
|---|---|---|
| image | `Image` attached through `imageables` | downscaled synchronously |
| video | `Document` in category **Klantinformatie** | downscale queued |
| document | `Document` in category **Klantinformatie** | as-is |

The note becomes a `Remark` attached through `remarkables`. All of it is one submission and so
**one** activity and **one** notification, not one per file.

Files are stored under `uploaded/ticket/{id}/` (images) and `uploaded/ticket/{id}/documents`,
matching `ImageController`/`DocumentController`, but named `{random8}-{sanitised original}` so a
customer cannot overwrite an existing file or steer the path.

### Validation — `config/customerupload.php`

```php
'token_days' => 14,
'max_files'  => 10,
'note_max'   => 5000,
'image'    => ['max_kb' => 25_600,  'extensions' => [...], 'max_edge' => 1920, 'quality' => 82],
'video'    => ['max_kb' => 204_800, 'extensions' => [...], 'max_height' => 720, 'crf' => 28],
'document' => ['max_kb' => 51_200,  'extensions' => [...]],
'ffmpeg'   => env('FFMPEG_PATH', 'ffmpeg'),
'ffprobe'  => env('FFPROBE_PATH', 'ffprobe'),
```

Every value is env-overridable and none of it is sized to a development box; the spec notes that
production FPM/nginx must allow the largest of them (`upload_max_filesize`, `post_max_size`,
`client_max_body_size`).

A per-file `CustomerUploadFile` rule decides the kind from the extension, then applies that kind's
cap and checks the detected MIME group agrees (`image/*`, `video/*`, or the document list), so a
renamed binary is refused. Messages are Dutch and name the file and the limit.

## 5. Downscaling — `MediaDownscaler`

```php
public function image(string $absolute_path): string;   // returns the final path (may change extension)
public function video(string $absolute_path): string;
public function canProcessImages(): bool;
public function canProcessVideo(): bool;
```

- **Images, synchronous.** Imagick — already a dependency of `SerialNumberOcrService` and guarded
  the same way with `class_exists`. Auto-orient, strip metadata, long edge ≤ 1920, JPEG quality 82;
  HEIC/HEIF re-encoded to JPEG, which also renames the file. An 8 MB phone photo lands near 400 KB.
- **Video, queued** (`DownscaleVideoJob`; the queue is `database` and a worker runs). ffmpeg to
  H.264 720p, CRF 28, AAC 128k, `+faststart`, into a temp file; on success it replaces the stored
  file and corrects the `Document`'s `path`, `name` and `size`. Never scales a small clip up.
- On failure, or when Imagick/ffmpeg is missing, the original is kept and the reason logged. A
  fault report is worth more than a saved megabyte.

The service is wired into the customer flow only; the staff upload controllers are untouched.

## 6. Notifications

### Schema

`notification_subscriptions` gains a nullable `subscribable` morph, `type` becomes nullable, and
the unique key becomes `[user_id, type, subscribable_type, subscribable_id]`.

| row | meaning |
|---|---|
| `type='ticket.customer_uploaded'`, subscribable NULL | that fact, on every ticket |
| `type=NULL`, subscribable `Ticket#42` | every notifiable fact on ticket 42 |

MySQL treats NULLs as distinct in a unique index, so that key is a backstop only; the Form Request
enforces uniqueness with an explicit query that spells out the nulls.

Two MySQL constraints shape the order of that migration, neither of which SQLite enforces, so the
test suite cannot catch them: the morph index needs a hand-written name (the generated one is 66
characters, two over the limit), and the old `(user_id, type)` unique is the only index covering
the `user_id` foreign key — the replacement key, which also starts with `user_id`, has to exist
before it can be dropped.

### New `UserNotificationType` cases

- `ticket_customer_uploaded = 'ticket.customer_uploaded'` — "Klant leverde informatie aan"
- `ticket_status_changed = 'ticket.status_changed'` — "Storingstatus gewijzigd"
- `ticket_priority_changed = 'ticket.priority_changed'` — "Storingprioriteit gewijzigd"

The last two hang off signals that already exist; they earn their place because following a single
ticket is worth little if only one kind of fact ever reports on it. All three require `ticket.read`
at type level and carry the ticket's own priority where that reads naturally.

### Delivery — `NotifySubscribers::subscribersFor()`

The set becomes the union of two groups, both minus the actor:

- **type-level**: subscribed to this type with no record, gated on the type's
  `requiredPermissions()` exactly as today.
- **record-level**: subscribed to this signal's subject, with `type` null or equal, gated on
  whether the subject is still **visible** to them — resolved generically through the subject
  model's `visibleTo` scope when it defines one, falling back to `requiredPermissions()` when it
  does not.

Visibility rather than the global permission is the right gate for a followed record: a monteur
who can see a ticket through the werkbon they execute may follow that ticket, and must not need
the blanket `ticket.read` to hear about it. The check runs at delivery time, so a permission or an
assignment taken away stops the notifications without deleting the wish to have them.

### UI

A bell toggle in the ticket header ("Volg deze storing" / "Je volgt deze storing"), posting to the
existing `NotificationSubscriptionController` with `subscribable_type` and `subscribable_id`. The
`Ticket::show` payload gains `subscription_id` (null when not following). The general subscription
screen is unchanged — the three new types simply appear in it.

## 7. Activities

Two new signals, written to the ticket's timeline by the existing `RecordActivity` listener:

- `CustomerInfoRequested` — category `email`:
  *"Aanvullende informatie opgevraagd (foto's, video's) — verstuurd aan info@vandijk.nl"*,
  metadata `{to, requested, expires_at}`.
- `CustomerInfoUploaded` — category `attachment`, actor `customer`:
  *"3 foto's en 1 video aangeleverd via de informatie-aanvraag"*, metadata `{images, documents,
  has_remark, to}`.

`CustomerInfoUploaded` overrides the actor after `parent::__construct()`: `actor_type = 'customer'`,
`actor_name = "Van Dijk B.V. (info@vandijk.nl)"`, `actor_id = null`. The customer's name and the
address the link was sent to both appear, so the trail says who acted and through which link.

`TimelineComponent` prints `'Systeem'` for every actor type that is not `user`, which would hide
that name; it gains a `customer` case. `ai` deliberately keeps reading as the system.

## 8. Collateral changes

`remarks.user_id` is `NOT NULL` and `RemarksComponent` dereferences `comment.user.name`, so a
customer remark would break the ticket page. The column becomes nullable, `author_name` is added,
and the component falls back to it with a *Klant* badge and no delete button. Every other component
that renders a remark author is checked for the same dereference.

`docs/handleiding.md`: the *Storingen* chapter gains the request-and-upload flow, and *Tijdlijn en
meldingen* gains following a single ticket.

## 9. Tests

- `AccessTokenTest` — issue/resolve round-trip, plaintext never stored, wrong purpose, expired,
  revoked, `markUsed` counting.
- `TicketInfoRequestTest` — permission gate, mail sent to the right address, token issued with the
  payload, status moved, activity written.
- `CustomerUploadTest` — page renders for a valid token, 404 for unknown/revoked, expired page,
  closed ticket refused, images vs documents vs remark split, oversize and disguised files refused,
  one activity and one notification per submission.
- `TicketFollowSubscriptionTest` — record subscription delivers, type subscription delivers, a
  follower who is also subscribed by type is told once, a follower who cannot see the ticket is
  not told, the actor is never told.
- `MediaDownscalerTest` — image shrunk and re-oriented (skipped when Imagick is absent), small
  images left alone, video job a no-op when ffmpeg is missing.

## 10. Out of scope

Chunked uploads; an admin screen for the mail wording; downscaling of staff uploads; virus
scanning; a UI for listing or revoking outstanding links (the model supports revocation and links
expire on their own); SMS.
