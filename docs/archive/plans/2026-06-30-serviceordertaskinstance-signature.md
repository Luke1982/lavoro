# Task Instance Customer Signature — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow users with `serviceordertaskinstance.open_close` permission to collect a customer name + drawn signature on a completed task instance, show it on the service order PDF, and display a signed indicator with a view modal in the UI.

**Architecture:** New migration adds three columns to `service_order_task_instances`. A dedicated Form Request + controller method handles signing. `TaskInstancesWidget.vue` gains a sign modal (name + `SignaturePad.vue`) and a read-only view modal. The PDF tasks table gains a signature column.

**Tech Stack:** Laravel 12, Inertia + Vue 3, `@selemondev/vue3-signature-pad` (already installed), DomPDF (already used for PDF), Lucide Vue icons.

## Global Constraints

- PHP snake_case for all variable names.
- No inline comments; no docblocks unless non-obvious.
- Authorization belongs in Form Request `authorize()` only.
- Validation belongs in Form Request `rules()` only; frontend only displays errors.
- No tests unless asked.
- String concatenation with spaces: `$string . ' other'`.

---

### Task 1: DB Migration + Model Update

**Files:**
- Create: `database/migrations/2026_06_30_000001_add_signature_fields_to_service_order_task_instances_table.php`
- Modify: `app/Models/ServiceOrderTaskInstance.php`

**Interfaces:**
- Produces: `service_order_task_instances` table with `signed_by` (string nullable), `signature_base64` (mediumText nullable), `signed_at` (timestamp nullable); model casts `signed_at` as `datetime`.

- [ ] **Step 1: Create the migration**

Create `database/migrations/2026_06_30_000001_add_signature_fields_to_service_order_task_instances_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->string('signed_by')->nullable()->after('is_complete');
            $table->mediumText('signature_base64')->nullable()->after('signed_by');
            $table->timestamp('signed_at')->nullable()->after('signature_base64');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->dropColumn(['signed_by', 'signature_base64', 'signed_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_30_000001_add_signature_fields_to_service_order_task_instances_table` followed by `Migrated`.

- [ ] **Step 3: Update the model**

In `app/Models/ServiceOrderTaskInstance.php`, update `$fillable` and `$casts`:

```php
protected $fillable = [
    'service_order_id',
    'service_order_task_id',
    'product_id',
    'quantity',
    'title',
    'description',
    'is_complete',
    'signed_by',
    'signature_base64',
    'signed_at',
];

protected $casts = [
    'is_complete' => 'boolean',
    'quantity'    => 'integer',
    'signed_at'   => 'datetime',
];
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_30_000001_add_signature_fields_to_service_order_task_instances_table.php app/Models/ServiceOrderTaskInstance.php
git commit -m "feat(ServiceOrderTaskInstance): add signed_by, signature_base64, signed_at columns"
```

---

### Task 2: Backend — Form Request, Route, Controller Method

**Files:**
- Create: `app/Http/Requests/ServiceOrderTaskInstanceSignRequest.php`
- Modify: `routes/web.php` (add `sign` route after the `toggle` route, around line 170)
- Modify: `app/Http/Controllers/ServiceOrderTaskInstanceController.php` (add `sign()` method + import)

**Interfaces:**
- Consumes: `service_order_task_instances.signed_by`, `service_order_task_instances.signature_base64`, `service_order_task_instances.signed_at` from Task 1.
- Produces: `POST serviceordertaskinstances/{serviceordertaskinstance}/sign` route named `serviceordertaskinstances.sign`.

- [ ] **Step 1: Create the Form Request**

Create `app/Http/Requests/ServiceOrderTaskInstanceSignRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskInstanceSignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.open_close'));
    }

    public function rules(): array
    {
        return [
            'signed_by'        => ['required', 'string', 'max:255'],
            'signature_base64' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 2: Add the route**

In `routes/web.php`, directly after the `toggle` route (after line 170), add:

```php
Route::post(
    'serviceordertaskinstances/{serviceordertaskinstance}/sign',
    [ServiceOrderTaskInstanceController::class, 'sign']
)->name('serviceordertaskinstances.sign');
```

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/ServiceOrderTaskInstanceController.php`:

Add the import at the top of the use-block (alongside the other request imports):
```php
use App\Http\Requests\ServiceOrderTaskInstanceSignRequest;
```

Add the `sign()` method at the end of the class (before the closing `}`):

```php
public function sign(ServiceOrderTaskInstanceSignRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
{
    if (!$serviceordertaskinstance->is_complete) {
        return redirect()->back()->withErrors([
            'sign' => 'Alleen voltooide taken kunnen ondertekend worden.',
        ]);
    }

    $data = $request->validated();

    $serviceordertaskinstance->update([
        'signed_by'        => $data['signed_by'],
        'signature_base64' => $data['signature_base64'],
        'signed_at'        => now(),
    ]);

    $serviceordertaskinstance->loadMissing('serviceOrder');

    $title = $serviceordertaskinstance->title
        ?? $serviceordertaskinstance->serviceOrderTask?->title
        ?? 'Taak';

    $serviceordertaskinstance->serviceOrder->logActivity(
        'Taak "' . $title . '" ondertekend door ' . $data['signed_by'],
        category: 'status',
    );

    return redirect()->back()->with('success', 'Taak ondertekend');
}
```

- [ ] **Step 4: Verify the route is registered**

```bash
php artisan route:list --name=serviceordertaskinstances
```

Expected: a line showing `POST serviceordertaskinstances/{serviceordertaskinstance}/sign` named `serviceordertaskinstances.sign`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/ServiceOrderTaskInstanceSignRequest.php routes/web.php app/Http/Controllers/ServiceOrderTaskInstanceController.php
git commit -m "feat(ServiceOrderTaskInstance): add sign endpoint"
```

---

### Task 3: Expose getDataUrl / isEmpty on SignaturePad

**Files:**
- Modify: `resources/js/Components/UI/SignaturePad.vue`

**Interfaces:**
- Produces: `signaturePadRef.value.isEmpty()` → `boolean`; `signaturePadRef.value.getDataUrl()` → `string` (JPEG data URL).

- [ ] **Step 1: Add defineExpose to SignaturePad.vue**

In `resources/js/Components/UI/SignaturePad.vue`, add the following at the very end of the `<script setup>` block (after `handleUndo`):

```js
defineExpose({
    isEmpty: () => !hasStrokes.value,
    getDataUrl: () => signature.value.saveSignature('image/jpeg'),
})
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/UI/SignaturePad.vue
git commit -m "feat(SignaturePad): expose isEmpty and getDataUrl for parent use"
```

---

### Task 4: TaskInstancesWidget — Sign Button, Sign Modal, View Modal

**Files:**
- Modify: `resources/js/Components/ServiceOrders/TaskInstancesWidget.vue`

**Interfaces:**
- Consumes: `POST /serviceordertaskinstances/{id}/sign` from Task 2; `signaturePadRef.isEmpty()` / `signaturePadRef.getDataUrl()` from Task 3.
- Consumes: `instance.signed_by`, `instance.signature_base64`, `instance.signed_at` — these come from the backend via Inertia page props; they are already on `internalInstances` items since the model returns them in the service order show response.

- [ ] **Step 1: Add imports**

At the top of the `<script setup>` section, update the lucide import line and add new component imports. Replace the existing lucide import line:

```js
import { Plus as PlusIcon, Trash2 as TrashIcon, EllipsisVertical as EllipsisVerticalIcon, ClipboardListIcon, PenLine as PenLineIcon, BadgeCheck as BadgeCheckIcon } from '@lucide/vue'
```

After the existing component imports, add:

```js
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import { nlDate, nlTime } from '@/Utilities/Utilities'
```

- [ ] **Step 2: Add canSign computed and sign-modal state**

After the existing `canDelete` computed, add:

```js
const canSign = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.open_close'))
```

After the `// ── Delete ──` section, add a new section:

```js
// ── Sign ──────────────────────────────────────────────────────────────────────
const signModalOpen = ref(false)
const signingInstance = ref(null)
const signName = ref('')
const signatureData = ref('')
const signError = ref('')
const signModalKey = ref(0)
const signaturePadRef = ref(null)
const signForm = useForm({ signed_by: '', signature_base64: '' })

function openSignModal(instance) {
    signingInstance.value = instance
    signName.value = ''
    signatureData.value = ''
    signError.value = ''
    signModalKey.value++
    signModalOpen.value = true
}

function closeSignModal() {
    signModalOpen.value = false
    signingInstance.value = null
    signName.value = ''
    signatureData.value = ''
    signError.value = ''
    signForm.reset()
}

function submitSign() {
    signError.value = ''
    if (!signName.value.trim()) {
        signError.value = 'Vul een naam in.'
        return
    }
    if (!signaturePadRef.value || signaturePadRef.value.isEmpty()) {
        signError.value = 'Teken een handtekening.'
        return
    }
    const data_url = signaturePadRef.value.getDataUrl()
    signForm.signed_by = signName.value.trim()
    signForm.signature_base64 = data_url
    signForm.post(`/serviceordertaskinstances/${signingInstance.value.id}/sign`, {
        preserveScroll: true,
        onSuccess: () => {
            const inst = internalInstances.value.find(i => i.id === signingInstance.value.id)
            if (inst) {
                inst.signed_by = signForm.signed_by
                inst.signature_base64 = signForm.signature_base64
                inst.signed_at = new Date().toISOString()
            }
            closeSignModal()
        },
        onError: () => {
            signError.value = 'Er is een fout opgetreden. Probeer het opnieuw.'
        },
    })
}

// ── View signature ────────────────────────────────────────────────────────────
const viewModalOpen = ref(false)
const viewingInstance = ref(null)

function openViewModal(instance) {
    viewingInstance.value = instance
    viewModalOpen.value = true
}
```

- [ ] **Step 3: Add sign and view buttons to the instance row**

In the template, inside the `<div class="flex justify-end gap-x-3 sm:gap-x-1 sm:justify-start items-center">` block (the action buttons area), add two buttons **before** the existing edit button. The full block becomes:

```html
<div class="flex justify-end gap-x-3 sm:gap-x-1 sm:justify-start items-center">
    <BadgeComponent :color="instance.is_complete ? 'green' : 'gray'" :has-dot="false"
        class="flex-none hidden sm:inline-flex">
        {{ instance.is_complete ? 'Voltooid' : 'In uitvoering' }}
    </BadgeComponent>
    <button v-if="instance.signed_by" type="button"
        class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
        @click="openViewModal(instance)" v-tooltip="'Ondertekend'">
        <BadgeCheckIcon class="w-4 h-4 text-green-500" />
    </button>
    <button v-if="canSign && instance.is_complete && !instance.signed_by" type="button"
        class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
        @click="openSignModal(instance)" v-tooltip="'Laten ondertekenen'">
        <PenLineIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
    </button>
    <button v-if="canEdit" type="button"
        class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
        @click="openEditDrawer(instance)" v-tooltip="'Bewerk taak'">
        <EllipsisVerticalIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
    </button>
    <button v-if="canDelete" type="button"
        class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
        @click="deleteInstance(instance.id)" v-tooltip="'Verwijder taak'">
        <TrashIcon class="w-4 h-4 text-red-500" />
    </button>
</div>
```

- [ ] **Step 4: Add sign modal and view modal to the template**

After the closing `</DrawerComponent>` of the serial number drawer (before `</BoxComponent>`), add:

```html
<!-- Sign modal -->
<ModalDialog :open="signModalOpen" @update:open="signModalOpen = $event" title="Taak ondertekenen"
    max-width-class="sm:max-w-lg">
    <div class="flex flex-col gap-4">
        <TextInput v-model="signName" label="Naam klant" placeholder="Volledige naam" />
        <div>
            <label
                class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Handtekening</label>
            <SignaturePad ref="signaturePadRef" :key="signModalKey" v-model="signatureData" />
        </div>
        <p v-if="signError" class="text-xs text-red-600">{{ signError }}</p>
    </div>
    <template #footer>
        <div class="flex justify-end gap-3">
            <button type="button" @click="closeSignModal"
                class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                Annuleren
            </button>
            <button type="button" :disabled="signForm.processing" @click="submitSign"
                class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                Ondertekenen
            </button>
        </div>
    </template>
</ModalDialog>

<!-- View signature modal -->
<ModalDialog :open="viewModalOpen" @update:open="viewModalOpen = $event" title="Ondertekening"
    max-width-class="sm:max-w-md">
    <div v-if="viewingInstance" class="flex flex-col gap-3">
        <div class="grid grid-cols-2 gap-2 text-sm">
            <span class="text-gray-500 dark:text-slate-400">Naam</span>
            <span class="text-gray-900 dark:text-slate-100 font-medium">{{ viewingInstance.signed_by }}</span>
            <span class="text-gray-500 dark:text-slate-400">Datum</span>
            <span class="text-gray-900 dark:text-slate-100">{{ nlDate(viewingInstance.signed_at) }}</span>
            <span class="text-gray-500 dark:text-slate-400">Tijd</span>
            <span class="text-gray-900 dark:text-slate-100">{{ nlTime(viewingInstance.signed_at) }}</span>
        </div>
        <div class="mt-2 border border-gray-200 dark:border-slate-600 rounded-lg p-3">
            <img :src="viewingInstance.signature_base64" alt="Handtekening" class="max-h-32 w-auto">
        </div>
    </div>
    <template #footer>
        <div class="flex justify-end">
            <button type="button" @click="viewModalOpen = false"
                class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 transition-opacity">
                Sluiten
            </button>
        </div>
    </template>
</ModalDialog>
```

- [ ] **Step 5: Verify the widget renders without console errors**

Open a service order that has task instances in the browser (run `composer run dev` if not already running). Confirm the widget loads, the existing task operations still work, and complete tasks show the `PenLine` icon (for users with `open_close` permission).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/ServiceOrders/TaskInstancesWidget.vue
git commit -m "feat(TaskInstancesWidget): add customer signature sign and view modals"
```

---

### Task 5: PDF — Add Signature Column to Tasks Table

**Files:**
- Modify: `resources/views/pdf/serviceorder.blade.php`

**Interfaces:**
- Consumes: `$instance->signed_by` (string|null), `$instance->signed_at` (Carbon|null), `$instance->signature_base64` (string|null) on each task instance. These are already available — `generateServiceOrderPdf()` in `ServiceOrderController` loads `taskInstances` and the fields are scalar columns on the model.

- [ ] **Step 1: Update the tasks table in the PDF template**

In `resources/views/pdf/serviceorder.blade.php`, find the tasks table section (around line 352). Replace the entire `@if (($taskInstances ?? collect())->isNotEmpty())` block with:

```blade
@if (($taskInstances ?? collect())->isNotEmpty())
    <h2 class="section">Taken</h2>
    <table class="table small compact">
        <thead>
            <tr>
                <th style="width:25%">Taak</th>
                <th>Omschrijving</th>
                <th style="width:18%">Serienummers</th>
                <th style="width:25%">Ondertekend door</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($taskInstances as $instance)
                <tr>
                    <td>{{ $instance->title ?? ($instance->serviceOrderTask?->title ?? '—') }}</td>
                    <td>{{ $instance->effective_description ?: '—' }}</td>
                    <td>
                        @if ($instance->assets->isNotEmpty())
                            {{ $instance->assets->pluck('serial_number')->filter()->implode(', ') ?: '—' }}
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if ($instance->signed_by)
                            <div>{{ $instance->signed_by }}</div>
                            <div class="muted" style="font-size:10px;">{{ $instance->signed_at?->format('d-m-Y H:i') }}</div>
                            <img src="{{ $instance->signature_base64 }}" alt="Handtekening"
                                style="max-height:60px; max-width:180px; display:block; margin-top:4px;">
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
```

- [ ] **Step 2: Verify the PDF**

Open a service order in the browser and export the PDF (via the PDF button). Confirm:
- The tasks table now has a fourth column "Ondertekend door".
- Unsigned instances show `—` in that column.
- After signing a task (via the UI from Task 4), regenerate the PDF and confirm the name, date/time, and signature image appear in the row.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pdf/serviceorder.blade.php
git commit -m "feat(ServiceOrderPDF): add signature column to tasks table"
```
