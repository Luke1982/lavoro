<template>
    <div :class="['flex', layout === 'vertical' ? 'flex-col' : 'flex-row flex-wrap']">
        <div :class="['flex', layout === 'vertical' ? '' : 'w-full lg:w-1/2']">
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
        <div :class="['flex flex-wrap', layout === 'vertical' ? '' : 'w-full lg:w-1/2']">
            <div class="w-1/2">
                <h3 class="text-xs font-bold mb-2">Bezoekadres</h3>
                <span class="text-sm text-gray-800">{{ customer.address }}<br>{{ customer.postal_code }}<span
                        v-if="customer.city">,</span> {{
                            customer.city
                        }}</span>
            </div>
            <div class="w-1/2">
                <h3 class="text-xs font-bold mb-2">Postadres</h3>
                <span class="text-sm text-gray-800">{{ customer.postal_address }}<br>{{
                    customer.postal_postal_code
                }}<span v-if="customer.postal_city">,</span> {{
                        customer.postal_city
                    }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { BuildingOffice2Icon } from '@heroicons/vue/24/outline';

defineProps({
    customer: {
        type: Object,
        required: true
    },
    layout: {
        type: String,
        default: 'vertical'
    }
});
</script>