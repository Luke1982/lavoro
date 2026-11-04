<template>
    <div>
        <div class="flex mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-2 justify-between items-center">
            <div class="flex items-center">
                <div class="flex-none flex items-center justify-center size-10 rounded-lavoro-sm bg-lavoro-blue/10 mr-3">
                    <MapPinIcon class="size-6 text-lavoro-blue stroke-2" />
                </div>
                <h2 class="font-semibold text-base dark:text-slate-200">Locaties</h2>
            </div>
            <button v-if="canCreate" type="button" @click="openAddDrawer"
                class="flex flex-none items-center gap-1.5 rounded-md bg-lavoro-blue px-3 py-2 text-sm font-medium text-white transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-lavoro-blue focus:ring-offset-2 dark:focus:ring-offset-slate-900">
                <PlusIcon class="size-4" />
                <span class="hidden sm:inline">Locatie toevoegen</span>
            </button>
        </div>

        <PagedList v-if="locations.length" v-slot="{ items }" :items="locations" :search-keys="searchKeys"
            label="locaties" search-placeholder="Zoek op naam, code of adres..." storage-key="customerLocationsPerPage">
            <div class="border-1 rounded-lavoro-sm border-gray-200/70" v-auto-animate>
                <div v-for="location in items" :key="location.id"
                    class="flex items-center gap-3 px-3 py-2 border-b border-gray-100 dark:border-slate-800 last:border-b-0">
                    <button type="button" @click="openMapModal(location)" :disabled="!formatAddress(location)"
                        class="flex flex-none items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10 text-lavoro-blue transition enabled:hover:bg-lavoro-blue/20 disabled:opacity-40"
                        v-tooltip="formatAddress(location) ? 'Toon op de kaart' : ''">
                        <MapPinIcon class="size-5" />
                    </button>
                    <Link :href="`/locations/${location.id}`"
                        class="flex flex-1 items-center justify-between gap-2 min-w-0 hover:opacity-80">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-slate-100 truncate">{{ location.title }}</p>
                            <p class="text-xs text-gray-500 dark:text-slate-400 truncate">{{ formatAddress(location) || '—' }}</p>
                        </div>
                        <ChevronRightIcon class="size-5 text-gray-400 dark:text-slate-500 shrink-0" />
                    </Link>
                    <TrashIcon v-if="canDelete"
                        class="size-5 text-red-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer shrink-0"
                        @click="openDelete(location)" v-tooltip="'Locatie verwijderen'" />
                </div>
            </div>
        </PagedList>
        <p v-else class="text-sm text-gray-400 dark:text-slate-500 italic">Nog geen locaties.</p>

        <LocationDeleteModal v-model:open="deleteModalOpen" :location="deleteTarget"
            :other-locations="otherLocations" />

        <ModalDialog v-model:open="mapModalOpen" :title="mapModalLocation?.title ?? 'Locatie'"
            max-width-class="w-full sm:max-w-3xl">
            <div class="overflow-hidden rounded-lavoro-sm">
                <OpenStreetMapWidget v-if="mapModalLocation" :key="mapModalLocation.id"
                    :address="formatAddress(mapModalLocation)" height-class="h-[60vh]" />
            </div>
            <p v-if="mapModalLocation" class="mt-3 text-sm text-gray-500 dark:text-slate-400">
                {{ formatAddress(mapModalLocation) || 'Geen adres bekend' }}
            </p>
        </ModalDialog>

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
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import OpenStreetMapWidget from '@/Components/OpenStreetMapWidget.vue';
import PagedList from '@/Components/UI/PagedList.vue';
import { formatAddress, hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    customerId: { type: Number, required: true },
    locations: { type: Array, default: () => [] },
});

const canCreate = hasPermission('location.create');
const canDelete = hasPermission('location.delete');

/** The columns Location::scopeMatchesText searches, so this list finds what every other location search finds. */
const searchKeys = ['title', 'location_code', 'address', 'postal_code', 'city', 'country'];

const mapModalOpen = ref(false);
const mapModalLocation = ref(null);
function openMapModal(location) {
    mapModalLocation.value = location;
    mapModalOpen.value = true;
}

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
