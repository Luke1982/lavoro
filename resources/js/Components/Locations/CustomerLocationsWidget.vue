<template>
    <div>
        <div class="flex items-start sm:items-center justify-between mb-4">
            <div class="flex items-start sm:items-center gap-3">
                <div class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                    <MapPinIcon class="h-5 w-5 text-white" />
                </div>
                <div class="flex flex-col">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Locaties</h2>
                    <p class="text-xs text-slate-400 dark:text-slate-400">Fysieke locaties van deze klant.</p>
                </div>
            </div>
            <button v-if="canCreate" type="button" @click="openAddDrawer"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-lavoro-blue hover:bg-lavoro-blue/90 rounded-md transition-colors">
                <PlusIcon class="size-4" />
                <span class="hidden sm:inline">Locatie toevoegen</span>
            </button>
        </div>

        <div v-if="locations.length > 0" class="border-1 rounded-lavoro-sm border-gray-200/70">
            <div v-auto-animate>
                <div v-for="location in locations" :key="location.id"
                    class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 dark:border-slate-800 last:border-b-0">
                    <Link :href="`/locations/${location.id}`"
                        class="flex flex-1 items-center justify-between min-w-0 hover:opacity-80">
                        <div class="flex flex-col min-w-0">
                            <span class="font-semibold text-sm text-gray-900 dark:text-slate-100 truncate">{{ location.title }}</span>
                            <span class="text-xs text-gray-400 dark:text-slate-500 truncate">
                                {{ [location.location_code, location.address, location.city].filter(Boolean).join(' • ') }}
                            </span>
                        </div>
                        <ChevronRightIcon class="size-5 text-gray-400 dark:text-slate-500 shrink-0" />
                    </Link>
                    <TrashIcon v-if="canDelete"
                        class="size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer shrink-0"
                        @click="openDelete(location)" v-tooltip="'Locatie verwijderen'" />
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500 italic">Nog geen locaties.</p>

        <LocationDeleteModal v-model:open="deleteModalOpen" :location="deleteTarget"
            :other-locations="otherLocations" />

        <DrawerComponent v-if="canCreate" v-model="addDrawerOpen" title="Nieuwe locatie toevoegen"
            subtitle="Vul de gegevens in van de nieuwe locatie.">
            <div class="divide-y divide-gray-200 dark:divide-slate-700">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel <span class="text-red-500">*</span></label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="addForm.title" :has-error="Boolean(addForm.errors.title)"
                            :error-message="addForm.errors.title" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Locatiecode</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="addForm.location_code" :has-error="Boolean(addForm.errors.location_code)"
                            :error-message="addForm.errors.location_code" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Adres <span class="text-red-500">*</span></label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="addForm.address" :has-error="Boolean(addForm.errors.address)"
                            :error-message="addForm.errors.address" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Postcode</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="addForm.postal_code" :has-error="Boolean(addForm.errors.postal_code)"
                            :error-message="addForm.errors.postal_code" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Plaats</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="addForm.city" :has-error="Boolean(addForm.errors.city)"
                            :error-message="addForm.errors.city" />
                    </div>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="closeAddDrawer"
                        class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                        Annuleren
                    </button>
                    <button type="button" @click="submitLocation" :disabled="addForm.processing"
                        class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { MapPinIcon, PlusIcon, ChevronRightIcon, TrashIcon } from '@heroicons/vue/24/outline';
import TextInput from '@/Components/UI/TextInput.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import LocationDeleteModal from '@/Components/Locations/LocationDeleteModal.vue';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    customerId: { type: Number, required: true },
    locations: { type: Array, default: () => [] },
});

const canCreate = hasPermission('location.create');
const canDelete = hasPermission('location.delete');

const addDrawerOpen = ref(false);
const addForm = useForm({
    customer_id: props.customerId,
    title: '',
    location_code: '',
    address: '',
    postal_code: '',
    city: '',
});

function openAddDrawer() {
    addForm.reset('title', 'location_code', 'address', 'postal_code', 'city');
    addForm.clearErrors();
    addDrawerOpen.value = true;
}

function closeAddDrawer() {
    addDrawerOpen.value = false;
    addForm.clearErrors();
}

function submitLocation() {
    addForm.post('/locations', {
        preserveScroll: true,
        onSuccess: () => closeAddDrawer(),
    });
}

const deleteModalOpen = ref(false);
const deleteTarget = ref(null);
const otherLocations = ref([]);

function openDelete(location) {
    deleteTarget.value = location;
    otherLocations.value = props.locations.filter(l => l.id !== location.id);
    deleteModalOpen.value = true;
}
</script>
