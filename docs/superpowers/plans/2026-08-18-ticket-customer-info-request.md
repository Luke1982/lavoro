# Aanvullende informatie opvragen bij de klant — Implementation Plan

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a ticket, mail the customer a tokenised link to a public page where they upload
photos, video, documents and a note, all of which land on the ticket, notify its followers and
write to its timeline.

**Architecture:** A generic `access_tokens` table plus a purpose enum and a resolving middleware
carry the public link; nothing in it knows about tickets. The ticket-specific half is a renderer,
a mailable, a public controller and an upload rule. Notifications gain a nullable `subscribable`
morph so a subscription can name one record, and delivery gates record-level followers on the
subject's own `visibleTo` scope instead of a blanket permission.

**Tech Stack:** Laravel 12, Inertia, Vue 3, TipTap, Imagick (present, guarded), ffmpeg (queued,
guarded), database queue.

**Spec:** `docs/superpowers/specs/2026-08-18-ticket-customer-info-request-design.md`

## Global Constraints

- PHP variables are `snake_case`. No inline comments; docblocks only where they earn their place.
- `!$foo` — no space after the not operator (pint.json disables that fixer).
- Authorisation lives in Form Request `authorize()` calling a policy; validation lives in `rules()`.
- Run `./vendor/bin/pint <file>` after each PHP edit — **never on a directory**, the repo is not
  pint-clean. Run `npm run fix:eslint` after Vue edits.
- No git commands. Changes are staged for review by the user, never committed here.
- Dutch user-facing copy throughout; the subject line is exactly
  `Wij ontvangen graag extra informatie over uw storing`.
- Every task ends green: `php artisan test --filter=<TestName>` for its own tests plus, at the end
  of the task, the full `composer test`.

---

### Task 1: Access token subsystem

**Files:**
- Create: `database/migrations/2026_08_18_000001_create_access_tokens_table.php`
- Create: `app/Enums/AccessTokenPurpose.php`
- Create: `app/Models/AccessToken.php`
- Create: `app/Domain/Access/IssuedAccessToken.php`
- Create: `app/Http/Middleware/ResolveAccessToken.php`
- Create: `config/customerupload.php`
- Modify: `bootstrap/app.php` (register the `accesstoken` middleware alias)
- Test: `tests/Feature/AccessTokenTest.php`

**Interfaces produced:**

```php
App\Enums\AccessTokenPurpose::ticket_customer_upload = 'ticket.customer_upload';
    public function label(): string;
    public function routeName(): string;
    public function ttlDays(): int;

App\Models\AccessToken
    public static function issue(Model $tokenable, AccessTokenPurpose $purpose,
        ?string $recipient = null, array $payload = []): IssuedAccessToken;
    public static function resolve(string $plaintext, AccessTokenPurpose $purpose): ?self;
    public function isExpired(): bool;
    public function isUsable(): bool;
    public function markUsed(): void;
    public function revoke(?User $by = null): void;
    public function tokenable(): MorphTo;
    protected $casts = ['payload' => 'array', 'expires_at' => 'datetime', ...];

App\Domain\Access\IssuedAccessToken
    public function __construct(public AccessToken $token, public string $plaintext);
    public function url(): string;

Middleware alias 'accesstoken' => ResolveAccessToken::class
    usage: ->middleware('accesstoken:ticket.customer_upload')
    substitutes the resolved AccessToken as the route's {token} parameter.
```

- [ ] **Step 1: Write the failing test**

```php
public function test_issue_returns_a_url_and_stores_only_the_hash(): void
{
    $ticket = $this->ticket();

    $issued = AccessToken::issue($ticket, AccessTokenPurpose::ticket_customer_upload, 'k@x.nl', ['requested' => ['photos']]);

    $this->assertStringContainsString($issued->plaintext, $issued->url());
    $this->assertDatabaseMissing('access_tokens', ['token_hash' => $issued->plaintext]);
    $this->assertSame(hash('sha256', $issued->plaintext), $issued->token->token_hash);
    $this->assertSame(['requested' => ['photos']], $issued->token->payload);
}

public function test_resolve_finds_a_usable_token_and_refuses_everything_else(): void
public function test_an_expired_token_resolves_but_reports_itself_expired(): void
public function test_a_revoked_token_does_not_resolve(): void
public function test_mark_used_counts_and_stamps(): void
public function test_the_middleware_404s_on_an_unknown_token_and_renders_the_expired_page_on_a_stale_one(): void
```

- [ ] **Step 2: Run and watch it fail** — `php artisan test --filter=AccessTokenTest`
- [ ] **Step 3: Migration.** Columns exactly as the spec's table; `nullableMorphs('tokenable')` is
      wrong here (the token always has a subject) so use `morphs('tokenable')`. Unique on
      `token_hash`; index `['tokenable_type','tokenable_id','purpose']` named by hand
      (`access_tokens_tokenable_purpose_index`) to stay inside MySQL's 64-character limit; index
      `expires_at`.
- [ ] **Step 4: `AccessTokenPurpose`.** One case, three methods, `ttlDays()` reading
      `config('customerupload.token_days', 14)`.
- [ ] **Step 5: `config/customerupload.php`** with the full key set from the spec §4, every value
      `env()`-backed. Nothing sized to this machine.
- [ ] **Step 6: `AccessToken` + `IssuedAccessToken`.** `issue()` generates `Str::random(48)`,
      stores `hash('sha256', ...)`, sets `expires_at = now()->addDays($purpose->ttlDays())` and
      `created_by_id = Auth::id()`. `resolve()` looks up by hash **and** purpose, returns null on
      a revoked row, returns the row when merely expired (the caller decides how to say so).
- [ ] **Step 7: `ResolveAccessToken` middleware.** Reads the `{token}` route parameter, resolves
      against the purpose in its own argument, `abort(404)` when null, renders
      `inertia('Public/LinkExpiredPage')->toResponse($request)->setStatusCode(410)` when expired,
      otherwise `$request->route()->setParameter('token', $access_token)` and continues. Register
      the alias in `bootstrap/app.php` next to the existing aliases.
- [ ] **Step 8: Green** — `php artisan test --filter=AccessTokenTest`, then pint the touched files.

---

### Task 2: The new ticket status

**Files:**
- Modify: `app/Enums/TicketStatusses.php`
- Modify: `app/Http/Controllers/TicketController.php` (the counters in `index()`)
- Modify: `resources/js/Pages/Tickets/IndexPage.vue`, `resources/js/Pages/Tickets/ShowPage.vue`
- Test: `tests/Feature/TicketWaitingStatusTest.php`

**Interfaces produced:** `TicketStatusses::wacht_op_klant = 'Wacht op terugkoppeling klant'`;
`TicketController::index` gains props `waitingCount`, `waitingPctVsAvg`.

- [ ] **Step 1: Failing test** — a ticket in the new status is counted in `waitingCount`, appears
      in `statusOptions`, and is filterable by the `wacht_op_klant` key.
- [ ] **Step 2: Run it, watch it fail.**
- [ ] **Step 3:** Add the enum case (last, after `gesloten`, so the pipeline reads in order:
      open → in behandeling → wacht op klant → gesloten; move it before `gesloten` for that).
- [ ] **Step 4:** In `index()`, add `$waiting_count`, include it in `$total_count`, divide the
      average by 4 instead of 3, and pass the two new props.
- [ ] **Step 5:** `IndexPage.vue` — a fourth `StatCard` and the badge colour case (`purple`).
      `ShowPage.vue` — the same colour case in `statusBadgeColor` and `statusTileClasses`.
- [ ] **Step 6: Green + pint + eslint.**

---

### Task 3: Record-level notification subscriptions

**Files:**
- Create: `database/migrations/2026_08_18_000002_add_subscribable_to_notification_subscriptions_table.php`
- Modify: `app/Models/NotificationSubscription.php`
- Modify: `app/Http/Requests/NotificationSubscriptionStoreRequest.php`
- Modify: `app/Listeners/NotifySubscribers.php`
- Modify: `app/Enums/UserNotificationType.php`
- Test: `tests/Feature/TicketFollowSubscriptionTest.php`

**Interfaces consumed:** none. **Produces:**

```php
NotificationSubscription: $fillable += ['subscribable_type', 'subscribable_id'];
    public function subscribable(): MorphTo;
    public function scopeForRecord($query, Model $record);

UserNotificationType::ticket_customer_uploaded = 'ticket.customer_uploaded';
UserNotificationType::ticket_status_changed    = 'ticket.status_changed';
UserNotificationType::ticket_priority_changed  = 'ticket.priority_changed';
```

- [ ] **Step 1: Failing tests**

```php
public function test_a_follower_of_one_ticket_is_told_about_that_ticket_only(): void
public function test_a_type_subscriber_is_told_about_every_ticket(): void
public function test_somebody_subscribed_both_ways_is_told_once(): void
public function test_a_follower_who_cannot_see_the_ticket_is_not_told(): void
public function test_the_actor_is_never_told(): void
public function test_following_the_same_ticket_twice_is_refused_by_validation(): void
```

- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: Migration.** `nullableMorphs('subscribable')`; `string('type')->nullable()->change()`;
      drop the `['user_id','type']` unique and add
      `['user_id','type','subscribable_type','subscribable_id']` under a hand-written name. Verify
      it runs on **SQLite** (the test database rebuilds the table) as well as MySQL.
- [ ] **Step 4: Model** — the morph, the fillable additions, `scopeForRecord`.
- [ ] **Step 5: `UserNotificationType`** — three cases and an answer in every method:
      `label`, `description`, `subscribable` (true), `requiredPermissions` (`['ticket.read']`),
      `icon` (`Upload`, `Flag`, `TrendingUp`), `color` (`blue`, `amber`, `amber`), `titleFor`,
      `bodyFor`, `priorityFor` (the ticket's own priority through
      `UserNotificationPriority::fromTicketPriority`). `shouldNotify` needs no case: all three
      always report. `bodyFor` for the upload type reads the counts off the signal.
- [ ] **Step 6: `NotifySubscribers::subscribersFor()`** becomes a union of two queries. Record-level
      rows are gated on visibility: when the subject's class defines `scopeVisibleTo`, filter the
      candidate ids through `$class::visibleTo($user)->whereKey($subject)->exists()`; resolve it
      per candidate but only for record-level followers, which is a handful of people at most.
      Type-level rows keep the existing permission query untouched. The union is `->unique()`, so
      a person subscribed both ways is written once.
- [ ] **Step 7: `NotificationSubscriptionStoreRequest`** — `type` becomes `nullable` but must be
      present when `subscribable_type` is absent; `subscribable_type` validated against an
      allow-list of morphable classes (`[Ticket::class]` today); uniqueness re-expressed as an
      explicit query with `whereNull` on the absent halves. Record-level rows are authorised on
      visibility (`Ticket::visibleTo($subscriber)->whereKey($id)->exists()`), not on
      `requiredPermissions()`.
- [ ] **Step 8: Green + pint.**

---

### Task 4: Signals and the timeline

**Files:**
- Create: `app/Domain/Signals/Tickets/CustomerInfoRequested.php`
- Create: `app/Domain/Signals/Tickets/CustomerInfoUploaded.php`
- Modify: `resources/js/Components/Timeline/TimelineComponent.vue:38`
- Test: `tests/Feature/Signals/TicketSignalsTest.php` (extend)

**Produces:**

```php
new CustomerInfoRequested(Ticket $ticket, string $to, array $requested, CarbonInterface $expires_at)
    key 'ticket.info_requested', category 'email'
new CustomerInfoUploaded(Ticket $ticket, int $images, int $documents, bool $has_remark,
    string $customer_name, string $recipient)
    key 'ticket.customer_uploaded', category 'attachment', actor_type 'customer'
```

- [ ] **Step 1: Failing test** — dispatching each writes one activity on the ticket with the Dutch
      sentence, the right category, and for the upload signal `actor_type = 'customer'` and
      `actor_name = "Van Dijk B.V. (info@vandijk.nl)"`.
- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: Write both signals.** `CustomerInfoUploaded` sets `actor_type`, `actor_name` and
      `actor_id = null` *after* `parent::__construct()`. Both return `[]` from `changes()` (they
      report no field), so `SignalShapeTest` stays green. Sentences read as Dutch prose and count
      properly: "1 foto", "3 foto's en 1 video", "een toelichting".
- [ ] **Step 4: `TimelineComponent.vue`** — `event.actorType === 'user' || event.actorType === 'customer' ? event.actorName : 'Systeem'`.
      `ai` keeps reading as the system on purpose.
- [ ] **Step 5: Green + pint + eslint.**

---

### Task 5: Sending the request (backend)

**Files:**
- Create: `database/migrations/2026_08_18_000003_add_ticket_request_customer_info_permission.php`
- Create: `app/Services/TicketInfoRequestRenderer.php`
- Create: `app/Mail/TicketInfoRequestMail.php`
- Create: `resources/views/emails/ticket_info_request.blade.php`
- Create: `app/Http/Controllers/TicketInfoRequestController.php`
- Create: `app/Http/Requests/TicketInfoRequestReadRequest.php`, `TicketInfoRequestSendRequest.php`
- Modify: `app/Policies/TicketPolicy.php`, `routes/api.php`
- Test: `tests/Feature/TicketInfoRequestTest.php`

**Consumes:** `AccessToken::issue` (Task 1), `TicketStatusses::wacht_op_klant` (Task 2),
`CustomerInfoRequested` (Task 4).

**Produces:**

```php
TicketInfoRequestRenderer::REQUESTABLE  // ['photos'=>"foto's van de storing", 'videos'=>..., 'other'=>...]
    ::subject(): string
    ::body(Ticket $ticket): string
    ::defaultRecipient(Ticket $ticket): ?string
    ::options(): array   // [['key'=>'photos','label'=>"foto's van de storing"], ...]

GET  /api/tickets/{ticket}/info-request  -> {to, subject, body, options, requested}
POST /api/tickets/{ticket}/info-request  -> {message}
TicketPolicy::requestCustomerInfo(User $user, Ticket $ticket): bool
```

- [ ] **Step 1: Failing tests**

```php
public function test_the_defaults_endpoint_fills_customer_machine_and_serial(): void
public function test_a_user_without_the_permission_is_refused(): void
public function test_sending_mails_the_customer_issues_a_token_and_moves_the_status(): void
public function test_sending_writes_one_email_activity_naming_the_recipient(): void
public function test_the_mail_body_contains_the_upload_url_and_the_typed_body(): void
public function test_at_least_one_kind_of_information_must_be_requested(): void
```

- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: Permission migration** in the shape of `2025_10_08_000001_add_ticket_permissions.php`.
- [ ] **Step 4: `TicketPolicy::requestCustomerInfo`** → `hasPermission('ticket.request_customer_info')`.
- [ ] **Step 5: Renderer.** `body()` produces the agreed paragraph with `[KLANT]`, `{ASSET}` and
      `{SERIAL}` filled from `$ticket->asset` (brand + model, else the product's name, else
      "uw machine"; serial else "onbekend"), wrapped in `<p>` tags TipTap round-trips. No list, no
      link. `defaultRecipient()` walks asset → resolved customer → `email`.
- [ ] **Step 6: Mailable + Blade.** Table-based CTA button, company logo from the main `Company`,
      the plain URL underneath the button, `{!! $body !!}` for the typed HTML.
- [ ] **Step 7: Requests + controller.** `authorize()` calls `can('requestCustomerInfo', $ticket)`.
      `rules()`: `to` required email; `subject` required string max 255; `body` required string;
      `requested` required array min 1, `requested.*` in the `REQUESTABLE` keys. `send()` wraps
      issue → mail → signal → status update in a transaction.
- [ ] **Step 8: Routes** under `auth:sanctum`.
- [ ] **Step 9: Green + pint.**

---

### Task 6: The request modal (frontend)

**Files:**
- Create: `resources/js/Components/Tickets/InfoRequestModal.vue`
- Modify: `resources/js/Pages/Tickets/ShowPage.vue`
- Test: `resources/js/Components/Tickets/__tests__/InfoRequestModal.spec.js`

**Consumes:** the two endpoints from Task 5.

- [ ] **Step 1: Failing component test** (vitest, in the shape of the existing
      `Components/UI/__tests__` specs): toggling *video's* off removes exactly that line from the
      body and leaves surrounding text untouched; toggling it back re-adds it; with nothing
      selected the send button is disabled.
- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: Write the modal.** `ModalDialog`, `TextInput` ×2, three toggle buttons styled like
      the app's other pill toggles, `TipTapEditor`. A `syncList(html, labels)` helper rewrites the
      single `<ul data-info-request>…</ul>` node — appending it after the last paragraph when it is
      absent, removing it when nothing is selected — using `DOMParser`, never a regex.
- [ ] **Step 4: Wire the header button** in `ShowPage.vue` behind
      `hasPermission('ticket.request_customer_info')`, opening on click, loading the defaults on
      open, posting on send, flashing success and reloading the ticket so the new status and the
      new activity appear.
- [ ] **Step 5: Green** — `npx vitest run` for the spec, then `npm run fix:eslint`.

---

### Task 7: Upload limits, validation and downscaling

**Files:**
- Create: `app/Rules/CustomerUploadFile.php`
- Create: `app/Services/MediaDownscaler.php`
- Create: `app/Jobs/DownscaleVideoJob.php`
- Test: `tests/Feature/MediaDownscalerTest.php`, `tests/Feature/CustomerUploadFileRuleTest.php`

**Produces:**

```php
CustomerUploadFile implements ValidationRule    // Dutch messages naming file and limit
    public static function kindFor(UploadedFile $file): ?string;   // 'image' | 'video' | 'document' | null

MediaDownscaler
    public function canProcessImages(): bool;
    public function canProcessVideo(): bool;
    public function image(string $absolute_path): string;   // final path, extension may change
    public function video(string $absolute_path): string;

DownscaleVideoJob(int $document_id)
```

- [ ] **Step 1: Failing tests** — a 4000×3000 fixture comes back at 1920 on its long edge and
      smaller on disk; an 800×600 fixture is left alone; an unreadable file returns the original
      path and logs; the rule accepts a jpg under the cap, refuses one over it, refuses a `.exe`
      renamed to `.jpg` (MIME group disagrees), and refuses an unlisted extension.
- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: `CustomerUploadFile`.** Kind from the lowercased client extension against the
      three config lists; then the kind's cap; then `str_starts_with($file->getMimeType(), 'image/')`
      (resp. `video/`) or membership of the document MIME list.
- [ ] **Step 4: `MediaDownscaler::image`** with Imagick behind `class_exists`, `autoOrient()`,
      `stripImage()`, `resizeImage` only when the long edge exceeds the target, quality from
      config, HEIC/HEIF written out as `.jpg` with the original removed. Every failure path
      returns the untouched path and logs.
- [ ] **Step 5: `MediaDownscaler::video`** through `Symfony\Component\Process\Process` with the
      binary from config, `-vf scale=-2:'min(720,ih)' -c:v libx264 -crf 28 -preset medium -c:a aac
      -b:a 128k -movflags +faststart`, to a temp file, replacing the original only on exit code 0
      **and** a smaller result. `canProcessVideo()` probes the binary once per request.
- [ ] **Step 6: `DownscaleVideoJob`** — loads the `Document`, runs the downscaler, updates `path`,
      `name` and `size` when the file changed, logs and leaves everything on failure.
- [ ] **Step 7: Green + pint.**

---

### Task 8: The customer page (backend)

**Files:**
- Create: `database/migrations/2026_08_18_000004_allow_guest_authors_on_remarks_table.php`
- Create: `app/Http/Controllers/CustomerUploadController.php`
- Create: `app/Http/Requests/CustomerUploadRequest.php`
- Modify: `app/Models/Remark.php`, `routes/web.php`
- Test: `tests/Feature/CustomerUploadTest.php`

**Consumes:** Tasks 1, 3, 4, 7.

- [ ] **Step 1: Failing tests**

```php
public function test_the_page_renders_the_machine_the_checklist_and_what_was_already_sent(): void
public function test_an_unknown_or_revoked_token_404s(): void
public function test_an_expired_token_renders_the_expired_page(): void
public function test_a_closed_ticket_refuses_further_uploads(): void
public function test_photos_land_as_images_and_video_and_pdf_as_documents(): void
public function test_the_note_lands_as_a_remark_without_a_user(): void
public function test_one_submission_writes_one_activity_and_one_notification(): void
public function test_the_upload_marks_the_token_used(): void
```

- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: Remarks migration** — `user_id` nullable, add `author_name` string nullable. Both
      directions must work on SQLite.
- [ ] **Step 4: `Remark`** — `author_name` fillable, plus an `author_label` accessor returning the
      user's name or the guest name.
- [ ] **Step 5: `CustomerUploadRequest`** — `authorize()` returns true (the middleware already
      resolved the token; there is nobody else to authorise), `rules()` from config: `files` array
      max `max_files`, `files.*` through `CustomerUploadFile`, `note` nullable string max
      `note_max`, and a rule refusing a submission with neither files nor note.
- [ ] **Step 6: Controller.** `show()` loads the ticket through the token, 410-style refusal when
      closed, and passes the machine, serial, checklist (from `payload.requested`), expiry and the
      list of what this token already sent (images and documents created after the token, matched
      by an `access_token_id`-free approach: the controller keeps the ids in the token's payload
      under `uploaded`). `store()` splits by kind, stores under the ticket's folders with
      `{random8}-{sanitised}` names, downscales images inline, dispatches `DownscaleVideoJob` for
      video, attaches everything, writes the remark, appends to `payload.uploaded`, marks the token
      used, and dispatches `CustomerInfoUploaded` once.
- [ ] **Step 7: Routes** — the two public routes with `accesstoken:ticket.customer_upload` and
      `throttle:60,1`, placed beside the password-reset routes outside the `auth` group.
- [ ] **Step 8: Green + pint.**

---

### Task 9: The customer page (frontend)

**Files:**
- Create: `resources/js/Pages/Public/TicketUploadPage.vue`, `resources/js/Pages/Public/LinkExpiredPage.vue`
- Modify: `resources/js/Components/RemarksComponent.vue`
- Test: `resources/js/Pages/Public/__tests__/TicketUploadPage.spec.js`

- [ ] **Step 1: Failing component test** — the checklist renders one line per requested kind; the
      send button is disabled with nothing chosen; a file over the cap is refused client-side with
      the Dutch message before it is ever posted.
- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3: Write the pages** with `EmptyLayout`, the company header, drop zone, note field,
      "al verstuurd" list and the expiry line. Mobile first: a customer opens this on a phone.
- [ ] **Step 4: `RemarksComponent`** — `comment.user?.name ?? comment.author_name`, a *Klant* badge
      when there is no user, no delete button for those. Grep every other component that renders a
      remark author and fix the same dereference.
- [ ] **Step 5: Green + eslint.**

---

### Task 10: Follow-this-ticket toggle

**Files:**
- Modify: `app/Http/Controllers/TicketController.php` (`show()` payload)
- Modify: `resources/js/Pages/Tickets/ShowPage.vue`
- Test: extend `tests/Feature/TicketFollowSubscriptionTest.php`

- [ ] **Step 1: Failing test** — `show()` passes `subscription_id` null for a stranger and the row
      id for a follower.
- [ ] **Step 2: Run, watch fail.**
- [ ] **Step 3:** Add the lookup to `show()` and the bell button to the header, posting to
      `/notificationsubscriptions` with `subscribable_type` and `subscribable_id` and deleting by
      id — a toggle, no separate off-switch.
- [ ] **Step 4: Green + pint + eslint.**

---

### Task 11: Manual

**Files:** Modify `docs/handleiding.md`

- [ ] **Step 1:** *Storingen* gains a section on asking the customer for information: what the mail
      says, what the customer sees, where the uploads land, how long the link works.
- [ ] **Step 2:** *Tijdlijn en meldingen* gains following a single storing and the three new
      notification types.
- [ ] **Step 3:** Full `composer test`.

## Self-Review

- **Spec coverage:** §1 → Task 1; §2 → Task 2; §3 → Tasks 5, 6; §4 → Tasks 7, 8, 9; §5 → Task 7;
  §6 → Tasks 3, 10; §7 → Task 4; §8 → Tasks 8, 9, 11; §9 → tests inside each task.
- **Placeholders:** none; every step names the file and the decision.
- **Type consistency:** `AccessToken::issue/resolve`, `IssuedAccessToken::url`,
  `MediaDownscaler::image/video`, `CustomerUploadFile::kindFor`, `TicketInfoRequestRenderer::*` and
  the three enum cases are spelled identically wherever they appear.
