# Appointment Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a plannable mechanic gets a push N minutes before each appointment they carry out. Tapping it opens the service order. It has a Route and a Bellen button. It reaches the browser, the PWA and the Android app.

**Architecture:**
- A per-tenant job, every five minutes, writes `UserNotification` rows of a new type `appointment.reminder`. Each row stores its button links and an expiry.
- One push layer turns notifications into `PushMessage`s and hands them to two channels:
  - web push (moved out of `WebPushSender`)
  - Firebase (new).
- The Android app draws Firebase data messages itself, with a native messaging service, so they can carry buttons.

**Tech Stack:** Laravel 12, Inertia/Vue 3, minishlink/web-push, kreait/laravel-firebase 8, Capacitor 8 (Android, Java).

**Spec:** `docs/superpowers/specs/2026-11-12-appointment-reminders-design.md`

## Global Constraints

- New migrations go in `database/migrations/tenant/`.
- PHP variables are snake_case. No inline comments; use docblocks only where they carry a reason.
- Run `./vendor/bin/pint <file>` after every PHP edit and `npx eslint --fix <file>` after every JS/Vue edit. File paths only, never a directory.
- Authorization goes in Form Request `authorize()` through a policy, or `isAdmin()` where the neighbouring admin settings requests already do that. Validation goes only in `rules()`.
- No automated tests (CLAUDE.md: "Don't write tests unless asked"). Verify by running the code: tinker scripts in the scratchpad, the browser, the Gradle compile.
- No commits. The user approves per change, and commits go through pathspecs. Other agents share this checkout. `docs/guide/handleiding.md` and `useWebPush.js` already carry their uncommitted edits, so edit those files narrowly.
- Lead time:
  - `GeneralSetting` key `appointment_reminder_minutes`
  - default 60, min 5, max 240.
- Type value `appointment.reminder`. Label "Afspraakherinnering".
- Every displayed time goes through `App\Domain\Planning\Clock::zone()`. The app timezone is UTC.
- Run the suite as `flock /tmp/claude-1000/-home-guido-nvme0n1p1-code-lavoro/test-db.lock php artisan test`.

## Review Focus

1. A person with diverging times gets the reminder for *their* start, and the title shows that time. Checked in Task 4, step 4.
2. A moved appointment is reminded once more, for the new start. The same start is never reminded twice, even when two jobs overlap. Checked in Task 4, step 4.
3. A reminder is never pushed after its appointment has started (expiry → TTL, dropped at 0). Checked in Task 3, step 6.
4. A pile of more than 5 notifications still delivers the reminder on its own, with its buttons. Checked in Task 3, step 6.
5. A phone that logged out stops receiving: its device token is deleted on logout. Checked in Task 9, step 4.

---

### Task 1: Storage

**Files:**
- Create: `database/migrations/tenant/2026_11_12_000001_add_actions_and_expiry_to_user_notifications_table.php`
- Create: `database/migrations/tenant/2026_11_12_000002_create_notification_mutes_table.php`
- Create: `database/migrations/tenant/2026_11_12_000003_create_event_reminders_table.php`
- Create: `app/Models/NotificationMute.php`, `app/Models/EventReminder.php`
- Modify: `app/Models/UserNotification.php` (fillable, casts), `app/Models/User.php` (`notificationMutes()`)

**Interfaces — Produces:**
- `user_notifications.actions` json nullable, cast `array`. Shape: `list<array{action: string, title: string, url: string}>`.
- `user_notifications.expires_at` timestamp nullable, cast `datetime`.
- `notification_mutes`: `id`, `user_id` (FK users, cascade), `type` string, timestamps. Unique `(user_id, type)`.
- `NotificationMute` model: fillable `user_id`, `type`; `user(): BelongsTo`. `User::notificationMutes(): HasMany`.
- `event_reminders`: `id`, `event_id` (FK events, cascade), `user_id` (FK users, cascade), `starts_at` datetime, timestamps. Unique `(event_id, user_id, starts_at)` named `event_reminders_unique`.
- `EventReminder` model: fillable `event_id`, `user_id`, `starts_at`; cast `starts_at` datetime.

- [ ] Step 1: write the three migrations and two models, and the fillable/casts/relation edits. Pint each file.
- [ ] Step 2: `php artisan migrate --path=database/migrations/tenant` against the local tenant (`APP_ENV=local`, as in `reference_local_browser_verification`), or through `./scripts/tenancy/dev.sh`'s tenant migrate. Expected: three migrations ran. `php artisan migrate:rollback --step=3` and migrate again also succeed.

### Task 2: The notification type

**Files:**
- Modify: `app/Enums/UserNotificationType.php`
- Modify: `resources/js/Navigation/icons.js` (add lucide `AlarmClock`)

**Interfaces — Produces:**
- `case appointment_reminder = 'appointment.reminder'`
  - label `Afspraakherinnering`
  - icon `AlarmClock`, color `blue`
  - `requiredPermissions()` `[]`
  - `titleFor`/`bodyFor` placeholders, as `execution_times_missing` has.
- `mutable(): bool`: true only for `appointment_reminder`. It means "on unless switched off".
- `mutableCases(): array<int, self>`.
- `subscribable()` returns false for it. `unconditionalCases()` excludes mutable cases.
- `timeCritical(): bool`: true only for `appointment_reminder`. Such a notification pushes as urgent, is never folded into a summary, and uses the `reminders` channel.
- `appliesTo(User $user): bool`: `appointment_reminder` → `(bool) $user->plannable`; everything else → true.
- `supersedes()`:
  - `own_appointment_moved` → `[appointment_reminder]`
  - `removed_from_appointment` → `[own_appointment_moved, appointment_reminder]`.

- [ ] Step 1: add the case and answer every `match` for it. Pint.
- [ ] Step 2: `php artisan tinker --execute 'dump(App\Enums\UserNotificationType::unconditionalCases(), App\Enums\UserNotificationType::mutableCases());'`. Expected: the reminder is in `mutableCases` only.

### Task 3: One push layer, two channels

**Files:**
- Create: `app/Services/Push/PushMessage.php`, `app/Services/Push/PushChannel.php`, `app/Services/Push/WebPushChannel.php` (moved from `app/Services/WebPushSender.php`, which is deleted), `app/Services/Push/FirebaseChannel.php`, `app/Services/Push/PushNotifications.php`
- Create: `app/Jobs/SendPushNotificationsJob.php` (replaces `app/Jobs/SendWebPushNotificationsJob.php`, deleted)
- Modify: `app/Listeners/NotifySubscribers.php`, `app/Console/Commands/NotifyMissingExecutionTimes.php` (use `PushNotifications`, `SendPushNotificationsJob`)

**Interfaces — Produces:**
- `final class PushMessage`, readonly:
  - properties `title`, `body`, `data` (array), `urgency` ('high'|'normal'|'low'), `ttl` (int seconds)
  - `static fromNotification(UserNotification $notification): self`
  - `static summary(Collection $notifications): self`
  - `static batchesFor(Collection $notifications): array<int, list<self>>`, keyed by user id. Time-critical notifications always travel on their own. The others are folded into one summary above 5 (`SUMMARY_FROM`, moved here). Notifications with `expires_at` in the past are dropped.
  - `webPayload(): array`: `['notification' => [title, body], 'data' => data]`
  - `firebaseData(): array<string, string>`: title, body and every data value, each a string; arrays are JSON-encoded.
  - The data keys are `type`, `id`, `priority`, `url`, `tag`, `channel` ('reminders'|'urgent'|'general'), plus `actions` when the notification has any.
  - `ttl` = seconds until `expires_at` when one is set, else `config('webpush.ttl')`.
  - `urgency` = 'high' for time-critical, else by priority, as today.
- `interface PushChannel { public function isConfigured(): bool; public function send(array $batches): void; }`, where `$batches` is `array<int user_id, list<PushMessage>>`.
- `WebPushChannel`:
  - today's `WebPushSender` minus the message building
  - per message, `queueNotification(..., json_encode($message->webPayload()), ['urgency' => $message->urgency, 'TTL' => $message->ttl])`.
- `FirebaseChannel`:
  - `isConfigured()` is true when `config('firebase.projects.' . config('firebase.default') . '.credentials')` is filled
  - `send()` resolves `Kreait\Firebase\Contract\Messaging` lazily
  - per user, per message: `sendMulticast(CloudMessage::new()->withData($message->firebaseData())->withAndroidConfig(['priority' => $message->urgency === 'high' ? 'high' : 'normal', 'ttl' => $message->ttl . 's']), $tokens)`
  - deletes `DeviceToken`s in `unknownTokens()` + `invalidTokens()`
  - logs any other failure as a warning, the way the web push channel does.
- `final class PushNotifications`:
  - `isConfigured(): bool` (any channel)
  - `send(iterable $notifications): void`: builds the batches once and gives them to each configured channel. One failing channel is logged and never stops the other.
- `SendPushNotificationsJob(array $notification_ids)`, same contract as the old job.

- [ ] Step 1: write the five classes and the job. Delete the old two. Point the listener and the command at the new names. Pint each.
- [ ] Step 2: `grep -rn "WebPushSender\|SendWebPushNotificationsJob" app routes tests config`. Expected: no hits.
- [ ] Step 3 (Review Focus 3 and 4): a scratchpad tinker script builds 7 unsaved `UserNotification`s for one user. One is an `appointment.reminder` with `expires_at` 10 minutes ahead and two actions; one is a normal one whose `expires_at` has passed; the rest are plain. It dumps `PushMessage::batchesFor(...)`. Expected:
  - two messages: the reminder (urgency high, ttl ≈ 600, channel `reminders`, actions present) and one summary covering the 5 remaining non-expired ones
  - `firebaseData()` holds only strings.

### Task 4: The reminder itself

**Files:**
- Create: `app/Services/AppointmentReminders.php`, `app/Jobs/SendAppointmentRemindersJob.php`
- Modify: `routes/console.php` (schedule), `app/Models/UserNotification.php` (`linkPath()`)

**Interfaces:**
- Consumes: Task 1 tables, Task 2 type, `PushNotifications::send()`.
- Produces:
  - `AppointmentReminders::SETTING = 'appointment_reminder_minutes'`, `DEFAULT_MINUTES = 60`, `MIN_MINUTES = 5`, `MAX_MINUTES = 240`
  - `static leadMinutes(): int`, which clamps a stored value into the bounds
  - `static saveLeadMinutes(int $minutes): void`
  - `write(CarbonInterface $now): Collection<UserNotification>`.
- `SendAppointmentRemindersJob::handle(AppointmentReminders $reminders, PushNotifications $push)` calls `$push->send($reminders->write(now()))`. Sending happens in this job, with no extra hop.
- Schedule: `Schedule::call(fn () => $forEachTenant(fn () => SendAppointmentRemindersJob::dispatch()))->everyFiveMinutes()->name('appointment-reminders')->withoutOverlapping();`
- `write()` rules, from the spec:
  - Candidates are events whose `start` falls on the local day of `$now` or on the local day of `$now + lead`, that are not cancelled and not `is_preliminary`.
  - Eager-loaded with:
    - `executingUsers` limited to plannable, not trashed, not muting `appointment.reminder`
    - `executions`, `serviceOrders.customer`, `customers`
    - `EventLocationResolver::relations()`.
  - Per member: `[$start] = $event->plannedTimesFor($member, Clock::zone())`. Due when `$now < $start <= $now + lead`. Skip a member whose execution has `actual_start`.
  - Guard: `EventReminder::insertOrIgnore([...])` with explicit timestamps. Write only when it inserted 1 row.
  - In one transaction with that guard:
    - delete the member's unread `appointment.reminder` rows for this event
    - create the `UserNotification` (priority `normaal`, subject the Event, `expires_at` = start, `actions`).
- Copy (my call, spec leaves wording to the builder):
  - Title: `Om H:i bij {customer}`; with no customer, `Om H:i: {event name}`; with neither, `Afspraak om H:i`.
  - Body: the order label (`Werkbon {number}`) and `display_location`, comma-joined. With no order, the event name stands in when the customer took the title. When everything is empty: `Je afspraak begint bijna.`
- Actions:
  - `route`, title `Route`, url `https://www.google.com/maps/dir/?api=1&destination=` + rawurlencode(display_location). Only when there is a location.
  - `call`, title `Bellen`, url `tel:` + the customer's mobile, else phone, with everything except digits and `+` stripped. Only when something is left.
- `UserNotification::linkPath()`: for an `Event` subject of type `appointment.reminder`, `/serviceorders/{first order id}` when there is one, else `plannerPath()`.

- [ ] Step 1: write the service, the job and the schedule line, and the `linkPath()` change. Pint each.
- [ ] Step 2: `php artisan schedule:list | grep appointment-reminders`. Expected: one line, every five minutes.
- [ ] Step 3: in the local tenant (tinker, `APP_ENV=local`), create an event 30 minutes ahead with a plannable executor and a service order with a customer, then run `app(App\Services\AppointmentReminders::class)->write(now())`. Expected: one notification with the right title, body, actions, `linkPath()` = `/serviceorders/{id}` and `expires_at` = start. A second run gives zero.
- [ ] Step 4 (Review Focus 1 and 2):
  - Move the event 10 minutes later and run: one new reminder, and the old unread one is gone.
  - Give the executor diverging times that start 90 minutes from now (lead 60) and run: zero. Move the diverging start 30 minutes ahead and run: one reminder, titled with that time.
  - Run two `write()` calls back to back on the same state: the second gives zero.
  - Clean up the test rows.

### Task 5: The lead-time setting

**Files:**
- Create: `app/Http/Requests/Admin/UpdateAppointmentReminderRequest.php`
- Modify: `app/Http/Controllers/Admin/GeneralSettingsController.php`, `routes/web.php`, `resources/js/Pages/Admin/GeneralSettingsPage.vue`

**Interfaces:**
- Request:
  - `authorize()`: `$this->user()?->isAdmin() ?? false`
  - `rules()`: `'minutes' => ['required', 'integer', 'min:' . AppointmentReminders::MIN_MINUTES, 'max:' . AppointmentReminders::MAX_MINUTES]`.
- Controller:
  - `index()` passes `'appointmentReminderMinutes' => AppointmentReminders::leadMinutes()`
  - `updateAppointmentReminder()` saves and redirects back with `Instellingen opgeslagen.`
- Route: `PUT admin/settings/appointment-reminder`, named `admin.settings.appointment-reminder`.
- Page: under "Planning", a number field "Afspraakherinnering, minuten van tevoren" with Opslaan, shaped like the minimum-photos form.

- [ ] Step 1: build it. Pint and eslint.
- [ ] Step 2: in the browser as demo admin, save 45, reload (shows 45), save 3 (shows the error under the field), then save 60.

### Task 6: The switch to turn it off

**Files:**
- Create: `app/Http/Controllers/NotificationMuteController.php`, `app/Http/Requests/NotificationMuteStoreRequest.php`, `app/Http/Requests/NotificationMuteDestroyRequest.php`, `app/Policies/NotificationMutePolicy.php`
- Modify: `routes/web.php`, `app/Http/Controllers/NotificationSubscriptionController.php`, `resources/js/Pages/NotificationSubscriptions/IndexPage.vue`

**Interfaces:**
- Routes: `Route::resource('notificationmutes', NotificationMuteController::class)->only(['store', 'destroy'])`, next to the subscriptions route.
- Store:
  - body `user_id` (nullable, exists and not trashed) and `type` (in the values of `mutableCases()`, and not already muted for that user)
  - authorize `can('create', [NotificationMute::class, $request->subject()])`
  - `subject()` resolves the user the same way `subscriber()` does in the subscription request.
- Destroy: authorize `can('delete', $mute)`.
- Policy: the same rule as `NotificationSubscriptionPolicy`. Your own, or the permission `usernotification.manage_subscriptions`.
- Subscriptions index passes `mutable`: per type `value`, `label`, `description`, `icon`, `color`, `mute_id` (or null) and `applies` (`appliesTo($subject)`).
- Page:
  - the mutable rows go above the subscriptions in the same list, with the switch on when `mute_id` is null
  - turning it off POSTs `/notificationmutes`, turning it on DELETEs `/notificationmutes/{id}`
  - when `applies` is false, an amber line: `{name} is niet inplanbaar en krijgt deze herinnering dus niet.`

- [ ] Step 1: build it. Pint and eslint.
- [ ] Step 2: in the browser, switch off, reload (still off, with a row in `notification_mutes`), switch on (the row is gone). As admin, do the same for a colleague.

### Task 7: Buttons in the browser

**Files:**
- Modify: `resources/service-worker.js`

- [ ] Step 1:
  - In `push`, set `options.actions` from `data.actions` (`{action, title}` only) when present.
  - In `notificationclick`, when `event.action` names an entry in `data.actions`, open its `url` in a new window and never navigate the app window. When `openWindow` fails for a `tel:` url, fall back to opening `data.url`.
  - Then run eslint and `npm run build`, which copies it to `public/service-worker.js`.
- [ ] Step 2: in desktop Chrome with push allowed for the demo user, write a reminder (Task 4, step 3) and send it. Expected: a banner with Route and Bellen. Route opens Google Maps with the address; the body opens the service order.

### Task 8: Android, natively

**Files:**
- Create: `android/app/src/main/java/nl/lavoro/fsm/LavoroMessagingService.java`, `android/app/src/main/java/nl/lavoro/fsm/PushSupportPlugin.java`, `android/app/src/main/res/drawable/ic_stat_notification.xml`
- Modify: `android/app/src/main/AndroidManifest.xml`, `android/app/build.gradle`, `android/app/src/main/java/nl/lavoro/fsm/MainActivity.java`, `android/app/src/main/res/values/strings.xml`

**Interfaces — Produces:**
- `BuildConfig.PUSH_AVAILABLE` (boolean): true when `google-services.json` exists with content.
- Capacitor plugin `PushSupport`:
  - `available() → {available: boolean}`
  - `batteryExempt() → {exempt: boolean}`
  - `requestBatteryExemption() → {exempt: boolean}`. It first shows an AlertDialog that explains why, using the strings `battery_disclosure_*`. Only "Toestaan" goes on to `Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`. "Niet nu" holds until the next app start, as in `LocationTrackerPlugin`.
- `LavoroMessagingService extends com.capacitorjs.plugins.pushnotifications.MessagingService`. In `onMessageReceived`, it calls super and then draws the data message:
  - channels `reminders` ("Afspraakherinneringen", HIGH), `urgent` ("Dringende meldingen", HIGH) and `general` ("Meldingen", DEFAULT)
  - `notify(tag, 0, …)` so a tag replaces
  - the content intent is `ACTION_VIEW` on `SERVER_URL + url`, explicit to `MainActivity`
  - actions: a `tel:` url gets `ACTION_DIAL`, anything else `ACTION_VIEW`
  - `PendingIntent.FLAG_IMMUTABLE`.
- Manifest:
  - `tools:node="remove"` on the plugin's `MessagingService`, and our service registered for `com.google.firebase.MESSAGING_EVENT`
  - `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` permission.
- `MainActivity`:
  - `registerPlugin(PushSupportPlugin.class)`
  - a launch intent `ACTION_VIEW` on the server's scheme+host+port sets `CapConfig.Builder.setStartPath(path + query)`
  - a running app gets the same intent through `appUrlOpen`, which `useDeepLinks` already follows.

- [ ] Step 1: write it.
- [ ] Step 2: `cd android && JAVA_HOME=/usr/lib/jvm/java-21-openjdk-amd64 ./gradlew :app:compileDebugJavaWithJavac`. Expected: BUILD SUCCESSFUL. Run `npx cap sync android` first if the Capacitor plugin sources are not linked yet.

### Task 9: Wiring it up in JS

**Files:**
- Modify: `resources/js/Composables/usePushNotifications.js`, `resources/js/Layouts/MainLayout.vue`, `app/Http/Middleware/HandleInertiaRequests.php` (`plannable` in `auth.user`)

**Interfaces:**
- `usePushNotifications()` returns:
  - `register()`, which first asks `PushSupport.available()` and replaces the `PUSH_ENABLED` constant
  - `unregister()`, with no argument, which deletes the token this launch registered
  - `askBatteryExemption()`.
- Taps on system-drawn notifications go to `data.url`, else `/serviceorders/{id}` for `service_order_assigned`.
- MainLayout:
  - registers push on native
  - asks for the battery exemption when `auth.user.plannable`
  - calls `unregister()` in `logout()` before `/logout`, next to `disable_web_push`.

- [ ] Step 1: build it. Eslint each file, pint the middleware.
- [ ] Step 2: `npm run build`. Expected: success.
- [ ] Step 3: the browser shows nothing new and no console errors.
- [ ] Step 4 (Review Focus 5): read through `logout()`. `unregister()` runs while still authenticated, and its failure never blocks logout.

### Task 10: Operations and the manual

**Files:**
- Modify: `app/Console/Commands/TenancyDoctor.php` (`checkFirebase()` next to `checkWebPush()`), `docs/install/android.md`, `docs/guide/handleiding.md`, the spec

- [ ] Step 1: `checkFirebase()`.
  - Blank credentials are bad, with this message: `FIREBASE_CREDENTIALS is empty -- the Android app gets no notifications. Put the path to the Firebase service account JSON in .env`.
  - Unreadable or invalid JSON is bad.
  - Otherwise pass `firebase credentials set`.
- [ ] Step 2: `php artisan tenancy:doctor 2>&1 | grep -i firebase`. Expected: the bad line, locally.
- [ ] Step 3: `docs/install/android.md`: Firebase project, `google-services.json`, `FIREBASE_CREDENTIALS`, and the battery permission.
- [ ] Step 4: `handleiding.md`:
  - Meldingen: the reminder, the switch, and the setting under Instellingen → Algemeen.
  - Mobiel: the app now shows notifications, asks for the battery exemption, and what to set on Xiaomi/Huawei/Samsung. Remove "de app toont ze (nog) niet".
- [ ] Step 5: sync the spec with the storage choices made here.
- [ ] Step 6: `flock /tmp/claude-1000/-home-guido-nvme0n1p1-code-lavoro/test-db.lock php artisan test`. Expected: the same failures as before this work, or none.
