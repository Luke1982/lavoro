<template>
    <div class="grid gap-6 grid-cols-1 xl:grid-cols-2">
        <div v-for="asset in assets" :key="asset.id"
            class="flex rounded-lg bg-white overflow-hidden transition border border-gray-200">
            <div class="w-20 flex items-center justify-center bg-pink-600 text-white font-semibold text-sm select-none">
                {{ asset.product.product_type.name.slice(0, 2).toUpperCase() }}
            </div>
            <div class="flex-1 p-4 pr-5">
                <div class="flex flex-col text-sm">
                    <Link :href="`/assets/${asset.id}`"
                        class="font-medium text-gray-900 hover:text-gray-700 leading-snug line-clamp-2">{{
                            asset.product.brand.name }} {{ asset.product.model }}</Link>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-gray-600">
                    <div class="flex items-center gap-1">
                        <CalendarDaysIcon class="h-4 w-4 text-gray-500" />
                        <span>{{ new Date(asset.next_service_date).toLocaleDateString('nl-NL', {
                            day: '2-digit', month:
                            '2-digit', year: 'numeric' }) }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <HashtagIcon class="h-3.5 w-3.5 text-gray-500" />
                        <span
                            class="bg-yellow-100 text-gray-800 px-1.5 py-0.5 rounded text-[11px] font-medium tracking-tight">SN
                            {{ asset.serial_number }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 mt-3">
                    <span v-if="asset.open_tickets.length"
                        class="inline-flex items-center gap-1 bg-amber-100 text-gray-900 px-2 py-1 rounded text-[11px] font-medium">
                        <span class="inline-block size-2 bg-amber-500 rounded-full"></span>
                        {{ asset.open_tickets.length }} open storing<span v-if="asset.open_tickets.length > 1">en</span>
                    </span>
                    <span v-if="asset.pending_tickets.length"
                        class="inline-flex items-center gap-1 bg-blue-100 text-gray-900 px-2 py-1 rounded text-[11px] font-medium">
                        <span class="inline-block size-2 bg-blue-500 rounded-full"></span>
                        {{ asset.pending_tickets.length }} lopende storing<span
                            v-if="asset.pending_tickets.length > 1">en</span>
                    </span>
                    <span v-if="asset.closed_tickets.length"
                        class="inline-flex items-center gap-1 bg-green-100 text-gray-900 px-2 py-1 rounded text-[11px] font-medium">
                        <span class="inline-block size-2 bg-green-500 rounded-full"></span>
                        {{ asset.closed_tickets.length }} gesloten storing<span
                            v-if="asset.closed_tickets.length > 1">en</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { CalendarDaysIcon, HashtagIcon } from '@heroicons/vue/24/outline';
defineProps({
    assets: {
        type: Array,
        required: true,
    },
});
</script>