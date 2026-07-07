# Design: Service Order Close/Sign Modal

**Date:** 2026-07-07
**Status:** Approved

## Overview

Change the "close serviceorder" flow so that instead of directly closing the order, the button opens a review-and-sign modal. The modal shows a read-only recap (non-internal remarks, non-internal images in a 4-wide grid, materials, task instances) and the customer signing step (name + `SignaturePad`), which moves out of the sidebar into the modal. The actual "Werkbon afsluiten" (close) action becomes a separate button that only appears once a name and signature are on file.

## Data / Backend

No schema changes. `signed_by` and `signature_base64` already exist on `ServiceOrder` and are saved through the existing `PUT /serviceorders/{serviceorder}` endpoint (`ServiceOrderUpdateRequest` / `ServiceOrderController::update`).

### `ServiceOrderUpdateRequest::withValidator`

Add a server-side backstop next to the existing incomplete-tasks / min-images checks (inside the `if (! $new_stage->is_closed_state) return;` guard): if the service order's **persisted** `signed_by` or `signature_base64` is blank, block the stage transition to a closed stage with an error on `service_order_stage_id`. This uses the model's current DB state (not request input), because the close request itself only carries `customer_id` + `service_order_stage_id` — the signature is saved earlier, via a separate request, when the modal's "Akkoord" step completes.

```php
if (blank($serviceorder->signed_by) || blank($serviceorder->signature_base64)) {
    $validator->errors()->add(
        'service_order_stage_id',
        'De werkbon moet ondertekend zijn door de klant voordat deze gesloten kan worden.'
    );
}
```

## Frontend

### `ShowPage.vue` — sidebar box ("Afronding en handtekening")

- Remove the inline `EditableTextField` for `signed_by` and the inline `SignaturePad` + "Juridisch bindend" block.
- Replace with a **read-only** display: name text (or a placeholder like "Nog niet ondertekend") and, if present, `<img :src="serviceOrder.signature_base64">`.
- Description / actual start time / actual end time fields are unchanged (still editable inline, still autosaved through the existing shared `form` + watcher).
- New computed `isSigned` (replaces `canClose`), derived from `props.serviceOrder.signed_by` / `signature_base64` (server truth, not the local `form`):
  ```js
  const isSigned = computed(() => {
      const name = (props.serviceOrder.signed_by ?? '').toString().trim();
      const sig = (props.serviceOrder.signature_base64 ?? '').toString().trim();
      return name.length > 0 && sig.length > 0;
  });
  ```
- Buttons, in order:
  1. **"Bekijk & onderteken"** (not yet signed) / **"Bekijk overzicht"** (signed) — shown when `hasPermission('serviceorder.close') || serviceOrder.is_closed`. Opens `CloseServiceOrderModal` (`showCloseModal.value = true`).
  2. **"Werkbon afsluiten"** (green) — shown when `closedStageId !== null && isSigned && !serviceOrder.is_closed && hasPermission('serviceorder.close')`. Handler simplifies to:
     ```js
     function closeViaStage() {
         if (!isSigned.value) {
             alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.');
             return;
         }
         if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
             return;
         }
         onStageChange(props.closedStageId);
     }
     ```
     (No more inline signature-saving logic — `onStageChange` already exists and is reused as-is.)
  3. "Werkbon heropenen" (unchanged) / "onvolledig markeren" (unchanged).

- Remove now-dead code: `editingSignature` ref, `signaturePadRef` ref, `isClosing` ref, the `PencilSquareIcon`/`Shield`/`SignaturePad` imports, and `signed_by`/`signature_base64` from the big autosave `watch([...])` array (along with the `newSig`/`oldSig`/`signatureChanged` handling it drove).
- Add `showCloseModal = ref(false)` and mount `<CloseServiceOrderModal v-model:open="showCloseModal" :service-order="serviceOrder" :user-roles="userRoles" @confirm="handleSignatureConfirm" />` near the existing `DrawerComponent` at the bottom of the template.
- New `handleSignatureConfirm({ signed_by, signature_base64 })`: sets those two fields on the **existing shared `form`** and calls `form.put(...)`, closing the modal (`showCloseModal.value = false`) on success. Reuses the shared form (not a fresh minimal one) specifically so users with full `update` permission don't hit the `customer_id` "required" validation rule that a partial payload would trip.
  ```js
  function handleSignatureConfirm({ signed_by, signature_base64 }) {
      form.signed_by = signed_by;
      form.signature_base64 = signature_base64;
      form.put(`/serviceorders/${props.serviceOrder.id}`, {
          preserveScroll: true,
          onSuccess: () => { form.defaults(); showCloseModal.value = false; },
          onError: (errors) => {
              form.reset('signed_by', 'signature_base64');
              const msg = errors.signed_by || errors.signature_base64 || Object.values(errors)[0];
              if (msg) usePage().props.flash.error = msg;
          },
      });
  }
  ```

### New component: `resources/js/Components/ServiceOrders/CloseServiceOrderModal.vue`

**Props:** `open` (Boolean, required), `serviceOrder` (Object, required), `userRoles` (Array, default `[]`).
**Emits:** `update:open`, `confirm` (payload `{ signed_by, signature_base64 }`).

Body (wrapped in `ModalDialog`, `max-width-class="sm:max-w-3xl"`, scrollable inner container):

1. **Remarks** — `<RemarksComponent :comments="serviceOrder.remarks" :disabled="true" remarkable-type="App\\Models\\ServiceOrder" :remarkable-id="serviceOrder.id" />` (non-internal only; `disabled` hides the add-comment box, list stays visible).
2. **Images** — 4-column grid (`grid grid-cols-4 gap-2`) of `serviceOrder.images` (non-internal), each an `aspect-square` `<a>` with a distinct lightbox class (e.g. `close-modal-lightbox`, **not** `.glightbox`, to avoid colliding with the page-level `ImageUploadComponent` lightbox instances). A dedicated `GLightbox` instance is created/destroyed each time the modal opens/closes (its DOM only exists while `open` is true, since `ModalDialog`'s `TransitionRoot` unmounts contents on close).
3. **Materials** — `<MaterialsWidget :service-order-id="serviceOrder.id" :materials="serviceOrder.materials" :freeform-materials="serviceOrder.freeform_materials" :all-materials="[]" :categories="[]" :usage-units="[]" :is-closed="true" :sent-to-administration="serviceOrder.sent_to_administration" :type="serviceOrder.type" />` — forcing `is-closed="true"` regardless of actual state reuses its existing read-only rendering (no add-forms, plain text instead of editable fields).
4. **Task instances** — `<TaskInstancesWidget :service-order-id="serviceOrder.id" :instances="serviceOrder.task_instances" :available-tasks="[]" :products="[]" :user-roles="userRoles" :is-closed="true" />` — same trick, forces the fully read-only rendering path.
5. **Signing section:**
   - If already signed and not re-signing: static `signed_by` text + `<img :src="serviceOrder.signature_base64">`, with an "Opnieuw ondertekenen" link (hidden once `serviceOrder.is_closed`) that switches into edit mode.
   - Otherwise (not yet signed, or re-signing): a `TextInput` for the name + a blank `SignaturePad`.

Internal computeds driving the above:
```js
const isSigned = computed(() => !!props.serviceOrder.signed_by?.trim() && !!props.serviceOrder.signature_base64?.trim());
const isEditing = computed(() => !isSigned.value || isResigning.value);
const canConfirm = computed(() =>
    !!signedByInput.value.trim() && !!signaturePadRef.value && !signaturePadRef.value.isEmpty()
);
```

Footer:
- Editing mode: single **"Akkoord"** button, disabled until name is non-blank and the pad is non-empty (`canConfirm`). Click calls `triggerConfirm()`, which reads `signaturePadRef.getDataUrl()` and emits `confirm`.
- Read-only mode (already signed, not re-signing): single **"Sluiten"** button that just dismisses.

Closing behavior (`@update:open` from the nested `ModalDialog`, covering backdrop click / Escape / the mobile X):
```js
function handleDialogUpdateOpen(newOpen) {
    if (newOpen) { emit('update:open', true); return; }
    if (isEditing.value && canConfirm.value) {
        triggerConfirm();  // parent saves, then flips `open` to false on success
        return;
    }
    isResigning.value = false;
    emit('update:open', false);
}
```
This means: if there's a name and a drawn (non-empty) signature pending, *any* way of closing the modal saves it (matches "closing the modal should save the signature"); if the pad is empty, closing just discards — an empty signature is never sent. The read-only-mode "Sluiten" footer button calls the same `handleDialogUpdateOpen(false)` (harmless there since `isEditing` is already `false`, so it just dismisses).

Scroll lock: `useScrollLock()` (existing composable, already used by `ImageUploadComponent` and planner components) — `lock()` when `open` becomes true, `unlock()` when it becomes false, in a `watch(() => props.open, ...)`. This watcher also resets `isResigning` to `false` and re-syncs `signedByInput` from `props.serviceOrder.signed_by` each time the modal opens, and is where the per-open `GLightbox` instance is created/destroyed.

## State matrix (sidebar buttons)

| State | "Bekijk & onderteken/overzicht" | "Werkbon afsluiten" | "Werkbon heropenen" |
|---|---|---|---|
| Not closed, not signed, has `serviceorder.close` | ✅ ("Bekijk & onderteken") | ❌ | ❌ |
| Not closed, signed, has `serviceorder.close` | ✅ ("Bekijk overzicht") | ✅ | ❌ |
| Closed | ✅ ("Bekijk overzicht", no permission needed) | ❌ | ✅ (if `serviceorder.reopen`) |

## Out of Scope

- Editing remarks/images/materials/tasks from within the modal — it is a read-only recap; editing continues to happen where it already does (sidebar / main column).
- Clearing `signed_by`/`signature_base64` on reopen — unchanged existing behavior (only `closed_on` is cleared).
- Automated tests (per project convention, not written unless asked).
