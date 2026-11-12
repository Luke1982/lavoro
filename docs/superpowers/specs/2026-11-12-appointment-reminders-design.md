# Appointment reminders — design

Date: 2026-11-12. Status: approved and built (see `docs/superpowers/plans/2026-11-12-appointment-reminders.md`).

Calls marked **(my call)** were made while writing this and have not been confirmed by the product owner. Everything else was asked for.

## Intent

A mechanic between two jobs gets a notification shortly before their next appointment, so they leave on time and can call the customer to say they are on their way. From that one notification they reach the three things they need without opening the planner: the service order, the route, and the customer's phone number.

The reminder has to arrive on a phone that is asleep in a van. A reminder that arrives after the appointment has started is worse than none.

## Goals

- A reminder before the start of every appointment, to each person carrying it out who is plannable (`users.plannable`).
- The time between reminder and start is a company setting, default 60 minutes.
- Each person can switch the reminder off for themselves.
- Tapping the notification opens the service order.
- Two buttons: **Route** opens Google Maps with directions to the appointment's address. **Bellen** opens the dialer with the customer's number.
- Delivered in the desktop browser, the PWA (Android Chrome, iOS home-screen install) and the Android app.
- Gets through Android's battery saving (Doze): sent as urgent, and only valid until the appointment starts.

## Non-goals

- A lead time per person. It is one company setting.
- Reminders for provisional appointments (`events.is_preliminary`). One that is confirmed inside the window gets its reminder on the next tick.
- Reminders for people who are not plannable, or for planners about other people's appointments.
- Reminders for appointments imported from Google Calendar (`events.origin = 'google'`). Google already reminds about them, they include private appointments, and all-day ones would "start" at midnight.
- Text message or email.
- Alarms scheduled on the device itself (local notifications), or reminders without a network connection.
- The native iOS app.
- Buttons in the in-app notification list. There the reminder behaves like any other notification and opens the service order.

## Anti-goals: failures even if everything technically works

- A reminder that arrives after the appointment started. Today pushes live for 24 hours (`config/webpush.php`), and that must not apply here.
- A reminder for an appointment that was cancelled or moved, or that you were removed from.
- More than one reminder per person for the same appointment and start time.
- A customer's address and phone number showing up on a phone that someone else now uses. Logging out must stop delivery to that device.
- A failure to send that breaks anything else. Sending is best effort, like the existing pushes.

## How the platforms differ

| Where | Gets there via | Buttons | Weak spots |
|---|---|---|---|
| Desktop browser | Web push (exists) | Chrome, Edge and Firefox show two | Nothing arrives while the browser is closed |
| PWA / Chrome on Android | Web push, which Chrome carries over Firebase | Two | Phone makers' battery killers (Xiaomi, Huawei, Samsung) can stop Chrome |
| PWA on iPhone | Web push, only when installed to the home screen | None; tapping still works | — |
| Android app | Firebase, **not wired up today** | Two, drawn by our own native code | Battery killers too, but the user can exempt the app |

Timers kept on the device (a page timer, the abandoned Notification Triggers API) stop when the phone sleeps. That is why the server decides when to send and pushes the reminder.

## What exists to build on

- `UserNotification` plus `SendWebPushNotificationsJob` plus `WebPushSender`. Every in-app notification is pushed to the user's browsers after commit. The payload shape is read by `resources/service-worker.js`.
- `notifications:missing-times` (`NotifyMissingExecutionTimes`, scheduled per tenant at 07:00) is the model for a time-driven notification without a signal:
  - it writes `UserNotification` rows itself
  - it dedupes on existing rows
  - it builds the sentence from the service order number, customer and `display_location`.
- `UserNotificationType` already has types that come no matter what (`own_appointment_moved`, `removed_from_appointment`). It also has `supersedes()` for dropping an unread notification that a later one makes outdated.
- `EventLocationResolver` resolves an appointment's address, falling back to the customer's.
- Firebase (Android app):
  - `@capacitor/push-notifications` is in the app.
  - `device_tokens` are stored through `/api/device-tokens`, and `User::routeNotificationForFcm()` exists.
  - `kreait/laravel-firebase` and `laravel-notification-channels/fcm` are installed. Only `NewServiceOrderAssigned` uses them.
  - There is no `android/app/google-services.json` and no `FIREBASE_CREDENTIALS`, so in practice the Android app gets no push at all today.
- `GeneralSetting::get/set` stores company settings per tenant.

## Behaviour

### Who gets one

For an appointment (`Event`) that is not cancelled, not provisional (`is_preliminary`), and whose start is still in the future, every executing user:

- who is plannable
- who is not deleted
- who has not switched the reminder off
- who has not already started their own execution of it. An `EventExecution` with `actual_start` means they are already there. **(my call)**

**"Start" means this person's own planned start** **(my call)**:

- That is `Event::plannedTimesFor($user)`, which picks up `userables.has_diverging_times` / `diverging_start`. The planner export and `EventExecutionController` already use it.
- A mechanic planned from 13:00 on an appointment that runs 08:00–17:00 gets their reminder at 12:00, saying "om 13:00".
- Everything below that says "start" means this per-person start: the window, the dedupe, the lifetime (TTL) and the time in the title.
- So the job cannot select only on `events.start`. It must also catch appointments where someone's own start falls in the window.

### When

- Company setting: minutes before start. Stored in `GeneralSetting`, default 60. An admin edits it on the general settings page. Validated in the Form Request between 5 and 240 **(my call on the bounds)**.
- One job per tenant, every five minutes **(my call)**, scheduled in `routes/console.php` through `Tenancy::forEachReachable`, like the other tasks. The job sends for (person, appointment) when `start − lead ≤ now < start` and that person has not had a reminder for this start yet. Consequences:
  - The reminder lands between `lead` and `lead − 5` minutes before the start. The text therefore states the clock time ("om 10:00"), not "over een uur", so it is never wrong.
  - An appointment planned inside the window (20 minutes before it starts) still gets a reminder on the next tick **(my call)**.
  - If the scheduler was down, a missed reminder still goes out as long as the appointment has not started. It never goes out after the start.
- Moved appointment: "one per person per appointment" means one per person per appointment **and start time**. A moved appointment gets a reminder for its new time.
  - A moved appointment already raises `own_appointment_moved`, which should supersede an unread reminder for the old time.
  - `removed_from_appointment` supersedes it as well.
  - Changing one person's own times raises no signal (`moved_in_time` only looks at `events.start`/`end`). And whoever moves their own appointment is skipped as the actor of `own_appointment_moved`. So on each tick the job withdraws every claim on a start still ahead that no longer holds, and deletes the unread reminder that goes with it **(my call)**. A claim no longer holds when:
    - the appointment is gone, cancelled, completed or provisional
    - the person is no longer on it
    - their planned start differs.

    With the claim gone, a start that comes back is reminded again.
  - Once its start has passed, a reminder is acknowledged automatically (`read_at` = `expires_at`). It stays in the list but no longer counts on the bell, which would otherwise grow by every appointment a mechanic has **(my call)**.
    - This only happens within 30 minutes of the start.
    - A reminder someone marks unread again later stays unread.
    - Claims on starts that have passed are pruned.
- **What happens on the phone after a reminder has gone out** **(my call)**:
  - Nothing is withdrawn on its own. The reminder uses the existing tag `event-{id}`, not a tag per start. That way:
    - the push for "Jouw afspraak verplaatst" or "Van afspraak afgehaald", which carries that same tag, replaces the reminder's banner on the phone
    - and a new reminder for a new time replaces the old banner too.
  - That covers moving and removing, on web push and in the native app, at no extra cost.
  - A cancellation sends no notification today. Its banner stays on the phone. The unread row goes on the next tick (see above).
  - A silent "withdraw" message is a non-goal: Chrome shows every push it receives, so a silent one cannot be sent.

### What it says

- Title: the type's label, "Afspraakherinnering".
- Body: one plain sentence saying when and where to be: "Let op, je moet om 09:30 bij {klant} zijn, op {adres}." Without a customer it names the address and the appointment ("… op {adres} zijn voor {afspraak}."); with neither, "Let op, om 09:30 begint {afspraak}." The service order number is left out, because a tap opens it.

### Tapping

- Opens `/serviceorders/{id}` for the appointment's first service order.
- With more than one order, the first one, as in `NotifyMissingExecutionTimes` **(my call)**.
- An appointment without a service order opens the planner at that appointment, which is what `UserNotification::plannerPath()` already does.
- The notification's subject stays the `Event`, because dedupe and superseding hang off it. `linkPath()` resolves to the service order for this type.

### Buttons

- **Route:** `https://www.google.com/maps/dir/?api=1&destination=<url-encoded address>`. That URL opens the Maps app on Android when it is installed.
  - The address comes from `EventLocationResolver`.
  - Whether to use stored coordinates (`EventLocationResolver::coordinates`) instead is left to the builder.
  - No address means no button.
- **Bellen:** `tel:` with the first dialable number in the customer's mobile field, else in their phone field (`App\Support\PhoneNumber`, shared with `/dial`'s validation). The rest of the field is ignored. Service orders and appointments have no contact person, so the customer is all there is **(my call)**. The customer comes from the first service order, else from the appointment's own customers (as in `NotifyMissingExecutionTimes::customerName()`). No number means no button.
- Both links are built when the reminder is sent, from the appointment as it is then. `user_notifications` has no column for them, and does not need one.

### Opting out

- A new, third kind of notification type: **on unless you switch it off**. Today there are only opt-in types (subscribable) and types that always come (unconditional).
- It appears on the Meldingen screen (`NotificationSubscriptions/IndexPage`) as a switch that starts out on.
- The existing `manageOthers` right lets a manager switch it for a colleague.
- Stored as a row in `notification_mutes` (`user_id`, `type`). The existing `notification_subscriptions` row means "I want this", and reusing it with the opposite meaning would confuse every query that reads it.

### Delivery

- **Urgent and short-lived for this type only:**
  - push urgency `high`
  - lifetime (TTL) = seconds until the start
  - the same values on Firebase (`android.priority = high`, `android.ttl`).
- Its bell priority stays `normaal`. This is not a fault report, and `hoog` would keep it on screen as `requireInteraction`.
- **Never rolled into the summary.** `WebPushSender` folds more than 5 notifications for one person into one "N nieuwe meldingen" banner. A reminder must always arrive as itself, with its buttons.
- Tag `event-{id}`, which is what `WebPushSender::payloadFor()` already produces for an `Event` subject. A resend, and the move/remove notifications, replace the banner instead of stacking next to it.
- One payload builder serves both web push and Firebase. `WebPushSender::payloadFor()` becomes the shared piece instead of being copied.

#### Browser and PWA

- The payload gets `actions` (`route`, `call`) when there are links, plus `data.route_url` and `data.call_url`.
- The service worker passes `actions` to `showNotification`. In `notificationclick`:
  - `event.action === 'route'` opens the route URL in a new window
  - `'call'` opens the `tel:` link.
  - Neither may navigate the app window that the current handler reuses.
- `call` opens the same-origin page `/dial?number=…`, with one large `tel:` link. The spike could not be run without a device, and a browser hands `tel:` from a notification to the dialer unreliably, so the reliable route was chosen at the cost of one extra tap. The native app dials directly.

#### Android app

1. **Operations.**
   - A Firebase project for `nl.lavoro.fsm`.
   - `google-services.json` in the Android build. It is gitignored; see `docs/install/android.md`.
   - A service account JSON on the server as `FIREBASE_CREDENTIALS`. One Firebase project serves all tenants.
   - `tenancy:doctor` reports missing or unreadable Firebase credentials. Like the web push check, it is an environment check and reads no tenant database.
2. **Server.**
   - The push job sends every `UserNotification`, not only reminders, to the user's `device_tokens` as well as to their browsers. As a result, every existing notification type starts reaching the app, which it does not today (confirmed).
   - Data-only messages with the shared payload. That includes the same "more than 5 becomes one summary" rule from `messagesFor()`, with reminders exempt.
   - Tokens Firebase reports as unregistered are deleted, as expired web subscriptions already are.
   - Whether to use the notification channel package or the kreait messaging client directly is left to the builder.
   - Someone who has the app and also a Chrome/PWA subscription on the same phone gets everything twice. That is accepted.
   - `NewServiceOrderAssigned` goes. It sent a system-drawn FCM message of its own, which would have gone live alongside this pipeline: a generic icon and channel, the record id instead of the werkbon number, and its tap lost when the app was closed (MainActivity swaps the launch intent so it cannot be replayed). It became a mandatory notification type, `assigned_to_serviceorder`, written by `NotifySubscribers` from `ServiceOrderAssigned` for the newly assigned users only, exactly like `removed_from_appointment`. With it go `NotifyAssignedUsers`, `User::routeNotificationForFcm()`, the `pushNotificationActionPerformed` handler and the `laravel-notification-channels/fcm` package; `kreait/laravel-firebase` is now required directly.
3. **Native.**
   - A messaging service in `android/app/src/main/java/nl/lavoro/fsm/` renders these data messages itself, for every type and not only reminders:
     - two notification channels: "Afspraakherinneringen" with high importance (heads-up) for reminders, and "Meldingen" for the rest, where a `hoog` notification is also drawn with high importance
     - the payload's `tag` becomes the Android notification tag, so replacing works as it does in the browser
     - tapping opens `MainActivity` with the path
     - **Route** is an `ACTION_VIEW` on the maps URL
     - **Bellen** is an `ACTION_DIAL` on `tel:`, so no `CALL_PHONE` permission is needed.
   - It has to live next to `@capacitor/push-notifications`, whose own messaging service handles the token. Extend it or forward to it. Check against the plugin's 8.x source.
   - This has to be native because a notification the system draws for a closed app cannot carry buttons, and the app's JavaScript does not run while it is closed.
4. **MainActivity** opens the path from the notification intent (`onCreate` and `onNewIntent`) in the webview. Only paths, and only on `BuildConfig.SERVER_URL`.
5. **JavaScript.**
   - `usePushNotifications` handles `data.url` in general instead of only `service_order_assigned`.
   - On logout it calls `unregister()`. Today `MainLayout.vue` removes the browser subscription on logout but leaves the device token, so a shared phone would keep getting the previous user's customers' addresses.
     - `unregister()` deletes the token on the server and in Firebase, and resets the registration, so the next login in the same app session registers afresh.
     - The server also deletes the token this session handed in when the session logs out (`ForgetDeviceToken`), in case the app's own request failed.
6. **Battery.**
   - For a plannable user, the app explains why reminders must arrive on time and then asks Android for the `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` exemption (asked for by the product owner).
   - "Niet nu" holds until the next app start.
   - Play policy restricts this permission, so the reason is written down for review in `docs/install/android.md`.
   - The manual also tells mechanics on Xiaomi, Huawei and Samsung phones about the manufacturer's own battery saving.
7. The native changes need a new app build handed out to the mechanics.

## Constraints

- Multi-tenancy:
  - The scheduler loops tenants and dispatches one job per tenant; the work happens in the job.
  - The lead time is per tenant (`GeneralSetting`).
  - Firebase credentials are global.
  - Device tokens and push subscriptions live in the tenant database.
- No signal: nothing happens at "start minus an hour", so a job writes the rows, the same reasoning as `notifications:missing-times`. The reminder does not belong in the activity trail.
- Authorization through policies and Form Requests. Validation only in `rules()`.
- `docs/guide/handleiding.md`:
  - the reminder
  - the switch
  - the setting
  - the battery advice for the Android app.
- No automated tests unless asked (CLAUDE.md). Verify in the running app instead (below).

## How it is checked

- A real Android phone in forced Doze (`adb shell dumpsys deviceidle force-idle`): the reminder arrives with both buttons:
  - with the app closed
  - in Chrome / the PWA.
  - Route opens Maps, Bellen opens the dialer with the number, tapping opens the service order.
- Desktop Chrome shows both buttons and both work.
- Move an appointment after its reminder: the unread old reminder is gone, and a new one comes at the new time.
- Remove a person: their reminder is gone. Cancel the appointment: no reminder.
- Switching it off stops it. A changed lead time applies from the next tick.
- Log out of the app: that phone gets nothing after that.
- `tenancy:doctor` warns when there are device tokens and no Firebase credentials.

## Left to the builder

- Exact Dutch wording.
- ~~How opting out is stored~~: `notification_mutes`.
- ~~How "already reminded for this start" is stored~~: `event_reminders`, with a unique key on (event, user, start). An insert-or-ignore on that key is the guard against two runs overlapping.
- ~~Which Firebase library route to use~~: the kreait messaging client directly, through `FirebaseChannel`, with `sendMulticast`.
- The buttons and the expiry are stored on the notification itself (`user_notifications.actions`, `expires_at`), written once like its title and body, so the push layer needs no knowledge of reminders.
- The `tel:` fallback page, after the spike.
- Where the lead-time field sits on the settings page.
- Address or coordinates for Route.
