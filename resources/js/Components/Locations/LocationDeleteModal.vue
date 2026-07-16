<template>
    <ModalDialog :open="open" :title="`Locatie verwijderen: ${location?.title ?? ''}`" max-width-class="sm:max-w-lg"
        @update:open="$emit('update:open', $event)">
        <div v-if="totalCount > 0" class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-slate-300">
                Er {{ totalCount === 1 ? 'is' : 'zijn' }} nog {{ summary }} gekoppeld aan deze locatie.
                Wat moet daarmee gebeuren?
            </p>

            <div class="space-y-2">
                <button type="button" @click="disposition = 'detach'"
                    :class="disposition === 'detach' ? 'border-lavoro-blue ring-2 ring-lavoro-blue/30' : 'border-gray-200 dark:border-slate-700'"
                    class="w-full text-left px-4 py-3 rounded-lg border transition">
                    <span class="block text-sm font-medium text-gray-900 dark:text-slate-100">Loskoppelen</span>
                    <span class="block text-xs text-gray-500 dark:text-slate-400">Blijven bij de klant, zonder locatie.</span>
                </button>

                <button type="button" @click="disposition = 'move'" :disabled="!otherLocations.length"
                    :class="[
                        disposition === 'move' ? 'border-lavoro-blue ring-2 ring-lavoro-blue/30' : 'border-gray-200 dark:border-slate-700',
                        !otherLocations.length ? 'opacity-50 cursor-not-allowed' : '',
                    ]"
                    class="w-full text-left px-4 py-3 rounded-lg border transition">
                    <span class="block text-sm font-medium text-gray-900 dark:text-slate-100">Verplaats naar andere locatie</span>
                    <span class="block text-xs text-gray-500 dark:text-slate-400">
                        {{ otherLocations.length ? 'Kies een andere locatie van deze klant.' : 'Geen andere locatie beschikbaar.' }}
                    </span>
                </button>

                <div v-if="disposition === 'move'" class="pl-1">
                    <ComboBox :options="locationOptions" v-model="targetLocationId" placeholder="Kies een locatie" />
                    <p v-if="moveError" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ moveError }}</p>
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-gray-600 dark:text-slate-300">
            Weet je zeker dat je deze locatie wilt verwijderen?
        </p>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="$emit('update:open', false)"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="confirmDelete" :disabled="processing"
                    class="px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Verwijderen
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    location: { type: Object, default: null },
    otherLocations: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'deleted']);

const disposition = ref('detach');
const targetLocationId = ref(null);
const processing = ref(false);
const moveError = ref('');

const assetCount = computed(() => props.location?.assets_count ?? props.location?.assets?.length ?? 0);
const serviceOrderCount = computed(() => props.location?.service_orders_count ?? 0);
const totalCount = computed(() => assetCount.value + serviceOrderCount.value);
const locationOptions = computed(() => props.otherLocations.map(l => ({ id: l.id, name: l.title })));

const summary = computed(() => {
    const parts = [];
    if (assetCount.value) parts.push(`${assetCount.value} ${assetCount.value === 1 ? 'machine' : 'machines'}`);
    if (serviceOrderCount.value) parts.push(`${serviceOrderCount.value} ${serviceOrderCount.value === 1 ? 'werkbon' : 'werkbonnen'}`);
    return parts.join(' en ');
});

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        disposition.value = 'detach';
        targetLocationId.value = null;
        moveError.value = '';
    }
});

function confirmDelete() {
    if (!props.location) return;
    if (totalCount.value > 0 && disposition.value === 'move' && !targetLocationId.value) {
        moveError.value = 'Kies een locatie om de machines en werkbonnen naartoe te verplaatsen.';
        return;
    }

    const data = totalCount.value > 0
        ? { disposition: disposition.value, target_location_id: disposition.value === 'move' ? targetLocationId.value : null }
        : { disposition: 'detach' };

    processing.value = true;
    router.delete(`/locations/${props.location.id}`, {
        data,
        preserveScroll: true,
        onSuccess: () => emit('deleted'),
        onFinish: () => {
            processing.value = false;
            emit('update:open', false);
        },
    });
}
</script>
