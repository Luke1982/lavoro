# Events without service orders + event feedback — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow creating events with no service order (persisted, editable) and let permitted users attach remarks and images to any event through a feedback modal on desktop planner, mobile planner, and the customer timeline.

**Architecture:** Backend adds a `no_service_order` column and a `events.provide_feedback` permission, makes `Event` remarkable/imageable, and exposes JSON endpoints by teaching the existing `RemarkController`/`ImageController` to content-negotiate. Frontend teaches the existing `RemarksComponent`/`ImageUploadComponent` a dual (`apiMode`) transport, and drives a reused `ModalDialog` from a shared `useEventFeedback` composable — no bespoke modal component.

**Tech Stack:** Laravel 12, Inertia, Vue 3, axios, Sanctum, Vitest + @vue/test-utils (new), PHPUnit.

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments; docblocks only when needed.
- Authorization lives in Form Requests via policies (`$user->can(...)`); `hasPermission` is only ever called inside policy methods, never directly in a Form Request.
- Validation belongs in Form Request `rules()` only; frontend only displays `form.errors`.
- Selecting/toggling in UI: clicking a selected item deselects it — never add separate X / clear buttons.
- String concatenation always uses spaces: `$string . ' more'`.
- Reuse the `imageables` / `remarkables` morph pivots (with their `internal` / `main` pivot columns) — do not introduce parallel tables.
- Permission name is exactly `events.provide_feedback` (plural `events`, matching `events.see_beyond_current_week`).
- Do not propose git commands beyond the commit steps in this plan.

---

### Task 1: `no_service_order` column, model, validation, and store logic

**Files:**
- Create: `database/migrations/2026_07_01_000003_add_no_service_order_to_events_table.php`
- Create: `database/factories/EventFactory.php`
- Modify: `app/Models/Event.php` (add `no_service_order` to `$fillable` and `$casts`)
- Modify: `app/Http/Requests/EventStoreRequest.php`
- Modify: `app/Http/Requests/EventUpdateRequest.php`
- Modify: `app/Http/Controllers/EventApiController.php:71-139` (`store`)
- Test: `tests/Feature/EventNoServiceOrderTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `Event` records may have `no_service_order = true` with `eventable_type`/`eventable_id` null. `EventFactory` with default state usable by later tasks. Admin-user test helper pattern (create `Role{name:'admin'}` and attach via `roles()`).

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_07_01_000003_add_no_service_order_to_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('no_service_order')->default(false)->after('is_preliminary');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('no_service_order');
        });
    }
};
```

- [ ] **Step 2: Create the Event factory**

Create `database/factories/EventFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => null,
            'event_type_id' => EventType::factory(),
            'status' => 'Gepland',
            'start' => now(),
            'end' => now()->addHour(),
            'no_service_order' => false,
        ];
    }
}
```

Then add the `HasFactory` trait to `app/Models/Event.php` if not already present:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
```
and add `use HasFactory;` in the class body alongside the existing traits.

- [ ] **Step 3: Add column to Event model**

In `app/Models/Event.php`, add `'no_service_order'` to `$fillable` (after `'is_preliminary'`) and add `'no_service_order' => 'boolean'` to `$casts`.

- [ ] **Step 4: Write the failing feature test**

Create `tests/Feature/EventNoServiceOrderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventNoServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin_user(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_event_can_be_created_without_service_order(): void
    {
        $admin = $this->admin_user();
        $type = EventType::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/events', [
            'event_type_id' => $type->id,
            'status' => 'Gepland',
            'start' => now()->format('Y-m-d H:i'),
            'end' => now()->addHour()->format('Y-m-d H:i'),
            'no_service_order' => true,
            'executing_user_ids' => [$admin->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('events', [
            'no_service_order' => true,
            'eventable_id' => null,
            'eventable_type' => null,
        ]);
        $this->assertDatabaseCount('service_orders', 0);
    }

    public function test_event_without_order_or_flag_is_rejected(): void
    {
        $admin = $this->admin_user();
        $type = EventType::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/events', [
            'event_type_id' => $type->id,
            'status' => 'Gepland',
            'start' => now()->format('Y-m-d H:i'),
            'end' => now()->addHour()->format('Y-m-d H:i'),
            'executing_user_ids' => [$admin->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('eventable_id');
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `php artisan test --filter=EventNoServiceOrderTest`
Expected: FAIL — first test errors/500 or creates a service order (flag not honored); the column/validation/store logic does not exist yet.

- [ ] **Step 6: Add validation rules**

In `app/Http/Requests/EventStoreRequest.php`, add to `rules()`:

```php
'no_service_order' => 'nullable|boolean',
```

Replace the `withValidator` body with:

```php
public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        if (
            ! $this->boolean('create_service_order')
            && ! $this->boolean('no_service_order')
            && ! $this->filled('eventable_id')
        ) {
            $validator->errors()->add('eventable_id', 'Koppel een werkbon aan de afspraak of maak een nieuwe aan.');
        }
    });
}
```

In `app/Http/Requests/EventUpdateRequest.php`, add `'no_service_order' => 'nullable|boolean',` to `rules()`.

- [ ] **Step 7: Honor the flag in the controller**

In `app/Http/Controllers/EventApiController.php`, inside the `store` transaction closure, replace the block starting at `if ($request->boolean('create_service_order'))` through the eventable attach so it skips order/eventable work when `no_service_order` is set:

```php
if ($request->boolean('create_service_order')) {
    $new_order = ServiceOrder::create(['customer_id' => $data['customer_id']]);
    $eventable_type = '\\App\\Models\\ServiceOrder';
    $eventable_id = $new_order->id;
}

$no_service_order = $request->boolean('no_service_order');

unset($data['create_service_order'], $data['customer_id']);
$data['eventable_type'] = $no_service_order ? null : $eventable_type;
$data['eventable_id'] = $no_service_order ? null : $eventable_id;

$event = Event::create($data);

$model = null;
if (! $no_service_order) {
    $model = $eventable_type::findOrFail($eventable_id);
    $model->events()->attach($event->id);
    if ($model instanceof ServiceOrder) {
        $model->advanceToPlannedStage();
    }
}

$executing_user_ids = $request['executing_user_ids'] ?? [];
if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
    $ids = array_map('intval', $executing_user_ids);
    $raw_breaktimes = (array) ($request->input('executing_user_breaktimes', []));
    $breaktimes = array_map('intval', $raw_breaktimes);
    $user_roles = (array) ($request->input('executing_user_roles', []));
    $diverging_times = (array) ($request->input('executing_user_diverging_times', []));
    $event->syncExecutingUsers($ids, $breaktimes, $user_roles, $diverging_times);
    if ($model) {
        $model->syncExecutingUsers($ids);
        $model->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($ids));
        if ($model instanceof ServiceOrder) {
            $notify_service_order = $model;
            $notify_user_ids = $ids;
        }
    }
}

return $event;
```

Also add `'no_service_order'` to the unset list at the top of `store` is NOT needed (it is a real column). Leave `$data['no_service_order']` intact so it persists.

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --filter=EventNoServiceOrderTest`
Expected: PASS (both tests).

- [ ] **Step 9: Run Pint**

Run: `./vendor/bin/pint app/Http/Controllers/EventApiController.php app/Http/Requests/EventStoreRequest.php app/Http/Requests/EventUpdateRequest.php app/Models/Event.php database/factories/EventFactory.php`
Expected: no style errors remain.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_07_01_000003_add_no_service_order_to_events_table.php database/factories/EventFactory.php app/Models/Event.php app/Http/Requests/EventStoreRequest.php app/Http/Requests/EventUpdateRequest.php app/Http/Controllers/EventApiController.php tests/Feature/EventNoServiceOrderTest.php
git commit -m "feat(Events) allow events without a service order"
```

---

### Task 2: `events.provide_feedback` permission, policy, and Event feedback relations

**Files:**
- Create: `database/migrations/2026_07_01_000004_seed_events_provide_feedback_permission.php`
- Modify: `app/Models/Event.php` (add `RemarkableTrait` and `images()`)
- Modify: `app/Policies/EventPolicy.php` (add `provideFeedback`)
- Test: `tests/Feature/EventFeedbackPermissionTest.php`

**Interfaces:**
- Consumes: `EventFactory` (Task 1).
- Produces: `Event::remarks()`, `Event::internalRemarks()`, `Event::images()` relations; `EventPolicy::provideFeedback(User, Event)`; permission row `events.provide_feedback`.

- [ ] **Step 1: Write the permission migration**

Create `database/migrations/2026_07_01_000004_seed_events_provide_feedback_permission.php`:

```php
<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'events.provide_feedback', 'label' => 'Mag terugkoppeling geven op afspraken'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (! Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
```

- [ ] **Step 2: Add relations to Event model**

In `app/Models/Event.php`:
- Add `use App\Models\Traits\RemarkableTrait;` to the imports.
- Add `use RemarkableTrait;` in the class body alongside the other traits.
- Add the `images()` relation (mirrors `ServiceOrder::images()`):

```php
public function images()
{
    return $this->morphToMany(Image::class, 'imageable')
        ->withPivot(['main', 'internal'])
        ->wherePivot('internal', false)
        ->withTimestamps();
}
```

Add `use App\Models\Image;` to the imports.

- [ ] **Step 3: Add the policy method**

In `app/Policies/EventPolicy.php`, add:

```php
public function provideFeedback(User $user, Event $event): bool
{
    return $user->isAdmin() || $user->hasPermission('events.provide_feedback');
}
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/EventFeedbackPermissionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventFeedbackPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_can_provide_feedback(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'planner']);
        $permission = Permission::create(['name' => 'events.provide_feedback', 'label' => 'x']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $event = Event::factory()->create();

        $this->assertTrue($user->can('provideFeedback', $event));
    }

    public function test_user_without_permission_cannot_provide_feedback(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->assertFalse($user->can('provideFeedback', $event));
    }

    public function test_event_has_remarks_and_images_relations(): void
    {
        $event = Event::factory()->create();

        $this->assertCount(0, $event->remarks);
        $this->assertCount(0, $event->images);
    }
}
```

- [ ] **Step 5: Run the test to verify it fails, then passes**

Run: `php artisan test --filter=EventFeedbackPermissionTest`
Expected before implementation of Steps 2-3: FAIL (relation/method missing). After Steps 1-3 are in place: PASS.

- [ ] **Step 6: Run Pint**

Run: `./vendor/bin/pint app/Models/Event.php app/Policies/EventPolicy.php`
Expected: no style errors.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_01_000004_seed_events_provide_feedback_permission.php app/Models/Event.php app/Policies/EventPolicy.php tests/Feature/EventFeedbackPermissionTest.php
git commit -m "feat(Events) add provide_feedback permission and remark/image relations"
```

---

### Task 3: JSON API for remarks, images, and event feedback

**Files:**
- Modify: `app/Http/Controllers/RemarkController.php`
- Modify: `app/Http/Controllers/ImageController.php`
- Modify: `app/Http/Controllers/EventApiController.php` (add `feedback`, add `withCount` in `index`)
- Modify: `app/Http/Requests/RemarkCreateRequest.php` (authorize via policy for JSON path — see step)
- Create: `app/Http/Requests/EventFeedbackRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/EventFeedbackApiTest.php`

**Interfaces:**
- Consumes: `Event::remarks()`, `Event::images()`, `EventPolicy::provideFeedback` (Task 2); `EventFactory` (Task 1).
- Produces: JSON endpoints `POST /api/remarks`, `DELETE /api/remarks/{remark}`, `POST /api/images`, `DELETE /api/images/{image}`, `POST /api/images/{image}/set-main`, `POST /api/images/update/{image}`, `GET /api/events/{event}/feedback`. Feedback response shape: `{ "remarks": [...], "images": [...] }`. Index events gain `remarks_count` and `images_count`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EventFeedbackApiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventFeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    private function feedback_user(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'planner']);
        foreach (['events.provide_feedback', 'image.upload', 'image.see', 'image.delete'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_remark_can_be_posted_to_event_via_json(): void
    {
        $user = $this->feedback_user();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/remarks', [
            'content' => 'Ziet er goed uit',
            'remarkable_type' => 'App\\Models\\Event',
            'remarkable_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('content', 'Ziet er goed uit');
        $this->assertCount(1, $event->fresh()->remarks);
    }

    public function test_feedback_endpoint_returns_remarks_and_images(): void
    {
        $user = $this->feedback_user();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/events/{$event->id}/feedback");

        $response->assertOk();
        $response->assertJsonStructure(['remarks', 'images']);
    }

    public function test_feedback_endpoint_forbidden_without_permission(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->getJson("/api/events/{$event->id}/feedback")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EventFeedbackApiTest`
Expected: FAIL — routes/controller methods return redirects (not JSON) or 404.

- [ ] **Step 3: Content-negotiate in RemarkController**

In `app/Http/Controllers/RemarkController.php`, update `store` to return JSON when requested:

```php
public function store(RemarkCreateRequest $request)
{
    if ($request->has('remarkable_type') && $request->has('remarkable_id')) {
        $remarkable = $request->remarkable_type::find($request->remarkable_id);
        $remark = Remark::create([
            'content' => $request->content,
            'user_id' => Auth::user()->id,
        ]);
        $remarkable->remarks()->attach($remark->id, [
            'internal' => $request->boolean('internal', false),
        ]);

        if ($request->wantsJson()) {
            return response()->json($remark->load('user'), 201);
        }

        return redirect()->back()->with([
            'success' => 'Opmerking is toegevoegd.',
        ]);
    }
}
```

And `destroy`:

```php
public function destroy(Remark $remark)
{
    $remark->delete();

    if (request()->wantsJson()) {
        return response()->json(['deleted' => true]);
    }

    return redirect()->back()->with([
        'success' => 'Opmerking is verwijderd.',
    ]);
}
```

- [ ] **Step 4: Content-negotiate in ImageController**

In `app/Http/Controllers/ImageController.php`, at the end of `store`, replace the final `return redirect()->back()->with([...])` with:

```php
if ($request->wantsJson()) {
    return response()->json($created_images, 201);
}

return redirect()->back()->with([
    'success' => 'Afbeelding(en) opgeslagen.',
    'extra' => json_encode($created_images, true),
]);
```

In `update`, replace the final return with:

```php
if ($request->wantsJson()) {
    return response()->json($image);
}

return redirect()->back()->with([
    'success' => 'Afbeelding bijgewerkt.',
    'extra' => json_encode($image, true),
]);
```

In `destroy`, before the existing `return redirect()->back()->with('success', 'Afbeelding verwijderd.');`, add:

```php
if ($request->wantsJson()) {
    return response()->json(['deleted' => true]);
}
```

In `setMain`, before the existing final redirect, add:

```php
if ($request->wantsJson()) {
    return response()->json(['success' => true]);
}
```

- [ ] **Step 5: Create the feedback Form Request**

Create `app/Http/Requests/EventFeedbackRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class EventFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()->can('provideFeedback', $event);
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 6: Add the feedback endpoint and index counts**

In `app/Http/Controllers/EventApiController.php`, add the import `use App\Http\Requests\EventFeedbackRequest;` and add the method:

```php
public function feedback(EventFeedbackRequest $request, Event $event)
{
    $event->load(['remarks.user', 'images']);

    return response()->json([
        'remarks' => $event->remarks,
        'images' => $event->images,
    ]);
}
```

In the `index` method's `->with([...])` eager-load chain, append `withCount` by chaining before `->orderBy('start')`:

```php
->withCount(['remarks', 'images'])
```

- [ ] **Step 7: Register the routes**

In `routes/api.php`, inside the `auth:sanctum` group, add after the existing events routes:

```php
Route::get('events/{event}/feedback', [EventApiController::class, 'feedback']);

Route::post('remarks', [\App\Http\Controllers\RemarkController::class, 'store']);
Route::delete('remarks/{remark}', [\App\Http\Controllers\RemarkController::class, 'destroy']);

Route::post('images', [\App\Http\Controllers\ImageController::class, 'store']);
Route::delete('images/{image}', [\App\Http\Controllers\ImageController::class, 'destroy']);
Route::post('images/update/{image}', [\App\Http\Controllers\ImageController::class, 'update']);
Route::post('images/{image}/set-main', [\App\Http\Controllers\ImageController::class, 'setMain']);
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --filter=EventFeedbackApiTest`
Expected: PASS.

- [ ] **Step 9: Run the full backend suite and Pint**

Run: `composer test`
Expected: PASS (no regressions in existing tests).
Run: `./vendor/bin/pint app/Http/Controllers/RemarkController.php app/Http/Controllers/ImageController.php app/Http/Controllers/EventApiController.php app/Http/Requests/EventFeedbackRequest.php`
Expected: no style errors.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/RemarkController.php app/Http/Controllers/ImageController.php app/Http/Controllers/EventApiController.php app/Http/Requests/EventFeedbackRequest.php routes/api.php tests/Feature/EventFeedbackApiTest.php
git commit -m "feat(Events) JSON API for event remarks, images and feedback"
```

---

### Task 4: Vitest setup + dual-mode RemarksComponent

**Files:**
- Modify: `package.json` (devDependencies + `test`/`build` scripts)
- Create: `vitest.config.js`
- Modify: `resources/js/Components/RemarksComponent.vue`
- Create: `resources/js/Components/__tests__/RemarksComponent.spec.js`

**Interfaces:**
- Consumes: JSON endpoints `POST /api/remarks`, `DELETE /api/remarks/{id}` (Task 3).
- Produces: `RemarksComponent` prop `apiMode: Boolean` (default `false`) and emits `created` (payload: created remark object) and `deleted` (payload: id). No behavior change when `apiMode` is falsey.

- [ ] **Step 1: Add Vitest tooling and scripts**

Run: `npm install -D vitest @vue/test-utils jsdom`

In `package.json`, update the `scripts` block:

```json
"build": "vitest run && vite build",
"dev": "vite",
"test": "vitest run",
"test:watch": "vitest",
```

(Keep the existing `fix:eslint`, `cap:*` scripts.)

- [ ] **Step 2: Create the Vitest config**

Create `vitest.config.js`:

```js
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
})
```

- [ ] **Step 3: Write the failing test**

Create `resources/js/Components/__tests__/RemarksComponent.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const postMock = vi.fn()
const deleteMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1, name: 'Tester' } } } }),
    useForm: (data) => ({
        ...data,
        post: postMock,
        delete: deleteMock,
        reset: vi.fn(),
    }),
}))

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve()),
        post: vi.fn(() => Promise.resolve({ data: { id: 99, content: 'hi', user: { id: 1, name: 'Tester' } } })),
        delete: vi.fn(() => Promise.resolve({ data: { deleted: true } })),
    },
}))

import axios from 'axios'
import RemarksComponent from '@/Components/RemarksComponent.vue'

const baseProps = {
    comments: [],
    remarkableType: 'App\\Models\\Event',
    remarkableId: 5,
}

describe('RemarksComponent', () => {
    beforeEach(() => {
        postMock.mockClear()
        deleteMock.mockClear()
        axios.post.mockClear()
    })

    it('uses Inertia form.post when apiMode is not set', async () => {
        const wrapper = mount(RemarksComponent, { props: baseProps })
        await wrapper.find('textarea').setValue('Een opmerking')
        await wrapper.find('button').trigger('click')

        expect(postMock).toHaveBeenCalledWith('/remarks', expect.anything())
        expect(axios.post).not.toHaveBeenCalled()
        expect(wrapper.emitted('created')).toBeFalsy()
    })

    it('uses axios and emits created when apiMode is true', async () => {
        const wrapper = mount(RemarksComponent, { props: { ...baseProps, apiMode: true } })
        await wrapper.find('textarea').setValue('Een opmerking')
        await wrapper.find('button').trigger('click')
        await Promise.resolve()
        await Promise.resolve()

        expect(axios.post).toHaveBeenCalledWith('/api/remarks', expect.objectContaining({
            content: 'Een opmerking',
            remarkable_type: 'App\\Models\\Event',
            remarkable_id: 5,
        }))
        expect(postMock).not.toHaveBeenCalled()
        expect(wrapper.emitted('created')).toBeTruthy()
    })
})
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `npm run test`
Expected: FAIL — second test fails because `apiMode` branch does not exist yet (axios not called, `created` not emitted).

- [ ] **Step 5: Implement dual mode in RemarksComponent**

In `resources/js/Components/RemarksComponent.vue`, add axios import at the top of `<script setup>`:

```js
import axios from 'axios'
```

Add `apiMode` to the destructured props (default false) and declare emits:

```js
const { comments, remarkableType, remarkableId, disabled, internal, apiMode } = defineProps({
    comments: Array,
    remarkableType: String,
    remarkableId: Number,
    disabled: {
        type: Boolean,
        default: false
    },
    internal: {
        type: Boolean,
        default: false
    },
    apiMode: {
        type: Boolean,
        default: false
    }
})

const emit = defineEmits(['created', 'deleted'])
```

Replace `addComment` and `deleteComment`:

```js
const addComment = async () => {
    if (apiMode) {
        await axios.get('sanctum/csrf-cookie')
        const { data } = await axios.post('/api/remarks', {
            content: form.content,
            user_id: page.props.auth.user.id,
            remarkable_type: remarkableType,
            remarkable_id: remarkableId,
            internal: internal,
        })
        emit('created', data)
        form.reset('content')
        return
    }

    form.post('/remarks', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('content');
        },
    })
}

const deleteComment = async (id) => {
    if (apiMode) {
        await axios.delete(`/api/remarks/${id}`)
        emit('deleted', id)
        return
    }

    form.delete(`/remarks/${id}`, {
        preserveScroll: true
    })
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `npm run test`
Expected: PASS (both tests).

- [ ] **Step 7: Run eslint**

Run: `npm run fix:eslint`
Expected: no unfixable errors in `RemarksComponent.vue`.

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json vitest.config.js resources/js/Components/RemarksComponent.vue resources/js/Components/__tests__/RemarksComponent.spec.js
git commit -m "feat(Remarks) add apiMode transport and Vitest coverage"
```

---

### Task 5: Dual-mode ImageUploadComponent

**Files:**
- Modify: `resources/js/Components/ImageUploadComponent.vue`
- Create: `resources/js/Components/__tests__/ImageUploadComponent.spec.js`

**Interfaces:**
- Consumes: JSON endpoints `POST /api/images`, `DELETE /api/images/{id}`, `POST /api/images/{id}/set-main`, `POST /api/images/update/{id}` (Task 3).
- Produces: `ImageUploadComponent` prop `apiMode: Boolean` (default `false`); continues to emit `imagesUploaded`, `imageDeleted`, `imageUpdated`. No behavior change when `apiMode` is falsey.

- [ ] **Step 1: Write the failing test**

Create `resources/js/Components/__tests__/ImageUploadComponent.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const formPost = vi.fn()
const formDelete = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1, name: 'Tester' } }, flash: { extra: '[]' } } }),
    router: { post: vi.fn() },
    useForm: (data) => ({
        ...data,
        post: formPost,
        delete: formDelete,
        reset: vi.fn(),
    }),
}))

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve()),
        post: vi.fn(() => Promise.resolve({ data: [{ id: 7, name: 'x', path: 'p' }] })),
        delete: vi.fn(() => Promise.resolve({ data: { deleted: true } })),
    },
}))

vi.mock('@/Utilities/Utilities.js', () => ({ hasPermission: () => true }))
vi.mock('glightbox', () => ({ default: () => ({ reload: vi.fn() }) }))
vi.mock('tui-image-editor', () => ({ default: class {} }))

import axios from 'axios'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'

const baseProps = {
    imageableId: 5,
    imageableType: 'App\\Models\\Event',
    existing: [{ id: 1, name: 'foto', path: 'uploaded/x.jpg', pivot: { main: false } }],
}

describe('ImageUploadComponent', () => {
    beforeEach(() => {
        formPost.mockClear()
        formDelete.mockClear()
        axios.post.mockClear()
        axios.delete.mockClear()
    })

    it('deletes via Inertia form.delete when apiMode is not set', async () => {
        const wrapper = mount(ImageUploadComponent, { props: baseProps })
        await wrapper.vm.deleteImage(1)

        expect(formDelete).toHaveBeenCalledWith('/images/1', expect.anything())
        expect(axios.delete).not.toHaveBeenCalled()
    })

    it('deletes via axios and emits imageDeleted when apiMode is true', async () => {
        const wrapper = mount(ImageUploadComponent, { props: { ...baseProps, apiMode: true } })
        await wrapper.vm.deleteImage(1)
        await Promise.resolve()

        expect(axios.delete).toHaveBeenCalledWith('/api/images/1', expect.anything())
        expect(formDelete).not.toHaveBeenCalled()
        expect(wrapper.emitted('imageDeleted')).toBeTruthy()
    })
})
```

Note: `deleteImage`, `uploadPhotos`, and `setMain` must be exposed for testing — add `defineExpose({ deleteImage, uploadPhotos, setMain })` at the end of `<script setup>` in Step 3.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test`
Expected: FAIL — `apiMode` delete branch does not exist; `wrapper.vm.deleteImage` may be undefined until exposed.

- [ ] **Step 3: Implement dual mode in ImageUploadComponent**

In `resources/js/Components/ImageUploadComponent.vue`:

Add axios import:

```js
import axios from 'axios'
```

Add `apiMode` to `defineProps`:

```js
const props = defineProps({
    imageableId: Number,
    imageableType: String,
    existing: Array,
    internal: {
        type: Boolean,
        default: false
    },
    apiMode: {
        type: Boolean,
        default: false
    },
});
```

Replace `uploadPhotos`, `deleteImage`, and `setMain` with dual-mode versions:

```js
const uploadPhotos = async () => {
    uploading.value = true;
    for (let i = 0; i < uploadImagesForm.images.length; i++) {
        const fileTitle = uploadImagesForm.titles[uploadImagesForm.images[i].name];
        if (fileTitle === undefined || fileTitle === '') {
            alert('Iedere afbeelding moet een titel hebben');
            uploading.value = false;
            return;
        }
    }

    if (props.apiMode) {
        await axios.get('sanctum/csrf-cookie');
        const data = new FormData();
        uploadImagesForm.images.forEach((file) => {
            data.append('images[]', file);
            data.append(`titles[${file.name}]`, uploadImagesForm.titles[file.name]);
        });
        data.append('imageable_id', props.imageableId);
        data.append('imageable_type', props.imageableType);
        data.append('internal', props.internal ? '1' : '0');
        const response = await axios.post('/api/images', data);
        emit('imagesUploaded', response.data);
        uploadImagesForm.reset();
        selectedFiles.value = [];
        uploading.value = false;
        return;
    }

    uploadImagesForm.post('/images',
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('imagesUploaded', JSON.parse(page.props.flash.extra));
                uploadImagesForm.reset();
                selectedFiles.value = [];
                uploading.value = false;
            }
        }
    );
};

const deleteImage = async (id) => {
    if (props.apiMode) {
        await axios.delete(`/api/images/${id}`, {
            data: {
                imageable_id: props.imageableId,
                imageable_type: props.imageableType,
            },
        });
        emit('imageDeleted', id);
        return;
    }

    uploadImagesForm.delete(`/images/${id}`, {
        preserveScroll: true,
    });
    emit('imageDeleted', id);
}

const setMain = async (id, isCurrentlyMain) => {
    if (props.apiMode) {
        await axios.post(`/api/images/${id}/set-main`, {
            imageable_id: props.imageableId,
            imageable_type: props.imageableType,
            currently_main: isCurrentlyMain,
        });
        emit('imagesUploaded', props.existing);
        return;
    }

    router.post(`/images/${id}/set-main`, {
        imageable_id: props.imageableId,
        imageable_type: props.imageableType,
        currently_main: isCurrentlyMain,
    }, { preserveScroll: true });
}
```

At the end of `<script setup>` add:

```js
defineExpose({ deleteImage, uploadPhotos, setMain })
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test`
Expected: PASS.

- [ ] **Step 5: Run eslint**

Run: `npm run fix:eslint`
Expected: no unfixable errors in `ImageUploadComponent.vue`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/ImageUploadComponent.vue resources/js/Components/__tests__/ImageUploadComponent.spec.js
git commit -m "feat(Images) add apiMode transport and Vitest coverage"
```

---

### Task 6: `useEventFeedback` composable

**Files:**
- Create: `resources/js/Composables/useEventFeedback.js`
- Create: `resources/js/Composables/__tests__/useEventFeedback.spec.js`

**Interfaces:**
- Consumes: `GET /api/events/{id}/feedback` (Task 3).
- Produces: `useEventFeedback()` returning `{ open, activeEvent, remarks, images, changed, openFeedback, onRemarkCreated, onRemarkDeleted, onImagesUploaded, onImageDeleted }`.
  - `openFeedback(event)` sets `activeEvent`, fetches feedback into `remarks`/`images`, sets `open = true`.
  - `changed` is a ref that increments whenever feedback lists mutate, so hosts can watch it to refresh.

- [ ] **Step 1: Write the failing test**

Create `resources/js/Composables/__tests__/useEventFeedback.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve({ data: { remarks: [{ id: 1 }], images: [{ id: 2 }] } })),
    },
}))

import axios from 'axios'
import { useEventFeedback } from '@/Composables/useEventFeedback'

describe('useEventFeedback', () => {
    beforeEach(() => axios.get.mockClear())

    it('loads feedback and opens on openFeedback', async () => {
        const fb = useEventFeedback()
        expect(fb.open.value).toBe(false)

        await fb.openFeedback({ id: 42, name: 'Bezoek' })

        expect(axios.get).toHaveBeenCalledWith('/api/events/42/feedback')
        expect(fb.open.value).toBe(true)
        expect(fb.activeEvent.value.id).toBe(42)
        expect(fb.remarks.value).toHaveLength(1)
        expect(fb.images.value).toHaveLength(1)
    })

    it('mutating handlers update lists and bump changed', async () => {
        const fb = useEventFeedback()
        await fb.openFeedback({ id: 42 })
        const before = fb.changed.value

        fb.onRemarkCreated({ id: 9 })
        expect(fb.remarks.value.some(r => r.id === 9)).toBe(true)

        fb.onRemarkDeleted(9)
        expect(fb.remarks.value.some(r => r.id === 9)).toBe(false)

        fb.onImageDeleted(2)
        expect(fb.images.value.some(i => i.id === 2)).toBe(false)

        expect(fb.changed.value).toBeGreaterThan(before)
    })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test`
Expected: FAIL — module `@/Composables/useEventFeedback` does not exist.

- [ ] **Step 3: Implement the composable**

Create `resources/js/Composables/useEventFeedback.js`:

```js
import { ref } from 'vue'
import axios from 'axios'

export function useEventFeedback() {
    const open = ref(false)
    const activeEvent = ref(null)
    const remarks = ref([])
    const images = ref([])
    const changed = ref(0)

    const openFeedback = async (event) => {
        activeEvent.value = event
        remarks.value = []
        images.value = []
        const { data } = await axios.get(`/api/events/${event.id}/feedback`)
        remarks.value = data.remarks ?? []
        images.value = data.images ?? []
        open.value = true
    }

    const onRemarkCreated = (remark) => {
        remarks.value = [remark, ...remarks.value]
        changed.value++
    }

    const onRemarkDeleted = (id) => {
        remarks.value = remarks.value.filter((remark) => remark.id !== id)
        changed.value++
    }

    const onImagesUploaded = (uploaded) => {
        const list = Array.isArray(uploaded) ? uploaded : [uploaded]
        images.value = [...images.value, ...list]
        changed.value++
    }

    const onImageDeleted = (id) => {
        images.value = images.value.filter((image) => image.id !== id)
        changed.value++
    }

    return {
        open,
        activeEvent,
        remarks,
        images,
        changed,
        openFeedback,
        onRemarkCreated,
        onRemarkDeleted,
        onImagesUploaded,
        onImageDeleted,
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Composables/useEventFeedback.js resources/js/Composables/__tests__/useEventFeedback.spec.js
git commit -m "feat(Events) add useEventFeedback composable"
```

---

### Task 7: `EventEditModal` — "Geen werkbon" checkbox

**Files:**
- Modify: `resources/js/Components/Planner/EventEditModal.vue`

**Interfaces:**
- Consumes: `no_service_order` accepted by `POST /api/events` and `PUT /api/events/{id}` (Task 1).
- Produces: modal writes `no_service_order` into the event payload; mutually exclusive with `create_service_order`.

- [ ] **Step 1: Add the form field**

In `resources/js/Components/Planner/EventEditModal.vue`, add to the `useForm({...})` object (after `is_preliminary`):

```js
no_service_order: props.initial.no_service_order || false,
```

- [ ] **Step 2: Add the checkbox and mutual exclusion**

In the "Werkbon" block, change the werkbon ComboBox `v-if` so it hides when either flag is set:

```html
<ComboBox v-if="!form.create_service_order && !form.no_service_order" v-model="form.eventable_id"
    :options="internalServiceOrders" class="w-full" :initial-id="form.eventable_id"
    placeholder="Zoek werkbon..." :hasError="Boolean(form.errors.eventable_id)"
    :errorMessage="form.errors.eventable_id" />
<p v-else-if="form.create_service_order" class="text-sm italic text-gray-500 dark:text-gray-400 py-2">
    Er wordt een nieuwe werkbon aangemaakt voor de geselecteerde klant.
</p>
<p v-else class="text-sm italic text-gray-500 dark:text-gray-400 py-2">
    Deze afspraak heeft geen werkbon nodig.
</p>
```

Below the existing "Maak een nieuwe werkbon aan" label, add the second checkbox:

```html
<label v-if="!editingExisting" class="flex items-center gap-2 mt-2 select-none cursor-pointer">
    <input type="checkbox" v-model="form.no_service_order"
        class="rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer" />
    <span class="text-sm text-gray-600 dark:text-gray-400">Geen werkbon nodig</span>
</label>
```

- [ ] **Step 3: Enforce mutual exclusion in script**

In `<script setup>`, add watchers so the two checkboxes never both hold (toggle semantics — checking one clears the other):

```js
watch(() => form.no_service_order, (val) => {
    if (val) {
        form.create_service_order = false
        form.eventable_id = ''
    }
})

watch(() => form.create_service_order, (val) => {
    if (val) {
        form.no_service_order = false
    }
})
```

- [ ] **Step 4: Relax the client-side save guard**

In `save()`, change the eventable guard so it does not fire when `no_service_order` is set:

```js
if (!props.editingExisting && !form.create_service_order && !form.no_service_order && !form.eventable_id) {
    form.setError('eventable_id', 'Koppel een werkbon aan de afspraak of maak een nieuwe aan.')
    return
}
```

- [ ] **Step 5: Verify the build compiles and lint passes**

Run: `npm run fix:eslint`
Expected: no unfixable errors in `EventEditModal.vue`.
Run: `npm run test`
Expected: PASS (no component tests broken).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Planner/EventEditModal.vue
git commit -m "feat(Events) add 'geen werkbon' option to the event modal"
```

---

### Task 8: Feedback button + modal on the three surfaces

**Files:**
- Modify: `resources/js/Components/Planner/PlannerEvent.vue` (emit `open-feedback`)
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue` (host modal for desktop)
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue` (button + host modal)
- Modify: `resources/js/Components/Timeline/EventTimelineComponent.vue` (button + host modal)

**Interfaces:**
- Consumes: `useEventFeedback` (Task 6); `RemarksComponent` / `ImageUploadComponent` with `apiMode` (Tasks 4–5); `ModalDialog` (`resources/js/Components/UI/ModalDialog.vue`, props `open`, `title`, `maxWidthClass`; `v-model:open`).
- Produces: a reusable inline modal snippet (repeated per host) that renders remarks + images for the active event and refreshes planner events on `changed`.

**Shared modal snippet** (used in ResourcePlannerWidget, MobilePlannerView, EventTimelineComponent — paste verbatim in each host's template where noted):

```html
<ModalDialog v-model:open="feedback.open.value" :title="feedbackTitle" max-width-class="sm:max-w-2xl">
    <div v-if="feedback.activeEvent.value" class="space-y-6">
        <RemarksComponent :comments="feedback.remarks.value" :remarkable-type="'App\\Models\\Event'"
            :remarkable-id="feedback.activeEvent.value.id" :api-mode="true"
            @created="feedback.onRemarkCreated" @deleted="feedback.onRemarkDeleted" />
        <ImageUploadComponent :existing="feedback.images.value" :imageable-type="'App\\Models\\Event'"
            :imageable-id="feedback.activeEvent.value.id" :api-mode="true"
            @images-uploaded="feedback.onImagesUploaded" @image-deleted="feedback.onImageDeleted" />
    </div>
</ModalDialog>
```

- [ ] **Step 1: Emit `open-feedback` from the desktop PlannerEvent button**

In `resources/js/Components/Planner/PlannerEvent.vue`:
- Add `MessageCircleReply` to the `@lucide/vue` import.
- Add `'open-feedback'` to `defineEmits`.
- Add `import { hasPermission } from '@/Utilities/Utilities'` (note: this file currently imports only `nlTime` from Utilities — extend that import).
- In the top-right control cluster (the `div` containing `EventExecutionControls`, around lines 79-104), add before `EventExecutionControls`:

```html
<button v-if="hasPermission('events.provide_feedback')" class="pointer-events-auto"
    @click.stop="$emit('open-feedback', event)" v-tooltip="'Terugkoppeling'">
    <MessageCircleReply class="size-3 text-gray-500 hover:text-lavoro-blue" />
</button>
```

- [ ] **Step 2: Host the modal in ResourcePlannerWidget (desktop)**

In `resources/js/Components/Planner/ResourcePlannerWidget.vue`:
- Import the pieces:

```js
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'
import { useEventFeedback } from '@/Composables/useEventFeedback'
import { computed, watch } from 'vue'
```

(Merge the `computed`/`watch` imports with the file's existing `vue` import rather than duplicating.)
- Set up state near the other composable usage (around line 560):

```js
const feedback = useEventFeedback()
const feedbackTitle = computed(() => feedback.activeEvent.value
    ? ('Terugkoppeling — ' + (feedback.activeEvent.value.name || ('#' + feedback.activeEvent.value.id)))
    : 'Terugkoppeling')
watch(feedback.changed, () => fetchEvents())
```

- On the `<PlannerEvent ... />` usage (around line 338), add `@open-feedback="feedback.openFeedback"`.
- Paste the shared modal snippet just before the closing root `</div>` (or `</template>`) of the component.

- [ ] **Step 3: Add button + host modal in MobilePlannerView**

In `resources/js/Components/Planner/MobilePlannerView.vue`:
- Add `MessageCircleReply` to the `@lucide/vue` import; add the shared imports (`ModalDialog`, `RemarksComponent`, `ImageUploadComponent`, `useEventFeedback`, `computed`, `watch`) as in Step 2.
- Add feedback state (same three lines: `const feedback = useEventFeedback()`, `feedbackTitle` computed, `watch(feedback.changed, () => fetchEvents())`).
- In the card header row (around lines 126-152, next to `EventExecutionControls`), add:

```html
<button v-if="hasPermission('events.provide_feedback')" @click.stop="feedback.openFeedback(ev)"
    class="p-1 text-gray-400 hover:text-lavoro-blue" title="Terugkoppeling">
    <MessageCircleReply class="size-4" />
</button>
```

(`hasPermission` is already imported in this file; if not, add it from `@/Utilities/Utilities`.)
- Paste the shared modal snippet before the component's closing root element.

- [ ] **Step 4: Add button + host modal in EventTimelineComponent**

In `resources/js/Components/Timeline/EventTimelineComponent.vue`:
- Add imports:

```js
import { MessageCircleReply } from '@lucide/vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'
import { useEventFeedback } from '@/Composables/useEventFeedback'
import { hasPermission } from '@/Utilities/Utilities'
import { computed } from 'vue'
```

(Merge `computed` into the existing `vue` import.)
- Add feedback state (no `fetchEvents` here — the timeline has no polling; the in-modal lists update themselves):

```js
const feedback = useEventFeedback()
const feedbackTitle = computed(() => feedback.activeEvent.value
    ? ('Terugkoppeling — ' + (feedback.activeEvent.value.name || ('#' + feedback.activeEvent.value.id)))
    : 'Terugkoppeling')
```

- Each timeline row's `internalEvents` map object already carries `id`, `name`, `start`, etc. In the row template (the `div` at lines 43-45 holding the `<time>`), add below the time:

```html
<button v-if="hasPermission('events.provide_feedback')" @click="feedback.openFeedback(ev)"
    class="mt-1 text-gray-400 hover:text-lavoro-blue" title="Terugkoppeling">
    <MessageCircleReply class="size-4 inline" />
</button>
```

- Paste the shared modal snippet before the closing `</template>` root `</div>`.

- [ ] **Step 5: Verify lint and tests**

Run: `npm run fix:eslint`
Expected: no unfixable errors in the four modified files.
Run: `npm run test`
Expected: PASS.

- [ ] **Step 6: Build to confirm everything compiles (and the gated test runs)**

Run: `npm run build`
Expected: Vitest passes, then Vite build completes without errors.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Planner/PlannerEvent.vue resources/js/Components/Planner/ResourcePlannerWidget.vue resources/js/Components/Planner/MobilePlannerView.vue resources/js/Components/Timeline/EventTimelineComponent.vue
git commit -m "feat(Events) add feedback button and modal to planner and timeline"
```

---

## Self-Review

**Spec coverage:**
- Section 1 (no_service_order flag): Task 1 (column, model, validation, store) + Task 7 (modal checkbox). ✓
- Section 2 (remarks/images model + API): Task 2 (relations, permission, policy) + Task 3 (JSON endpoints, feedback endpoint, index counts). ✓
- Section 3 (dual-mode components): Task 4 (RemarksComponent) + Task 5 (ImageUploadComponent). ✓
- Section 4 (feedback modal via ModalDialog + composable + buttons on 3 surfaces): Task 6 (composable) + Task 8 (buttons + hosts). ✓
- Section 5 (Vitest, dual-mode regression test, gated into build): Task 4 (setup + `build` gating) + Tasks 5–6 (further specs). ✓
- `remarks_count` / `images_count` on index: Task 3 Step 6. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"; all code steps carry concrete code. ✓

**Type consistency:** `useEventFeedback` returns `open`, `activeEvent`, `remarks`, `images`, `changed`, `openFeedback`, `onRemarkCreated`, `onRemarkDeleted`, `onImagesUploaded`, `onImageDeleted` — the exact names used in Task 8's modal snippet and host wiring. `apiMode` prop and `created`/`deleted`/`imagesUploaded`/`imageDeleted` emit names are consistent across Tasks 4, 5, 6, 8. Feedback JSON shape `{ remarks, images }` consistent between Task 3 producer and Task 6 consumer. ✓

**Note for implementer:** `.value` appears in the template snippet (e.g. `feedback.open.value`) because `feedback` is a plain object of refs returned from the composable, not auto-unwrapped setup state. This is intentional and correct for refs accessed through an object in the template.
