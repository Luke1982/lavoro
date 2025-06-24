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
            </BoxComponent>
            <BoxComponent class="mt-6">
                <h2 class="font-semibold mb-4 text-xl">Apparaten die binnen 30 dagen verlopen</h2>
                <AssetListComponent :assets="customer.upcoming_assets" />
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
import { BuildingOffice2Icon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListComponent from '@/Components/AssetListComponent.vue';

defineProps({
    customer: {
        type: Object,
        required: true,
    },
});
</script>