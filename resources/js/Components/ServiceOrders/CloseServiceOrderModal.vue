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
                <MaterialsWidget :service-order-id="serviceOrder.id" :materials="materials"
                    :freeform-materials="freeformMaterials" :all-materials="[]" :categories="[]"
                    :usage-units="[]" :is-closed="true" :sent-to-administration="serviceOrder.sent_to_administration"
                    :type="serviceOrder.type" />
            </div>

            <div>
                <TaskInstancesWidget :service-order-id="serviceOrder.id" :instances="allTaskInstances"
                    :available-tasks="[]" :products="[]" :user-roles="userRoles" :is-closed="true" :boxed="false" />
            </div>

            <div class="border-t border-gray-200 dark:border-slate-700 pt-4">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-slate-100">Werkzaamheden gereed</h3>
                        <p class="text-sm text-gray-500 dark:text-slate-400">
                            {{ serviceOrder.is_closed
                                ? 'De werkbon is gesloten, dit kan niet meer worden gewijzigd.'
                                : 'Zet uit als er op locatie nog werk te doen is.' }}
                        </p>
                    </div>
                    <SwitchComponent :model-value="serviceOrder.work_completed" :disabled="serviceOrder.is_closed"
                        @update:modelValue="(v) => emit('update-work-completed', v)" />
                </div>
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
                    <SignaturePad ref="signaturePadRef" />
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
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { useScrollLock } from '@/Composables/useScrollLock.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    serviceOrder: { type: Object, required: true },
    allTaskInstances: { type: Array, default: () => [] },
    materials: { type: Array, default: () => [] },
    freeformMaterials: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'confirm', 'update-work-completed']);

const { lock: lockScroll, unlock: unlockScroll } = useScrollLock();

const isResigning = ref(false);
const didLock = ref(false);
const signedByInput = ref(props.serviceOrder.signed_by || '');
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
        lockScroll();
        didLock.value = true;
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
        didLock.value = false;
        lightbox?.destroy();
        lightbox = null;
    }
});

onUnmounted(() => {
    if (didLock.value) {
        unlockScroll();
        didLock.value = false;
    }
    lightbox?.destroy();
});
</script>
