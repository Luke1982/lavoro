<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <CustomerHeaderComponent :customer="props.customer" />
                <ComboBox class="mt-4" :options="props.allCustomers" v-model="form.billing_customer_id"
                    label="Facturatieklant" placeholder="Kies naar welke klant de factuur moet"
                    @update:modelValue="updateCustomer" />
            </BoxComponent>
            <BoxComponent class="mt-6">
                <div class="flex mb-4">
                    <BellAlertIcon class="size-6 flex-none text-red-500 mr-2" />
                    <h2 class="font-regular text-xl">Apparaten die binnen 30 dagen verlopen</h2>
                </div>
                <AssetListGroupComponent :assetGroups="upcomingAssetsByType" />
                <div class="flex mb-4">
                    <BellSnoozeIcon class="size-6 flex-none text-yellow-500 mr-2" />
                    <h2 class="font-regular text-xl">Apparaten die na 30 dagen verlopen</h2>
                </div>
                <AssetListGroupComponent :assetGroups="nonUpcomingAssetsByType" />
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent>
                <div class="flex mb-4 border-b-1 border-gray-200 pb-2 justify-between">
                    <div class="flex">
                        <ClipboardDocumentListIcon class="size-6 flex-none text-gray-500 mr-2" />
                        <h2 class="font-regular text-xl">Werkbonnen</h2>
                    </div>
                    <PlusCircleIcon class="size-6 flex-none text-green-500 cursor-pointer hover:text-green-700"
                        @click="newServiceOrderForm.post(`/serviceorders`, { preserveScroll: true })"
                        v-tooltip="`Maak een nieuwe werkbon aan voor ${customer.name}`" />
                </div>
                <ServiceOrderRow v-for="serviceorder in customer.service_orders" v-bind:key="serviceorder.id"
                    :serviceorder="serviceorder" />
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import '@/Layouts/TwoThirdsOneThird.vue';
import '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BellAlertIcon, BellSnoozeIcon, ClipboardDocumentListIcon, PlusCircleIcon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListGroupComponent from '@/Components/AssetListGroupComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { useForm } from '@inertiajs/vue3';
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue';
import CustomerHeaderComponent from '@/Components/CustomerHeaderComponent.vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    upcomingAssetsByType: {
        type: Object,
        required: true,
    },
    nonUpcomingAssetsByType: {
        type: Object,
        required: true,
    },
    allCustomers: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    ...props.customer,
    billing_customer_id: props.customer.billing_customer_id || null,
});

const newServiceOrderForm = useForm({
    customer_id: props.customer.id,
});

const updateCustomer = () => {
    form.patch(`/customers/${props.customer.id}`)
};
</script>