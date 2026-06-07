# Android — Build, Install & Release

How to build the Lavoro Android app from the command line, and how to ship an
update so technicians get the in-app "new version" banner.

## Prerequisites (one-time)

- **Android Studio** installed (provides the SDK + a bundled JDK 21).
- **Android SDK** at `~/Android/Sdk` (set up on first Android Studio launch).
- The bundled JDK lives at `/snap/android-studio/<build>/jbr` — find the exact
  path with:
  ```bash
  find /snap/android-studio -name java -path "*jbr*"
  ```
  Everything below uses `JBR=/snap/android-studio/227/jbr` — update `227` to
  match your install.

The Capacitor JS changes (the Vue app) are served from the **server**, not
bundled in the APK. The APK only needs rebuilding when **native** code changes:
anything under `android/`, `capacitor.config.ts`, or a plugin add/remove.

## Build a debug APK (command line)

```bash
# 1. Build the web assets (only needed if the launcher page changed)
npm run build

# 2. Sync web assets + config + plugins into the android project
npx cap sync android

# 3. Compile the APK using Android Studio's bundled JDK
cd android
./gradlew assembleDebug -Dorg.gradle.java.home=/snap/android-studio/227/jbr
```

Output APK:
```
android/app/build/outputs/apk/debug/app-debug.apk
```

### Compile-check only (faster, no APK)

```bash
cd android
./gradlew :app:compileDebugJavaWithJavac -Dorg.gradle.java.home=/snap/android-studio/227/jbr
```

## Install on a connected device

USB debugging must be on (Settings → Developer options → USB debugging).

```bash
export PATH=$PATH:~/Android/Sdk/platform-tools
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
```

`-r` reinstalls over the existing app, keeping its data (stored server URL,
ping queue). To wipe app data, uninstall first: `adb uninstall nl.lavoro.fsm`.

## Debugging a running device

```bash
export PATH=$PATH:~/Android/Sdk/platform-tools

# GPS service logs (POST results, errors)
adb logcat -d | grep LavoroLocation

# Is the foreground service alive?
adb shell dumpsys activity services nl.lavoro.fsm | grep -A2 LocationForegroundService

# Inspect the WebView console: open chrome://inspect in Chrome on the desktop
```

---

# Releasing an update (the in-app warning)

Deploying the Laravel app (`deploy.sh`) does **NOT** ship a new APK. The APK is
a separate binary. To push an update that technicians get prompted to install:

### 1. Bump the native version

In `android/app/build.gradle`:
```gradle
versionCode 2        // increment by 1 every release
versionName "1.1"    // human-readable, your choice
```

### 2. Build the APK

```bash
npm run build && npx cap sync android
cd android && ./gradlew assembleDebug -Dorg.gradle.java.home=/snap/android-studio/227/jbr
```

### 3. Put the APK on the server

Copy `app-debug.apk` to the server at:
```
storage/app/releases/lavoro.apk
```
This is served (auth-free) by the `download/lavoro.apk` route.

### 4. Bump the advertised build number

In `routes/web.php`, the `app/version` route:
```php
Route::get('app/version', fn() => response()->json([
    'build'        => 2,   // <-- must match versionCode from step 1
    'download_url' => config('app.url') . '/download/lavoro.apk',
]));
```

### 5. Deploy the Laravel change

Push + run `deploy.sh` on the server.

### What the user sees

On next app launch, `useAppUpdate` calls `App.getInfo()` (installed build) and
compares it to `/app/version` (server build). If the server build is higher,
the blue **"Nieuwe versie beschikbaar"** banner slides up. Tapping **Updaten**
downloads the APK; Android prompts to install over the existing app.

### Release checklist

- [ ] `versionCode` bumped in `android/app/build.gradle`
- [ ] APK built
- [ ] APK copied to `storage/app/releases/lavoro.apk` on the server
- [ ] `build` in `routes/web.php` matches the new `versionCode`
- [ ] Laravel change deployed

If the `build` number and `versionCode` don't match, the banner logic still
works (it only compares numbers), but keeping them equal avoids confusion.

---

# Production (signed) build — later

Debug APKs are fine for sideloading to your own technicians. For Play Store or
long-term distribution you'll want a signed release build:

```bash
cd android
./gradlew assembleRelease -Dorg.gradle.java.home=/snap/android-studio/227/jbr
```

This requires a signing keystore configured in `android/app/build.gradle`
(`signingConfigs`). Not set up yet — do this when you move off debug builds.
