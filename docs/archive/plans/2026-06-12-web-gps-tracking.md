# Web GPS Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add browser-based GPS tracking that mirrors Android behaviour for users with the `location.track` permission, constrained to a configurable time/day window set by admins in a new General Settings page.

**Architecture:** A migration seeds the new permission and three `general_settings` keys. A new `Admin\GeneralSettingsController` serves and saves the time/day window. `HandleInertiaRequests` shares the window to every page. `useLocationTracker.js` gains a web branch using `navigator.geolocation.watchPosition` that buffers pings and flushes them via axios to the existing `/api/location/pings` endpoint; a 60-second interval enforces the window. `MainLayout.vue` starts tracking for any user with `location.track` (not just native).

**Tech Stack:** Laravel 12, Inertia, Vue 3, Axios, Browser Geolocation API, Heroicons

---

## File map

| Action | Path |
|--------|------|
| Create | `database/migrations/2026_06_12_000001_seed_location_track_settings.php` |
| Create | `app/Http/Controllers/Admin/GeneralSettingsController.php` |
| Create | `app/Http/Requests/Admin/UpdateLocationTrackingSettingsRequest.php` |
| Create | `resources/js/Pages/Admin/GeneralSettingsPage.vue` |
| Modify | `app/Http/Middleware/HandleInertiaRequests.php` |
| Modify | `routes/web.php` |
| Modify | `resources/js/Composables/useLocationTracker.js` |
| Modify | `resources/js/Layouts/MainLayout.vue` |

---

## Task 1: Migration — seed permission and general_settings rows

**Files:**
- Create: `database/migrations/2026_06_12_000001_seed_location_track_settings.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name'       => 'location.track',
                'label'      => 'Locatie tracking',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['label', 'updated_at']);

        DB::table('general_settings')->upsert([
            ['key' => 'location_tracking_start', 'value' => '07:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'location_tracking_end',   'value' => '18:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'location_tracking_days',  'value' => '1,2,3,4,5', 'created_at' => now(), 'updated_at' => now()],
        ], ['key'], ['value', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'location.track')->delete();
        DB::table('general_settings')
            ->whereIn('key', ['location_tracking_start', 'location_tracking_end', 'location_tracking_days'])
            ->delete();
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_12_000001_seed_location_track_settings` then `Migrated`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_12_000001_seed_location_track_settings.php
git commit -m "feat(GPS): seed location.track permission and tracking window settings"
```

---

## Task 2: Form Request + Controller + Routes

**Files:**
- Create: `app/Http/Requests/Admin/UpdateLocationTrackingSettingsRequest.php`
- Create: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the Form Request**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationTrackingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'start'   => ['required', 'date_format:H:i'],
            'end'     => ['required', 'date_format:H:i'],
            'days'    => ['required', 'array', 'min:1'],
            'days.*'  => ['integer', 'between:1,7'],
        ];
    }
}
```

- [ ] **Step 2: Create the Controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLocationTrackingSettingsRequest;
use App\Models\GeneralSetting;
use Inertia\Inertia;

class GeneralSettingsController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Admin/GeneralSettingsPage', [
            'locationTracking' => [
                'start' => GeneralSetting::get('location_tracking_start', '07:00'),
                'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
                'days'  => array_map('intval', explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))),
            ],
        ]);
    }

    public function updateLocationTracking(UpdateLocationTrackingSettingsRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();

        GeneralSetting::set('location_tracking_start', $data['start']);
        GeneralSetting::set('location_tracking_end', $data['end']);
        GeneralSetting::set('location_tracking_days', implode(',', $data['days']));

        return redirect()->back()->with('success', 'Instellingen opgeslagen.');
    }
}
```

- [ ] **Step 3: Add routes in `routes/web.php`**

Inside the existing `Route::middleware('admin')->group(function () {` block (after the last `calendar-grants` route, before the closing `});`), add:

```php
Route::get(
    'admin/settings',
    [\App\Http\Controllers\Admin\GeneralSettingsController::class, 'index'],
)->name('admin.settings.index');
Route::put(
    'admin/settings/location-tracking',
    [\App\Http\Controllers\Admin\GeneralSettingsController::class, 'updateLocationTracking'],
)->name('admin.settings.location-tracking');
```

- [ ] **Step 4: Verify routes are registered**

```bash
php artisan route:list --name=admin.settings
```

Expected: two rows — `GET admin/settings` and `PUT admin/settings/location-tracking`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Admin/UpdateLocationTrackingSettingsRequest.php \
        app/Http/Controllers/Admin/GeneralSettingsController.php \
        routes/web.php
git commit -m "feat(GPS): admin general settings controller and routes"
```

---

## Task 3: Share tracking window via Inertia middleware

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

- [ ] **Step 1: Add `use` import and `location_tracking` key**

At the top of `HandleInertiaRequests.php`, after the existing `use Illuminate\Http\Request;` line, add:

```php
use App\Models\GeneralSetting;
```

In the `share()` method, add `location_tracking` alongside the existing `flash` and `auth` keys:

```php
'location_tracking' => $request->user() ? [
    'start' => GeneralSetting::get('location_tracking_start', '07:00'),
    'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
    'days'  => array_map('intval', explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))),
] : null,
```

The full `share()` return array after the change:

```php
return [
    ...parent::share($request),
    'flash' => [
        'success' => $request->session()->get('success'),
        'error'   => $request->session()->get('error'),
        'extra'   => $request->session()->get('extra'),
    ],
    'auth' => [
        'user'        => $user_data,
        'permissions' => $request->user() ? $request->user()->permissionNames() : [],
        'isAdmin'     => $request->user() ? $request->user()->isAdmin() : false,
    ],
    'location_tracking' => $request->user() ? [
        'start' => GeneralSetting::get('location_tracking_start', '07:00'),
        'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
        'days'  => array_map('intval', explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))),
    ] : null,
];
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat(GPS): share location_tracking window via Inertia"
```

---

## Task 4: Admin General Settings Vue page

**Files:**
- Create: `resources/js/Pages/Admin/GeneralSettingsPage.vue`

- [ ] **Step 1: Create the page**

```vue
<template>
    <div class="p-6 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Instellingen</h1>

        <section>
            <h2 class="text-base font-semibold text-gray-900 mb-4">Locatie tracking</h2>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="flex gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Starttijd</label>
                        <input
                            type="time"
                            v-model="form.start"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.start" class="mt-1 text-xs text-red-600">{{ form.errors.start }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Eindtijd</label>
                        <input
                            type="time"
                            v-model="form.end"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.end" class="mt-1 text-xs text-red-600">{{ form.errors.end }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Dagen</label>
                    <div class="flex gap-2 flex-wrap">
                        <button
                            v-for="day in DAYS"
                            :key="day.value"
                            type="button"
                            @click="toggleDay(day.value)"
                            :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                form.days.includes(day.value)
                                    ? 'bg-indigo-600 border-indigo-600 text-white'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                            ]"
                        >
                            {{ day.label }}
                        </button>
                    </div>
                    <p v-if="form.errors.days" class="mt-1 text-xs text-red-600">{{ form.errors.days }}</p>
                </div>

                <div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Opslaan
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    locationTracking: { type: Object, required: true },
})

const DAYS = [
    { value: 1, label: 'Ma' },
    { value: 2, label: 'Di' },
    { value: 3, label: 'Wo' },
    { value: 4, label: 'Do' },
    { value: 5, label: 'Vr' },
    { value: 6, label: 'Za' },
    { value: 7, label: 'Zo' },
]

const form = useForm({
    start: props.locationTracking.start,
    end:   props.locationTracking.end,
    days:  [...props.locationTracking.days],
})

function toggleDay(value) {
    const index = form.days.indexOf(value)
    if (index === -1) {
        form.days.push(value)
    } else {
        form.days.splice(index, 1)
    }
}

function submit() {
    form.put('/admin/settings/location-tracking')
}
</script>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Admin/GeneralSettingsPage.vue
git commit -m "feat(GPS): admin general settings page with tracking window UI"
```

---

## Task 5: Add "Instellingen" link to admin sidebar

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

- [ ] **Step 1: Add `Cog6ToothIcon` to the heroicons import**

In the existing `@heroicons/vue/24/outline` import block (around line 372), add `Cog6ToothIcon` to the destructured list:

```js
import {
    Bars3Icon,
    Bars4Icon,
    // ... existing icons ...
    Cog6ToothIcon,
} from '@heroicons/vue/24/outline'
```

(Add `Cog6ToothIcon,` on a new line inside the existing import, keeping alphabetical order is optional but tidy — insert after `ClipboardDocumentListIcon`.)

- [ ] **Step 2: Add link in the mobile sidebar admin block**

In the mobile sidebar (the `<div class="px-6 mb-2 space-y-1" v-if="isAdmin">` block around line 103), after the existing `Agenda-toegang` link and before the closing `</div>`:

```html
<Link @click="sidebarOpen = false" :href="'/admin/settings'" :class="[
    currentPath.startsWith('/admin/settings') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
]">
    <Cog6ToothIcon class="size-6 shrink-0" />
    Instellingen
</Link>
```

- [ ] **Step 3: Add link in the desktop sidebar admin block**

In the desktop sidebar (the `<div ... v-if="isAdmin">` block around line 252), after the existing `Agenda-toegang` link and before the closing `</div>`:

```html
<Link :href="'/admin/settings'" :class="[
    currentPath.startsWith('/admin/settings') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
]">
    <Cog6ToothIcon class="size-6 shrink-0" />
    Instellingen
</Link>
```

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/MainLayout.vue
git commit -m "feat(GPS): add Instellingen admin sidebar link"
```

---

## Task 6: Web GPS branch in `useLocationTracker.js`

**Files:**
- Modify: `resources/js/Composables/useLocationTracker.js`

- [ ] **Step 1: Replace the file contents**

```js
import { ref } from 'vue';
import { registerPlugin } from '@capacitor/core';
import { usePage } from '@inertiajs/vue3';
import { useCapacitor } from './useCapacitor.js';
import axios from 'axios';

const LocationTracker = registerPlugin('LocationTracker');

const is_tracking = ref(false);

// Web-only module-level state (singleton — one tracker per browser session)
let watch_id = null;
let ping_buffer = [];
let flush_interval_id = null;
let csrf_fetched = false;

function js_day_to_iso(day) {
    // JS getDay() returns 0=Sun … 6=Sat; ISO weekday is 1=Mon … 7=Sun
    return day === 0 ? 7 : day;
}

function is_within_window(settings) {
    if (!settings) return true;
    const now = new Date();
    const iso_day = js_day_to_iso(now.getDay());
    const days = Array.isArray(settings.days)
        ? settings.days.map(Number)
        : String(settings.days).split(',').map(Number);
    if (!days.includes(iso_day)) return false;
    const hhmm = now.toTimeString().slice(0, 5); // "HH:MM"
    return hhmm >= settings.start && hhmm < settings.end;
}

async function ensure_csrf() {
    if (csrf_fetched) return;
    await axios.get('/sanctum/csrf-cookie');
    csrf_fetched = true;
}

async function flush_buffer() {
    if (ping_buffer.length === 0) return;
    const pings = ping_buffer.splice(0);
    try {
        await ensure_csrf();
        await axios.post('/api/location/pings', { pings });
    } catch {
        ping_buffer.unshift(...pings);
    }
}

function start_watch() {
    if (watch_id !== null) return;
    watch_id = navigator.geolocation.watchPosition(
        (pos) => {
            ping_buffer.push({
                lat:         pos.coords.latitude,
                lng:         pos.coords.longitude,
                accuracy:    pos.coords.accuracy ?? null,
                speed:       pos.coords.speed ?? null,
                heading:     pos.coords.heading ?? null,
                recorded_at: new Date(pos.timestamp).toISOString(),
            });
            if (ping_buffer.length >= 10) flush_buffer();
        },
        (err) => {
            if (err.code === GeolocationPositionError.PERMISSION_DENIED) {
                console.warn('GPS: locatiepermissie geweigerd');
                stop_watch();
            }
        },
        { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 },
    );
}

function stop_watch() {
    if (watch_id === null) return;
    navigator.geolocation.clearWatch(watch_id);
    watch_id = null;
}

export function useLocationTracker() {
    const { is_native } = useCapacitor();

    async function start() {
        if (is_tracking.value) return;

        if (is_native) {
            // The native foreground service collects GPS and POSTs to the server
            // independently of the WebView, so it keeps running when the app is
            // backgrounded. It needs the server origin and reads session cookies
            // from the shared native cookie store for authentication.
            await LocationTracker.start({ serverUrl: window.location.origin });
            is_tracking.value = true;
            return;
        }

        if (!navigator.geolocation) return;

        is_tracking.value = true;

        // Every 60 s: check window and flush buffer
        flush_interval_id = setInterval(() => {
            const settings = usePage().props.location_tracking;
            if (is_within_window(settings)) {
                start_watch();
            } else {
                stop_watch();
            }
            flush_buffer();
        }, 60_000);

        // Start immediately if inside the window
        if (is_within_window(usePage().props.location_tracking)) {
            start_watch();
        }
    }

    async function stop() {
        if (!is_tracking.value) return;

        if (is_native) {
            await LocationTracker.stop();
        } else {
            stop_watch();
            clearInterval(flush_interval_id);
            flush_interval_id = null;
            await flush_buffer();
        }

        is_tracking.value = false;
    }

    return { is_tracking, start, stop };
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Composables/useLocationTracker.js
git commit -m "feat(GPS): add browser Geolocation API branch to useLocationTracker"
```

---

## Task 7: Update tracking guard and logout in `MainLayout.vue`

**Files:**
- Modify: `resources/js/Layouts/MainLayout.vue`

- [ ] **Step 1: Update `onMounted` tracking guard**

Find (around line 428–445):

```js
onMounted(async () => {
    try { await init_network() } catch (e) { console.error('Network initialization failed:', e) }
    if (is_native && page.props.auth?.user) {
        try { await init_deep_links() } catch (e) { console.error('Deep link init failed:', e) }
        try { await check_update() } catch (e) { console.error('Update check failed:', e) }
        try {
            await start_tracking()
        } catch (e) {
            console.error('GPS tracking failed to start:', e)
        }
        if (PUSH_ENABLED) {
            try {
                await register_push()
            } catch (e) {
                console.error('Push registration failed:', e)
            }
        }
    }
})
```

Replace with:

```js
onMounted(async () => {
    try { await init_network() } catch (e) { console.error('Network initialization failed:', e) }
    if (is_native && page.props.auth?.user) {
        try { await init_deep_links() } catch (e) { console.error('Deep link init failed:', e) }
        try { await check_update() } catch (e) { console.error('Update check failed:', e) }
        if (PUSH_ENABLED) {
            try {
                await register_push()
            } catch (e) {
                console.error('Push registration failed:', e)
            }
        }
    }
    if (page.props.auth?.user && hasPermission('location.track')) {
        try {
            await start_tracking()
        } catch (e) {
            console.error('GPS tracking failed to start:', e)
        }
    }
})
```

- [ ] **Step 2: Update `logout` to stop tracking unconditionally**

Find (around line 688):

```js
const logout = async () => {
    if (is_native) {
        await stop_tracking()
    }
```

Replace with:

```js
const logout = async () => {
    await stop_tracking()
```

(`stop_tracking` is a no-op when `is_tracking` is false, so this is safe for all users.)

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/MainLayout.vue
git commit -m "feat(GPS): start web tracking for users with location.track permission"
```

---

## Self-review

**Spec coverage:**
- ✅ `location.track` permission — Task 1
- ✅ `general_settings` rows for start/end/days — Task 1
- ✅ Admin settings page with time inputs + day toggles — Task 4
- ✅ Admin-only access (`authorize(): isAdmin()`) — Task 2
- ✅ Menu link (both sidebars) — Task 5
- ✅ Inertia shared `location_tracking` — Task 3
- ✅ Web GPS using `watchPosition`, buffer, 60s flush, window enforcement — Task 6
- ✅ Tracking guard uses `location.track` permission instead of `is_native` — Task 7

**Placeholders:** none.

**Type consistency:** `location_tracking.days` is always `number[]` — the controller sends `array_map('intval', ...)`, the shared data does the same, and `is_within_window` defensively normalises either format. Consistent throughout.
