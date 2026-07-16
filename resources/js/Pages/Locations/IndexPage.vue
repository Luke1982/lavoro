<template>
    <IndexHeaderComponent title="Locaties" search-placeholder="Zoek op titel, code, adres, plaats of klant..."
        search-url="/locations" :can-add="false" />

    <div>
        <PaginationComponent v-if="(locations.links || []).length" :paginator="locations"
            :params="{ search: searchParam }" class="border-b border-gray-200 dark:border-slate-700/60" />
        <div
            class="bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700/60 sm:rounded-lg overflow-hidden">
            <ul v-if="locations.data.length" role="list" class="divide-y divide-gray-100 dark:divide-slate-800/70">
                <li v-for="location in locations.data" :key="location.id">
                    <Link :href="`/locations/${location.id}`"
                        class="group flex items-center justify-between gap-x-6 px-4 py-4 hover:bg-gray-50 dark:hover:bg-slate-800/60 transition even:bg-gray-50 even:dark:bg-slate-800/40">
                        <div class="flex min-w-0 gap-x-4">
                            <span
                                class="size-11 flex-none rounded-lg bg-lavoro-blue/10 text-lavoro-blue ring-1 ring-lavoro-blue/20 flex items-center justify-center">
                                <MapPinIcon class="size-5" />
                            </span>
                            <div class="min-w-0 flex-auto">
                                <p class="text-sm font-semibold leading-6 text-gray-900 dark:text-slate-100 group-hover:underline truncate">
                                    {{ location.title }}
                                </p>
                                <p class="mt-1 truncate text-xs leading-5 text-gray-500 dark:text-slate-400">
                                    {{ [location.location_code, location.address, location.city].filter(Boolean).join(' • ') }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-x-4 shrink-0">
                            <span class="hidden sm:block text-xs text-gray-500 dark:text-slate-400">
                                {{ location.customer?.name }}
                            </span>
                            <ChevronRightIcon class="size-5 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                        </div>
                    </Link>
                </li>
            </ul>
            <p v-else class="px-4 py-8 text-sm text-center text-gray-400 dark:text-slate-500 italic">
                Geen locaties gevonden. Voeg locaties toe vanaf de klantpagina.
            </p>
        </div>
        <PaginationComponent v-if="(locations.links || []).length" :paginator="locations"
            :params="{ search: searchParam }" class="border-t border-gray-200 dark:border-slate-700/60" />
    </div>
</template>

<script setup>
import { ChevronRightIcon, MapPinIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';

defineProps({
    locations: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const searchParam = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('search') || ''
    : '';
</script>
