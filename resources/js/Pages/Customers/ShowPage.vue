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
                <ul role="list" class="mt-3 grid grid-cols-2 gap-4">
                    <li class="col-span-1 flex rounded-md shadow-xs" v-for="asset in customer.upcoming_assets"
                        :key="asset.id">
                        <span :title="asset.product.product_type.name"
                            class="flex w-16 shrink-0 items-center justify-center rounded-l-md bg-pink-600 text-sm font-medium text-white">
                            {{ asset.product.product_type.name.slice(0, 2).toUpperCase() }}</span>
                        <div
                            class="flex flex-1 items-center justify-between truncate rounded-r-md border-t border-r border-b border-gray-200 bg-white">
                            <div class="flex-1 truncate px-4 py-2 text-sm">
                                <Link :href="`/assets/${asset.id}`"
                                    class="font-medium text-gray-900 hover:text-gray-600">{{
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
                                        {{ asset.open_tickets.length }} open storing<span
                                            v-if="asset.open_tickets.length > 1">en</span>
                                    </span>
                                    <span v-if="asset.pending_tickets.length > 0"
                                        :class="[asset.open_tickets.length > 0 ? 'ml-1' : '', 'inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-gray-200 ring-inset']">
                                        <svg class="size-1.5 fill-yellow-500" viewBox="0 0 6 6" aria-hidden="true">
                                            <circle cx="3" cy="3" r="3" />
                                        </svg>
                                        {{ asset.pending_tickets.length }} lopende storing<span
                                            v-if="asset.pending_tickets.length > 1">en</span>
                                    </span>
                                    <span v-if="asset.closed_tickets.length > 0"
                                        :class="[asset.open_tickets.length > 0 || asset.pending_tickets.length > 0 ? 'ml-1' : '', 'inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-gray-200 ring-inset']">
                                        <svg class="size-1.5 fill-green-500" viewBox="0 0 6 6" aria-hidden="true">
                                            <circle cx="3" cy="3" r="3" />
                                        </svg>
                                        {{ asset.closed_tickets.length }} gesloten storing<span
                                            v-if="asset.closed_tickets.length > 1">en</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
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
import { BuildingOffice2Icon, CalendarDaysIcon, HashtagIcon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    customer: {
        type: Object,
        required: true,
    },
});
</script>