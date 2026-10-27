# Google Calendar Sync — Design

**Date:** 2026-05-21
**Status:** Approved for planning
**Scope:** Bidirectional (limited) sync between Lavoro `Event` records and per-user Google Calendars, including cross-user calendar grants.

---

## 1. Goals & summary

- Each Lavoro user can connect their Google account once. On connect, Lavoro creates a calendar named **"Lavoro"** in that user's Google Calendar and pushes every event for which they are an executing user.
- Admins can grant other users access to a user's calendar. If admin grants Joe access to Peter, Joe sees a **second** calendar in his Google Calendar named **"Peter — Lavoro"** containing Peter's executing events.
- Sync is **bidirectional but limited** and **permission-gated**:
    - Time changes flow back to Lavoro when the actor has `event.update` (own) or `event.update_others`.
    - Deletes flow back when the actor has `event.delete` (own) or `event.delete_others` — soft delete in Lavoro.
    - Description/title/location: bidirectional only for events that **originated in Google**; events that originated in Lavoro have these fields enforced from Lavoro on every sync.
    - New events created directly in Google can be imported back to Lavoro when the actor has `event.create` (own calendar) or `event.create_others` (cross-user calendar).
    - Every other change — and every unauthorised change — is reverted by a corrective push back to Google.
- Change detection: Google push notifications (webhooks) with a 5-minute polling fallback via `events.list` + `syncToken`.

## 2. Out of scope

- Sync of ServiceOrder, ServiceJob, Ticket (only `Event`).
- Attendees / guests, reminders, recurring events, free/busy queries.
- Mobile push channels.

## 3. Data model

Four new tables, one extension to `events`.

### 3.1 `google_calendar_integrations`
One row per connected Google account. Unique on `user_id` (one Google account per Lavoro user).

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk → users.id | |
| `google_account_email` | string | for display |
| `google_account_sub` | string | Google's stable user id (`sub`) |
| `access_token` | text | encrypted via `Crypt::encryptString` |
| `refresh_token` | text | encrypted |
| `expires_at` | datetime | access-token expiry |
| `scopes` | json | scopes granted, for downgrade detection |
| `backfill_total` | int nullable | for UI progress bar |
| `backfill_done` | int nullable | |
| `connected_at` | datetime | |
| `last_error` | text nullable | last failure message |
| `disabled_at` | datetime nullable | set when refresh fails |
| timestamps | | |

### 3.2 `google_synced_calendars`
One row per Google calendar this integration owns. Unique on (`google_calendar_integration_id`, `owner_user_id`).

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `google_calendar_integration_id` | fk | |
| `owner_user_id` | fk → users.id | whose events this calendar shows; equals integration owner for "Lavoro", differs for "X — Lavoro" |
| `google_calendar_id` | string | Google's calendar id |
| `summary` | string | `"Lavoro"` or `"{owner.name} — Lavoro"` |
| `sync_token` | text nullable | latest `nextSyncToken` from Google |
| `watch_channel_id` | uuid nullable | uuid we generated |
| `watch_channel_token` | string nullable | random secret sent in `X-Goog-Channel-Token` |
| `watch_resource_id` | string nullable | returned by Google watch call |
| `watch_expires_at` | datetime nullable | |
| `last_full_sync_at` | datetime nullable | |
| timestamps | | |

### 3.3 `google_synced_events`
Mapping between Lavoro events and Google event ids. Unique on (`google_synced_calendar_id`, `event_id`) and on (`google_synced_calendar_id`, `google_event_id`).

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `google_synced_calendar_id` | fk | |
| `event_id` | fk → events.id, cascade delete | |
| `google_event_id` | string | |
| `etag` | string | last etag we observed, used for echo suppression |
| `last_pushed_at` | datetime | |
| timestamps | | |

### 3.4 `calendar_grants`
Admin-managed "Joe can see Peter". Unique on (`owner_user_id`, `viewer_user_id`); a CHECK / Form-Request constraint prevents `owner === viewer`.

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `owner_user_id` | fk → users.id | the user whose calendar is being shared |
| `viewer_user_id` | fk → users.id | the user who gets the extra "X — Lavoro" calendar |
| timestamps | | |

### 3.5 `events` table additions
- `deleted_at` (softdeletes) — `App\Models\Event` gets `use SoftDeletes`.
- `origin` — enum/string, `'lavoro'` | `'google'`, default `'lavoro'`. Identifies whether the event was originally created in Lavoro (description/title/location are server-controlled) or in Google (those fields are bidirectional).

### 3.6 New permissions
Added in the integration migration alongside the table creation:

| permission | purpose |
|---|---|
| `google_calendar.connect` | seeded to default user role; controls whether the user sees the "Connect" UI |
| `calendar_grant.manage` | admin-only; controls the admin grants page |
| `event.create` | create events in Lavoro / in your own Google "Lavoro" calendar |
| `event.create_others` | create events in a "X — Lavoro" granted calendar |
| `event.update` | update events you own |
| `event.update_others` | update events you do not own |
| `event.delete` | delete events you own |
| `event.delete_others` | delete events you do not own |

Existing `event.read` / `event.see_all` are unchanged.

### 3.7 New `EventPolicy`
- `update($user, $event)` → admin OR (`$event` is owned by `$user` AND `event.update`) OR `event.update_others`.
- `delete($user, $event)` → admin OR (owner AND `event.delete`) OR `event.delete_others`.
- `create($user)` → admin OR `event.create`.
- `createOthers($user, $owner_user)` → admin OR (`$owner_user === $user` AND `event.create`) OR `event.create_others`.

Existing event Form Requests will reference these policy methods instead of bare `hasPermission(...)` checks where applicable; legacy `event.read` checks are left untouched.

## 4. Architecture overview

### 4.1 Services (`app/Services/Google/`)

- **`GoogleClientFactory`** — produces a configured `Google\Client` for a given `GoogleCalendarIntegration`; transparently refreshes access tokens (60s skew); persists refreshed tokens; on `invalid_grant`, sets `disabled_at` and `last_error`. Single point for all token handling.
- **`GoogleCalendarApi`** — thin wrapper around the SDK's `Calendar` service: `createCalendar`, `deleteCalendar`, `insertEvent`, `patchEvent`, `deleteEvent`, `listChanges($syncToken)`, `watchCalendar`, `stopWatch`. Centralises retries / exponential backoff (1s, 2s, 4s, 8s with jitter on 429/5xx) and logging.
- **`EventPayloadBuilder`** — converts a Lavoro `Event` to a Google event payload: title, description (Lavoro description + linked SO/customer summary + deep link), location, start/end (with all-day detection).
- **`CalendarSyncService`** — orchestration:
    - `pushEvent(Event, GoogleSyncedCalendar)` — upsert one event into one calendar.
    - `pullChanges(GoogleSyncedCalendar)` — `listChanges` with stored `sync_token`, walk results, route each through `IncomingChangeHandler`, store the new `nextSyncToken`.
- **`IncomingChangeHandler`** — applies the permission-gated rules from §5.
- **`GrantSyncService`** — when an admin creates/revokes a `calendar_grant`, sets up or tears down the corresponding secondary calendar in the viewer's integration.

### 4.2 Queued jobs (`app/Jobs/Google/`)

- **`PushEventJob(event_id)`** — fan-out: for every synced calendar that should contain this event, enqueue a `PushEventToCalendarJob`.
- **`PushEventToCalendarJob(event_id, google_synced_calendar_id)`** — single API call (insert vs patch decided from mapping). Idempotent. Records new `etag` on the mapping row.
- **`DeleteEventFromGoogleJob(google_synced_event_id)`** — runs before the mapping row is removed, so `google_event_id` is still available.
- **`BackfillCalendarJob(google_synced_calendar_id)`** — used on first connect and on grant creation. Walks all events where `owner_user_id` (of the synced calendar) is in `executingUsers` and `event.end >= now() - GOOGLE_SYNC_LOOKBACK_DAYS`. Updates `backfill_total` / `backfill_done` on the integration.
- **`PullCalendarChangesJob(google_synced_calendar_id)`** — runs `CalendarSyncService::pullChanges`. Dispatched from the webhook controller and from the scheduler.
- **`RenewWatchChannelsJob`** — scheduled hourly. Any calendar with `watch_expires_at` within 24h → `stopWatch` (best-effort) + new `watchCalendar` call.
- **`TeardownIntegrationJob(integration_id)`** — on disconnect: stop all watches, delete all Google calendars created by this integration, soft-delete rows, revoke refresh token.

### 4.3 Wiring & schedules

- **`EventObserver`** (registered in `AppServiceProvider`): on `created`/`updated` → `PushEventJob`; on `deleted` → fan-out `DeleteEventFromGoogleJob` for every mapping row.
- Listener wired into the `attaching` / `attached` / `detached` events on the `Event` model's `executingUsers` / `owners` morph relations (Laravel emits these on `morphToMany` mutations). On any change, re-evaluate fan-out — newly relevant calendars get a push, no-longer-relevant calendars get a delete.
- **`routes/console.php`**:
    - Every 5 min: dispatch `PullCalendarChangesJob` for each `google_synced_calendar` (skipped if integration is disabled).
    - Hourly: `RenewWatchChannelsJob`.

### 4.4 Failure handling

- Inside any job, `GoogleException` 401 triggers a token refresh attempt via `GoogleClientFactory`; on `invalid_grant` the integration is disabled and the UI shows the reconnect banner.
- Transient errors (429, 5xx) use Laravel job retry/backoff (`tries=5, backoff=[60,300,1800,3600]`).
- Hard 404 on a Google calendar (user deleted it manually) → integration disabled with `last_error = "Lavoro calendar missing"`; UI offers a "Recreate calendar" button that re-runs backfill.

## 5. Permission-gated bidirectional flow

Default stance: **read-only**. Unauthorised changes are always reverted by a corrective push back to Google. Authorised changes flow into Lavoro and re-fan-out (echo-suppressed by etag).

Actor identification: the **integration owner** is the actor for any change on any calendar they own. So a change on Joe's "Peter — Lavoro" calendar = Joe is the actor — Lavoro permissions apply to the editor, not to the event owner.

Per change type, applied inside `IncomingChangeHandler::apply($change, $actor, $cal)`:

| change | authorised | not authorised |
|---|---|---|
| time moved | apply new start/end to Lavoro event → fan-out re-push (echo-suppressed) | corrective push of original times; Activity logged |
| deletion (`status === cancelled`) | `$event->delete()` (soft-delete) → fan-out `DeleteEventFromGoogleJob` for other calendars | re-insert in Google with same payload; rewrite mapping with new `google_event_id`; Activity logged |
| description change, `origin === lavoro` | always revert (description is composed from SO/customer data) | revert |
| description change, `origin === google` | apply to Lavoro `description` verbatim | revert |
| title or location, `origin === lavoro` | always revert | revert |
| title or location, `origin === google` | apply | revert |
| new event in Google, no mapping, integration owner's own calendar | create Lavoro `Event` with `origin = google`, owner = actor, executing user = actor, type = `GOOGLE_DEFAULT_EVENT_TYPE_ID`; establish mapping; fan-out push to other calendars containing this owner | delete from Google; in-app notification to actor |
| new event in a "X — Lavoro" granted calendar | as above but owner of Lavoro event = X (calendar owner); executing user = X; Activity records actor as creator | delete from Google; notify |
| restored from `cancelled` | if Lavoro event still soft-deleted → restore (if authorised); else treat as new event | re-cancel in Google |

### 5.1 Echo suppression

Every push stores Google's returned `etag` on `google_synced_events`. Incoming notifications whose `etag` matches the stored one are dropped — they're our own writes echoing back.

### 5.2 Activity logging

Every applied or rejected incoming change writes an `Activity` on the related Event (the model already uses `HasActivities`). Rejections include the actor name and the original Google delta for auditing.

## 6. UI

Three surfaces, all using existing Inertia + Vue 3 conventions.

### 6.1 User profile — "Google Calendar" section

Embedded into the existing profile page as `GoogleCalendarSection.vue`. Three states:

- **Not connected** — explanation + `Connect Google Calendar` button → GET `/google/oauth/start` → backend redirects (Inertia `external: true`) to Google.
- **Connecting / backfilling** — after callback, shows account email + small badge "Backfill running ({backfill_done} / {backfill_total} events)" polled from `/api/google/integration/status`.
- **Connected** — account email, last sync time, `Disconnect` button (uses existing `ConfirmModal`). On confirm → `DELETE /google/integration` → `TeardownIntegrationJob`.

If `disabled_at` is set, an amber inline banner replaces the status line: *"Google Calendar sync paused — please reconnect."*

### 6.2 Admin — Calendar grants page

New route `/admin/calendar-grants` inside the existing `admin` middleware block.

- Two-pane layout: left = users list with grant-count badges; right = detail for selected user.
- Detail pane shows:
    - "Users who can see {Peter}'s calendar" — list of current viewers. Per project convention, click an item to deselect/remove (no separate clear button); confirm via tooltip.
    - "Add viewer" — `SelectMenuComponent` with users not yet granted.
- Backend: `CalendarGrantStoreRequest` (`authorize: $user->can('manage', CalendarGrant::class)`), `CalendarGrantDestroyRequest`. Validation is backend-only (per project convention) — frontend only displays `form.errors`.
- Side effects:
    - On store → `GrantSyncService::onGrantCreated($grant)` → if the viewer has an integration, enqueue a backfill job for the new "{owner} — Lavoro" calendar; otherwise leave for when they next connect.
    - On destroy → `GrantSyncService::onGrantRevoked($grant)` → stop watch + delete the secondary calendar in Google.

### 6.3 Global reconnect banner

When `auth.user.googleIntegration?.disabled_at` is set, `MainLayout` shows a persistent top banner: *"Your Google Calendar sync is paused. [Reconnect]"* — single-click goes through OAuth start. Banner is dismissable per session (sessionStorage) but reappears next login.

### 6.4 i18n

All strings added to `lang/nl/` (primary) and `lang/en/` (fallback), matching the existing translation patterns.

## 7. OAuth, webhook & security details

### 7.1 OAuth

- **One-time developer setup** (described in §10 and `docs/google-calendar-setup.md`): Google Cloud project, OAuth 2.0 Web Client, Calendar API enabled, consent screen configured. Yields `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET`.
- **End users** click "Connect" → standard Google consent → done. No API keys, no setup on their side.
- Scopes: `https://www.googleapis.com/auth/calendar` (full read/write) + `openid email profile`.
- `access_type=offline` + `prompt=consent` on first connect to ensure a refresh token is issued.
- **PKCE** (code verifier + S256 challenge) on the auth URL.
- **State token**: 32-byte base64url random; signed and stored in the session before redirect; verified on callback. Prevents OAuth CSRF.
- Tokens stored encrypted via `Crypt::encryptString`. Decryption happens only inside `GoogleClientFactory`.
- Disconnect: revoke the refresh token at Google (`oauth2/v2/revoke`) **after** `TeardownIntegrationJob` finishes; best-effort.

### 7.2 Webhook security

Webhook route registered outside `web` middleware (no session, no CSRF) — Google won't send a session cookie or CSRF token. The minimal middleware group strips cookies and adds `throttle:60,1`.

Five layers of validation in `GoogleWebhookController@handle`:

1. **HTTPS-only** route.
2. **Channel id lookup** — `GoogleSyncedCalendar::where('watch_channel_id', $channelId)` must hit; 404 otherwise.
3. **Channel token check** — `hash_equals($expected, request->header('X-Goog-Channel-Token'))`; 403 on mismatch.
4. **Resource id check** — `request->header('X-Goog-Resource-Id')` must match stored `watch_resource_id`; 403 otherwise.
5. **Drop `sync` state** — `X-Goog-Resource-State === 'sync'` (the initial handshake) returns 200 immediately.

For valid notifications: dispatch `PullCalendarChangesJob($syncedCalendar->id)` and return 200 quickly. All real work happens off the request thread.

### 7.3 Watch channel lifecycle

- We request 7-day channels (Calendar API max is 30 days).
- `RenewWatchChannelsJob` runs hourly: any calendar with `watch_expires_at` within 24h → stop old channel + start a new one with a fresh channel id + token.
- If renewal transiently fails, the polling fallback still picks up changes.

### 7.4 Polling fallback

- `PullCalendarChangesJob` per calendar every 5 minutes.
- Inside: pages `events.list?syncToken=...&showDeleted=true` until no `nextPageToken`; stores `nextSyncToken`.
- On `410 Gone` (sync token invalidated after long quiet periods): clear `sync_token`, do a full list with `timeMin = now - GOOGLE_SYNC_LOOKBACK_DAYS`, store the new token.

## 8. Edge cases

- **User changes Google account** → disconnect + reconnect. New integration row replaces the old; old calendars are deleted on disconnect.
- **Executing users change on an event** → pivot observer triggers re-evaluation: new executing users get the event pushed to their calendars; removed users get it deleted from theirs.
- **Owner pivot change on an event** → same fan-out logic.
- **Event without an `end`** → skip the sync; warning Activity. Google requires `end >= start`.
- **All-day events** → detected when both `start` and `end` time components are `00:00:00` and `end > start` by whole days; serialised to Google as `start.date`/`end.date`.
- **Concurrent edits** → last-writer-wins via etag; 412 Precondition Failed triggers re-fetch + re-evaluate + re-apply.
- **Soft-deleted Lavoro events** → represented as `cancelled` in Google. Restore un-cancels.
- **Google calendar deleted manually by user** → next push returns 404 → integration disabled with "Lavoro calendar missing" error; UI offers "Recreate calendar" which re-runs `BackfillCalendarJob`.

## 9. Backfill & teardown

### 9.1 First connect

1. OAuth callback creates the integration row.
2. `BackfillCalendarJob` dispatched:
    - Creates the "Lavoro" Google calendar.
    - Registers `events.watch` (only if `GOOGLE_WEBHOOK_ENABLED=true`).
    - Queries `Event::whereHas('executingUsers', fn $q => $q->where('users.id', $user->id))->where('end', '>=', now()->subDays(GOOGLE_SYNC_LOOKBACK_DAYS))->orderBy('start')`.
    - Enqueues `PushEventToCalendarJob` per event; updates `backfill_total` / `backfill_done`.
3. After the integration owner's own calendar is done, the job processes any pre-existing `calendar_grants` where `viewer_user_id = $user->id` — creates each "X — Lavoro" secondary calendar and backfills it.

### 9.2 Admin creates a grant

`GrantSyncService::onGrantCreated($grant)`:
- If `viewer_user` has no integration → just record the grant; the connect flow handles the rest later.
- Else → enqueue `BackfillCalendarJob` for the viewer × owner pair. Secondary calendar summary = `"{owner.name} — Lavoro"`.

### 9.3 Disconnect / grant revoke

`TeardownIntegrationJob`:
1. Stop every watch channel (best-effort).
2. Delete every Google calendar created by this integration (`calendars.delete` removes events with it).
3. Soft-delete the integration and related rows.
4. Revoke refresh token.

`GrantSyncService::onGrantRevoked($grant)` does the same scoped to the single viewer×owner calendar.

## 10. Configuration & local development

### 10.1 `.env.example` additions

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_OAUTH_REDIRECT_URI=http://localhost:8000/google/oauth/callback
GOOGLE_WEBHOOK_ENABLED=false
GOOGLE_WEBHOOK_URL=
GOOGLE_SYNC_LOOKBACK_DAYS=365
GOOGLE_DEFAULT_EVENT_TYPE_ID=
```

These are also bound in a new `config/google.php`.

### 10.2 One-time Google Cloud setup (developer)

A separate `docs/google-calendar-setup.md` will hold a step-by-step with screenshots-worth-of-detail. See `docs/google-calendar-setup.md` for the full step-by-step. Summary:

1. Create a project in [console.cloud.google.com](https://console.cloud.google.com).
2. APIs & Services → enable **Google Calendar API**.
3. APIs & Services → OAuth consent screen → configure (app name "Lavoro", support email, scopes: `auth/calendar`, `email`, `profile`, `openid`).
4. APIs & Services → Credentials → Create Credentials → **OAuth client ID** → type "Web application". Add `http://localhost:8000/google/oauth/callback` as an authorised redirect URI for dev (and your production URI later).
5. Copy Client ID + Client Secret into `.env`.

While the consent screen is in **Testing** mode, only pre-registered test users (≤100) can authorise. To roll out to all customers, the app must go through Google's **verification** review — separate milestone.

### 10.3 Local development paths

**Path A — OAuth only, no webhooks (recommended for day-to-day)**

Works on `http://localhost:8000`. Polling fallback covers change detection.

1. Set `.env` per §10.1, leaving `GOOGLE_WEBHOOK_ENABLED=false`.
2. `php artisan serve` + `php artisan queue:work` + `php artisan schedule:work` (the existing `composer dev` script handles the first two; we need a third terminal for `schedule:work` so the 5-min polling job runs).
3. Connect via UI; changes you make in Google show up in Lavoro within ~5 minutes.

**Path B — full webhook testing**

Webhooks require a public HTTPS URL **with a verified domain**. Localhost is not acceptable to Google.

1. Run a tunnel: `ngrok http 8000` → note the assigned HTTPS URL.
2. Verify the tunnel domain in Google Search Console (DNS TXT or HTML file).
3. Add it under APIs & Services → Domain verification.
4. Update `.env`:
    - `GOOGLE_OAUTH_REDIRECT_URI=https://abc123.ngrok-free.app/google/oauth/callback`
    - `GOOGLE_WEBHOOK_ENABLED=true`
    - `GOOGLE_WEBHOOK_URL=https://abc123.ngrok-free.app/google/webhook`
5. Re-connect the integration so `events.watch` uses the tunnel URL.

The `GOOGLE_WEBHOOK_ENABLED` flag is honoured by `BackfillCalendarJob` and `RenewWatchChannelsJob`: when false, no watches are registered and only the polling job runs.

### 10.4 Required infrastructure changes

- **Queue worker** running in production (`php artisan queue:work`) — many jobs in this design assume a queue.
- **Scheduler** running in production (`* * * * * php artisan schedule:run`) for `PullCalendarChangesJob` (every 5 min per calendar) and `RenewWatchChannelsJob` (hourly).
- **HTTPS** with a verified domain on production for webhooks.

## 11. Dependencies

- New composer require: `google/apiclient` (^2.x).

## 12. Files added / changed (overview)

Added:
- `database/migrations/2026_xx_xx_create_google_calendar_integrations_table.php`
- `database/migrations/2026_xx_xx_create_google_synced_calendars_table.php`
- `database/migrations/2026_xx_xx_create_google_synced_events_table.php`
- `database/migrations/2026_xx_xx_create_calendar_grants_table.php`
- `database/migrations/2026_xx_xx_add_softdeletes_and_origin_to_events_table.php`
- `database/migrations/2026_xx_xx_add_event_and_calendar_grant_permissions.php`
- `app/Models/GoogleCalendarIntegration.php`
- `app/Models/GoogleSyncedCalendar.php`
- `app/Models/GoogleSyncedEvent.php`
- `app/Models/CalendarGrant.php`
- `app/Policies/EventPolicy.php`
- `app/Policies/CalendarGrantPolicy.php`
- `app/Services/Google/GoogleClientFactory.php`
- `app/Services/Google/GoogleCalendarApi.php`
- `app/Services/Google/EventPayloadBuilder.php`
- `app/Services/Google/CalendarSyncService.php`
- `app/Services/Google/IncomingChangeHandler.php`
- `app/Services/Google/GrantSyncService.php`
- `app/Jobs/Google/PushEventJob.php`
- `app/Jobs/Google/PushEventToCalendarJob.php`
- `app/Jobs/Google/DeleteEventFromGoogleJob.php`
- `app/Jobs/Google/BackfillCalendarJob.php`
- `app/Jobs/Google/PullCalendarChangesJob.php`
- `app/Jobs/Google/RenewWatchChannelsJob.php`
- `app/Jobs/Google/TeardownIntegrationJob.php`
- `app/Observers/EventObserver.php` (registered in `AppServiceProvider`)
- `app/Http/Controllers/GoogleOAuthController.php`
- `app/Http/Controllers/GoogleWebhookController.php`
- `app/Http/Controllers/Api/GoogleIntegrationStatusController.php`
- `app/Http/Controllers/Admin/CalendarGrantController.php`
- `app/Http/Requests/CalendarGrantStoreRequest.php`
- `app/Http/Requests/CalendarGrantDestroyRequest.php`
- `config/google.php`
- `resources/js/Components/GoogleCalendarSection.vue`
- `resources/js/Pages/Admin/CalendarGrants/IndexPage.vue`
- `lang/nl/google.php`, `lang/en/google.php`
- `docs/google-calendar-setup.md`

Changed:
- `app/Models/Event.php` (SoftDeletes, `origin` column, observer registration)
- `app/Models/User.php` (relations to integration + grants)
- `resources/js/Layouts/MainLayout.vue` (reconnect banner)
- `resources/js/Pages/Users/EditPage.vue` (or current profile page) — embed `GoogleCalendarSection.vue`
- `routes/web.php` (`/google/oauth/start`, `/google/oauth/callback`, admin grants routes)
- `routes/api.php` (`/api/google/integration/status`)
- `routes/console.php` (schedule entries)
- `composer.json` (`google/apiclient`)
- `.env.example`

## 13. Open questions / explicit decisions made

- **Default `EventType` for Google-originated events** — controlled by `GOOGLE_DEFAULT_EVENT_TYPE_ID` env var; falls back to the first `EventType` if unset.
- **Lookback for backfill** — `GOOGLE_SYNC_LOOKBACK_DAYS=365` by default; configurable.
- **Webhook off by default in dev** — `GOOGLE_WEBHOOK_ENABLED=false` in `.env.example`.
- **Verification submission for the OAuth consent screen** — out of scope for this implementation; tracked as a separate go-live milestone.
