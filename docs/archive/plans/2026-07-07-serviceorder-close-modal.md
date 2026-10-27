# Service Order Close/Sign Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the serviceorder "close" button with a review-and-sign modal (remarks/images/materials/tasks recap + signature capture), moving the signature pad out of the sidebar and gating the actual close button on a saved signature.

**Architecture:** One new Vue component (`CloseServiceOrderModal.vue`) that reuses existing read-only-capable widgets (`RemarksComponent`, `MaterialsWidget`, `TaskInstancesWidget`) for the recap, plus its own name+`SignaturePad` step. It emits the signed data up to `ShowPage.vue`, which performs the actual save through its existing shared Inertia `form` (required so the full field set — including `customer_id` — is present, avoiding a validation failure for users with full `update` permission). A small backend guard in `ServiceOrderUpdateRequest` blocks closing without a persisted signature.

**Tech Stack:** Laravel 12 + Inertia + Vue 3, Inertia `useForm`, HeadlessUI `Dialog` (via existing `ModalDialog.vue`), `glightbox` (already a dependency), the existing `useScrollLock` composable.

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments; prefer clear names.
- Don't propose git commands or workflows (already on `feature/serviceorder-close-modal`).
- Don't write tests unless asked — this project's CLAUDE.md explicitly overrides the default TDD-first flow. Verify each task by running the existing suites (`composer test`, `npm run test`) as a regression check, plus manual verification — do not add new test files.
- String concatenation always with spaces: `$string . ' some other string'`.
- Selecting/toggling UI: clicking a selected item deselects it — no separate clear/X buttons (not directly relevant here, but keep in mind if touching selection UI).
- Reuse the `userables` pivot / existing permission (`serviceorder.close`, `serviceorder.reopen`) conventions — no new permissions needed for this feature.

---

## Task 1: Backend guard — block closing without a signature

**Files:**
- Modify: `app/Http/Requests/ServiceOrderUpdateRequest.php:97-105`

**Interfaces:**
- Consumes: nothing new — reads `$serviceorder->signed_by` / `$serviceorder->signature_base64` (existing `ServiceOrder` model attributes).
- Produces: nothing consumed by later tasks — this is a standalone server-side safety check.

- [ ] **Step 1: Add the signature guard**

In `app/Http/Requests/ServiceOrderUpdateRequest.php`, the `withValidator` method currently ends its `is_closed_state` block like this (lines 97-105):

```php
            $min = (int) GeneralSetting::get('serviceorder_min_images', 0);
            if ($min > 0) {
                $count = $serviceorder->images()->count();
                if ($count < $min) {
                    $message = "Er zijn minimaal {$min} foto's vereist om de werkbon te sluiten."
                        . " Er zijn er {$count} toegevoegd.";
                    $validator->errors()->add('service_order_stage_id', $message);
                }
            }
        });
    }
```

Replace it with:

```php
            $min = (int) GeneralSetting::get('serviceorder_min_images', 0);
            if ($min > 0) {
                $count = $serviceorder->images()->count();
                if ($count < $min) {
                    $message = "Er zijn minimaal {$min} foto's vereist om de werkbon te sluiten."
                        . " Er zijn er {$count} toegevoegd.";
                    $validator->errors()->add('service_order_stage_id', $message);
                }
            }

            if (blank($serviceorder->signed_by) || blank($serviceorder->signature_base64)) {
                $validator->errors()->add(
                    'service_order_stage_id',
                    'De werkbon moet ondertekend zijn door de klant voordat deze gesloten kan worden.'
                );
            }
        });
    }
```

- [ ] **Step 2: Verify with Tinker**

Run: `php artisan tinker --execute="
\$so = App\Models\ServiceOrder::whereNull('signed_by')->orWhereNull('signature_base64')->first();
\$closedStage = App\Models\ServiceOrderStage::where('is_closed_state', true)->first();
echo \$so ? \$so->id : 'none'; echo PHP_EOL; echo \$closedStage ? \$closedStage->id : 'none';
"`

Note the printed IDs (a service order missing a signature, and the closed-stage ID). If either prints `none`, skip this manual check — Task 4's browser walkthrough will cover it anyway.

- [ ] **Step 3: Run the existing backend test suite (regression check)**

Run: `composer test`
Expected: all existing tests still pass (no test currently covers this code path, so nothing new should fail or pass — this only confirms no regression).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/ServiceOrderUpdateRequest.php
git commit -m "feat: require a saved signature before a service order can be closed"
```

---

## Task 2: Create `CloseServiceOrderModal.vue`

**Files:**
- Create: `resources/js/Components/ServiceOrders/CloseServiceOrderModal.vue`

**Interfaces:**
- Consumes: `ModalDialog` (`resources/js/Components/UI/ModalDialog.vue`, props `open`/`title`/`maxWidthClass`, emits `update:open`), `RemarksComponent` (`resources/js/Components/RemarksComponent.vue`, props `comments`/`remarkableType`/`remarkableId`/`disabled`), `MaterialsWidget` (`resources/js/Components/Materials/MaterialsWidget.vue`), `TaskInstancesWidget` (`resources/js/Components/ServiceOrders/TaskInstancesWidget.vue`), `SignaturePad` (`resources/js/Components/UI/SignaturePad.vue`, `v-model`, exposes `isEmpty()`/`getDataUrl()`), `TextInput` (`resources/js/Components/UI/TextInput.vue`, `v-model` via `modelValue`/`label`), `useScrollLock` (`resources/js/Composables/useScrollLock.js`, returns `{ lock, unlock }`), `glightbox` npm package (already installed).
- Produces: exported component with props `open: Boolean (required)`, `serviceOrder: Object (required)`, `userRoles: Array (default [])`; emits `update:open` (Boolean) and `confirm` (payload `{ signed_by: string, signature_base64: string }`). Consumed by Task 3.

- [ ] **Step 1: Write the component**

Create `resources/js/Components/ServiceOrders/CloseServiceOrderModal.vue`:

```vue
<template>
    <ModalDialog :open="open" title="Werkbon afronden en ondertekenen" max-width-class="sm:max-w-3xl"
        @update:open="handleDialogUpdateOpen">
        <div class="space-y-6 max-h-[65vh] overflow-y-auto pr-1">
            <div>
                <RemarksComponent remarkable-type="App\Models\ServiceOrder" :remarkable-id="serviceOrder.id"
                    :comments="serviceOrder.remarks" :disabled="true" />
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-200 mb-2">Foto's</h3>
                <div v-if="serviceOrder.images.length > 0" class="grid grid-cols-4 gap-2">
                    <a v-for="image in serviceOrder.images" :key="image.id" :href="`/storage/${image.path}`"
                        class="close-modal-lightbox block aspect-square overflow-hidden rounded-md ring-1 ring-gray-200 dark:ring-slate-700">
                        <img :src="`/storage/${image.path}`" :alt="image.name" class="w-full h-full object-cover" />
                    </a>
                </div>
                <p v-else class="text-sm text-gray-400 dark:text-slate-500">Geen foto's</p>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css" />
            </div>

            <div>
                <MaterialsWidget :service-order-id="serviceOrder.id" :materials="serviceOrder.materials"
                    :freeform-materials="serviceOrder.freeform_materials" :all-materials="[]" :categories="[]"
                    :usage-units="[]" :is-closed="true" :sent-to-administration="serviceOrder.sent_to_administration"
                    :type="serviceOrder.type" />
            </div>

            <div>
                <TaskInstancesWidget :service-order-id="serviceOrder.id" :instances="serviceOrder.task_instances"
                    :available-tasks="[]" :products="[]" :user-roles="userRoles" :is-closed="true" />
            </div>

            <div class="border-t border-gray-200 dark:border-slate-700 pt-4">
                <h3 class="font-bold text-gray-900 dark:text-slate-100 mb-3">Handtekening</h3>

                <div v-if="isSigned && !isResigning">
                    <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ serviceOrder.signed_by }}</p>
                    <img :src="serviceOrder.signature_base64" alt="Handtekening" class="mt-2 max-h-32">
                    <button v-if="!serviceOrder.is_closed" type="button" @click="startResigning"
                        class="mt-3 text-sm text-lavoro-blue underline cursor-pointer">
                        Opnieuw ondertekenen
                    </button>
                </div>
                <div v-else-if="!serviceOrder.is_closed">
                    <TextInput v-model="signedByInput" label="Naam van tekeningsbevoegde" class="mb-4" />
                    <SignaturePad ref="signaturePadRef" v-model="signatureInput" />
                </div>
            </div>
        </div>

        <template #footer>
            <div v-if="!serviceOrder.is_closed && isEditing" class="flex justify-end">
                <button type="button" :disabled="!canConfirm" @click="triggerConfirm" :class="[
                    'px-4 py-2 text-sm font-semibold text-white rounded-md',
                    canConfirm ? 'bg-green-600 hover:bg-green-700 cursor-pointer' : 'bg-gray-300 dark:bg-slate-700 cursor-not-allowed'
                ]">
                    Akkoord
                </button>
            </div>
            <div v-else class="flex justify-end">
                <button type="button" @click="handleDialogUpdateOpen(false)"
                    class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer">
                    Sluiten
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, computed, watch, nextTick, onUnmounted } from 'vue';
import GLightbox from 'glightbox';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import RemarksComponent from '@/Components/RemarksComponent.vue';
import MaterialsWidget from '@/Components/Materials/MaterialsWidget.vue';
import TaskInstancesWidget from '@/Components/ServiceOrders/TaskInstancesWidget.vue';
import SignaturePad from '@/Components/UI/SignaturePad.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { useScrollLock } from '@/Composables/useScrollLock.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    serviceOrder: { type: Object, required: true },
    userRoles: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'confirm']);

const { lock: lockScroll, unlock: unlockScroll } = useScrollLock();

const isResigning = ref(false);
const signedByInput = ref(props.serviceOrder.signed_by || '');
const signatureInput = ref('');
const signaturePadRef = ref(null);

const isSigned = computed(() =>
    !!(props.serviceOrder.signed_by ?? '').toString().trim()
    && !!(props.serviceOrder.signature_base64 ?? '').toString().trim()
);
const isEditing = computed(() => !isSigned.value || isResigning.value);
const canConfirm = computed(() =>
    signedByInput.value.trim().length > 0
    && !!signaturePadRef.value
    && !signaturePadRef.value.isEmpty()
);

function startResigning() {
    isResigning.value = true;
    signedByInput.value = props.serviceOrder.signed_by || '';
}

function triggerConfirm() {
    if (!canConfirm.value) return;
    emit('confirm', {
        signed_by: signedByInput.value.trim(),
        signature_base64: signaturePadRef.value.getDataUrl(),
    });
}

function handleDialogUpdateOpen(newOpen) {
    if (newOpen) {
        emit('update:open', true);
        return;
    }
    if (!props.serviceOrder.is_closed && isEditing.value && canConfirm.value) {
        triggerConfirm();
        return;
    }
    isResigning.value = false;
    emit('update:open', false);
}

let lightbox = null;

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        isResigning.value = false;
        signedByInput.value = props.serviceOrder.signed_by || '';
        signatureInput.value = '';
        lockScroll();
        await nextTick();
        lightbox = GLightbox({
            selector: '.close-modal-lightbox',
            touchNavigation: true,
            loop: true,
            zoomable: true,
            closeEffect: 'none',
        });
        lightbox.on('open', lockScroll);
        lightbox.on('close', unlockScroll);
    } else {
        unlockScroll();
        lightbox?.destroy();
        lightbox = null;
    }
});

onUnmounted(() => {
    lightbox?.destroy();
});
</script>
```

- [ ] **Step 2: Lint and build check**

Run: `npm run fix:eslint`
Expected: no errors reported for the new file (auto-fixes formatting if needed).

Run: `npm run build`
Expected: build completes with no errors (this catches import/syntax mistakes — e.g. a bad prop name — even though we can't manually click through yet since the component isn't wired into any page).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/ServiceOrders/CloseServiceOrderModal.vue
git commit -m "feat: add read-only recap + signing modal for closing service orders"
```

---

## Task 3: Wire the modal into `ShowPage.vue`

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `CloseServiceOrderModal` from Task 2 (props `open`/`serviceOrder`/`userRoles`, emits `update:open`/`confirm`).
- Produces: nothing consumed by later tasks (this is the last code task; Task 4 is manual verification only).

- [ ] **Step 1: Replace the inline name/signature edit block with a read-only display**

In `resources/js/Pages/ServiceOrders/ShowPage.vue`, find this block (currently lines 379-419):

```vue
                            <div class="py-2">
                                <EditableTextField v-model="form.signed_by" class="w-full mb-5"
                                    :readonly="serviceOrder.is_closed || !hasPermission('serviceorder.close')"
                                    @update="val => { form.signed_by = val; }">
                                    <template #display>
                                        <span class="text-xs">{{
                                            serviceOrder.signed_by || `Klik hier om een naam van een tekeningsbevoegde
                                            in te voeren`
                                            }}</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="relative" v-if="!editingSignature">
                                <img :src="serviceOrder.signature_base64" alt="">
                                <PencilSquareIcon v-if="!serviceOrder.is_closed && hasPermission('serviceorder.close')"
                                    class="absolute top-2 right-2 transform w-5 h-5 text-gray-600 dark:text-slate-400 cursor-pointer hover:text-gray-500 dark:hover:text-slate-300"
                                    @click="editingSignature = true" />
                            </div>
                            <div v-if="editingSignature && !serviceOrder.is_closed">
                                <div class="mb-3">
                                    <h3 class="font-bold text-gray-900 dark:text-slate-100">Handtekening</h3>
                                    <p class="text-sm text-gray-500 dark:text-slate-400">Laat de klant hier ondertekenen
                                        ter akkoord van de
                                        uitgevoerde werkzaamheden.</p>
                                </div>
                                <SignaturePad ref="signaturePadRef" v-model="form.signature_base64"
                                    :readonly="serviceOrder.is_closed" />
                                <div class="mt-4 flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                    <div
                                        class="flex-shrink-0 w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center">
                                        <Shield class="w-5 h-5 text-white" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-900 dark:text-slate-100">Juridisch
                                            bindend</p>
                                        <p class="text-sm text-gray-500 dark:text-slate-400">Deze handtekening is
                                            juridisch bindend en wordt
                                            opgeslagen bij deze werkbon.</p>
                                    </div>
                                </div>
                            </div>
```

Replace it with:

```vue
                            <div class="py-2">
                                <span class="block text-xs font-semibold text-gray-500 dark:text-slate-400 mb-1">Naam</span>
                                <span class="text-sm dark:text-slate-200">{{ serviceOrder.signed_by || 'Nog niet ondertekend' }}</span>
                            </div>
                            <div v-if="serviceOrder.signature_base64" class="py-2">
                                <img :src="serviceOrder.signature_base64" alt="Handtekening" class="max-h-24">
                            </div>
```

- [ ] **Step 2: Add the "Bekijk & onderteken" button and gate the close button on `isSigned`**

Find this block (now directly below the block from Step 1):

```vue
                            <button
                                v-if="closedStageId !== null && !serviceOrder.is_closed && hasPermission('serviceorder.close')"
                                @click="closeViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-green-600 text-white hover:bg-green-700 cursor-pointer font-semibold text-sm flex items-center justify-center gap-2">
                                <Check class="w-5 h-5" />
                                Werkbon afsluiten
                            </button>
                            <button v-else-if="serviceOrder.is_closed && hasPermission('serviceorder.reopen')"
                                @click="reopenViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-blue-500 text-white hover:bg-blue-600 cursor-pointer font-semibold text-sm">
                                Werkbon heropenen
                            </button>
```

Replace it with:

```vue
                            <button v-if="hasPermission('serviceorder.close') || serviceOrder.is_closed"
                                @click="showCloseModal = true"
                                class="mt-4 w-full p-3 rounded-md ring-1 ring-gray-200 dark:ring-slate-600 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer font-semibold text-sm flex items-center justify-center gap-2">
                                <Signature class="w-5 h-5" />
                                {{ isSigned ? 'Bekijk overzicht' : 'Bekijk & onderteken' }}
                            </button>
                            <button
                                v-if="closedStageId !== null && isSigned && !serviceOrder.is_closed && hasPermission('serviceorder.close')"
                                @click="closeViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-green-600 text-white hover:bg-green-700 cursor-pointer font-semibold text-sm flex items-center justify-center gap-2">
                                <Check class="w-5 h-5" />
                                Werkbon afsluiten
                            </button>
                            <button v-else-if="serviceOrder.is_closed && hasPermission('serviceorder.reopen')"
                                @click="reopenViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-blue-500 text-white hover:bg-blue-600 cursor-pointer font-semibold text-sm">
                                Werkbon heropenen
                            </button>
```

- [ ] **Step 3: Mount the modal at the bottom of the template**

Find (lines 633-634):

```vue
    </DrawerComponent>
</template>
```

Replace it with:

```vue
    </DrawerComponent>

    <CloseServiceOrderModal v-model:open="showCloseModal" :service-order="serviceOrder" :user-roles="userRoles"
        @confirm="handleSignatureConfirm" />
</template>
```

- [ ] **Step 4: Update imports**

Find:

```js
import { DocumentTextIcon, PencilSquareIcon, CalendarDaysIcon, ClipboardDocumentListIcon, ExclamationTriangleIcon, ExclamationCircleIcon, InformationCircleIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline';
import { Shield, Check, TrashIcon } from '@lucide/vue';
```

Replace it with:

```js
import { DocumentTextIcon, CalendarDaysIcon, ClipboardDocumentListIcon, ExclamationTriangleIcon, ExclamationCircleIcon, InformationCircleIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline';
import { Check, TrashIcon } from '@lucide/vue';
```

Find:

```js
import SignaturePad from '@/Components/UI/SignaturePad.vue';
```

Delete that line entirely.

Find:

```js
import TaskInstancesWidget from '@/Components/ServiceOrders/TaskInstancesWidget.vue';
```

Replace it with:

```js
import TaskInstancesWidget from '@/Components/ServiceOrders/TaskInstancesWidget.vue';
import CloseServiceOrderModal from '@/Components/ServiceOrders/CloseServiceOrderModal.vue';
```

- [ ] **Step 5: Replace `editingSignature` with `showCloseModal`**

Find:

```js
const editingSignature = ref(!props.serviceOrder.is_closed);


const internalCustomers = computed(() =>
```

Replace it with:

```js
const showCloseModal = ref(false);


const internalCustomers = computed(() =>
```

- [ ] **Step 6: Simplify `closeViaStage` and add `handleSignatureConfirm`**

Find:

```js
function closeViaStage() {
    const has_unsaved_signature = editingSignature.value && signaturePadRef.value
        && !signaturePadRef.value.isEmpty() && !signaturePadRef.value.isSaved()
    if (has_unsaved_signature) {
        isClosing.value = true
        form.signature_base64 = signaturePadRef.value.getDataUrl()
    }
    if (!canClose.value) {
        alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.')
        isClosing.value = false
        return
    }
    if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
        isClosing.value = false
        return
    }
    if (has_unsaved_signature) {
        form.service_order_stage_id = props.closedStageId
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingSignature.value = false
                isClosing.value = false
                form.defaults()
            },
            onError: (errors) => {
                isClosing.value = false
                const msg = errors.service_order_stage_id || Object.values(errors)[0]
                if (msg) usePage().props.flash.error = msg
            },
        })
        return
    }
    onStageChange(props.closedStageId)
}
```

Replace it with:

```js
function closeViaStage() {
    if (!isSigned.value) {
        alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.')
        return
    }
    if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
        return
    }
    onStageChange(props.closedStageId)
}

function handleSignatureConfirm({ signed_by, signature_base64 }) {
    form.signed_by = signed_by
    form.signature_base64 = signature_base64
    form.put(`/serviceorders/${props.serviceOrder.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults()
            showCloseModal.value = false
        },
        onError: (errors) => {
            form.reset('signed_by', 'signature_base64')
            const msg = errors.signed_by || errors.signature_base64 || Object.values(errors)[0]
            if (msg) usePage().props.flash.error = msg
        },
    })
}
```

- [ ] **Step 7: Remove dead refs**

Find:

```js
const isReverting = ref(false);
const isClosing = ref(false);
const signaturePadRef = ref(null);
```

Replace it with:

```js
const isReverting = ref(false);
```

- [ ] **Step 8: Trim `signed_by`/`signature_base64` out of the autosave watcher**

Find:

```js
watch(
    [
        () => form.description,
        () => form.signed_by,
        () => form.signature_base64,
        () => form.external_purchaseorder_no,
        () => form.external_invoice_no,
        () => form.financial_comments,
        () => form.execution_location,
        () => form.actual_start_time,
        () => form.actual_end_time,
        () => form.customer_id,
        () => form.project_id,
        () => form.type,
    ],
    ([, , newSig], [, , oldSig]) => {
        if (isReverting.value) {
            isReverting.value = false;
            return;
        }
        if (isClosing.value) {
            return;
        }
        const signatureChanged = newSig !== oldSig;
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (signatureChanged) {
                    editingSignature.value = false;
                }
                form.defaults();
            },
            onError: () => {
                isReverting.value = true;
                form.reset();
                usePage().props.flash.error = usePage().props.errors;
            }
        });
    }
)
```

Replace it with:

```js
watch(
    [
        () => form.description,
        () => form.external_purchaseorder_no,
        () => form.external_invoice_no,
        () => form.financial_comments,
        () => form.execution_location,
        () => form.actual_start_time,
        () => form.actual_end_time,
        () => form.customer_id,
        () => form.project_id,
        () => form.type,
    ],
    () => {
        if (isReverting.value) {
            isReverting.value = false;
            return;
        }
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.defaults();
            },
            onError: () => {
                isReverting.value = true;
                form.reset();
                usePage().props.flash.error = usePage().props.errors;
            }
        });
    }
)
```

- [ ] **Step 9: Replace `canClose` with `isSigned` (server-truth based)**

Find:

```js
const canClose = computed(() => {
    const name = (form.signed_by ?? '').toString().trim();
    const sig = (form.signature_base64 ?? '').toString().trim();
    return name.length > 0 && sig.length > 0;
});
```

Replace it with:

```js
const isSigned = computed(() => {
    const name = (props.serviceOrder.signed_by ?? '').toString().trim();
    const sig = (props.serviceOrder.signature_base64 ?? '').toString().trim();
    return name.length > 0 && sig.length > 0;
});
```

- [ ] **Step 10: Lint and build check**

Run: `npm run fix:eslint`
Expected: no errors.

Run: `npm run build`
Expected: build succeeds. This is the point where any leftover reference to the removed `editingSignature`/`signaturePadRef`/`isClosing`/`canClose`/`Shield`/`PencilSquareIcon`/`SignaturePad` symbols would surface as an undefined-reference or unused-import failure — if it does, grep the file for the old name and remove the remaining reference before moving on.

- [ ] **Step 11: Regression check on the JS test suite**

Run: `npx vitest run`
Expected: existing specs (`RemarksComponent.spec.js`, `ImageUploadComponent.spec.js`, `useEventFeedback.spec.js`) still pass — none of them test `ShowPage.vue` or the new component, so this only confirms nothing else broke.

- [ ] **Step 12: Commit**

```bash
git add resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat: open the close/sign modal from the serviceorder close button"
```

---

## Task 4: Manual end-to-end verification

**Files:** none (verification only).

**Interfaces:** none produced — terminal task.

- [ ] **Step 1: Start the dev stack**

Run: `composer run dev`
Expected: Laravel server, queue worker, and Vite all start without errors.

- [ ] **Step 2: Open a service order that is not yet closed and not yet signed**

In the browser, navigate to `/serviceorders/{id}` for an order with `serviceorder.close` permission, no `signed_by`, no stage set to the closed stage.

Verify:
- Sidebar shows "Naam: Nog niet ondertekend", no signature image.
- Sidebar shows a "Bekijk & onderteken" button; no green "Werkbon afsluiten" button.
- Clicking "Bekijk & onderteken" opens the modal; background does not scroll while it's open (try scrolling the page behind it).
- Modal shows non-internal remarks, a 4-wide photo grid (clicking a photo opens the lightbox, and it closes cleanly), materials (read-only, no add form), task instances (read-only, no add/edit/sign controls).
- "Akkoord" button is disabled until both a name is typed and something is drawn on the pad.

- [ ] **Step 3: Verify the empty-signature guard**

With the name filled in but the pad empty, click outside the modal (backdrop) to dismiss it.

Verify: the modal closes, and reloading the page shows `signed_by` was **not** saved (sidebar still shows "Nog niet ondertekend"). This confirms an empty signature is never persisted.

- [ ] **Step 4: Sign and confirm via "Akkoord"**

Reopen the modal, type a name, draw a signature, click "Akkoord".

Verify:
- Modal closes.
- Sidebar now shows the name and signature image, and the green "Werkbon afsluiten" button has appeared.
- Reload the page — the signature persisted (Inertia's own state isn't enough; a page reload proves it round-tripped to the DB).

- [ ] **Step 5: Verify closing is blocked without a signature (defense in depth)**

Via `php artisan tinker`, temporarily null out `signed_by` on this same service order (`App\Models\ServiceOrder::find($id)->update(['signed_by' => null])`), then in the browser click "Werkbon afsluiten".

Verify: an alert appears client-side ("Vul zowel de naam als de handtekening in...") and the order does not close. Restore `signed_by` afterwards (or just re-sign through the UI) before continuing.

- [ ] **Step 6: Close the order**

With the signature restored, click "Werkbon afsluiten", confirm the browser `confirm()` dialog.

Verify: the order's stage changes to the closed stage, the page reflects `is_closed = true`, and the sidebar buttons update to show "Werkbon heropenen" (if permitted) and a read-only "Bekijk overzicht" entry point (no "Bekijk & onderteken" wording, no Akkoord button when reopened).

- [ ] **Step 7: Verify "opnieuw ondertekenen" on a still-open (not-yet-closed) signed order**

Find or create a different signed-but-not-closed order. Open the modal, click "Opnieuw ondertekenen", draw a new signature, click "Akkoord".

Verify: the new signature replaces the old one in the sidebar after a page reload.

- [ ] **Step 8: Final regression pass**

Run: `composer test`
Run: `npx vitest run`
Run: `npm run build`

Expected: all three pass/succeed with no errors.
