# Android: build, sign and publish on Google Play

The Android app is a native shell around `https://app.lavorofsm.nl`. It has no
server address to fill in. Every customer works on the same server, and logging
in decides which company you are in, exactly as in the browser.

What the shell adds to the website:

- a foreground service that sends the mechanic's location within the company's
  tracking window (`LocationForegroundService`, `TrackingWindow`);
- downloads through Android's download manager (`MainActivity`);
- tapped `https://app.lavorofsm.nl/...` links that open in the app (App Links);
- an offline page when the server cannot be reached (`capacitor-launcher/index.html`).

The Vue app itself is **served by the server**, not bundled. A Laravel deploy
updates it for every installed app at once. Build a new app only when something
native changes: anything under `android/`, `capacitor.config.ts`,
`capacitor-launcher/`, or a Capacitor plugin that is added or removed.

## Prerequisites

- Android Studio, with the SDK (`sdk.dir` in `android/local.properties`).
- **JDK 21 to run Gradle.** Capacitor 8 compiles for Java 21, and Gradle 8.14
  cannot run on Java 25, which is what newer Android Studio versions bundle as
  their JBR. Install one (`sudo apt install openjdk-21-jdk`) and either set it
  in Android Studio (Settings → Build, Execution, Deployment → Build Tools →
  Gradle → Gradle JDK) or pass it on the command line:

  ```bash
  export JDK21=/usr/lib/jvm/java-21-openjdk-amd64
  ./gradlew <task> -Dorg.gradle.java.home=$JDK21
  ```

  If Android Studio fails with "Unsupported class file major version", check
  `android/.gradle/config.properties`: it pins the JDK path the IDE last used.

## Debug build

```bash
npx cap sync android
cd android
./gradlew assembleDebug -Dorg.gradle.java.home=$JDK21
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

The server comes from the Gradle property `lavoroServerUrl`, which defaults to
`https://app.lavorofsm.nl`. To run the app against your local install on port
8199, build with the local address and forward the ports to the phone or
emulator, so the app loads the very origin that `APP_URL` and Sanctum expect:

```bash
./gradlew assembleDebug -PlavoroServerUrl=http://127.0.0.1:8199 -Dorg.gradle.java.home=$JDK21
adb reverse tcp:8199 tcp:8199
adb reverse tcp:5173 tcp:5173   # Vite, when it runs
```

An `http://` address switches cleartext traffic on for that build. A release
build refuses anything other than `https://`.

Debugging a running app:

```bash
adb logcat -d | grep LavoroLocation                                   # the location service
adb shell dumpsys activity services nl.lavoro.fsm | grep -A2 Location  # is it running?
# The WebView console: chrome://inspect in Chrome on the desktop (debug builds only)
```

## Release build (for Google Play)

Lavoro is signed with **one key of our own**, `lavoro-release.jks` (alias
`lavoro`, SHA-256 `1A:6E:06:3B:05:C7:17:03:81:39:B5:46:1E:64:BB:C4:1C:81:B8:4A:E8:4D:8F:F6:5E:AD:67:A7:26:28:EB:97`).
The package name `nl.lavoro.fsm` is registered to that key in Play Console
(Android developer verification), and Google Play signs with the same key
because we gave it a copy on the first release. Every copy of the app on every
phone therefore carries this one signature.

The key file and its password live in the password manager. Lose both and no
update can ever be signed again outside Google Play.

### 1. Point Gradle at the key (once per computer)

Put `lavoro-release.jks` in `android/` and create `android/keystore.properties`
next to it. Git ignores both, together with every `*.jks` and `*.keystore`:

```properties
storeFile=lavoro-release.jks
keyAlias=lavoro
storePassword=...
keyPassword=...
```

Without this file a release build stops with a message saying so.

### 2. Bump the version and build

In `android/app/build.gradle`, raise `versionCode` by one for every upload
(Google Play refuses a number it has already seen) and set `versionName` to what
users should see. Then:

```bash
npx cap sync android
cd android
./gradlew bundleRelease
```

Output: `android/app/build/outputs/bundle/release/app-release.aab`. Upload it in
Play Console under Test and release.

### 3. The first release only: give Play the key

At "App signing" on the first release, choose to use your own key, exported from
a Java keystore. Play offers its PEPK tool and an encryption key; run the
command Play shows with `--keystore=lavoro-release.jks --alias=lavoro` and
upload the zip it writes. Choose this before the first bundle is accepted:
afterwards Play has made a key of its own and the app's signature can no longer
be the registered one.

## App Links and the signing fingerprint

Android opens a tapped link to `app.lavorofsm.nl` in the app only after it has
checked `https://app.lavorofsm.nl/.well-known/assetlinks.json`. That file lists
the SHA-256 fingerprints of the keys that sign installed copies: the release key
above, and the debug key for development builds. On the server:

```dotenv
ANDROID_SHA256_CERT_FINGERPRINTS=1A:6E:06:3B:05:C7:17:03:81:39:B5:46:1E:64:BB:C4:1C:81:B8:4A:E8:4D:8F:F6:5E:AD:67:A7:26:28:EB:97,00:8D:D1:A2:9C:75:D0:C0:AD:AB:DC:50:44:03:26:C9:C7:2C:7B:D3:0B:63:FD:85:3C:B0:52:84:89:8A:9E:DE
```

Without the variable the route only lists the debug key. Check it:

```bash
curl -i https://app.lavorofsm.nl/.well-known/assetlinks.json   # 200, application/json, no redirect
adb shell pm verify-app-links --re-verify nl.lavoro.fsm
adb shell pm get-app-links nl.lavoro.fsm                       # app.lavorofsm.nl: verified
```

The debug key's fingerprint: `./gradlew :app:signingReport` (Variant: debug).

## Location

Users with the `location.track` permission share their location, and so do
administrators, because `hasPermission()` lets them past every check. Before
Android asks for the permission, the app explains in its own dialog what is
collected, why, that it continues while the app is closed, and who sees it.
Google Play requires that dialog ("prominent disclosure"). If the user answers
"Niet nu", the question comes back the next time the app is started.

The service follows the tracking window from Algemene instellingen. Outside it
the app requests no location, and the notification reads "Locatie gepauzeerd
buiten werktijd". It checks the window every minute, so it picks up again by
itself when working hours begin. The window travels with every start, so a
change in the settings applies the next time the app is opened.

The app does not ask for `ACCESS_BACKGROUND_LOCATION`. A foreground service
started while the app is open keeps its location access, and that permission
would bring a separate, much stricter Play review.

## Push notifications

The app's WebView cannot receive web push, so the app gets its notifications
through Firebase: the same ones the browser gets, plus the appointment
reminder with its Route and Bellen buttons. One Firebase project serves every
customer.

- **In the build.**
  - `android/app/google-services.json` is the Firebase console's client
    configuration for the Android app `nl.lavoro.fsm`. It is committed: every
    APK carries it, so it is no secret. The secret is the server's key below.
  - Restrict its API key in Google Cloud to the Android app (package name and
    signing certificate fingerprint), so nobody else can use it.
  - Without the file, `BuildConfig.PUSH_AVAILABLE` is false and the app never
    registers for push; registering without it would crash the app natively.
    A debug build allows that, a release build refuses to start.
- **On the server.**
  - Create a service account key in the same Firebase project.
  - Store the JSON outside the web root and set `FIREBASE_CREDENTIALS` in
    `.env` to its path.
  - Also set `FIREBASE_HTTP_CLIENT_TIMEOUT=10`. Without it, a Firebase call
    that hangs holds up the reminder round until the worker gives up.
  - `php artisan tenancy:doctor` reports a missing or unreadable key.
  - Restart the workers afterwards, because they keep the configuration they
    started with.

The server sends data-only messages. `LavoroMessagingService` draws them
itself, because a notification the system draws for a closed app cannot carry
buttons. It uses three channels that people can tune in Android's settings:
Afspraakherinneringen, Dringende meldingen and Meldingen.

**Battery.**
- For a plannable user, the app explains why reminders need to arrive on time,
  then asks Android to exempt Lavoro from battery optimisation
  (`REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`). "Niet nu" holds until the app is
  started again.
- Google Play allows that permission only when the app's core function needs
  it. Be ready to explain it in review: time-critical appointment reminders
  for field technicians.
- Some manufacturers (Xiaomi, Huawei, Samsung) have their own battery saving on
  top. The manual tells users where to switch it off.

**Logging out** deletes the phone's device token, so whoever uses the phone
next does not get the previous user's appointments.

## Moving off the old APK

The old APK, downloaded from the application and bound to `spee.lavorofsm.nl`,
no longer works: that host redirects to `app.lavorofsm.nl`, and the old app
opens that address in the browser. It was also signed with a different key, so
the Play version cannot install over it. Users uninstall the old app first,
then install Lavoro from Google Play and log in again.

The in-app "Nieuwe versie beschikbaar" banner and the `download/lavoro.apk` route
are gone. Google Play does not allow an app to update itself outside the store,
and the store updates the app by itself.

## Google Play Console: what the code cannot do

- **Developer account.** Register as an *organization* (needs a D-U-N-S number).
  A *personal* account created after 13 November 2023 first has to run a closed
  test with at least 12 testers for 14 days before it may publish.
- **Privacy policy.** A page on the website, entered in Play Console and set as
  `PRIVACY_POLICY_URL` on the server, so the login screen links to it (Google
  requires the link inside the app too). Keep it in step with what Lavoro does:
  a new outside service, a new kind of data or another retention period means
  changing that page and its date.
- **App access.** Reviewers cannot get past the login screen without an
  account. Give them a demo login, and tell them the location dialog appears
  right after logging in (for a mechanic, and for an administrator too).
- **Foreground service declaration** (App content). Type `location`, with a
  description and a link to a short video that shows logging in, the disclosure
  dialog, the permission prompt and the "Locatie actief" notification.
- **Data safety.** At least: precise location, name and email address, photos
  and files the user uploads, and the device token for push notifications.
  Everything is encrypted in transit. Explain how deletion is requested. Accounts
  are created by the customer's administrator, so a deletion request goes
  through them, or through MajorLabel.
- **Account deletion.** The privacy page's section with the anchor
  `#account-verwijderen`.
  Deleting a user in Lavoro only deactivates them; a request by mail means
  MajorLabel anonymises that user's name, email address and avatar by hand,
  within the 30 days the page promises.
- **Content rating, target audience (18+, not aimed at children), ads (none).**
- **Store listing.** App icon 512×512 (`resources/play-store-icon.png`), feature graphic
  1024×500, at least two phone screenshots, a short description (80
  characters) and a full one (4000).
- **Target API level.** New apps and updates must target API 36 (Android 16)
  since 31 August 2026. `targetSdkVersion` is 36 in `android/variables.gradle`.

## Release checklist

- [ ] `versionCode` raised in `android/app/build.gradle`
- [ ] `npx cap sync android`, then `./gradlew bundleRelease`
- [ ] `.aab` uploaded in Play Console (internal test first)
- [ ] First release only: own key given to Play (PEPK), App Links verified on a device
