# Per-User Event Execution Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an executing user run their own event through a planned → ongoing → completed (or cancelled) lifecycle from the desktop and mobile resource planner, capturing actual start/end times and a mandatory signature, and let them edit those times (date-locked) and signature afterwards.

**Architecture:** A dedicated `event_user_executions` table (one row per event + user, created lazily) holds the per-user lifecycle data, keeping the shared `userables` pivot clean. A single `EventExecutionController` exposes a fetch, a transition (start/stop/cancel), and a date-locked edit endpoint, all scoped to the authenticated user's own row and authorised via a new `EventPolicy::executeOwn` method. The planner JSON payload is extended with each user's execution summary (signature excluded from the list for poll efficiency; fetched on demand). The frontend adds one `EventExecutionControls.vue` (reused by both desktop `PlannerEvent.vue` and mobile card) plus one slot-based `ExecutionModal.vue` built on the existing `ModalDialog` + `SignaturePad`.

**Tech Stack:** Laravel 12, Inertia, Vue 3, axios, dayjs (`@/Utilities/dayjs`), `@lucide/vue`, `@selemondev/vue3-signature-pad` (via existing `SignaturePad.vue`).

## Global Constraints

- PHP variables: `snake_case`.
- No inline comments; docblocks only when needed.
- Do NOT write automated tests (project rule: tests only when explicitly asked).
- Do NOT include git commit or other git workflow steps; do not commit without explicit user approval.
- Authorisation in Form Request `authorize()` only via policy `can()` calls; `hasPermission(...)` checks live inside policies, never in Form Requests.
- Validation belongs in Form Request `rules()` only; the frontend only displays `form.errors`.
- App timezone is UTC; convert local wall-clock → UTC with the existing `localToUtcDatetime(dateStr, timeStr)` helper. Never assemble UTC datetimes by hand.
- String concatenation always uses spaces around the dot: `$a . ' ' . $b`.
- Reuse `ModalDialog.vue`, `SignaturePad.vue`, and the date helpers in `resources/js/Utilities/Utilities.js` — do not reinvent them.
- Icons come from `@lucide/vue`.

---

### Task 1: Migration + completion-status enum

**Files:**
- Create: `database/migrations/2026_06_22_000004_create_event_user_executions_table.php`
- Create: `app/Enums/EventCompletionStatus.php`

**Interfaces:**
- Produces: table `event_user_executions` with columns `id`, `event_id`, `user_id`, `completion_status` (string, default `'planned'`), `actual_start` (datetime, nullable), `actual_end` (datetime, nullable), `signature_base64` (longText, nullable), timestamps; `unique(event_id, user_id)`.
- Produces: enum `App\Enums\EventCompletionStatus` with cases `planned`, `ongoing`, `completed`, `cancelled` and a `comboBoxArray()` helper (via existing enum traits).

- [ ] **Step 1: Write the migration**

```php
<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_user_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('completion_status')->default('planned');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->longText('signature_base64')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_user_executions');
    }
};
```

- [ ] **Step 2: Write the enum**

Mirror the structure of `app/Enums/EventStatusses.php` (same two traits). The `value` strings are the Dutch labels shown in dropdowns.

```php
<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;
use App\Enums\Traits\EnumValueArrayTrait;

enum EventCompletionStatus: string
{
    use EnumValueArrayTrait;
    use EnumComboBoxArrayTrait;

    case planned   = 'Gepland';
    case ongoing   = 'Gaande';
    case completed = 'Afgerond';
    case cancelled = 'Geannuleerd';
}
```

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: `event_user_executions` table created, no errors.

- [ ] **Step 4: Verify style**

Run: `./vendor/bin/pint app/Enums/EventCompletionStatus.php database/migrations/2026_06_22_000004_create_event_user_executions_table.php`
Expected: PASS (no style violations remaining).

---

### Task 2: `EventUserExecution` model + `Event` relation + lazy accessor

**Files:**
- Create: `app/Models/EventUserExecution.php`
- Modify: `app/Models/Event.php`

**Interfaces:**
- Consumes: `event_user_executions` table (Task 1).
- Produces: `EventUserExecution` model with `$fillable = ['event_id', 'user_id', 'completion_status', 'actual_start', 'actual_end', 'signature_base64']` and casts `actual_start`/`actual_end` → `datetime`.
- Produces: `Event::executions(): HasMany`.
- Produces: `Event::executionFor(int $user_id): EventUserExecution` — returns the existing row or `firstOrCreate`s one with `completion_status = 'planned'`.

- [ ] **Step 1: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventUserExecution extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'completion_status',
        'actual_start',
        'actual_end',
        'signature_base64',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end'   => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 2: Add the relation and accessor to `Event`**

Add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports, then add these methods to `app/Models/Event.php` (after `serviceOrders()`):

```php
    public function executions(): HasMany
    {
        return $this->hasMany(EventUserExecution::class);
    }

    public function executionFor(int $user_id): EventUserExecution
    {
        return $this->executions()->firstOrCreate(
            ['user_id' => $user_id],
            ['completion_status' => 'planned'],
        );
    }
```

- [ ] **Step 3: Verify it loads**

Run: `php artisan tinker --execute="echo App\Models\Event::query()->with('executions')->first()?->id ?? 'no-events';"`
Expected: prints an event id (or `no-events`) without relation errors.

- [ ] **Step 4: Verify style**

Run: `./vendor/bin/pint app/Models/EventUserExecution.php app/Models/Event.php`
Expected: PASS.

---

### Task 3: Policy authorisation for own-execution actions

**Files:**
- Modify: `app/Policies/EventPolicy.php`

**Interfaces:**
- Consumes: `Event::hasExecutingUser(int)` (existing on `HasExecutingUsers`), `User::isAdmin()`, `User::hasPermission(string)`.
- Produces: `EventPolicy::executeOwn(User $user, Event $event): bool`.

- [ ] **Step 1: Add the policy method**

Add to `app/Policies/EventPolicy.php`:

```php
    public function executeOwn(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('event.update') && $event->hasExecutingUser($user->id);
    }
```

- [ ] **Step 2: Verify style**

Run: `./vendor/bin/pint app/Policies/EventPolicy.php`
Expected: PASS.

---

### Task 4: Form Requests for transition and edit

**Files:**
- Create: `app/Http/Requests/EventExecutionTransitionRequest.php`
- Create: `app/Http/Requests/EventExecutionUpdateRequest.php`

**Interfaces:**
- Consumes: `EventPolicy::executeOwn` (Task 3), enum `EventCompletionStatus` (Task 1).
- Produces: `EventExecutionTransitionRequest` validating `status` ∈ {`Gaande`, `Afgerond`, `Geannuleerd`} and `signature_base64` required when `status` is `Afgerond`.
- Produces: `EventExecutionUpdateRequest` validating `actual_start` (H:i), `actual_end` (H:i, after `actual_start`), `signature_base64` (string, required).

Both resolve the route-model-bound `Event` as `$this->route('event')`.

- [ ] **Step 1: Write the transition request**

`status` carries the enum **value** (Dutch label) sent by the frontend; `planned` is intentionally excluded (you cannot transition *back* to planned through this endpoint).

```php
<?php

namespace App\Http\Requests;

use App\Enums\EventCompletionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventExecutionTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOwn', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    EventCompletionStatus::ongoing->value,
                    EventCompletionStatus::completed->value,
                    EventCompletionStatus::cancelled->value,
                ]),
            ],
            'signature_base64' => [
                'nullable',
                'string',
                Rule::requiredIf($this->input('status') === EventCompletionStatus::completed->value),
            ],
        ];
    }
}
```

- [ ] **Step 2: Write the update request**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventExecutionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOwn', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'actual_start'     => ['required', 'date_format:H:i'],
            'actual_end'       => ['required', 'date_format:H:i', 'after:actual_start'],
            'signature_base64' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 3: Verify style**

Run: `./vendor/bin/pint app/Http/Requests/EventExecutionTransitionRequest.php app/Http/Requests/EventExecutionUpdateRequest.php`
Expected: PASS.

---

### Task 5: `EventExecutionController` + routes

**Files:**
- Create: `app/Http/Controllers/EventExecutionController.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `Event::executionFor(int)` (Task 2), `EventExecutionTransitionRequest` / `EventExecutionUpdateRequest` (Task 4), enum `EventCompletionStatus` (Task 1), helper `Illuminate\Support\Facades\Auth`.
- Produces routes (inside the `auth:sanctum` group), registered immediately after the existing `events/{event}/send-confirmation` line:
  - `GET    /api/events/{event}/execution`              → `show`
  - `POST   /api/events/{event}/execution/transition`   → `transition`
  - `PATCH  /api/events/{event}/execution`              → `update`
- `show` returns JSON `{ completion_status, actual_start, actual_end, signature_base64 }` for the auth user's row.
- `transition` stamps `actual_start = now()` when moving to `ongoing`, `actual_end = now()` when moving to `completed`, stores `signature_base64` when provided, sets `completion_status`, returns the fresh row JSON.
- `update` rebuilds `actual_start`/`actual_end` from the event's locked planned date + submitted `H:i` time (UTC), stores signature, returns the fresh row JSON.

- [ ] **Step 1: Write the controller**

`transition` receives the enum value (label); compare against `EventCompletionStatus` cases. `update` keeps the date locked to the event's planned `start` date — only the time changes — and converts the local wall-clock time to UTC with `Carbon` parsing in the app timezone.

```php
<?php

namespace App\Http\Controllers;

use App\Enums\EventCompletionStatus;
use App\Http\Requests\EventExecutionTransitionRequest;
use App\Http\Requests\EventExecutionUpdateRequest;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class EventExecutionController extends Controller
{
    public function show(Event $event)
    {
        $execution = $event->executionFor(Auth::id());

        return response()->json([
            'completion_status' => $execution->completion_status,
            'actual_start'      => $execution->actual_start,
            'actual_end'        => $execution->actual_end,
            'signature_base64'  => $execution->signature_base64,
        ]);
    }

    public function transition(EventExecutionTransitionRequest $request, Event $event)
    {
        $status    = $request->validated('status');
        $execution = $event->executionFor(Auth::id());

        if ($status === EventCompletionStatus::ongoing->value) {
            $execution->actual_start = now();
        }

        if ($status === EventCompletionStatus::completed->value) {
            $execution->actual_end       = now();
            $execution->signature_base64 = $request->validated('signature_base64');
        }

        $execution->completion_status = $status;
        $execution->save();

        return response()->json($this->payload($execution));
    }

    public function update(EventExecutionUpdateRequest $request, Event $event)
    {
        $execution = $event->executionFor(Auth::id());
        $date      = $event->start->format('Y-m-d');

        $execution->actual_start     = Carbon::parse($date . ' ' . $request->validated('actual_start'));
        $execution->actual_end       = Carbon::parse($date . ' ' . $request->validated('actual_end'));
        $execution->signature_base64 = $request->validated('signature_base64');
        $execution->save();

        return response()->json($this->payload($execution));
    }

    private function payload($execution): array
    {
        return [
            'completion_status' => $execution->completion_status,
            'actual_start'      => $execution->actual_start,
            'actual_end'        => $execution->actual_end,
            'has_signature'     => filled($execution->signature_base64),
        ];
    }
}
```

- [ ] **Step 2: Register the routes**

Add to `routes/api.php` directly below the `events/{event}/send-confirmation` line, inside the `auth:sanctum` group, and add the import `use App\Http\Controllers\EventExecutionController;` at the top:

```php
    Route::get('events/{event}/execution', [EventExecutionController::class, 'show']);
    Route::post('events/{event}/execution/transition', [EventExecutionController::class, 'transition']);
    Route::patch('events/{event}/execution', [EventExecutionController::class, 'update']);
```

- [ ] **Step 3: Verify routes registered**

Run: `php artisan route:list --path=api/events`
Expected: the three `events/{event}/execution...` rows appear.

- [ ] **Step 4: Verify style**

Run: `./vendor/bin/pint app/Http/Controllers/EventExecutionController.php routes/api.php`
Expected: PASS.

---

### Task 6: Surface per-user execution in the planner payload

**Files:**
- Modify: `app/Http/Controllers/EventApiController.php`

**Interfaces:**
- Consumes: `Event::executions()` (Task 2).
- Produces: each entry in the JSON `executing_users[].pivot` gains `completion_status`, `actual_start`, `actual_end`, `has_signature`. Signature body is intentionally excluded.

- [ ] **Step 1: Eager-load executions wherever events are returned**

In `EventApiController::index`, `store`, `update`, and `copy`, add `'executions'` to the existing `->with([...])` / `->load([...])` arrays alongside `'executingUsers'`.

- [ ] **Step 2: Augment pivot inside `withUserRoles`**

In `app/Http/Controllers/EventApiController.php`, extend the loop in `withUserRoles` so each executing user's pivot also carries the execution summary. Inside `foreach ($collection as $event)`, before the inner `foreach`, build a lookup:

```php
            $executions_by_user = $event->executions->keyBy('user_id');
```

Then inside `foreach ($event->executingUsers as $user)` add:

```php
                $execution = $executions_by_user->get($user->id);
                $user->pivot->setAttribute('completion_status', $execution->completion_status ?? 'planned');
                $user->pivot->setAttribute('actual_start', $execution?->actual_start);
                $user->pivot->setAttribute('actual_end', $execution?->actual_end);
                $user->pivot->setAttribute('has_signature', filled($execution?->signature_base64));
```

- [ ] **Step 3: Verify payload manually**

Run the dev stack (`composer run dev`), open the planner, and in the browser network tab confirm `GET /api/events` returns `executing_users[].pivot.completion_status` (defaults to `planned`).
Expected: field present on each executing user.

- [ ] **Step 4: Verify style**

Run: `./vendor/bin/pint app/Http/Controllers/EventApiController.php`
Expected: PASS.

---

### Task 7: Map execution fields into planner events (frontend data layer)

**Files:**
- Modify: `resources/js/Composables/usePlannerEvents.js`

**Interfaces:**
- Consumes: pivot fields from Task 6.
- Produces: each `executing_users[]` object gains `completion_status`, `actual_start`, `actual_end`, `has_signature`.

- [ ] **Step 1: Extend the executing_users mapping**

In `mapEvent`, extend the `executing_users` map object with:

```js
            completion_status: u.pivot?.completion_status ?? "planned",
            actual_start: u.pivot?.actual_start ?? null,
            actual_end: u.pivot?.actual_end ?? null,
            has_signature: u.pivot?.has_signature ?? false,
```

- [ ] **Step 2: Verify lint**

Run: `npm run fix:eslint`
Expected: no errors on `usePlannerEvents.js`.

---

### Task 8: `ExecutionModal.vue` — slot-based signature modal

**Files:**
- Create: `resources/js/Components/Planner/ExecutionModal.vue`

**Interfaces:**
- Consumes: `ModalDialog.vue`, `SignaturePad.vue`.
- Produces: component props `open` (Boolean), `mode` (`'stop' | 'edit'`), `initialSignature` (String, default `''`), `busy` (Boolean, default false). Emits `update:open` and `confirm(signatureBase64)`. Default slot renders caller-supplied fields (time inputs in edit mode) above the pad. Confirm is disabled until a signature exists.

- [ ] **Step 1: Write the component**

```vue
<template>
    <ModalDialog :open="open" :title="title" @update:open="$emit('update:open', $event)">
        <div class="space-y-4">
            <slot />
            <SignaturePad v-model="signature" />
        </div>
        <template #footer>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button type="button"
                    class="rounded-md border border-gray-200 dark:border-slate-600 px-4 py-2 text-sm text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/50"
                    @click="$emit('update:open', false)">
                    Annuleren
                </button>
                <button type="button" :disabled="!signature || busy"
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-lavoro-green px-4 py-2 text-sm font-semibold text-gray-900 disabled:opacity-40"
                    @click="$emit('confirm', signature)">
                    <SaveAll class="size-4 shrink-0" />
                    {{ confirmLabel }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import { SaveAll } from '@lucide/vue'

const props = defineProps({
    open: { type: Boolean, required: true },
    mode: { type: String, default: 'stop' },
    initialSignature: { type: String, default: '' },
    busy: { type: Boolean, default: false },
})

defineEmits(['update:open', 'confirm'])

const signature = ref(props.initialSignature)

watch(() => props.open, (isOpen) => {
    if (isOpen) signature.value = props.initialSignature
})

const title = computed(() => props.mode === 'edit' ? 'Tijden en handtekening aanpassen' : 'Handtekening vereist')
const confirmLabel = computed(() => props.mode === 'edit' ? 'Opslaan' : 'Afronden')
</script>
```

- [ ] **Step 2: Verify lint**

Run: `npm run fix:eslint`
Expected: no errors on `ExecutionModal.vue`.

---

### Task 9: `EventExecutionControls.vue` — buttons + modal wiring

**Files:**
- Create: `resources/js/Components/Planner/EventExecutionControls.vue`

**Interfaces:**
- Consumes: `ExecutionModal.vue` (Task 8), enum-equivalent status strings from Task 7, axios, date helpers `formatLocalDateAsISO`, `localToUtcDatetime`, `nlTime` (`@/Utilities/Utilities`).
- Produces: component props `event` (Object), `userId` (Number). Emits `changed` after any successful action so the parent can refetch. Renders nothing unless `event.executing_user_ids.includes(userId)`.
- Status values are the Dutch enum labels: planned `'Gepland'`, ongoing `'Gaande'`, completed `'Afgerond'`, cancelled `'Geannuleerd'`.

Button matrix by my `completion_status`:
- `Gepland` → `Play` (start) + `X` (cancel, confirm dialog)
- `Gaande` → `Square` (stop → opens modal in `stop` mode) + `X` (cancel, confirm dialog)
- `Afgerond` → `SaveAll` (edit → opens modal in `edit` mode, prefilled via GET)
- `Geannuleerd` → muted `Ban` icon, no actions

- [ ] **Step 1: Write the component**

```vue
<template>
    <div v-if="isMine" class="flex items-center gap-1" @click.stop @pointerdown.stop>
        <template v-if="status === 'Gepland'">
            <button type="button" v-tooltip="'Starten'"
                class="p-1 rounded text-green-600 hover:bg-green-50 disabled:opacity-40"
                :disabled="busy" @click="start">
                <Play class="size-4" />
            </button>
            <button type="button" v-tooltip="'Annuleren'"
                class="p-1 rounded text-red-600 hover:bg-red-50 disabled:opacity-40"
                :disabled="busy" @click="cancel">
                <X class="size-4" />
            </button>
        </template>

        <template v-else-if="status === 'Gaande'">
            <button type="button" v-tooltip="'Afronden'"
                class="p-1 rounded text-blue-600 hover:bg-blue-50 disabled:opacity-40"
                :disabled="busy" @click="openStop">
                <Square class="size-4" />
            </button>
            <button type="button" v-tooltip="'Annuleren'"
                class="p-1 rounded text-red-600 hover:bg-red-50 disabled:opacity-40"
                :disabled="busy" @click="cancel">
                <X class="size-4" />
            </button>
        </template>

        <template v-else-if="status === 'Afgerond'">
            <button type="button" v-tooltip="'Tijden/handtekening aanpassen'"
                class="p-1 rounded text-gray-600 hover:bg-gray-100 disabled:opacity-40"
                :disabled="busy" @click="openEdit">
                <SaveAll class="size-4" />
            </button>
        </template>

        <template v-else-if="status === 'Geannuleerd'">
            <Ban class="size-4 text-gray-400" v-tooltip="'Geannuleerd'" />
        </template>

        <ExecutionModal v-if="modalOpen" :open="modalOpen" :mode="modalMode"
            :initial-signature="editSignature" :busy="busy"
            @update:open="modalOpen = $event" @confirm="onModalConfirm">
            <div v-if="modalMode === 'edit'" class="grid grid-cols-2 gap-3">
                <label class="text-sm">
                    <span class="block text-gray-600 dark:text-slate-300 mb-1">Starttijd</span>
                    <input v-model="editStart" type="time"
                        class="w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                </label>
                <label class="text-sm">
                    <span class="block text-gray-600 dark:text-slate-300 mb-1">Eindtijd</span>
                    <input v-model="editEnd" type="time"
                        class="w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm" />
                </label>
            </div>
        </ExecutionModal>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'
import ExecutionModal from '@/Components/Planner/ExecutionModal.vue'
import { Play, Square, X, SaveAll, Ban } from '@lucide/vue'
import { nlTime } from '@/Utilities/Utilities'

const props = defineProps({
    event: { type: Object, required: true },
    userId: { type: Number, required: true },
})

const emit = defineEmits(['changed'])

const busy = ref(false)
const modalOpen = ref(false)
const modalMode = ref('stop')
const editSignature = ref('')
const editStart = ref('')
const editEnd = ref('')

const myExecution = computed(() =>
    props.event.executing_users?.find(u => u.id === props.userId) ?? null
)
const isMine = computed(() => !!myExecution.value)
const status = computed(() => myExecution.value?.completion_status ?? 'Gepland')

async function postTransition(payload) {
    if (busy.value) return
    busy.value = true
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.post(`/api/events/${props.event.id}/execution/transition`, payload)
        emit('changed')
    } finally {
        busy.value = false
    }
}

function start() {
    postTransition({ status: 'Gaande' })
}

function cancel() {
    if (!window.confirm('Weet je zeker dat je deze afspraak wilt annuleren?')) return
    postTransition({ status: 'Geannuleerd' })
}

function openStop() {
    modalMode.value = 'stop'
    editSignature.value = ''
    modalOpen.value = true
}

async function openEdit() {
    busy.value = true
    try {
        const { data } = await axios.get(`/api/events/${props.event.id}/execution`)
        editSignature.value = data.signature_base64 ?? ''
        editStart.value = data.actual_start ? nlTime(data.actual_start) : ''
        editEnd.value = data.actual_end ? nlTime(data.actual_end) : ''
        modalMode.value = 'edit'
        modalOpen.value = true
    } finally {
        busy.value = false
    }
}

async function onModalConfirm(signature) {
    if (modalMode.value === 'stop') {
        await postTransition({ status: 'Afgerond', signature_base64: signature })
        modalOpen.value = false
        return
    }

    if (busy.value) return
    busy.value = true
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.patch(`/api/events/${props.event.id}/execution`, {
            actual_start: editStart.value,
            actual_end: editEnd.value,
            signature_base64: signature,
        })
        emit('changed')
        modalOpen.value = false
    } finally {
        busy.value = false
    }
}
</script>
```

Note: the controller derives the locked date server-side from `event.start`, so the PATCH body sends only the `H:i` times. `nlTime` is used to prefill the time inputs from the stored UTC datetimes.

- [ ] **Step 2: Verify lint**

Run: `npm run fix:eslint`
Expected: no errors on `EventExecutionControls.vue`.

---

### Task 10: Mount controls on the desktop planner card

**Files:**
- Modify: `resources/js/Components/Planner/PlannerEvent.vue`

**Interfaces:**
- Consumes: `EventExecutionControls.vue` (Task 9). `PlannerEvent` already has `event` and `userId` props.
- Produces: controls rendered in the card's top-right cluster; a `changed` event bubbles up so the planner refetches.

- [ ] **Step 1: Import and render the controls**

Add the import in `<script setup>`:

```js
import EventExecutionControls from '@/Components/Planner/EventExecutionControls.vue'
```

Add `'changed'` to the `defineEmits` array. Then inside the existing top-right cluster `<div class="absolute top-1 right-2 ...">` change `pointer-events-none` handling by adding the controls as the first child with pointer events enabled:

```vue
            <EventExecutionControls :event="event" :user-id="userId" class="pointer-events-auto"
                @changed="$emit('changed')" />
```

- [ ] **Step 2: Bubble `changed` to a refetch**

In `ResourcePlannerWidget.vue`, find where `<PlannerEvent` is rendered and add `@changed="fetchEvents()"` (use the existing fetch function name used by the widget — confirm via `grep -n "fetchEvents\|PlannerEvent" resources/js/Components/Planner/ResourcePlannerWidget.vue`).

- [ ] **Step 3: Verify build + lint**

Run: `npm run fix:eslint && npm run build`
Expected: build succeeds, no eslint errors.

---

### Task 11: Mount controls on the mobile planner card

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

**Interfaces:**
- Consumes: `EventExecutionControls.vue` (Task 9), existing `authUserId` computed, existing `fetchEvents`.
- Produces: controls shown on each card for the authenticated user's own row; `changed` triggers `fetchEvents()`.

- [ ] **Step 1: Import the controls**

Add to `<script setup>`:

```js
import EventExecutionControls from '@/Components/Planner/EventExecutionControls.vue'
```

- [ ] **Step 2: Render in the card header**

Inside the card's title row `<div class="flex items-start gap-2">`, after the avatars block and before/after the `TriangleAlert`, add:

```vue
                                            <EventExecutionControls :event="ev" :user-id="authUserId"
                                                @changed="fetchEvents" />
```

- [ ] **Step 3: Verify build + lint**

Run: `npm run fix:eslint && npm run build`
Expected: build succeeds, no eslint errors.

---

### Task 12: End-to-end manual verification

**Files:** none (manual).

- [ ] **Step 1: Lifecycle on desktop**

Run `composer run dev`. As a user who is an executing user on an event with status `planned`:
1. Confirm the ▶ Play and ✕ buttons appear only on your own row.
2. Click Play → row flips to ongoing, ⏹ Stop appears. Verify in DB `event_user_executions.actual_start` is set to roughly now (UTC).
3. Click Stop → signature modal appears; confirm is disabled until you sign; saving sets status `Afgerond` and `actual_end`, stores the signature.
4. The `SaveAll` edit icon appears; open it → existing signature + times prefilled, date implicitly locked (only time inputs). Change a time, re-sign, save → times update, no date change.

- [ ] **Step 2: Cancel flow**

On a fresh planned event row, click ✕ → browser confirm → on accept status becomes `Geannuleerd`, the muted `Ban` icon shows, no other user's row affected.

- [ ] **Step 3: Mobile parity**

Repeat Step 1's start/stop/edit on the mobile planner view; confirm controls only show on your own row and the planner refetches after each action.

- [ ] **Step 4: Permission gate**

As a user with neither `event.update` nor membership on the event, confirm no controls render and the transition endpoint returns 403 (network tab).

---

## Self-Review Notes

- **Spec coverage:** dedicated table (Task 1) ✓; per-user start/end + signature + status enum (Tasks 1–2) ✓; own-row play→ongoing+start (Task 9 `start`) ✓; play→stop swap (Task 9 state matrix) ✓; stop→completed+end with mandatory signature modal intermediate step (Tasks 8–9) ✓; X cancel with confirm, own row only (Task 9 `cancel`, Task 3 policy) ✓; SaveAll edit icon when completed, own row only, date-locked time + signature edit (Tasks 5 `update`, 8, 9) ✓; desktop + mobile (Tasks 10–11) ✓; timezone correctness (server `now()` for transitions, server-side date-lock for edit) ✓; dedicated-table advice (Task 1) ✓.
- **Reuse:** `ModalDialog`, `SignaturePad`, existing date helpers, `@lucide/vue`, existing `EventPolicy`/`event.update` permission — no new deps.
- **Type consistency:** status strings are the Dutch enum values everywhere (`Gepland`/`Gaande`/`Afgerond`/`Geannuleerd`); pivot keys (`completion_status`, `actual_start`, `actual_end`, `has_signature`) are consistent across Tasks 6, 7, 9.
