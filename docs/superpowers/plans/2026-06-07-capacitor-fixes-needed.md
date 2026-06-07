# Capacitor Implementation — Review & Required Fixes

**Reviewed:** commits `6ac916e` → `4e061d2` (Capacitor launcher, GPS tracking, FCM push, network warnings).

**Verdict:** The *scaffolding* is solid — sensible file layout, migrations, models, Form Requests, routes, composables, the Option-C launcher concept, and the git-hash service-worker cache are all good calls. But **three of the headline features (push notifications, GPS timestamp storage, and possibly the whole native bridge) would not work as shipped.** Several bugs are silent — they fail without errors, so they'd survive a casual "it builds" check and only surface on a real device in production.

Every claim below was verified against the actually-installed code/packages, not assumed.

---

## CRITICAL — features are broken, not just suboptimal

### 1. The FCM notification is written against the wrong API and wrong namespace — fatal on dispatch

**File:** `app/Notifications/NewServiceOrderAssigned.php`

The installed package (`laravel-notification-channels/fcm` v6.1) uses namespace `NotificationChannels\Fcm` with classes `FcmChannel`, `FcmMessage`, `Resources\Notification`, and **fluent** methods. The code uses `NotificationChannels\FCM\…` (uppercase `FCM`) and **setter** methods that don't exist:

```php
// WRONG (current) — case-sensitive Linux = class-not-found fatal:
use NotificationChannels\FCM\FCMChannel;
use NotificationChannels\FCM\FCMMessage;
use NotificationChannels\FCM\Resources\Notification as FCMNotification;
...
FCMMessage::create()->setNotification(FCMNotification::create()->setTitle(...)->setBody(...))->setData([...]);
```

Verified: `vendor/.../src/FcmMessage.php` exposes `->notification()` and `->data()`; `Resources/Notification.php` exposes `->title()` and `->body()`. `setNotification`/`setTitle`/`setBody`/`setData` do not exist.

**Fix:**
```php
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

public function via(object $notifiable): array
{
    return empty($notifiable->routeNotificationFor('fcm')) ? [] : [FcmChannel::class];
}

public function toFcm(object $notifiable): FcmMessage
{
    return FcmMessage::create()
        ->notification(FcmNotification::create()
            ->title('Nieuwe werkbon toegewezen')
            ->body("Werkbon #{$this->service_order->id} is aan u toegewezen."))
        ->data(['type' => 'service_order_assigned', 'id' => (string) $this->service_order->id]);
}
```

### 2. No Firebase credentials configured — the channel can't even be constructed

`FcmChannel` depends on `kreait/laravel-firebase`, which needs `config/firebase.php` + a `FIREBASE_CREDENTIALS` service-account path. Verified: neither `config/firebase.php` nor any `FIREBASE_*` env key exists. The first queued notification will fail to resolve `Messaging` from the container.

**Fix:** `php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider"`, set `FIREBASE_CREDENTIALS=storage/app/firebase-credentials.json`, deploy the service-account JSON (gitignored). This was listed in the plan's post-impl checklist, so it's half-acknowledged — but combined with #1 it means push has never run once.

### 3. GPS `recorded_at` is raw-inserted as an ISO-8601 string into a MySQL `TIMESTAMP` column

**File:** `app/Http/Controllers/Api/LocationPingController.php:13-21`

`LocationPing::insert($rows)` bypasses the model's `'recorded_at' => 'datetime'` cast (bulk insert skips casts/mutators). The client sends `"2026-06-07T13:19:59.123Z"`, validated only as `'date'`, so that literal string — with `T` and `Z` — goes straight to MySQL. MySQL `TIMESTAMP` won't parse it: in strict mode it errors the whole batch; otherwise it stores `0000-00-00`. Either way `TechnicianLocationController`'s `where('recorded_at', '>=', now()->subHours(8))` then filters out every ping, so **the map is always empty.** It works by luck only because `config/app.php` timezone is UTC (the `Z` offset is ignored, not applied).

**Fix:** normalise before insert:
```php
$rows = array_map(fn($ping) => array_merge($ping, [
    'user_id'     => $user_id,
    'recorded_at' => \Carbon\Carbon::parse($ping['recorded_at'])->utc()->toDateTimeString(),
    'created_at'  => $now,
]), $request->validated('pings'));
```

---

## MAJOR — works in the happy path, breaks in real use

### 4. Queued notification dispatched inside a DB transaction with the `database` queue driver

**File:** `app/Http/Controllers/EventApiController.php:72-98`

`store()` wraps everything in `DB::transaction(...)`; the `$user->notify(new NewServiceOrderAssigned(...))` at line 96-98 is inside it. `NewServiceOrderAssigned implements ShouldQueue`, queue is `database`, and `config/queue.php` has `after_commit => false` (all verified). A worker can pick up the job before the transaction commits → it loads a `ServiceOrder` that isn't visible yet, or the transaction rolls back leaving an orphan job → `ModelNotFoundException`.

**Fix:** set `'after_commit' => true` on the database queue connection (cleanest, fixes it globally), or append `->afterCommit()` to the dispatch.

### 5. Every event edit re-notifies *all* assigned technicians as "newly assigned"

**File:** `app/Http/Controllers/EventApiController.php` — `update()`, lines ~153-166

`syncExecutingUsers($ids)` detaches+reattaches everyone, then the notification fires for the **entire** `$ids` set, not just newly-added users. A planner nudging the appointment time re-spams "Nieuwe werkbon toegewezen" to everyone already on the order.

**Fix:** capture the existing set *before* syncing and notify only the delta:
```php
$existing = $event->executingUsers()->pluck('users.id')->all();
// ... syncExecutingUsers($ids) ...
$new_ids = array_diff($ids, $existing);
User::whereIn('id', $new_ids)->get()->each(fn($u) => $u->notify(new NewServiceOrderAssigned($model)));
```

### 6. `/api/*` POSTs send no CSRF token and are silently rejected (419)

**Files:** `usePushNotifications.js:19,46`, `useLocationTracker.js:44`

`bootstrap/app.php:26` calls `$middleware->statefulApi()` and the sanctum guard is `web` (session-cookie auth). That makes these stateful SPA requests subject to CSRF — they need the `XSRF-TOKEN` cookie, which axios only sends after `axios.get('sanctum/csrf-cookie')`. The rest of the codebase primes this everywhere (per CLAUDE.md); these three new calls don't, and their errors are swallowed by `catch {}`. Result: tokens never register, pings never upload — silently.

**Fix:** prime the cookie once before the first mutating call (e.g. a one-time `await axios.get('sanctum/csrf-cookie')` guarded by a module boolean), or add a global axios bootstrap.

### 7. Location queue can silently drop pings (read-modify-write race)

**File:** `resources/js/Composables/useLocationTracker.js:33-49`

`flush()` reads the queue, awaits the POST, then `save_queue([])`. If a watcher fires and `enqueue`s a new ping during that await, the subsequent `save_queue([])` overwrites it — the new ping is lost. The watcher calls `enqueue`+`flush`, and the `is_online` watcher also calls `flush`, so concurrent access is realistic.

**Fix:** don't blind-clear. Capture the sent count, then re-read and `splice(0, sent_count)`, or serialise all queue mutations through a promise-chained mutex.

### 8. Push listeners accumulate on every page navigation

**Files:** `resources/js/app.js:14`, `usePushNotifications.js:17-40`

Layout is assigned with the **non-persistent** pattern (`page.default.layout = page.default.layout || MainLayout`), so MainLayout re-mounts on every full Inertia visit, re-running `onMounted` → `register_push()`. Each call adds four more `PushNotifications.addListener(...)` with no removal. After N navigations, one notification tap fires N `window.location.href` redirects and the token POSTs N times. (Finding #1 currently masks this on Android, but it's real once push works / on iOS.)

**Fix:** guard with a module-level `registered` boolean like `useNetworkStatus` already does, or call `PushNotifications.removeAllListeners()` before re-adding.

---

## VERIFY ON DEVICE — the Option-C premise itself is at risk

### 9. `allowNavigation: ['*']` may stop Capacitor from injecting its bridge on the remote origin

**File:** `capacitor.config.ts:7-9`

This is the load-bearing assumption of the whole approach and I **could not test it here** (no device/emulator), so treat it as a must-verify rather than settled fact. Multiple Capacitor issues ([#1389](https://github.com/ionic-team/capacitor/issues/1389), [#4164](https://github.com/ionic-team/capacitor/issues/4164), [#7454](https://github.com/ionic-team/capacitor/issues/7454)) report that after `window.location.href` navigates to an `allowNavigation` host — especially with a wildcard — `Capacitor.getPlatform()` returns `'web'` and plugins stop working on Android. If that happens here, `useCapacitor().is_native` is `false` on the remote page and **push, GPS, and native network detection all silently no-op** (each guards `if (!is_native) return`).

**Action:** Build the Android app and confirm on a real device that `Capacitor.isNativePlatform()` is `true` *after* the launcher redirect. If it's false:
- enumerate concrete hosts instead of `*` (e.g. `['lavoro.mijnbedrijf.nl']`), or
- reconsider Option C — a build-time `server.url` per tenant keeps the bridge reliably, at the cost of the runtime URL entry. This trade-off should be re-decided with the on-device result in hand.

This is the single highest-priority thing to validate; everything else is moot if the bridge is dead.

---

## MINOR — correctness nits

- **`device_tokens.token` is `VARCHAR(255)` but validation allows `max:500`** (`UpsertDeviceTokenRequest:18`). FCM tokens can exceed 255 chars → truncation/insert error. Bump the column to `string('token', 512)` or lower the rule to 255.
- **Duplicate migration timestamp** — both new migrations are `2026_06_07_131959_*`. Harmless (no cross-FK) but re-stamp one.
- **`distanceFilter` mismatch** — `capacitor.config.ts` says `30`, the runtime `addWatcher` in `useLocationTracker.js` says `50`. The runtime value wins; the config value is dead/confusing. Pick one.
- **`MAX(id)` as "latest ping"** (`TechnicianLocationController:16-21`) — fine normally, but offline batches flushed later have higher ids than newer real-time pings, so it can show a stale fix. Prefer ordering by `recorded_at`. Also the composite index doesn't serve the `MAX(id) … GROUP BY` pattern well; revisit at volume.
- **`abort_unless($request->user()->isAdmin(), 403)`** (`TechnicianLocationController:13`) violates the project rule to authorize via Form Request/policy. Works, but non-compliant.
- **Launcher accepts `http://`** (`index.html`) — a plaintext-downgrade path for a field app handling location data. Consider https-only.
- **Production queue worker** — `composer run dev` runs `queue:listen` locally, but production must run `queue:work`/Supervisor or queued notifications never send. Verify the deploy.

---

## What was done well (keep)

- Migrations, models, Form Requests, route placement — clean, convention-following, correct `decimal(10,7)` for coordinates, correct `$timestamps=false` + `useCurrent()`.
- `DeviceTokenController::upsert` device-reuse handling (delete other-user rows, then upsert on unique token) — actually correct under concurrency.
- `useNetworkStatus` singleton with the `initialized` guard — correct; the `usePushNotifications` leak (#8) is what the push composable was missing.
- The git-hash service-worker cache plugin — genuinely nice; deploys now auto-invalidate.
- The Option-C launcher UX (branded, triple-tap reset, offline-friendly) — good idea, *pending* the on-device bridge check (#9).

---

## Fix priority

1. **#9** — verify the native bridge survives the redirect. Everything depends on it.
2. **#1 + #2** — rewrite the FCM notification API and add Firebase credentials. Push is 100% non-functional otherwise.
3. **#3** — normalise `recorded_at`. GPS storage is broken otherwise.
4. **#4, #5, #6** — transaction/queue race, re-notification spam, CSRF.
5. **#7, #8**, then the minors.
