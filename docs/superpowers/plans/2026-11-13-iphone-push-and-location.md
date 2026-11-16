# iPhone app: push and location — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An iPhone build of the Lavoro shell where push notifications arrive and open the right page, and the mechanic's location is shared during working hours, including while the app is in the background, without changing what the Android app, the browser or the PWA do.

**Architecture:** `npx cap add ios` generates the iOS project. It runs on Linux and uses Swift Package Manager (verified 2026-11-13). Codemagic builds it, because nobody here has a Mac.

- **Location:** an iPhone version of the Android `LocationTracker` plugin, with the same name, the same `start({ serverUrl, window })` and `stop()`, and the same rules:
  - one ping every 5 minutes, only inside the company's tracking window;
  - a queue of at most 200 pings that survives the app being closed;
  - posting with the webview's cookies, and one retry with a fresh CSRF token after a 419.

  Because the JS contract is identical, `useLocationTracker.js` does not change at all. It runs on "When In Use" permission plus the `location` background mode: tracking continues with the app in the background or the phone locked, and iOS shows its blue location indicator. It stops if the mechanic swipes the app away, and starts again when they open it.
- **Push:** the iPhone hands in the plain APNs token from `@capacitor/push-notifications`. A new server-side `ApnsChannel` sends to Apple, and `FirebaseChannel` stops sending to iOS tokens. There is no Firebase SDK on the iPhone.

**Tech Stack:** Capacitor 8.4 (iOS via SPM), Swift with CoreLocation, Laravel HTTP client over HTTP/2, `firebase/php-jwt` (ES256), Vitest (jsdom), PHPUnit on MySQL, Codemagic, and a rented Mac (MacinCloud) for the simulator.

**Spec:** There is no separate spec. The decisions above come from the App Store conversation of 2026-11-13, and this plan carries them.

## Global Constraints

- Nothing under `android/` changes, and nothing in `useLocationTracker.js` changes.
- Android, browser and PWA behaviour do not change. Every JS change leaves the existing code path for `platform === 'android'` and `platform === 'web'` as it is, and new behaviour sits behind `platform === 'ios'`.
- The iPhone location code follows the Android code rule for rule. Where this plan does not say otherwise, `LocationForegroundService.java`, `LocationTrackerPlugin.java` and `TrackingWindow.java` are the spec.
- Bundle id `nl.lavoro.fsm`. Server `https://app.lavorofsm.nl`. APNs host `https://api.push.apple.com`: TestFlight and App Store builds always use production APNs.
- Swift, plist, storyboard and `project.pbxproj` changes cannot be compiled here. A task that touches them is not done until the Codemagic `ios-simulator` workflow passes. Codemagic builds from GitHub, so ask before pushing a branch for it.
- PHP: snake_case variables, no inline comments (docblocks only), and `./vendor/bin/pint <file>` on every edited file, never on a directory.
- JS: `npx eslint --fix <file>` on every edited file.
- PHP tests: `flock /tmp/claude-1000/-home-guido-nvme0n1p1-code-lavoro/test-db.lock php artisan test --filter=<Name>`. JS tests: `npx vitest run <spec path>`.
- Text that people read (iOS permission prompts, `docs/guide/handleiding.md`) is in Dutch.
- Every task ends at a checkpoint: show the diff of that task's files and wait for approval. Nothing gets committed without it.

## Review Focus

1. **Expired session.** The mechanic's session expires while the app sits in the background. The first upload gets a 419; after the CSRF refresh the same pings arrive, and none are lost. Checked in Task 2, simulator step 6.
2. **End of the tracking window.** At the end time, with the app in the background, pings stop, the blue indicator disappears, and no ping is recorded outside the window. Checked in Task 2, simulator step 5.
3. **Two phones.** A user with an Android phone and an iPhone gets each message once on each: the Android token through Firebase only, the iPhone token through APNs only. Covered in Task 3 by `test_firebase_skips_iphone_tokens` and `test_android_tokens_are_left_alone`.
4. **Refused tokens.** When APNs refuses a token for good (410, `BadDeviceToken`, `DeviceTokenNotForTopic`), that row is deleted. When the refusal is about us (403 `InvalidProviderToken`, 429, 5xx), the token is kept and a warning is logged. Covered in Task 3.
5. **Bad tap targets.** A tapped notification with no `url`, or with a URL on another host, does nothing. It must never navigate to `/undefined`. Covered in Task 4 by the `followLink` tests.

---

### Task 0: Accounts and keys (by hand, outside the repo)

No code. Task 1 and Task 2 need only the Codemagic account and a MacinCloud pay-as-you-go account (about $1 an hour, a few hours in total). Task 3 needs the APNs key to be tried for real. Task 5 needs all of these.

- [ ] Codemagic personal account (500 free macOS minutes a month), connected to the GitHub repository.
- [ ] MacinCloud pay-as-you-go, with Xcode on it, used only for the iPhone simulator.
- [ ] Apple Developer Program, enrolled as an organisation (get the D-U-N-S number first).
- [ ] App ID `nl.lavoro.fsm` with the Push Notifications capability switched on.
- [ ] APNs auth key (`.p8`). Note its Key ID and the Team ID. Put the file on the server outside the webroot, readable by the user the queue workers run as.
- [ ] App Store Connect: an app record for `nl.lavoro.fsm`, plus an API key with the App Manager role, added to Codemagic as an integration named `lavoro-asc`.

---

### Task 1: iOS shell that opens Lavoro

**Files** (as built on 2026-11-13; nothing outside these three paths changes):
- Create: `ios/`, the output of `npx cap add ios`, unedited except for the changes below
- Create: `ios/App/App/LavoroViewController.swift`
- Create: `ios/App/App/LavoroNavigationDelegate.swift`
- Modify: `ios/App/App/Base.lproj/Main.storyboard`
- Modify: `ios/App/App/Info.plist`
- Modify: `ios/App/App.xcodeproj/project.pbxproj` (the two new files, `TARGETED_DEVICE_FAMILY`)
- Create: `codemagic.yaml`
- Create: `docs/install/ios.md`

**Interfaces:**
- Produces:
  - `class LavoroViewController: CAPBridgeViewController` in `LavoroViewController.swift`. Task 2 registers its plugin in its `capacitorDidLoad()`, after the line that installs the navigation delegate.
  - `final class LavoroNavigationDelegate: NSObject, WKNavigationDelegate`. It forwards everything to Capacitor's `WebViewDelegationHandler`, except that it catches `/opnieuw-verbinden` on the local origin and shows the offline page only for the failures and statuses `LavoroWebViewClient` lists. No `errorPath` is set.
  - The Info.plist key `LavoroServerURL` (string).
  - The Codemagic workflow `ios-simulator`, with the artifacts `build/App-simulator.zip` and `build/xcodebuild.log`. It fails when Xcode reports that one of our Swift methods "nearly matches" a WebKit method, because such a method would silently never run.
- Every task that adds files under `ios/` repeats the website build comparison: build a clean copy of the last commit with and without the new files, and the output must be identical. Tailwind scans `ios/` too: a class name in a Swift file would end up in the website's CSS.

- [ ] **Step 1: Generate the project**

Run `npx cap add ios` in the repo root.
Expected: `[success] ios platform added!`, with three plugins listed: `@capacitor/app`, `@capacitor/network`, `@capacitor/push-notifications`. No file under `android/` has changed.

- [ ] **Step 2: `LavoroViewController.swift` and `LavoroNavigationDelegate.swift`**

These are the iOS counterparts of `MainActivity` and `LavoroWebViewClient`.

- `LavoroViewController.instanceDescriptor()` sets `serverURL` from `LavoroServerURL` and `backgroundColor` to `#081020`.
- `LavoroViewController.capacitorDidLoad()` installs `LavoroNavigationDelegate` in front of Capacitor's handler.

Both files go into the App target by hand in `project.pbxproj`: copy the four entries `AppDelegate.swift` has.

- [ ] **Step 3: Point the storyboard at it**

In `Main.storyboard`, change the view controller to `customClass="LavoroViewController" customModule="App" customModuleProvider="target"`.

- [ ] **Step 4: Info.plist keys**

| Key | Value |
|---|---|
| `LavoroServerURL` | `https://app.lavorofsm.nl` |
| `NSCameraUsageDescription` | `Lavoro gebruikt de camera voor foto's bij werkbonnen en om serienummers te scannen.` |
| `NSPhotoLibraryUsageDescription` | `Lavoro opent je foto's als je een bestaande foto aan een werkbon toevoegt.` |
| `ITSAppUsesNonExemptEncryption` | `false` (boolean) |

Without the two camera and photo strings, the existing `<input capture="environment">` in `ImageUploadComponent.vue` crashes the app.

- [ ] **Step 5: iPhone only**

In `project.pbxproj`, set `TARGETED_DEVICE_FAMILY = 1;` in both build configurations of the App target.
Check: `grep -c 'TARGETED_DEVICE_FAMILY = 1;'` returns `2`, and `grep -c '"1,2"'` returns `0`.

- [ ] **Step 6: Offline page retry on iOS**

This is handled by `LavoroNavigationDelegate` (Step 2). `capacitor-launcher/index.html` is shared with Android and stays unchanged.

- [ ] **Step 7: (dropped)** `capacitor.config.ts` stays unchanged; the explanation is in `docs/install/ios.md`.

- [ ] **Step 8: `codemagic.yaml`**

```yaml
workflows:
  ios-simulator:
    name: iOS simulator build (no signing)
    instance_type: mac_mini_m2
    max_build_duration: 30
    environment:
      node: 22.12.0
      xcode: latest
    scripts:
      - name: Install
        script: npm ci
      - name: Sync iOS
        script: npx cap sync ios
      - name: Build for the simulator
        script: |
          xcodebuild -project ios/App/App.xcodeproj -scheme App \
            -configuration Debug -sdk iphonesimulator \
            -destination 'generic/platform=iOS Simulator' \
            -derivedDataPath build/ios-sim \
            CODE_SIGNING_ALLOWED=NO build
      - name: Package the simulator app
        script: |
          cd build/ios-sim/Build/Products/Debug-iphonesimulator
          zip -qr "$CM_BUILD_DIR/build/App-simulator.zip" App.app
    artifacts:
      - build/App-simulator.zip
```

If `xcodebuild` reports that scheme `App` does not exist, replace `-scheme App` with `-target App`.

- [ ] **Step 9: `docs/install/ios.md`**

Model it on `docs/install/android.md`. It should cover:
- What the shell adds.
- What it lacks compared with Android: no notification buttons, no App Links, and location stops when the app is swiped away.
- That builds run in Codemagic.
- When a new build is needed: the same rule as Android, plus anything under `ios/`.
- How to point a test build at another server: a Codemagic script step `plutil -replace LavoroServerURL -string <url> ios/App/App/Info.plist` before the build.
- How to run the simulator artifact on a rented Mac (copy Task 2, step 8).

- [ ] **Step 10: Verify**

1. `npx cap sync ios` exits 0.
2. No file under `android/` has changed.
3. In Codemagic, the `ios-simulator` workflow ends with `** BUILD SUCCEEDED **` and has `App-simulator.zip` as an artifact.

- [ ] **Step 11: Checkpoint.** Show the diff and wait for approval.

---

### Task 2: Location on the iPhone, also in the background

**Files:**
- Create: `ios/App/App/TrackingWindow.swift`
- Create: `ios/App/App/PingUploader.swift`
- Create: `ios/App/App/LocationTracker.swift`
- Create: `ios/App/App/LocationTrackerPlugin.swift`
- Modify: `ios/App/App.xcodeproj/project.pbxproj` (the four files go into the App target)
- Modify: `ios/App/App/AppDelegate.swift` (register the plugin)
- Modify: `ios/App/App/Info.plist`
- Modify: `docs/install/ios.md` (location section)

**Interfaces:**
- Consumes: `LavoroViewController.capacitorDidLoad()` from Task 1, and the Info.plist key `LavoroServerURL`.
- Consumes the JS contract that already exists (`useLocationTracker.js:89-99` and `:126-128`). It must not change:
  - `LocationTracker.start({ serverUrl, window })`. `window` is `{ days: number[], start: 'HH:mm', end: 'HH:mm' }` or `null`, built in `HandleInertiaRequests.php:152-165`. It resolves once tracking runs, and rejects with `Location permission denied` when permission is refused.
  - `LocationTracker.stop()` resolves.
- Produces:
  - `struct TrackingWindow`:
    - `init(_ value: [String: Any]?)`. A missing `start`, `end` or `days` gives `.always`.
    - `static let always`
    - `func contains(_ date: Date) -> Bool`: the ISO weekday (Mon=1 … Sun=7) is in `days`, and the local `HH:mm` is at least `start` and less than `end`. This is the same rule as `TrackingWindow.contains` (Java) and `is_within_window` (JS).
  - `final class PingUploader`, used through `.shared`:
    - `add(_ ping: [String: Any])` appends to the UserDefaults key `lavoro_pending_pings` and keeps the newest 200.
    - `flush()` runs one upload at a time on a serial queue.
    - The upload is `POST {LavoroServerURL}/api/location/pings` with the body `{ "pings": [...] }`.
    - Its headers are `Content-Type` and `Accept` set to `application/json`, `X-Requested-With: XMLHttpRequest`, `Origin` set to the server, `Referer` set to the server plus `/`, `Cookie` built from `WKWebsiteDataStore.default().httpCookieStore` for the server's host, and `X-XSRF-TOKEN` set to the URL-decoded `XSRF-TOKEN` cookie.
    - A 419 leads to `GET /sanctum/csrf-cookie`; its `Set-Cookie` headers are written back into that cookie store, and the post is retried once.
    - Redirects are not followed: the `URLSessionTaskDelegate` redirect callback returns `nil`.
    - On 2xx, only the pings that were sent are removed.
    - The whole flush runs inside `UIApplication.shared.beginBackgroundTask`.
  - `final class LocationTracker: NSObject, CLLocationManagerDelegate`, used through `.shared`, with `start(window: TrackingWindow)` and `stop()`.
    - Its `CLLocationManager` uses `desiredAccuracy = kCLLocationAccuracyHundredMeters`, `distanceFilter = kCLDistanceFilterNone`, `allowsBackgroundLocationUpdates = true`, `showsBackgroundLocationIndicator = true`, `pausesLocationUpdatesAutomatically = false` and `activityType = .otherNavigation`.
    - A 60-second `Timer` applies the window. Inside it, `startUpdatingLocation()`. Outside it, `stopUpdatingLocation()` and `flush()`.
    - A ping is recorded at most every 300 seconds, the first one immediately, and only while `window.contains(Date())`. The ping is `{ lat, lng, accuracy, speed, heading, recorded_at }`:
      - A location with `horizontalAccuracy < 0` is skipped.
      - `speed < 0` and `course < 0` become `null`.
      - `recorded_at` is ISO 8601 UTC with milliseconds, like Android's `iso_now()`.
    - Each ping goes to `PingUploader.shared.add` and then to `flush()`.
  - `public class LocationTrackerPlugin: CAPPlugin, CAPBridgedPlugin`, with `identifier = "LocationTrackerPlugin"`, `jsName = "LocationTracker"` and the methods `start` and `stop`.
    - `start` asks `requestWhenInUseAuthorization()` when the status is `.notDetermined`, then waits for `locationManagerDidChangeAuthorization`.
    - `.authorizedWhenInUse` or `.authorizedAlways` start the tracker and resolve. Anything else rejects with `Location permission denied`.
    - It never asks for "Always".
  - `LavoroViewController.capacitorDidLoad()` calls `bridge?.registerPluginInstance(LocationTrackerPlugin())`.

- [ ] **Step 1: Write `TrackingWindow.swift`, `PingUploader.swift`, `LocationTracker.swift` and `LocationTrackerPlugin.swift`** as described under Interfaces. Each gets a docblock naming the Android file it mirrors.

- [ ] **Step 2: Add the four files to the App target**

Edit `project.pbxproj` by hand. For each new file, copy the four entries `AppDelegate.swift` has (`PBXBuildFile`, `PBXFileReference`, a child of the `App` group, and an entry in the App target's `PBXSourcesBuildPhase`). Give each one fresh 24-hex-digit ids (`openssl rand -hex 12 | tr a-f A-F`).
Check: for each of the four file names, `grep -c` on `project.pbxproj` returns `4`.

- [ ] **Step 3: Info.plist**

Set `UIBackgroundModes` to an array with `location`.

| Key | Value |
|---|---|
| `NSLocationWhenInUseUsageDescription` | `Lavoro deelt binnen de werktijden die je bedrijf heeft ingesteld je locatie met de planner, zodat die ziet waar de monteurs zijn. Dat gaat door als de app op de achtergrond staat; buiten werktijd niet.` |
| `NSLocationAlwaysAndWhenInUseUsageDescription` | The same text. |

- [ ] **Step 4: Register the plugin** in `LavoroViewController.capacitorDidLoad()`.

- [ ] **Step 5: iOS compiles.** The Codemagic `ios-simulator` workflow passes.

- [ ] **Step 6: Nothing else moved.** No file under `android/` or `resources/js/` has changed in this task.

- [ ] **Step 7: Checkpoint.** Show the diff and wait for approval, before the simulator session costs money.

- [ ] **Step 8: Simulator check on a rented Mac (MacinCloud)**

Use a test account on app.lavorofsm.nl with `location.track`, and a tracking window that covers the next hour.

Setup:
1. Download `App-simulator.zip` from the Codemagic build and unzip it.
2. `xcrun simctl boot "iPhone 16"`, then `open -a Simulator`.
3. `xcrun simctl install booted App.app`, then `xcrun simctl launch booted nl.lavoro.fsm`.

Checks:
1. Log in and allow location. The prompt shows the Dutch text and offers "While Using the App".
2. In Simulator, choose Features → Location → Freeway Drive. Within 10 minutes there are at least 2 rows for that user in the tenant's `location_pings`, about 5 minutes apart.
3. Background: press Home (⇧⌘H). The blue location indicator shows, and rows keep arriving every ~5 minutes for 15 minutes.
4. Locked: press Lock (⌘L). Rows keep arriving.
5. End of the window (Review Focus 2): set the window's end 3 minutes ahead under Algemene instellingen, then reopen the app so it picks that up. Send it to the background. After the end time, the blue indicator is gone within a minute, and there are no new rows.
6. Expired session (Review Focus 1): with the app in the background inside the window, delete that user's rows from the tenant's `sessions` table. Pings still arrive within 10 minutes.
7. Logout stops it: the indicator is gone and no new rows arrive.
8. Denied: erase the simulator (`xcrun simctl erase booted`), install it again, log in and choose "Don't Allow". No rows arrive and no second prompt appears.

Note any failure with the step number, then stop.

- [ ] **Step 9: `docs/install/ios.md`**

A location section: how it differs from Android (the blue indicator instead of a notification, and it stops when the app is swiped away), and the simulator check above.

---

### Task 3: APNs on the server

**Files:**
- Create: `config/apns.php`
- Create: `app/Services/Push/ApnsChannel.php`
- Modify: `app/Services/Push/PushMessage.php` (add `apnsPayload()`)
- Modify: `app/Services/Push/FirebaseChannel.php:38` (token query and class docblock)
- Modify: `app/Services/Push/PushNotifications.php:24-30` (constructor)
- Modify: `app/Console/Commands/TenancyDoctor.php` (`checkApns()`, called right after `checkFirebase()`)
- Modify: `composer.json` (`firebase/php-jwt` becomes a direct dependency, `"^6.10 || ^7.0"`, matching what is already installed)
- Modify: `phpunit.xml` (`APNS_KEY`, `APNS_KEY_ID` and `APNS_TEAM_ID` all set to `""`, so the developer's `.env` does not leak into tests)
- Modify: `.env.example` (APNS block)
- Modify: `docs/install/ios.md` (push section: the `.env` keys, where the `.p8` goes, and what the doctor checks)
- Test: `tests/Feature/Push/ApnsChannelTest.php`, `tests/Feature/Push/FirebaseChannelPlatformTest.php`, `tests/Feature/Tenancy/ApnsDoctorTest.php`

**Interfaces:**
- `config/apns.php` holds:
  - `key`, from `APNS_KEY`: the contents of the `.p8`, or a path to it (a relative path resolves against `base_path`)
  - `key_id`, from `APNS_KEY_ID`
  - `team_id`, from `APNS_TEAM_ID`
  - `bundle_id`, from `APNS_BUNDLE_ID`, default `nl.lavoro.fsm`
  - `production`, from `APNS_PRODUCTION`, default `true`
- `PushMessage::apnsPayload(): array` returns `$this->data` without `actions`, plus `aps`:
  - `aps.alert` is `{ title, body }`.
  - `aps.sound` is `'default'`, except when `urgency === 'low'`, where it is left out.
  - `aps['thread-id']` is `$this->data['channel'] ?? 'general'`.
- `final class ApnsChannel implements PushChannel`, constructed with `(LoggerInterface $logger)`:
  - `public static function key(): string`: the PEM, or `''` when it is unset or unreadable.
  - `isConfigured(): bool`: `key()`, `key_id` and `team_id` are all non-empty.
  - `send(array $batches): void`:
    - Tokens come from `DeviceToken::whereIn('user_id', …)->where('platform', 'ios')`.
    - One `POST {host}/3/device/{token}` per message per token, through `Http::withOptions(['version' => 2.0])->timeout(10)`.
    - The host is `https://api.push.apple.com`, or `https://api.sandbox.push.apple.com` when `production` is false.
  - The headers:

    | Header | Value |
    |---|---|
    | `authorization` | `bearer <jwt>` |
    | `apns-topic` | `bundle_id` |
    | `apns-push-type` | `alert` |
    | `apns-priority` | `10` when urgency is `high`, otherwise `5` |
    | `apns-expiration` | now + ttl, as a unix timestamp |
    | `apns-collapse-id` | `data['tag']` cut to 64 bytes, only when present |

  - Gone tokens are status 410, or status 400 with `reason` `BadDeviceToken` or `DeviceTokenNotForTopic`. They are deleted after the loop.
  - Any other non-2xx response logs the warning `Appmelding niet bezorgd (iPhone)` with the first 12 characters of the token, the status and the reason.
  - The provider token is an ES256 JWT with header `kid = key_id` and claims `iss = team_id`, `iat = now`. It is cached with `Cache::remember('apns-provider-token:' . key_id, 2400, …)`.
- In `FirebaseChannel`, the token query gains `->where('platform', '!=', 'ios')`.
- The `PushNotifications` constructor gains `ApnsChannel $apns`, and the channels become `[$web, $firebase, $apns]`.
- `TenancyDoctor::checkApns()`:

  | Situation | Result |
  |---|---|
  | Not configured | `bad`: `APNS_KEY, APNS_KEY_ID and APNS_TEAM_ID are not all set -- the iPhone app gets no notifications (docs/install/ios.md)` |
  | `openssl_pkey_get_private(key())` fails or is not `OPENSSL_KEYTYPE_EC` | `bad`: key unreadable or not an APNs key |
  | `curl_version()['features'] & CURL_VERSION_HTTP2` is zero | `bad`: `curl in this PHP has no HTTP/2 -- Apple refuses every iPhone notification` |
  | Otherwise | `pass`: `apns key <key_id> for team <team_id>`, plus ` (sandbox)` when not production |

  `deploy.sh` treats a non-zero doctor exit as a warning, not a failed deploy (`scripts/deploy.sh:142`).

- [ ] **Step 0: Baseline.** Run the full suite under `flock` before changing anything, and save its summary line to a scratch file.

- [ ] **Step 1: Write the failing tests**

`ApnsChannelTest`:
- Build the key per test with `openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])`, exported to PEM, and put it in `config(['apns.key' => …, 'apns.key_id' => 'KEY123', 'apns.team_id' => 'TEAM123'])`.
- Use `Http::fake()`, and build `PushMessage` directly with its constructor.

The tests:
- `test_it_is_not_configured_without_a_key`
- `test_it_sends_an_alert_to_each_iphone_token`: the URL is `https://api.push.apple.com/3/device/abc`, `apns-topic` is `nl.lavoro.fsm`, `apns-push-type` is `alert`, `aps.alert.title` is the message title, and the top-level `url` equals `data['url']`.
- `test_urgent_messages_get_priority_ten`: `high` gives `10`, `normal` gives `5`.
- `test_the_tag_becomes_the_collapse_id`
- `test_buttons_are_left_out_on_the_iphone`
- `test_a_token_apple_has_forgotten_is_deleted`: both 410 and 400 `{"reason":"BadDeviceToken"}` remove the row.
- `test_a_refusal_about_us_keeps_the_token`: 403 `{"reason":"InvalidProviderToken"}` keeps the row, and the warning is logged.
- `test_the_provider_token_is_signed_for_the_team`: `alg = ES256`, `kid = KEY123` and `iss = TEAM123`, verified with the public key.
- `test_android_tokens_are_left_alone`: an android token gets no request.

`FirebaseChannelPlatformTest`:
- `test_firebase_skips_iphone_tokens`: a mocked `Messaging::sendMulticast` receives `['a1']` only for a user with the android token `a1` and the ios token `i1`, and returns `MulticastSendReport::withItems([])`.

`ApnsDoctorTest`, using the `doctorOutput()` helper from `ApiAuthenticationDoctorTest`:
- `test_it_complains_when_apns_is_not_set_up`
- `test_it_complains_about_a_key_that_is_not_an_ec_key`
- `test_it_passes_a_working_key`

- [ ] **Step 2: Run them.** Expected: they fail because `ApnsChannel`, `config/apns.php` and `checkApns` do not exist yet.

- [ ] **Step 3: Implement** as described under Interfaces, then run `./vendor/bin/pint` on each changed PHP file.

- [ ] **Step 4: Run the three test classes.** All pass.

- [ ] **Step 5: Run the full suite** under `flock`. Expected: the Step 0 summary plus the new tests, and no failure that was not already in Step 0.

- [ ] **Step 6: Checkpoint.** Show the diff and wait for approval.

---

### Task 4: The iPhone receives push and opens the right page

**Files:**
- Modify: `ios/App/App/AppDelegate.swift`
- Create: `ios/App/App/App.entitlements`
- Modify: `ios/App/App.xcodeproj/project.pbxproj`
- Modify: `resources/js/Composables/useDeepLinks.js`
- Modify: `resources/js/Composables/usePushNotifications.js`
- Test: `resources/js/Composables/__tests__/useDeepLinks.spec.js`, `resources/js/Composables/__tests__/usePushNotifications.spec.js`

**Interfaces:**
- Consumes: Task 3's `ApnsChannel`, which puts `url` at the top level of the payload. `UpsertDeviceTokenRequest` already accepts `platform: 'ios'`.
- Produces, in `useDeepLinks.js`: `export function followLink(target: unknown): void`.
  - It does nothing unless `target` is a non-empty string.
  - It resolves `new URL(target, window.location.origin)`.
  - It follows only same-origin URLs, and not the URL you are already on.
  - The `appUrlOpen` listener calls it with `event.url`. For absolute URLs the result is identical to today's.
- Changes in `usePushNotifications.js`:
  - `push_available()` returns `true` on `'ios'` without asking `PushSupport`.
  - `attach()`, on `'ios'` only and before `register()`, adds `pushNotificationActionPerformed`, which calls `followLink(notification.data?.url)`.
  - The guard in `askBatteryExemption()` becomes `platform !== 'android'`.

- [ ] **Step 1: Write the failing tests**

`useDeepLinks.spec.js`, testing `followLink`:
- An absolute same-origin URL navigates to its path, search and hash.
- `'/serviceorders/12'` navigates.
- `'https://evil.example/x'` does not navigate.
- `undefined`, `''` and `42` do not navigate.
- The URL of the current page does not navigate.

`usePushNotifications.spec.js`, with `@capacitor/core`, `@capacitor/push-notifications` and `axios` mocked:
- `android still asks PushSupport`: `PushSupport.available` is called, and no `pushNotificationActionPerformed` listener is added.
- `iphone registers without PushSupport`: `PushSupport.available` is not called. The `registration` event with `{ value: 'abc' }` makes `axios.post` get `('/api/device-tokens', { token: 'abc', platform: 'ios' })`.
- `iphone tap opens the url`: calling the action handler with `{ notification: { data: { url: '/serviceorders/12' } } }` navigates there.
- `the battery question is android only`: on `'ios'`, `PushSupport.requestBatteryExemption` is not called.

- [ ] **Step 2: Run them.** Expected: the iphone tests and the new `followLink` tests fail.

- [ ] **Step 3: Implement the JS** as described under Interfaces, then run `npx eslint --fix` on both files.

- [ ] **Step 4: Run the tests.** All pass.

- [ ] **Step 5: Native push**

Add these two methods to `AppDelegate`:

```swift
func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
    NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: deviceToken)
}

func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
    NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
}
```

Create `App.entitlements` with `aps-environment` set to `production`. In `project.pbxproj`, add `CODE_SIGN_ENTITLEMENTS = App/App.entitlements;` to both build configurations that contain `INFOPLIST_FILE = App/Info.plist;`.
Check: `grep -c 'CODE_SIGN_ENTITLEMENTS = App/App.entitlements;'` returns `2`.

- [ ] **Step 6: iOS compiles.** The Codemagic `ios-simulator` workflow passes.

- [ ] **Step 7: Checkpoint.** Show the diff and wait for approval.

---

### Task 5: TestFlight on a real iPhone, and Android unchanged

**Files:**
- Modify: `codemagic.yaml` (add the workflow `ios-testflight`)
- Modify: `docs/install/ios.md` (release section, App Review notes)
- Modify: `docs/guide/handleiding.md:513` (the "Mobiel" paragraph), only after the acceptance below passes

**Interfaces:**
- Consumes: everything from Task 0, and the Codemagic integration `lavoro-asc`.

- [ ] **Step 1: Add `ios-testflight`**

Same machine, Node and Xcode versions as `ios-simulator`. Add:
- `integrations.app_store_connect: lavoro-asc`
- `environment.ios_signing` with `distribution_type: app_store` and `bundle_identifier: nl.lavoro.fsm`

The scripts, in order:
1. `npm ci`
2. `npx cap sync ios`
3. `xcode-project use-profiles`
4. `cd ios/App && agvtool new-version -all $BUILD_NUMBER`
5. `xcode-project build-ipa --project ios/App/App.xcodeproj --scheme App`

Set `artifacts: [build/ios/ipa/*.ipa]`, and publish with `app_store_connect` using `auth: integration` and `submit_to_testflight: true`. Where a key differs, follow Codemagic's Ionic Capacitor iOS sample.

- [ ] **Step 2: Run it.** The build appears in TestFlight. Install it on a borrowed iPhone, for one full working day.

- [ ] **Step 3: Acceptance on the iPhone**, every point checked off:
  1. The app opens on the login screen, and the demo login works.
  2. Camera upload on a werkbon: the prompt shows the Dutch text, and the photo uploads.
  3. Location over the working day, with the phone in a pocket and the app in the background:
     - Pings arrive every ~5 minutes inside the window, and none outside it.
     - Note the battery percentage at the start and the end of the day.
  4. A notification arrives as a banner, both with the app closed and with it open. Tapping it opens the record. Its token is in `device_tokens` with `platform = 'ios'`.
  5. After logging out, the token row is gone, the next notification does not arrive, and location stops.
  6. Airplane mode, then restart the app: the offline page shows. Airplane mode off: the app goes on to Lavoro by itself.
  7. Download a werkbon PDF and note what happens. This plan does not fix it (see below).

- [ ] **Step 4: Android regression**

Build Android debug from the same commit and install it on an Android phone. Check:
- A notification arrives with the Route and Bellen buttons, and tapping it opens the record.
- "Locatie actief" appears inside the tracking window.
- Logging out stops both.

- [ ] **Step 5: App Review notes** in `docs/install/ios.md`, ready to paste into App Store Connect:
  - What the location is for (the planner's map of mechanics, only inside working hours that the company sets).
  - A demo account that has `location.track` and a tracking window covering all hours.
  - A note that Apple may ask for a video of the feature.

- [ ] **Step 6: Manual**

In the "Mobiel" paragraph of `handleiding.md`, add an iPhone app with the same login. Location is shared during working hours, also in the background, with the blue indicator, and stops when the app is swiped away until it is opened again. Notifications have no buttons (tapping opens the record), and there is no battery question.

- [ ] **Step 7: Checkpoint.** Show the diff and wait for approval.

---

## Not in this plan (check before submitting to App Store review)

- **PDF downloads on the iPhone.** `download.js` calls `window.open(url, '_blank')`. Capacitor hands that to Safari without the app's session, so it probably shows a login page. Task 5 step 3.7 shows whether that happens.
- **Tracking after the app is swiped away.** That needs "Always" permission and gets more questions from Apple.
- **Universal links.** Tapping an app.lavorofsm.nl link should open the app. That needs Associated Domains and an `apple-app-site-association` file.
- **iOS app icon and splash screen.** `npx capacitor-assets generate --ios` runs on Linux.
- **Route and Bellen buttons on iPhone notifications.** They need a `UNNotificationCategory`.
- **The App Store listing:** 6.9" screenshots, privacy labels (location linked to the user, for app functionality), and the privacy policy URL.
