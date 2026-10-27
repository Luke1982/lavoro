---
title: Web GPS Tracking
date: 2026-06-12
status: approved
---

# Web GPS Tracking

## Overview

Extend the existing Android GPS tracking to the browser. When a user with the `location.track` permission has the site open, the browser's Geolocation API collects position fixes and POSTs them to the existing `/api/location/pings` endpoint. Admins configure a time/day window via a new General Settings admin page; the client enforces the window and only tracks within it.

---

## 1. Permission & settings data

### Permission

New migration seeds `location.track` (label: "Locatie tracking") into the `permissions` table using the existing `DB::table('permissions')->upsert(...)` pattern.

### General settings keys

Three new rows seeded into `general_settings` (same migration or a dedicated one):

| key | default | format |
|-----|---------|--------|
| `location_tracking_start` | `07:00` | HH:MM 24h |
| `location_tracking_end` | `18:00` | HH:MM 24h |
| `location_tracking_days` | `1,2,3,4,5` | comma-separated ISO weekday numbers (1=Mon … 7=Sun) |

---

## 2. Admin settings page

### Route

- `GET /admin/settings` → `Admin\GeneralSettingsController@index` (Inertia render, `admin` middleware)
- `PUT /admin/settings/location-tracking` → `Admin\GeneralSettingsController@updateLocationTracking`

### Form Request

`Admin\UpdateLocationTrackingSettingsRequest`:
- `authorize()`: `$user->isAdmin()`
- `rules()`: `start` and `end` are `required|date_format:H:i`; `days` is `required|array|min:1` with each element `integer|between:1,7`

### Controller

`Admin\GeneralSettingsController`:
- `index()`: fetches all three settings, passes to Inertia as `locationTracking` prop
- `updateLocationTracking()`: writes `start`, `end`, `days` (days serialised as comma-joined string) to `GeneralSetting::set()`; returns redirect back

### Vue page

`Pages/Admin/GeneralSettingsPage.vue`:

- **"Locatie tracking" section**
  - Two `<input type="time">` fields bound to start/end (Tailwind-styled to match existing inputs)
  - Days-of-week row: seven pill buttons Ma Di Wo Do Vr Za Zo, clicking a selected day deselects it (no separate clear button)
  - Save button POSTs via Inertia `useForm`

### Menu

Add to the `isAdmin` block in `MainLayout.vue` sidebar (both mobile and desktop):

```
<Link href="/admin/settings">
  <Cog6ToothIcon /> Instellingen
</Link>
```

---

## 3. Inertia shared data

`HandleInertiaRequests::share()` adds a `location_tracking` key, read once per request for authenticated users:

```php
'location_tracking' => $request->user() ? [
    'start' => GeneralSetting::get('location_tracking_start', '07:00'),
    'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
    'days'  => GeneralSetting::get('location_tracking_days', '1,2,3,4,5'),
] : null,
```

This avoids repeated DB lookups per page and keeps the window data available everywhere without extra Inertia props on individual pages.

---

## 4. Frontend tracking logic

### `useLocationTracker.js` — web branch

When `!is_native`, `start()` and `stop()` use the browser Geolocation API instead of the Capacitor plugin.

**Ping collection:**
- `navigator.geolocation.watchPosition(onSuccess, onError, { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 })`
- Each `GeolocationPosition` is mapped to `{ lat, lng, accuracy, speed, heading, recorded_at }` (speed/heading are nullable on most browsers)
- Pings buffered in a module-level array

**Flushing:**
- A `setInterval` fires every 60 seconds
- If buffer has ≥ 1 ping, fetches Sanctum CSRF cookie once (`axios.get('/sanctum/csrf-cookie')`, cached after first call), then POSTs `{ pings: buffer }` to `/api/location/pings` and clears buffer
- Also flushes eagerly when buffer reaches 10 pings

**Window enforcement:**
- A helper `isWithinTrackingWindow(settings)` checks: current day (JS `getDay()` converted to ISO 1–7) is in `settings.days`, and current time (HH:MM) is between `settings.start` and `settings.end`
- Called on `start()`: if outside window, registers a 60s interval that polls until the window opens (instead of starting watchPosition immediately)
- The flush interval also calls `isWithinTrackingWindow`; if false, stops watchPosition and sets a re-check interval

### `MainLayout.vue`

Change the tracking guard from:
```js
if (is_native && page.props.auth?.user) {
    await start_tracking()
}
```
to:
```js
if (page.props.auth?.user && hasPermission('location.track')) {
    await start_tracking()
}
```

`start_tracking` already handles the `is_native` / web branch distinction internally. The `location_tracking` settings are passed into `start_tracking` (or read from `usePage()` inside the composable).

---

## 5. Error handling

- Geolocation `PERMISSION_DENIED`: log warning, stop tracking, do not retry (user must grant permission in browser)
- Geolocation `POSITION_UNAVAILABLE` / `TIMEOUT`: log warning, retry on next flush interval tick
- Failed API POST: keep buffer, retry on next flush tick (no infinite retry loop)

---

## 6. No server-side window enforcement

The tracking window is a soft operational setting, not a security boundary. Client-side enforcement is sufficient for internal staff. The API endpoint remains unchanged.

---

## Files touched

| File | Change |
|------|--------|
| `database/migrations/2026_06_12_*_seed_location_track_permission.php` | new — seeds `location.track` permission + 3 general_settings rows |
| `app/Http/Controllers/Admin/GeneralSettingsController.php` | new |
| `app/Http/Requests/Admin/UpdateLocationTrackingSettingsRequest.php` | new |
| `resources/js/Pages/Admin/GeneralSettingsPage.vue` | new |
| `app/Http/Middleware/HandleInertiaRequests.php` | add `location_tracking` to shared data |
| `routes/web.php` | add 2 admin routes |
| `resources/js/Composables/useLocationTracker.js` | add web branch |
| `resources/js/Layouts/MainLayout.vue` | update tracking guard + add sidebar link |
