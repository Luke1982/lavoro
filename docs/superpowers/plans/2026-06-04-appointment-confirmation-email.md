# Appointment Confirmation Email — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Stuur afspraakbevestiging" right-click context menu entry in the Planner that sends a minimal appointment date/time confirmation email to the customer of the linked service order.

**Architecture:** A new `POST /api/events/{event}/send-confirmation` API route handled by `EventApiController::sendConfirmation()` resolves the linked service order and customer, sends `AppointmentConfirmationMail` via the existing Mail facade, and returns JSON. The frontend calls it via axios in the same pattern as existing planner actions (`toggleExecutingUser`, `changeEventType`).

**Tech Stack:** Laravel 12 (Mail facade, Mailable, Blade), Vue 3, axios, `@imengyu/vue3-context-menu`

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `app/Mail/AppointmentConfirmationMail.php` | Mailable — subject + view binding |
| Create | `resources/views/emails/event/appointment_confirmation.blade.php` | HTML email template (Dutch) |
| Modify | `app/Http/Controllers/EventApiController.php` | Add `sendConfirmation()` method |
| Modify | `routes/api.php` | Register `POST events/{event}/send-confirmation` |
| Modify | `resources/js/Components/Planner/ResourcePlannerWidget.vue` | Add context menu item + axios handler |

---

### Task 1: Create `AppointmentConfirmationMail`

**Files:**
- Create: `app/Mail/AppointmentConfirmationMail.php`

- [ ] **Step 1: Create the Mailable class**

Create `app/Mail/AppointmentConfirmationMail.php` with this content:

```php
<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Event $event, public ServiceOrder $serviceOrder)
    {
        //
    }

    public function build(): self
    {
        return $this->subject('Afspraakbevestiging #' . $this->serviceOrder->id)
            ->view('emails.event.appointment_confirmation', [
                'event' => $this->event,
                'serviceOrder' => $this->serviceOrder,
            ]);
    }
}
```

---

### Task 2: Create the Blade email template

**Files:**
- Create: `resources/views/emails/event/appointment_confirmation.blade.php`

> Note: The `resources/views/emails/event/` directory does not yet exist — create it along with the file.

- [ ] **Step 1: Create the directory and Blade template**

Create `resources/views/emails/event/appointment_confirmation.blade.php`:

```blade
{{-- HTML version to avoid markdown auto-formatting issues --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Afspraakbevestiging #{{ $serviceOrder->id }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, 'Noto Sans', sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f6f8;
        }
        .wrapper { width: 100%; padding: 24px 0; }
        .container {
            max-width: 570px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
            padding: 24px;
        }
        h1 { font-size: 18px; margin: 0 0 20px; color: #1a202c; }
        p { line-height: 1.55; font-size: 14px; color: #374151; margin: 0 0 16px; }
        .meta strong { color: #2d3748; }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo img { max-height: 56px; }
        .footer { text-align: center; font-size: 12px; color: #a0aec0; margin-top: 28px; padding: 24px 0 0; }
        .label { font-weight: 600; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="logo">
            @php($logo = config('app.mail_logo_url'))
            @if (!$logo)
                @php($publicStorageLogo = public_path('storage/logo.png'))
                @if (file_exists($publicStorageLogo))
                    @php($logo = asset('storage/logo.png'))
                @endif
            @endif
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ config('app.name') }}">
            @else
                <span style="font-size:20px;font-weight:600;color:#2d3748;">{{ config('app.name') }}</span>
            @endif
        </div>
        <div class="container">
            <h1>Afspraakbevestiging</h1>
            <p>Beste {{ $serviceOrder->customer->contact_person ?? $serviceOrder->customer->name }},</p>
            <p>Hierbij bevestigen wij uw afspraak:</p>
            <p class="meta">
                <span class="label">Datum:</span> {{ $event->start->format('d-m-Y') }}<br>
                <span class="label">Tijd:</span> {{ $event->start->format('H:i') }} – {{ $event->end->format('H:i') }}<br>
                <span class="label">Werkbon:</span> #{{ $serviceOrder->id }}<br>
                <span class="label">Klant:</span> {{ $serviceOrder->customer->name }}
            </p>
            @if ($serviceOrder->description)
                <p>
                    <span class="label">Omschrijving:</span><br>
                    {{ $serviceOrder->description }}
                </p>
            @endif
            <p>Met vriendelijke groet,</p>
            <p>{{ config('app.name') }}</p>
        </div>
        <div class="footer">&copy; {{ date('Y') }} {{ config('app.name') }}. Alle rechten voorbehouden.</div>
    </div>
</body>
</html>
```

---

### Task 3: Add the API route and controller method

**Files:**
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/EventApiController.php`

- [ ] **Step 1: Register the route in `routes/api.php`**

Inside the `auth:sanctum` group, after the existing `Route::resource('events', ...)` line, add:

```php
Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);
```

The group should look like:

```php
Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::resource('events', EventApiController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);

    Route::get('projects', [ProjectApiController::class, 'index']);
    Route::get('projectmilestones', [ProjectApiController::class, 'milestones']);

    Route::get('google/integration/status', GoogleIntegrationStatusController::class)
        ->name('api.google.integration.status');
});
```

- [ ] **Step 2: Add imports and `sendConfirmation()` to `EventApiController`**

Add these two `use` statements to the existing imports at the top of `app/Http/Controllers/EventApiController.php`:

```php
use App\Mail\AppointmentConfirmationMail;
use Illuminate\Support\Facades\Mail;
```

Then add this method at the end of the class, before the closing `}`:

```php
public function sendConfirmation(Event $event)
{
    $service_order = $event->serviceOrders()->with('customer')->first();

    if (!$service_order) {
        return response()->json(['message' => 'Geen werkbon gekoppeld aan deze afspraak.'], 422);
    }

    $recipients = array_unique(array_filter([
        $service_order->customer?->email,
        $service_order->customer?->invoice_email,
    ]));

    if (empty($recipients)) {
        return response()->json(['message' => 'Klant heeft geen e-mailadres.'], 422);
    }

    Mail::to($recipients)->send(new AppointmentConfirmationMail($event, $service_order));

    $service_order->logActivity(
        'Afspraakbevestiging per e-mail verzonden naar: ' . implode(', ', $recipients)
    );

    return response()->json([
        'message' => 'Bevestiging verzonden naar: ' . implode(', ', $recipients),
    ]);
}
```

- [ ] **Step 3: Verify the route is reachable**

Run:
```bash
php artisan route:list --path=api/events
```

Expected output includes a line like:
```
POST   api/events/{event}/send-confirmation   EventApiController@sendConfirmation
```

---

### Task 4: Add the frontend context menu item and handler

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

The context menu is built in `onEventContextMenu()` (around line 792). The "Open werkbon" item is conditionally pushed when `ev.eventable_id` is set (lines 819–825). Add the new item in that same block, immediately after.

- [ ] **Step 1: Add the context menu item inside the `if (ev.eventable_id)` block**

Find this block in `onEventContextMenu()`:

```js
if (ev.eventable_id) {
    items.push({
        label: `Open werkbon #${ev.eventable_id}`,
        divided: true,
        onClick: () => router.visit(`/serviceorders/${ev.eventable_id}`),
    })
}
```

Replace it with:

```js
if (ev.eventable_id) {
    items.push({
        label: `Open werkbon #${ev.eventable_id}`,
        divided: true,
        onClick: () => router.visit(`/serviceorders/${ev.eventable_id}`),
    })
    items.push({
        label: 'Stuur afspraakbevestiging',
        onClick: () => sendAppointmentConfirmation(ev),
    })
}
```

- [ ] **Step 2: Add the `sendAppointmentConfirmation` function**

Add this function near the other async event-action functions (e.g., after `changeEventType` or `deleteEvent`):

```js
async function sendAppointmentConfirmation(ev) {
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post(`/api/events/${ev.id}/send-confirmation`)
        page.props.flash.success = r.data.message
    } catch (e) {
        page.props.flash.error = e.response?.data?.message || 'Kon bevestiging niet verzenden'
    }
}
```

---

## Self-Review

**Spec coverage check:**
- ✅ Route `POST /api/events/{event}/send-confirmation` — Task 3, Step 1
- ✅ `sendConfirmation()` resolves service order + customer — Task 3, Step 2
- ✅ Returns 422 with Dutch error when no service order — Task 3, Step 2
- ✅ Returns 422 with Dutch error when no email — Task 3, Step 2
- ✅ Sends `AppointmentConfirmationMail` — Task 3, Step 2
- ✅ Logs activity on service order — Task 3, Step 2
- ✅ Returns 200 JSON with success message — Task 3, Step 2
- ✅ Mailable with correct subject and view — Task 1
- ✅ Blade template with greeting, date+time, service order number, closing — Task 2
- ✅ Context menu item visible when `ev.eventable_id` is set — Task 4, Step 1
- ✅ Frontend fires-and-forgets, flash success/error — Task 4, Step 2

**Placeholder scan:** None found.

**Type consistency:** `AppointmentConfirmationMail` constructor accepts `Event $event, ServiceOrder $serviceOrder` — matches usage in `sendConfirmation()` and the Blade template variables `$event`, `$serviceOrder`. `$event->start` and `$event->end` are Carbon instances (cast in `Event` model). `logActivity()` is available on `ServiceOrder` via `HasActivities` trait.
