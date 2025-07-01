<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex">
                    <BuildingOffice2Icon
                        class="size-12 flex-none rounded-lg bg-white object-cover ring-1 ring-gray-900/10 p-2 mb-6" />
                    <div class="flex flex-col ml-4">
                        <h1 class="text-l font-semibold">{{ customer.name }}</h1>
                        <div class="flex text-sm text-gray-500 gap-x-2">
                            <a target="_blank" class="underline" v-if="customer.website" :href="customer.website">{{
                                customer.website
                                }}</a>
                            <span v-if="customer.website && customer.email">&bull;</span>
                            <a class="underline" :href="`mailto:${customer.email}`" v-if="customer.email">{{
                                customer.email
                                }}</a>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap">
                    <div class="w-100 lg:w-1/2">
                        <h3 class="text-xs font-bold mb-2">Bezoekadres</h3>
                        <span class="text-sm text-gray-800">{{ customer.address }}<br>{{ customer.postal_code }}<span
                                v-if="customer.city">,</span> {{
                                    customer.city
                                }}</span>
                    </div>
                    <div class="w-100 lg:w-1/2">
                        <h3 class="text-xs font-bold mb-2">Postadres</h3>
                        <span class="text-sm text-gray-800">{{ customer.postal_address }}<br>{{
                            customer.postal_postal_code
                            }}<span v-if="customer.postal_city">,</span> {{
                                customer.postal_city
                            }}</span>
                    </div>
                </div>
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
            <h2 class="text-xl font-semibold mb-2">Actions</h2>
            <ul>
                <li><a href="#" class="text-blue-500 hover:underline">Edit Customer</a></li>
                <li><a href="#" class="text-blue-500 hover:underline">Delete Customer</a></li>
                <li><a href="#" class="text-blue-500 hover:underline">View Orders</a></li>
            </ul>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import '@/Layouts/TwoThirdsOneThird.vue';
import '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BellAlertIcon, BellSnoozeIcon, BuildingOffice2Icon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListGroupComponent from '@/Components/AssetListGroupComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { useForm } from '@inertiajs/vue3';

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

const updateCustomer = () => {
    form.patch(`/customers/${props.customer.id}`)
};
</script>