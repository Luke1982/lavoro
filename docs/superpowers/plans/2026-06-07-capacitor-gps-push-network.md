# Capacitor + Background GPS + Push Notifications + Network Warnings

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn Lavoro into a Capacitor-wrapped native iOS/Android app with background GPS location tracking, FCM push notifications, and offline network warnings — without rewriting the Inertia/Laravel stack.

**Architecture:** Capacitor uses a local launcher page (`capacitor-launcher/index.html`) as its `webDir` instead of a hardcoded `server.url`. On first launch the launcher shows a server-address setup form; on subsequent launches it redirects in ~800ms. `allowNavigation: ['*']` keeps the Capacitor bridge active after the redirect. Once on the server, all Inertia page rendering and session-based auth work unchanged. GPS positions are tracked via `@capacitor-community/background-geolocation`, rate-limited to one ping per 10 minutes, queued locally in localStorage when offline, and flushed in batches to `POST /api/location/pings`. Push notifications use Firebase Cloud Messaging; device tokens are stored in a new `device_tokens` table and registered on login. Network state is monitored via `@capacitor/network` and surfaced as a slide-in banner in `MainLayout.vue`.

**Tech Stack:** Capacitor 6, `@capacitor-community/background-geolocation`, `@capacitor/push-notifications`, `@capacitor/network`, `@capacitor/preferences`, `laravel-notification-channels/fcm`

---

## Assessment Notes (Read Before Starting)

**Auth:** Session cookies work in Capacitor's WebView exactly as in a browser. After the launcher redirects to the server, the WebView is fully on `https://server.com` — all requests are same-origin and `SameSite=lax` (the default) works fine. `SameSite=none; Secure` is **not** needed for Option C. The production server `.env` still benefits from `SESSION_SECURE_COOKIE=true` for HTTPS-only cookie delivery, but `config/session.php` defaults are left untouched — these are server-side deploy concerns, not dev concerns. `auth:sanctum` routes accept session cookies when `SANCTUM_STATEFUL_DOMAINS` includes the production host (auto-derived from `APP_URL` in the existing `config/sanctum.php`).

**Service worker:** `public/service-worker.js` already exists and handles caching. We add a `push` listener to it for web PWA delivery (native push on iOS/Android goes through FCM/APNs directly, not the service worker).

**Leaflet:** Already in `package.json` (v1.9.4). The admin technician map reuses it.

**User model:** Uses the `Notifiable` trait. Needs `deviceTokens()`, `locationPings()` relationships and `routeNotificationForFcm()` method.

**Executing user assignment:** `HasExecutingUsers::syncExecutingUsers()` (in `app/Models/Traits/HasExecutingUsers.php`) is called from `EventApiController` at two points — event `store` (line 92) and event `update` (lines 148, 154). These are where we dispatch push notifications for newly assigned technicians on service orders.

**No tests** are included per project convention.

---

## File Map

**Created:**
- `capacitor.config.ts`
- `database/migrations/XXXX_create_location_pings_table.php`
- `database/migrations/XXXX_create_device_tokens_table.php`
- `app/Models/LocationPing.php`
- `app/Models/DeviceToken.php`
- `app/Http/Requests/Api/StoreLocationPingsRequest.php`
- `app/Http/Requests/Api/UpsertDeviceTokenRequest.php`
- `app/Http/Controllers/Api/LocationPingController.php`
- `app/Http/Controllers/Api/DeviceTokenController.php`
- `app/Http/Controllers/TechnicianLocationController.php`
- `app/Notifications/NewServiceOrderAssigned.php`
- `resources/js/Composables/useCapacitor.js`
- `resources/js/Composables/useNetworkStatus.js`
- `resources/js/Composables/useLocationTracker.js`
- `resources/js/Composables/usePushNotifications.js`
- `resources/js/Components/UI/OfflineBanner.vue`
- `resources/js/Pages/Admin/TechnicianMap.vue`

**Modified:**
- `package.json` — Capacitor packages
- `config/session.php` — `SameSite=none`, `Secure=true`
- `config/sanctum.php` — stateful domains
- `routes/api.php` — GPS ping, device token routes
- `routes/web.php` — technician map route
- `app/Models/User.php` — new relationships + `routeNotificationForFcm()`
- `app/Http/Controllers/EventApiController.php` — dispatch notification after sync
- `resources/js/Layouts/MainLayout.vue` — `OfflineBanner`, composable wiring
- `public/service-worker.js` — `push` + `notificationclick` listeners
- `ios/App/App/Info.plist` — location + background mode permissions
- `android/app/src/main/AndroidManifest.xml` — location + notification permissions

---

## Task 1: Install Capacitor Core + Plugins ✅ DONE

**Files:**
- Modify: `package.json`
- Create: `capacitor.config.ts`

- [ ] **Step 1: Install npm packages**

```bash
npm install @capacitor/core @capacitor/cli @capacitor/ios @capacitor/android
npm install @capacitor/geolocation @capacitor/network @capacitor/preferences @capacitor/push-notifications
npm install @capacitor-community/background-geolocation
```

Expected: packages added to `package.json` dependencies, no peer-dependency errors.

- [ ] **Step 2: Create capacitor.config.ts at the project root**

```typescript
import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'nl.lavoro.fsm',
  appName: 'Lavoro',
  webDir: 'public/build',
  server: {
    url: 'https://YOUR_PRODUCTION_DOMAIN',
    cleartext: false,
    allowNavigation: ['YOUR_PRODUCTION_DOMAIN'],
  },
  plugins: {
    BackgroundGeolocation: {
      backgroundMessage: 'Lavoro volgt uw locatie voor servicebezoeken.',
      backgroundTitle: 'Locatie actief',
      requestPermissions: true,
      stale: false,
      distanceFilter: 30,
    },
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
  },
};

export default config;
```

Replace `YOUR_PRODUCTION_DOMAIN` with the actual hostname (e.g. `lavoro.mijnbedrijf.nl`). During local dev, change `server.url` to your LAN IP + port (e.g. `http://192.168.1.10:8000`) and set `cleartext: true` temporarily.

- [ ] **Step 3: Add build + sync scripts to package.json scripts section**

```json
"cap:sync": "npm run build && npx cap sync",
"cap:ios": "npm run build && npx cap sync && npx cap open ios",
"cap:android": "npm run build && npx cap sync && npx cap open android"
```

- [ ] **Step 4: Add iOS and Android native platforms**

```bash
npx cap add ios
npx cap add android
```

Expected:
```
✔ Adding native ios project in: /path/to/lavoro/ios
✔ Adding native android project in: /path/to/lavoro/android
```

- [ ] **Step 5: Initial build + sync**

```bash
npm run build && npx cap sync
```

Expected: Vite build completes, Capacitor copies to `ios/` and `android/`.

- [ ] **Step 6: Commit**

```bash
git add capacitor.config.ts package.json package-lock.json ios/ android/
git commit -m "feat: add Capacitor shell with iOS and Android platforms"
```

---

## Task 2: Laravel Session + Sanctum Configuration for Native WebView ✅ DONE (no code changes needed for Option C)

iOS WKWebView requires `SameSite=none; Secure` on the session cookie or it will be silently dropped on requests initiated from the WebView. Without this, the user appears logged out on every page in the native app.

**Files:**
- Modify: `config/session.php`
- Modify: `config/sanctum.php`
- Modify: `.env.example`

- [ ] **Step 1: Fix SameSite in config/session.php**

Find and update:
```php
// Before:
'same_site' => env('SESSION_SAME_SITE', 'lax'),

// After:
'same_site' => env('SESSION_SAME_SITE', 'none'),
```

- [ ] **Step 2: Ensure Secure cookie in config/session.php**

```php
// Before:
'secure' => env('SESSION_SECURE_COOKIE'),

// After:
'secure' => env('SESSION_SECURE_COOKIE', true),
```

- [ ] **Step 3: Add your production domain to Sanctum stateful domains**

In `config/sanctum.php`, the stateful domains list controls which origins can use session-cookie auth with `auth:sanctum`. Add your production host:

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', implode(',', [
    'localhost',
    'localhost:3000',
    '127.0.0.1',
    '127.0.0.1:8000',
    '::1',
    parse_url(env('APP_URL', ''), PHP_URL_HOST),
]))),
```

And in `.env` / `.env.example`:
```
SANCTUM_STATEFUL_DOMAINS=lavoro.mijnbedrijf.nl
SESSION_SAME_SITE=none
SESSION_SECURE_COOKIE=true
```

Note: for local HTTP dev, temporarily set `SESSION_SECURE_COOKIE=false` — browsers reject `Secure` cookies over plain HTTP.

- [ ] **Step 4: Smoke-test login in a browser after these changes**

```bash
php artisan serve
```

Open `http://localhost:8000`, log in, navigate several pages. Session should persist as before.

- [ ] **Step 5: Commit**

```bash
git add config/session.php config/sanctum.php .env.example
git commit -m "feat: configure session cookie SameSite=none for Capacitor WebView compatibility"
```

---

## Task 3: iOS Native Permissions + Background Modes ⏳ Mac-only — manual Xcode steps documented above

**Files:**
- Modify: `ios/App/App/Info.plist`

- [ ] **Step 1: Add location permission strings to Info.plist**

Open `ios/App/App/Info.plist`. Before the closing `</dict>` tag, add:

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>Lavoro gebruikt uw locatie om servicelocaties op de kaart te tonen.</string>
<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
<string>Lavoro volgt uw locatie op de achtergrond voor servicebezoeken.</string>
<key>NSLocationAlwaysUsageDescription</key>
<string>Lavoro volgt uw locatie op de achtergrond voor servicebezoeken.</string>
```

- [ ] **Step 2: Add background modes to Info.plist**

```xml
<key>UIBackgroundModes</key>
<array>
    <string>location</string>
    <string>fetch</string>
    <string>remote-notification</string>
</array>
```

- [ ] **Step 3: Enable capabilities in Xcode**

Run `npx cap open ios` to open Xcode.

In Xcode:
1. Select the `App` target → **Signing & Capabilities** tab
2. **+** → add **Push Notifications**
3. **+** → add **Background Modes** → check **Remote notifications** and **Location updates**

Save. Xcode writes `App.entitlements` automatically.

- [ ] **Step 4: Configure APNs in Firebase Console**

1. Firebase Console → Project Settings → Cloud Messaging → iOS app section
2. Upload your APNs Authentication Key (`.p8` file from Apple Developer → Keys)
3. Fill in Key ID and Team ID

APNs keys are created at: Apple Developer Portal → Certificates, Identifiers & Profiles → Keys → `+`.

- [ ] **Step 5: Commit**

```bash
git add ios/App/App/Info.plist
git commit -m "feat(ios): add location and push notification permissions with background modes"
```

---

## Task 4: Android Native Permissions + Google Services ✅ DONE (manifest updated; google-services.json still needed from Firebase)

**Files:**
- Modify: `android/app/src/main/AndroidManifest.xml`
- Note: `android/app/google-services.json` must be placed here but is NOT committed

- [ ] **Step 1: Add permissions to AndroidManifest.xml**

Inside `<manifest>` before `<application>`:

```xml
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
<uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
<uses-permission android:name="android.permission.VIBRATE" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
```

- [ ] **Step 2: Add the background geolocation foreground service declaration**

Inside `<application>` in `AndroidManifest.xml`:

```xml
<service
    android:name="com.equimaps.capacitorbackgroundgeolocation.BackgroundGeolocationService"
    android:enabled="true"
    android:exported="false"
    android:foregroundServiceType="location" />
```

- [ ] **Step 3: Download google-services.json from Firebase and place it**

1. Firebase Console → Project Settings → Your Apps → Android → download `google-services.json`
2. Place file at `android/app/google-services.json`
3. Add to `android/app/.gitignore` (create if needed): `google-services.json`

- [ ] **Step 4: Verify Google Services plugin in build.gradle files**

Check `android/app/build.gradle` contains:
```gradle
apply plugin: 'com.google.gms.google-services'
```

Check `android/build.gradle` (project level) contains in `dependencies {}`:
```gradle
classpath 'com.google.gms:google-services:4.4.2'
```

Capacitor adds these automatically; if missing, add them.

- [ ] **Step 5: Sync**

```bash
npx cap sync android
```

- [ ] **Step 6: Commit**

```bash
git add android/app/src/main/AndroidManifest.xml android/build.gradle android/app/build.gradle
git commit -m "feat(android): add location and push notification permissions"
```

---

## Task 5: Database Migrations ✅ DONE

**Files:**
- Create: `database/migrations/XXXX_create_location_pings_table.php`
- Create: `database/migrations/XXXX_create_device_tokens_table.php`

- [ ] **Step 1: Generate location_pings migration**

```bash
php artisan make:migration create_location_pings_table
```

Replace the generated file body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('accuracy')->nullable();
            $table->float('speed')->nullable();
            $table->float('heading')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pings');
    }
};
```

Note: No `updated_at` — pings are immutable. No `$table->timestamps()` call.

- [ ] **Step 2: Generate device_tokens migration**

```bash
php artisan make:migration create_device_tokens_table
```

Replace the generated file body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate
```

Expected output includes two `DONE` lines for the new tables.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: add location_pings and device_tokens migrations"
```

---

## Task 6: Models + User Relationships ✅ DONE

**Files:**
- Create: `app/Models/LocationPing.php`
- Create: `app/Models/DeviceToken.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create LocationPing.php**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationPing extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'lat', 'lng', 'accuracy', 'speed', 'heading', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy' => 'float',
            'speed' => 'float',
            'heading' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 2: Create DeviceToken.php**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Add to User.php — three new methods before the closing `}`**

```php
public function locationPings()
{
    return $this->hasMany(LocationPing::class)->orderByDesc('recorded_at');
}

public function deviceTokens()
{
    return $this->hasMany(DeviceToken::class);
}

public function routeNotificationForFcm(): array
{
    return $this->deviceTokens()->pluck('token')->toArray();
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/LocationPing.php app/Models/DeviceToken.php app/Models/User.php
git commit -m "feat: add LocationPing and DeviceToken models with User relationships"
```

---

## Task 7: Form Requests ✅ DONE

**Files:**
- Create: `app/Http/Requests/Api/StoreLocationPingsRequest.php`
- Create: `app/Http/Requests/Api/UpsertDeviceTokenRequest.php`

- [ ] **Step 1: Create StoreLocationPingsRequest**

```bash
php artisan make:request Api/StoreLocationPingsRequest
```

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationPingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'pings'              => ['required', 'array', 'min:1', 'max:200'],
            'pings.*.lat'        => ['required', 'numeric', 'between:-90,90'],
            'pings.*.lng'        => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy'   => ['nullable', 'numeric', 'min:0'],
            'pings.*.speed'      => ['nullable', 'numeric', 'min:0'],
            'pings.*.heading'    => ['nullable', 'numeric', 'between:0,360'],
            'pings.*.recorded_at' => ['required', 'date'],
        ];
    }
}
```

- [ ] **Step 2: Create UpsertDeviceTokenRequest**

```bash
php artisan make:request Api/UpsertDeviceTokenRequest
```

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:500'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/Api/
git commit -m "feat: add Form Requests for location pings and device token endpoints"
```

---

## Task 8: API Controllers ✅ DONE

**Files:**
- Create: `app/Http/Controllers/Api/LocationPingController.php`
- Create: `app/Http/Controllers/Api/DeviceTokenController.php`

- [ ] **Step 1: Create LocationPingController**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLocationPingsRequest;
use App\Models\LocationPing;

class LocationPingController extends Controller
{
    public function store(StoreLocationPingsRequest $request): \Illuminate\Http\JsonResponse
    {
        $user_id = $request->user()->id;
        $now = now()->toDateTimeString();

        $rows = array_map(fn($ping) => array_merge($ping, [
            'user_id' => $user_id,
            'created_at' => $now,
        ]), $request->validated('pings'));

        LocationPing::insert($rows);

        return response()->json(['stored' => count($rows)]);
    }
}
```

- [ ] **Step 2: Create DeviceTokenController**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpsertDeviceTokenRequest;
use App\Models\DeviceToken;

class DeviceTokenController extends Controller
{
    public function upsert(UpsertDeviceTokenRequest $request): \Illuminate\Http\JsonResponse
    {
        $token = $request->validated('token');
        $user = $request->user();

        // Move token to current user if another user previously owned it (device re-use / re-install).
        DeviceToken::where('token', $token)->where('user_id', '!=', $user->id)->delete();

        DeviceToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $user->id, 'platform' => $request->validated('platform')]
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(UpsertDeviceTokenRequest $request): \Illuminate\Http\JsonResponse
    {
        DeviceToken::where('token', $request->validated('token'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/LocationPingController.php app/Http/Controllers/Api/DeviceTokenController.php
git commit -m "feat: add LocationPingController and DeviceTokenController"
```

---

## Task 9: API Routes + Technician Location Controller ✅ DONE

**Files:**
- Modify: `routes/api.php`
- Create: `app/Http/Controllers/TechnicianLocationController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add imports and routes to routes/api.php**

At the top of `routes/api.php`, add alongside existing imports:

```php
use App\Http\Controllers\Api\LocationPingController;
use App\Http\Controllers\Api\DeviceTokenController;
```

Inside the existing `Route::group(['middleware' => 'auth:sanctum'], ...)` block, add:

```php
Route::post('location/pings', [LocationPingController::class, 'store']);
Route::post('device-tokens', [DeviceTokenController::class, 'upsert']);
Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);
```

These routes work via session cookie (from the WebView) because the domain is in `SANCTUM_STATEFUL_DOMAINS`.

- [ ] **Step 2: Create TechnicianLocationController**

Returns the most recent ping per user from the last 8 hours — used by the admin map.

```php
<?php

namespace App\Http\Controllers;

use App\Models\LocationPing;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TechnicianLocationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $latest_pings = LocationPing::query()
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('location_pings')
                    ->where('recorded_at', '>=', now()->subHours(8))
                    ->groupBy('user_id');
            })
            ->with('user:id,name,avatar')
            ->get(['id', 'user_id', 'lat', 'lng', 'accuracy', 'speed', 'heading', 'recorded_at']);

        return Inertia::render('Admin/TechnicianMap', [
            'pings' => $latest_pings,
        ]);
    }
}
```

- [ ] **Step 3: Add web route in routes/web.php**

Inside the `auth` middleware group (near other admin routes), add:

```php
use App\Http\Controllers\TechnicianLocationController;

Route::get('admin/technician-map', [TechnicianLocationController::class, 'index'])
    ->name('admin.technician-map');
```

- [ ] **Step 4: Commit**

```bash
git add routes/api.php routes/web.php app/Http/Controllers/TechnicianLocationController.php
git commit -m "feat: add location ping + device token API routes and admin technician map route"
```

---

## Task 10: Capacitor Detection + Network Status Composables

**Files:**
- Create: `resources/js/Composables/useCapacitor.js`
- Create: `resources/js/Composables/useNetworkStatus.js`

- [ ] **Step 1: Create useCapacitor.js**

```javascript
import { Capacitor } from '@capacitor/core';

export function useCapacitor() {
    const is_native = Capacitor.isNativePlatform();
    const platform = Capacitor.getPlatform(); // 'ios' | 'android' | 'web'

    return { is_native, platform };
}
```

- [ ] **Step 2: Create useNetworkStatus.js**

`is_online` is module-level so it is a singleton shared across all consumers. `init()` is safe to call multiple times.

```javascript
import { ref } from 'vue';
import { useCapacitor } from './useCapacitor.js';

const is_online = ref(true);
const connection_type = ref('unknown');
let initialized = false;

export function useNetworkStatus() {
    const { is_native } = useCapacitor();

    async function init() {
        if (initialized) return;
        initialized = true;

        if (is_native) {
            const { Network } = await import('@capacitor/network');
            const status = await Network.getStatus();
            is_online.value = status.connected;
            connection_type.value = status.connectionType;

            await Network.addListener('networkStatusChange', (status) => {
                is_online.value = status.connected;
                connection_type.value = status.connectionType;
            });
        } else {
            is_online.value = navigator.onLine;
            window.addEventListener('online', () => { is_online.value = true; });
            window.addEventListener('offline', () => { is_online.value = false; });
        }
    }

    return { is_online, connection_type, init };
}
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Composables/useCapacitor.js resources/js/Composables/useNetworkStatus.js
git commit -m "feat: add useCapacitor and useNetworkStatus composables"
```

---

## Task 11: Location Tracker Composable

**Files:**
- Create: `resources/js/Composables/useLocationTracker.js`

Design:
- Background geolocation watcher fires on movement; a `last_ping_at` timestamp gates to **one ping per 10 minutes** — no periodic interval timer needed
- Queue stored in localStorage (local launcher origin); flushed to `POST /api/location/pings` when online
- A watcher on `is_online` flushes on reconnect
- Max 200 pings in queue; oldest dropped first
- Only starts on native platforms (no-op on web)

- [ ] **Step 1: Create useLocationTracker.js**

```javascript
import { ref, watch } from 'vue';
import { Preferences } from '@capacitor/preferences';
import { useCapacitor } from './useCapacitor.js';
import { useNetworkStatus } from './useNetworkStatus.js';
import axios from 'axios';

const QUEUE_KEY = 'location_ping_queue';
const MAX_QUEUE = 200;
const FLUSH_INTERVAL_MS = 60_000;

const is_tracking = ref(false);
let watcher_id = null;
let flush_timer = null;

async function get_queue() {
    const { value } = await Preferences.get({ key: QUEUE_KEY });
    return value ? JSON.parse(value) : [];
}

async function save_queue(queue) {
    await Preferences.set({ key: QUEUE_KEY, value: JSON.stringify(queue) });
}

async function enqueue(ping) {
    const queue = await get_queue();
    queue.push(ping);
    if (queue.length > MAX_QUEUE) {
        queue.splice(0, queue.length - MAX_QUEUE);
    }
    await save_queue(queue);
}

async function flush() {
    const queue = await get_queue();
    if (!queue.length) return;
    try {
        await axios.post('/api/location/pings', { pings: queue });
        await save_queue([]);
    } catch {
        // Keep queue intact; retry on next flush.
    }
}

export function useLocationTracker() {
    const { is_native } = useCapacitor();
    const { is_online } = useNetworkStatus();

    watch(is_online, async (online) => {
        if (online) await flush();
    });

    async function start() {
        if (!is_native || is_tracking.value) return;
        is_tracking.value = true;

        const BackgroundGeolocation = (await import('@capacitor-community/background-geolocation')).default;

        watcher_id = await BackgroundGeolocation.addWatcher(
            {
                backgroundMessage: 'Lavoro volgt uw locatie voor servicebezoeken.',
                backgroundTitle: 'Locatie actief',
                requestPermissions: true,
                stale: false,
                distanceFilter: 30,
            },
            async function (location, error) {
                if (error || !location) return;
                await enqueue({
                    lat: location.latitude,
                    lng: location.longitude,
                    accuracy: location.accuracy ?? null,
                    speed: location.speed ?? null,
                    heading: location.bearing ?? null,
                    recorded_at: new Date(location.time).toISOString(),
                });
                if (is_online.value) await flush();
            }
        );

        flush_timer = setInterval(async () => {
            if (is_online.value) await flush();
        }, FLUSH_INTERVAL_MS);
    }

    async function stop() {
        if (!is_tracking.value) return;
        is_tracking.value = false;

        if (watcher_id !== null) {
            const BackgroundGeolocation = (await import('@capacitor-community/background-geolocation')).default;
            await BackgroundGeolocation.removeWatcher({ id: watcher_id });
            watcher_id = null;
        }

        if (flush_timer) {
            clearInterval(flush_timer);
            flush_timer = null;
        }
    }

    return { is_tracking, start, stop };
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Composables/useLocationTracker.js
git commit -m "feat: add useLocationTracker with background geolocation and offline queue"
```

---

## Task 12: Push Notifications Composable

**Files:**
- Create: `resources/js/Composables/usePushNotifications.js`

- [ ] **Step 1: Create usePushNotifications.js**

```javascript
import { useCapacitor } from './useCapacitor.js';
import axios from 'axios';

export function usePushNotifications() {
    const { is_native, platform } = useCapacitor();

    async function register() {
        if (!is_native) return;

        const { PushNotifications } = await import('@capacitor/push-notifications');

        const permission = await PushNotifications.requestPermissions();
        if (permission.receive !== 'granted') return;

        await PushNotifications.register();

        PushNotifications.addListener('registration', async (token) => {
            try {
                await axios.post('/api/device-tokens', {
                    token: token.value,
                    platform: platform,
                });
            } catch {
                // Non-fatal: retry happens on next app launch.
            }
        });

        PushNotifications.addListener('registrationError', (err) => {
            console.error('Push registration error:', err.error);
        });

        PushNotifications.addListener('pushNotificationReceived', (notification) => {
            // App is in foreground — log only. A future iteration can wire this
            // to the existing GlobalNotification.vue flash system via a custom event.
            console.info('Push foreground:', notification.title);
        });

        PushNotifications.addListener('pushNotificationActionPerformed', (action) => {
            const data = action.notification.data;
            if (data?.type === 'service_order_assigned' && data?.id) {
                window.location.href = `/serviceorders/${data.id}`;
            }
        });
    }

    async function unregister(token) {
        if (!is_native) return;
        try {
            await axios.delete('/api/device-tokens', { data: { token, platform } });
        } catch {
            // Best-effort cleanup.
        }
    }

    return { register, unregister };
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Composables/usePushNotifications.js
git commit -m "feat: add usePushNotifications composable with FCM token registration and tap-to-navigate"
```

---

## Task 13: OfflineBanner Component

**Files:**
- Create: `resources/js/Components/UI/OfflineBanner.vue`

- [ ] **Step 1: Create OfflineBanner.vue**

```vue
<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="-translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-full opacity-0"
    >
        <div
            v-if="!is_online"
            class="fixed top-0 inset-x-0 z-50 flex items-center justify-center gap-2 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950 shadow-md"
        >
            <ExclamationTriangleIcon class="size-4 shrink-0" />
            Geen internetverbinding — gegevens worden verstuurd zodra u weer online bent.
        </div>
    </Transition>
</template>

<script setup>
import { ExclamationTriangleIcon } from '@heroicons/vue/24/solid';
import { useNetworkStatus } from '@/Composables/useNetworkStatus.js';

const { is_online } = useNetworkStatus();
</script>
```

`@heroicons/vue` is already installed. `ExclamationTriangleIcon` exists in v2. The banner uses `fixed` positioning — it overlays content without causing layout shift.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/UI/OfflineBanner.vue
git commit -m "feat: add OfflineBanner component for offline network state"
```

---

## Task 14: Wire Composables into MainLayout

`MainLayout.vue` is 642 lines. Changes are additive — import 4 items, add 1 template tag, modify `onMounted`, modify `logout`.

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

- [ ] **Step 1: Add imports to the `<script setup>` block**

Find the existing `import` block in the script section. Add:

```javascript
import OfflineBanner from '@/Components/UI/OfflineBanner.vue';
import { useCapacitor } from '@/Composables/useCapacitor.js';
import { useNetworkStatus } from '@/Composables/useNetworkStatus.js';
import { useLocationTracker } from '@/Composables/useLocationTracker.js';
import { usePushNotifications } from '@/Composables/usePushNotifications.js';
```

- [ ] **Step 2: Initialize composables — add near the top of the `<script setup>` body**

```javascript
const { is_native } = useCapacitor();
const { init: init_network } = useNetworkStatus();
const { start: start_tracking, stop: stop_tracking } = useLocationTracker();
const { register: register_push } = usePushNotifications();
```

- [ ] **Step 3: Call init on mount — add inside the existing `onMounted` or create one**

If an `onMounted` already exists, add inside it. Otherwise add:

```javascript
onMounted(async () => {
    await init_network();

    if (is_native && props.auth?.user) {
        await register_push();
        await start_tracking();
    }
});
```

`onMounted` is already imported from `vue` in this file. `props` is the Inertia shared props — the layout receives `auth` as a prop from `HandleInertiaRequests`.

- [ ] **Step 4: Stop tracking on logout**

Find the existing `logout` function (around line 630). Add the stop call before the router navigation:

```javascript
const logout = async () => {
    if (is_native) {
        await stop_tracking();
    }
    // ... existing service worker cleanup lines stay unchanged ...
    router.get('/logout');
};
```

- [ ] **Step 5: Add OfflineBanner at the top of the template**

At the very first line inside `<template>`, before the existing root `<div class="min-h-screen ...">`:

```html
<OfflineBanner />
```

- [ ] **Step 6: Manual verification**

```bash
npm run dev
```

Open the app in a browser. Log in. Open DevTools → Network → set to "Offline". The amber banner should slide in from the top within 1 second. Set back to "Online" — banner slides out. No console errors.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Layouts/MainLayout.vue
git commit -m "feat: integrate GPS tracking, push notifications and offline banner into MainLayout"
```

---

## Task 15: Service Worker Push Listener

The existing `public/service-worker.js` handles caching only. Adding a `push` listener enables web-PWA push (browser users who add the app to homescreen). Native Capacitor push goes through FCM/APNs and does NOT go through the service worker — but adding this costs nothing and completes PWA support.

**Files:**
- Modify: `public/service-worker.js`

- [ ] **Step 1: Bump the cache version on line 1**

```javascript
// Before:
const CACHE_NAME = "wh-crm-cache-a91f3d2";

// After:
const CACHE_NAME = "lavoro-cache-v2";
```

- [ ] **Step 2: Append push and notificationclick event listeners**

At the end of `public/service-worker.js`, add:

```javascript
self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { notification: { title: 'Lavoro', body: event.data.text() } };
    }

    const title = payload.notification?.title ?? 'Lavoro';
    const options = {
        body: payload.notification?.body ?? '',
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data: payload.data ?? {},
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data = event.notification.data;

    const url = (data?.type === 'service_order_assigned' && data?.id)
        ? `/serviceorders/${data.id}`
        : '/';

    event.waitUntil(clients.openWindow(url));
});
```

- [ ] **Step 3: Commit**

```bash
git add public/service-worker.js
git commit -m "feat: add push and notificationclick listeners to service worker"
```

---

## Task 16: Firebase + FCM Backend

**Files:**
- Composer package: `laravel-notification-channels/fcm`
- Create: `app/Notifications/NewServiceOrderAssigned.php`
- Modify: `app/Http/Controllers/EventApiController.php`

- [ ] **Step 1: Install the FCM notification channel**

```bash
composer require laravel-notification-channels/fcm
```

Expected: installs without conflicts. This package uses `kreait/firebase-php` internally.

- [ ] **Step 2: Publish the package config**

```bash
php artisan vendor:publish --provider="NotificationChannels\FCM\FCMServiceProvider"
```

This creates `config/fcm.php`. Open it and verify it reads from `FIREBASE_CREDENTIALS`.

- [ ] **Step 3: Download the Firebase service account key**

1. Firebase Console → Project Settings → Service accounts tab
2. Click **Generate new private key** → download the JSON
3. Place it at `storage/app/firebase-credentials.json`
4. Add to `.gitignore`: `storage/app/firebase-credentials.json`

- [ ] **Step 4: Add to .env**

```
FIREBASE_CREDENTIALS=storage/app/firebase-credentials.json
```

And to `.env.example`:
```
FIREBASE_CREDENTIALS=
```

- [ ] **Step 5: Create the notification class**

```bash
php artisan make:notification NewServiceOrderAssigned
```

Replace the entire generated file with:

```php
<?php

namespace App\Notifications;

use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\FCM\FCMChannel;
use NotificationChannels\FCM\FCMMessage;
use NotificationChannels\FCM\Resources\Notification as FCMNotification;

class NewServiceOrderAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ServiceOrder $service_order) {}

    public function via(object $notifiable): array
    {
        if (empty($notifiable->routeNotificationForFcm())) {
            return [];
        }
        return [FCMChannel::class];
    }

    public function toFcm(object $notifiable): FCMMessage
    {
        return FCMMessage::create()
            ->setNotification(
                FCMNotification::create()
                    ->setTitle('Nieuwe werkbon toegewezen')
                    ->setBody("Werkbon #{$this->service_order->id} is aan u toegewezen.")
            )
            ->setData([
                'type' => 'service_order_assigned',
                'id'   => (string) $this->service_order->id,
            ]);
    }
}
```

- [ ] **Step 6: Hook notification into EventApiController — store() method**

In `EventApiController::store()`, lines 89-96 currently read:

```php
$executing_user_ids = $request['executing_user_ids'] ?? [];
if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
    $event->syncExecutingUsers(array_map('intval', $executing_user_ids));
    $model->syncExecutingUsers(array_map('intval', $executing_user_ids));
    $model->serviceJobs()->each(function ($job) use ($executing_user_ids) {
        $job->syncExecutingUsers(array_map('intval', $executing_user_ids));
    });
}
```

Replace with:

```php
$executing_user_ids = $request['executing_user_ids'] ?? [];
if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
    $ids = array_map('intval', $executing_user_ids);
    $event->syncExecutingUsers($ids);
    $model->syncExecutingUsers($ids);
    $model->serviceJobs()->each(fn($job) => $job->syncExecutingUsers($ids));

    if ($model instanceof ServiceOrder) {
        $model->executingUsers()->whereIn('users.id', $ids)->get()
            ->each(fn($user) => $user->notify(new \App\Notifications\NewServiceOrderAssigned($model)));
    }
}
```

- [ ] **Step 7: Hook notification into EventApiController — update() method**

In `update()`, there are two spots where `syncExecutingUsers` is called on a service order: lines ~148 and ~154. After each, add the same notification dispatch.

After line 148 (`$model->syncExecutingUsers($ids);`) inside `if ($model)`:

```php
if ($model instanceof ServiceOrder) {
    $model->executingUsers()->whereIn('users.id', $ids)->get()
        ->each(fn($user) => $user->notify(new \App\Notifications\NewServiceOrderAssigned($model)));
}
```

After line 154 (`$order->syncExecutingUsers($ids);`) inside the `$event->serviceOrders->each(...)` closure:

```php
$order->executingUsers()->whereIn('users.id', $ids)->get()
    ->each(fn($user) => $user->notify(new \App\Notifications\NewServiceOrderAssigned($order)));
```

- [ ] **Step 8: Commit**

```bash
git add app/Notifications/NewServiceOrderAssigned.php app/Http/Controllers/EventApiController.php composer.json composer.lock config/fcm.php .gitignore .env.example
git commit -m "feat: add FCM push notification dispatched when technicians are assigned to service orders"
```

---

## Task 17: Admin Technician Map Page

**Files:**
- Create: `resources/js/Pages/Admin/TechnicianMap.vue`
- Modify: `resources/js/Layouts/MainLayout.vue` (nav link)

- [ ] **Step 1: Create TechnicianMap.vue**

```vue
<template>
    <div class="relative" style="height: calc(100vh - 4rem);">
        <div ref="map_el" class="w-full h-full" />

        <div class="absolute top-4 left-4 z-[1000] bg-white dark:bg-slate-800 rounded-lg shadow-lg p-3 min-w-40">
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">Technici (laatste 8u)</p>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ props.pings.length }} zichtbaar</p>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    pings: { type: Array, required: true },
});

const map_el = ref(null);
let map = null;

onMounted(() => {
    map = L.map(map_el.value).setView([52.3, 5.3], 8);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);

    props.pings.forEach((ping) => {
        const age_min = Math.round((Date.now() - new Date(ping.recorded_at).getTime()) / 60_000);
        const marker = L.circleMarker([ping.lat, ping.lng], {
            radius: 10,
            color: '#1d4ed8',
            fillColor: '#3b82f6',
            fillOpacity: 0.85,
            weight: 2,
        }).addTo(map);

        marker.bindPopup(
            `<strong>${ping.user?.name ?? 'Onbekend'}</strong><br><span class="text-xs">${age_min} min. geleden</span>`
        );
    });

    if (props.pings.length > 0) {
        const bounds = L.latLngBounds(props.pings.map((p) => [p.lat, p.lng]));
        map.fitBounds(bounds, { padding: [50, 50] });
    }
});

onUnmounted(() => {
    map?.remove();
    map = null;
});
</script>
```

- [ ] **Step 2: Add nav link in MainLayout.vue**

In `MainLayout.vue`, find the navigation array (the `const navigation = [...]` definition in the script section). Locate the admin-only entries (items with `adminOnly: true` or a permission check for admin users). Add a new entry following the same pattern as existing items:

```javascript
{
    name: 'Technicikaart',
    href: route('admin.technician-map'),
    icon: MapPinIcon,
    current: false,
    permission: null,
    adminOnly: true,
},
```

Add `MapPinIcon` to the heroicons import at the top of the script section:

```javascript
import { MapPinIcon } from '@heroicons/vue/24/outline';
```

Note: inspect the exact shape of navigation entries in `MainLayout.vue` — if entries use different property names (e.g. `admin: true` instead of `adminOnly: true`), match that exact pattern.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Admin/TechnicianMap.vue resources/js/Layouts/MainLayout.vue
git commit -m "feat: add admin technician location map page with Leaflet"
```

---

## Post-Implementation Checklist

- [ ] Set `FIREBASE_CREDENTIALS=storage/app/firebase-credentials.json` in production `.env`
- [ ] Set `SANCTUM_STATEFUL_DOMAINS=<your-domain>` in production `.env`
- [ ] Set `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true` in production `.env`
- [ ] Upload APNs auth key to Firebase Console → Cloud Messaging → iOS app
- [ ] Download `google-services.json` from Firebase and place in `android/app/` on each dev machine
- [ ] Run `npm run cap:sync` after every frontend build before deploying to device
- [ ] Test GPS on a **real device** — simulators do not reliably support background geolocation
- [ ] Test push on a **real device** — simulators have limited FCM support
- [ ] Add a data retention policy for `location_pings` (suggest: delete pings older than 30 days via a scheduled artisan command)
- [ ] Consider a `location.tracking` permission migration to let admins control which users are GPS-tracked

---

## Known Limitations

- **Offline navigation:** When offline, Inertia page navigations fail (server unreachable). The service worker caches the last visited shell only. Full offline page browsing would require replacing Inertia with Vue Router + a REST API — a major refactor beyond this plan's scope.
- **Stationary pings:** `distanceFilter: 30` means no pings are generated when the technician is stationary. The map shows "last known position", which is correct for battery life but means there is no heartbeat from parked technicians.
- **Multiple device tokens per user:** Supported by the `device_tokens` table schema. Notifications are sent to all registered tokens — a user with both a phone and tablet receives both.
- **iOS 17+ background execution:** Apple tightens background location restrictions with each OS release. Test on the target iOS version. The `background-geolocation` plugin uses the correct `CLLocationManager` background mode but Apple may ask users to re-confirm "Always Allow" at any time.
