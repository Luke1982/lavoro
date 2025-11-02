<template>
    <div class="flex">
        <Link :href="`/customers/${customer.id}`">
        <BuildingOffice2Icon
            class="size-12 flex-none rounded-lg bg-white dark:bg-slate-700 object-cover ring-1 ring-gray-900/10 dark:ring-slate-600/60 p-2 mb-6 text-gray-700 dark:text-slate-200 transition-colors" />
        </Link>
        <div class="flex flex-wrap w-full flex-col md:flex-row">
            <div class="flex flex-col w-full md:w-1/3 pl-4 pr-2">
                <Link :href="`/customers/${customer.id}`"
                    class="text-l font-semibold text-gray-800 dark:text-slate-100 mb-1">{{ customer.name }}</Link>
                <div class="flex flex-col text-sm text-gray-500 dark:text-slate-400 gap-x-2">
                    <div v-if="customer.website">
                        <HomeIcon class="inline-block size-4 mr-1 text-gray-500 dark:text-slate-500" />
                        <a target="_blank" class="underline break-all" :href="customer.website">{{
                            customer.website
                            }}</a>
                    </div>
                    <div v-if="customer.email">
                        <AtSymbolIcon class="inline-block size-4 mr-1 text-gray-500 dark:text-slate-500" />
                        <a class="underline break-all" :href="`mailto:${customer.email}`">{{
                            customer.email
                        }}</a>
                    </div>
                </div>
            </div>
            <div class="w-full md:w-4/6 flex flex-col md:flex-row ml-4 mt-4 md:ml-0 md:mt-0">
                <div class="w-full md:w-1/2" v-if="customer.address || customer.postal_code || customer.city">
                    <h3 class="text-xs mb-1 text-gray-700 dark:text-slate-400">Bezoekadres</h3>
                    <a :href="mapsLinkFromCustomer(customer)" target="_blank"
                        class="text-md text-gray-800 dark:text-slate-200">{{ customer.address }}<br>{{
                            customer.postal_code }}<span v-if="customer.city">,</span> {{
                            customer.city
                        }}</a>
                </div>
                <div class="w-full md:w-1/2"
                    v-if="customer.postal_address || customer.postal_postal_code || customer.postal_city">
                    <h3 class="text-xs mb-1 text-gray-700 dark:text-slate-400">Postadres</h3>
                    <span class="text-md text-gray-800 dark:text-slate-200">{{ customer.postal_address }}<br>{{
                        customer.postal_postal_code
                    }}<span v-if="customer.postal_city">,</span> {{
                            customer.postal_city
                        }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { AtSymbolIcon, BuildingOffice2Icon, HomeIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { mapsLinkFromCustomer } from '@/Utilities/Utilities';

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