<template>
    <div v-for="(assets, groupName) in assetGroups" :key="groupName" class="ring-1 ring-gray-200 rounded-md p-4 mb-6">
        <h3 class="text-l font-regular mb-2">{{ groupName }}</h3>
        <ul role="list" class="mt-2 grid grid-cols-2 gap-4">
            <li class="col-span-1 flex rounded-md shadow-xs" v-for="asset in assets" :key="asset.id">
                <span :title="asset.product.product_type.name"
                    class="flex w-16 shrink-0 items-center justify-center rounded-l-md bg-pink-600 text-sm font-medium text-white">
                    {{ asset.product.product_type.name.slice(0, 2).toUpperCase() }}</span>
                <div
                    class="flex flex-1 items-start justify-between truncate rounded-r-md border-t border-r border-b border-gray-200 bg-white">
                    <div class="flex-1 truncate px-4 py-2 text-sm">
                        <Link :href="`/assets/${asset.id}`" class="font-medium text-gray-900 hover:text-gray-600">{{
                            asset.product.brand.name }} {{ asset.product.model }}</Link>
                        <div class="flex gap-x-4 mt-2">
                            <div class="flex items-center">
                                <CalendarDaysIcon class="h-5 w-5 text-gray-800 mr-1" title="Verloopdatum" />
                                <p class="text-gray-500">
                                    {{ new Date(asset.next_service_date)
                                        .toLocaleDateString('nl-NL', {
                                            day: '2-digit', month: '2-digit', year: 'numeric'
                                        })
                                    }}
                                </p>
                            </div>
                            <div class="flex items-center">
                                <HashtagIcon class="h-4 w-4 text-gray-800 mr-1" />
                                <p class="bg-amber-200 inline-block px-0.5">{{ asset.serial_number }}
                                </p>
                            </div>
                        </div>
                        <div class="flex mt-2">
                            <span v-if="asset.open_tickets.length > 0"
                                class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-gray-200 ring-inset">
                                <svg class="size-1.5 fill-red-500" viewBox="0 0 6 6" aria-hidden="true">
                                    <circle cx="3" cy="3" r="3" />
                                </svg>
                                <span>
                                    {{ asset.open_tickets.length }} open storing<span
                                        v-if="asset.open_tickets.length > 1">en</span>
                                </span>
                            </span>
                            <span v-if="asset.pending_tickets.length > 0"
                                :class="[asset.open_tickets.length > 0 ? 'ml-1' : '', 'inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-gray-200 ring-inset']">
                                <svg class="size-1.5 fill-yellow-500" viewBox="0 0 6 6" aria-hidden="true">
                                    <circle cx="3" cy="3" r="3" />
                                </svg>
                                <span>
                                    {{ asset.pending_tickets.length }} lopende storing<span
                                        v-if="asset.pending_tickets.length > 1">en</span>
                                </span>
                            </span>
                            <span v-if="asset.closed_tickets.length > 0"
                                :class="[asset.open_tickets.length > 0 || asset.pending_tickets.length > 0 ? 'ml-1' : '', 'inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-gray-200 ring-inset']">
                                <svg class="size-1.5 fill-green-500" viewBox="0 0 6 6" aria-hidden="true">
                                    <circle cx="3" cy="3" r="3" />
                                </svg>
                                <span>
                                    {{ asset.closed_tickets.length }} gesloten storing<span
                                        v-if="asset.closed_tickets.length > 1">en</span>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { CalendarDaysIcon, HashtagIcon } from '@heroicons/vue/24/outline';
defineProps({
    assetGroups: {
        type: Object,
        required: true,
    },
});
</script>