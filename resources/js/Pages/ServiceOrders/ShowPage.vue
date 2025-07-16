<template>
    <BoxComponent>
        <h1 class="text-2xl font-bold mb-4 text-center uppercase">Werkbon</h1>
        <div class="grid grid-cols-12 gap-y-2 border-b border-gray-200 pb-4">
            <div class="col-span-2 text-xs">
                Naam klant
            </div>
            <div class="col-span-4">
                <Link :href="`/customers/${serviceOrder.customer.id}`" class="underline">
                {{ serviceOrder.customer.name }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Adres
            </div>
            <div class="col-span-4">
                <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank" class="underline">{{
                    serviceOrder.customer.address
                }}, {{
                        serviceOrder.customer.postal_code }} {{
                        serviceOrder.customer.city }}
                </a>
            </div>
            <div class="col-span-2 text-xs">
                Naam contactpersoon
            </div>
            <div class="col-span-4">
                <EditableTextField :value="form.signed_by" class="w-full"
                    @update="val => { form.signed_by = val; updateServiceOrder(); }" />
            </div>
        </div>
        <h1 class="text-xl font-bold my-4 text-center uppercase">Keuringen</h1>
        <div class="grid grid-cols-12 mt-4">
            <div class="col-span-2 text-xs">
                Kies een machine om te keuren
            </div>
            <div class="col-span-10 flex">
                <ComboBox :options="internalAssets" class="flex-grow" v-model="assetToCheck" />
                <button @click="addServiceJob"
                    class="ml-2 px-4 py-1.5 bg-indigo-600 text-white rounded hover:bg-indigo-700 cursor-pointer text-sm">
                    Keuren
                </button>
            </div>
        </div>
        <div class="grid-cols-6 grid mt-3 text-xs gap-4 font-bold border-b-1 border-gray-300 pb-3">
            <div class="col-span-1">Machine</div>
            <div class="col-span-1">Uitkomst</div>
            <div class="col-span-1">Tijdelijke goedkeur</div>
            <div class="col-span-1">Afgerond op</div>
            <div class="col-span-2">Omschrijving</div>
        </div>
        <ServiceJobRow v-for="job in serviceOrder.servicejobs" :key="job.id" :servicejob="job" class="mt-4"
            :asset="job.asset" />
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ServiceJobRow from '@/Components/ServiceJobRow.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import { mapsLinkFromCustomer, nlDate } from '@/Utilities/Utilities';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    }
});

const internalAssets = props.serviceOrder.customer.assets.map((asset) => {
    return {
        id: asset.id,
        name: `${asset.product.brand.name} ${asset.product.model} (${asset.serial_number}), ${asset.status}. Verloopt op ${nlDate(asset.next_service_date)}`,
    };
});

const assetToCheck = ref(internalAssets[0]?.id || null);

const form = useForm({
    ...props.serviceOrder
});

const newServicejobForm = useForm({
    service_order_id: props.serviceOrder.id,
    asset_id: assetToCheck.value,
    outcome: 'Nog geen uitkomst',
});

const addServiceJob = () => {
    newServicejobForm.asset_id = assetToCheck.value;
    newServicejobForm.post(`/servicejobs`, {
        preserveScroll: true
    })
};

const updateServiceOrder = () => {
    form.put(`/serviceorders/${props.serviceOrder.id}`, {
        preserveScroll: true,
    });
};
</script>