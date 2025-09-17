<template>
    <div class="grid gap-6 grid-cols-1 xl:grid-cols-2" v-auto-animate>
        <div v-for="asset in assets" :key="asset.id"
            class="flex rounded-lg bg-white dark:bg-slate-900 overflow-hidden transition border border-gray-200 dark:border-slate-700/60 relative hover:shadow-sm hover:border-gray-300 dark:hover:border-slate-600/80">
            <div
                class="w-20 flex items-center justify-center bg-pink-600 text-white font-semibold text-sm select-none dark:bg-pink-700">
                {{ asset.product.product_type.name.slice(0, 2).toUpperCase() }}
            </div>
            <div class="flex-1 p-4 pr-8">
                <div class="flex flex-col text-sm">
                    <Link :href="`/assets/${asset.id}`"
                        class="font-medium text-gray-900 dark:text-slate-100 hover:text-gray-700 dark:hover:text-slate-300 leading-snug line-clamp-2">
                    {{
                        asset.product.brand.name }} {{ asset.product.model }}</Link>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-gray-600 dark:text-slate-400">
                    <div class="flex items-center gap-1">
                        <CalendarDaysIcon class="h-4 w-4 text-gray-500 dark:text-slate-500" />
                        <span>{{ new Date(asset.next_service_date).toLocaleDateString('nl-NL', {
                            day: '2-digit', month:
                                '2-digit', year: 'numeric'
                        }) }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <HashtagIcon class="h-3.5 w-3.5 text-gray-500 dark:text-slate-500" />
                        <span
                            class="bg-yellow-100 dark:bg-yellow-900/40 text-gray-800 dark:text-slate-200 px-1.5 py-0.5 rounded text-[11px] font-medium tracking-tight">SN
                            {{ asset.serial_number }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 mt-3">
                    <span v-if="asset.open_tickets.length"
                        class="inline-flex items-center gap-1 bg-amber-100 dark:bg-amber-900/40 text-gray-900 dark:text-slate-100 px-2 py-1 rounded text-[11px] font-medium">
                        <span class="inline-block size-2 bg-amber-500 rounded-full"></span>
                        {{ asset.open_tickets.length }} open storing<span v-if="asset.open_tickets.length > 1">en</span>
                    </span>
                    <span v-if="asset.pending_tickets.length"
                        class="inline-flex items-center gap-1 bg-blue-100 dark:bg-blue-900/40 text-gray-900 dark:text-slate-100 px-2 py-1 rounded text-[11px] font-medium">
                        <span class="inline-block size-2 bg-blue-500 rounded-full"></span>
                        {{ asset.pending_tickets.length }} lopende storing<span
                            v-if="asset.pending_tickets.length > 1">en</span>
                    </span>
                    <span v-if="asset.closed_tickets.length"
                        class="inline-flex items-center gap-1 bg-green-100 dark:bg-green-900/40 text-gray-900 dark:text-slate-100 px-2 py-1 rounded text-[11px] font-medium">
                        <span class="inline-block size-2 bg-green-500 rounded-full"></span>
                        {{ asset.closed_tickets.length }} gesloten storing<span
                            v-if="asset.closed_tickets.length > 1">en</span>
                    </span>
                </div>
            </div>
            <TrashIcon
                class="w-5 h-5 text-red-500 dark:text-red-400 cursor-pointer absolute top-2 right-2 opacity-80 hover:opacity-100"
                @click="deleteAsset(asset.id)" v-tooltip="'Verwijder machine'" />
        </div>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { CalendarDaysIcon, HashtagIcon, TrashIcon } from '@heroicons/vue/24/outline';
defineProps({
    assets: {
        type: Array,
        required: true,
    },
});

const deleteForm = useForm({});
const deleteAsset = (id) => {
    if (!confirm('Weet je zeker dat je deze machine wilt verwijderen?')) return;
    deleteForm.delete(`/assets/${id}`, { preserveScroll: true });
};
</script>