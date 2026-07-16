<template>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 mb-6">
        <div class="inline sm:flex items-center">
            <Link href="/locations" class="text-slate-400 text-sm font-medium inline">Locaties</Link>
            <ChevronRightIcon class="size-4 text-gray-400 mx-2 inline" />
            <Link v-if="location.customer" :href="`/customers/${location.customer.id}`"
                class="text-slate-400 text-sm font-medium inline">{{ location.customer.name }}</Link>
            <ChevronRightIcon v-if="location.customer" class="size-4 text-gray-400 mx-2 inline" />
            <span class="text-slate-800 dark:text-slate-200 font-bold text-sm inline">{{ location.title }}</span>
        </div>
        <button v-if="canDelete" type="button" @click="deleteModalOpen = true"
            class="px-3 py-1.5 text-sm font-medium bg-white text-red-600 ring-gray-200 ring-1 rounded-full cursor-pointer">
            <TrashIcon class="size-5" />
        </button>
    </div>

    <div class="flex flex-col sm:flex-row mt-2 mb-4">
        <div class="flex flex-col justify-around flex-grow items-start py-2 sm:py-6 gap-2">
            <h1 class="text-2xl font-bold">{{ location.title }}</h1>
            <p class="text-sm text-gray-500 dark:text-slate-400">
                Locatiecode: {{ location.location_code }}
                <span v-if="location.customer"> — klant:
                    <Link :href="`/customers/${location.customer.id}`" class="hover:underline">{{ location.customer.name }}</Link>
                </span>
            </p>
        </div>
    </div>

    <LocationDeleteModal v-model:open="deleteModalOpen" :location="location" :other-locations="otherLocations" />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <MapPinIcon class="size-5 text-gray-500 mr-2" />
                    <span class="text-md font-bold">Locatiegegevens</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="flex flex-col gap-6">
                        <EditableTextField v-model="form.title" type="input" label="Titel"
                            :error="form.errors.title" @revert="form.clearErrors('title')" />
                        <EditableTextField v-model="form.location_code" type="input" label="Locatiecode"
                            :error="form.errors.location_code" @revert="form.clearErrors('location_code')" />
                        <EditableTextField v-model="form.address" type="input" label="Adres"
                            :error="form.errors.address" @revert="form.clearErrors('address')" />
                    </div>
                    <div class="flex flex-col gap-6">
                        <EditableTextField v-model="form.postal_code" type="input" label="Postcode"
                            :error="form.errors.postal_code" @revert="form.clearErrors('postal_code')" />
                        <EditableTextField v-model="form.city" type="input" label="Plaats"
                            :error="form.errors.city" @revert="form.clearErrors('city')" />
                        <EditableTextField v-model="form.country" type="input" label="Land"
                            :error="form.errors.country" @revert="form.clearErrors('country')" />
                    </div>
                </div>
            </BoxComponent>

            <BoxComponent>
                <div class="flex items-center mb-3 pb-3 border-b border-gray-200 dark:border-slate-700">
                    <PuzzlePieceIcon class="size-5 text-gray-500 mr-2" />
                    <span class="text-sm font-medium">Machines op deze locatie</span>
                </div>
                <p v-if="!location.assets?.length" class="text-sm text-gray-400 italic">Geen machines op deze locatie.</p>
                <ul v-else class="divide-y divide-gray-100 dark:divide-slate-800">
                    <li v-for="asset in location.assets" :key="asset.id" class="py-2">
                        <Link :href="`/assets/${asset.id}`" class="flex flex-col hover:underline">
                            <span class="text-sm font-medium text-gray-900 dark:text-slate-100">
                                {{ [asset.product?.brand?.name, asset.product?.model].filter(Boolean).join(' ') || `Machine #${asset.id}` }}
                            </span>
                            <span v-if="asset.serial_number" class="text-xs text-gray-400 dark:text-slate-500">
                                Serienummer: {{ asset.serial_number }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </BoxComponent>
        </div>

        <div class="space-y-6">
            <BoxComponent v-if="form.address" padding="p-0" extra-classes="overflow-hidden">
                <OpenStreetMapWidget :key="`${form.address},${form.postal_code} ${form.city}`"
                    :address="`${form.address}, ${form.postal_code} ${form.city}`" />
            </BoxComponent>
        </div>
    </div>
</template>

<script setup>
import { ChevronRightIcon, MapPinIcon, PuzzlePieceIcon } from '@heroicons/vue/24/outline';
import { TrashIcon } from '@lucide/vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import { useCustomerLocations } from '@/Composables/useCustomerLocations';
import BoxComponent from '@/Components/BoxComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import OpenStreetMapWidget from '@/Components/OpenStreetMapWidget.vue';
import LocationDeleteModal from '@/Components/Locations/LocationDeleteModal.vue';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    location: { type: Object, required: true },
});

const canDelete = hasPermission('location.delete');
const deleteModalOpen = ref(false);

const { locations: customerLocations, load: loadCustomerLocations } = useCustomerLocations();
const otherLocations = computed(() =>
    customerLocations.value
        .filter(l => l.id !== props.location.id)
        .map(l => ({ id: l.id, title: l.name }))
);

onMounted(() => {
    if (canDelete && props.location.customer) {
        loadCustomerLocations(props.location.customer.id);
    }
});

const form = useForm({
    title:         props.location.title,
    location_code: props.location.location_code,
    address:       props.location.address,
    postal_code:   props.location.postal_code,
    city:          props.location.city,
    country:       props.location.country,
});

watch([
    () => form.title,
    () => form.location_code,
    () => form.address,
    () => form.postal_code,
    () => form.city,
    () => form.country,
], () => {
    form.patch(`/locations/${props.location.id}`, { preserveScroll: true });
});
</script>
