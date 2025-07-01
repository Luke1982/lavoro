<template>
    <ul role="list" class="grid grid-cols-1 gap-x-6 gap-y-8 lg:grid-cols-3 xl:gap-x-8">
        <li v-for="customer in customers" :key="customer.id"
            class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="flex items-center gap-x-4 border-b border-gray-900/5 p-6">
                <BuildingOfficeIcon
                    class="size-12 flex-none rounded-lg bg-white object-cover ring-1 ring-gray-900/10 p-2" />
                <Link :href="`/customers/${customer.id}`" class="flex flex-col">
                <h3 class="text-sm/6 font-medium text-gray-900">{{
                    customer.name
                    }}
                </h3>
                <span class="text-gray-500 text-xs">{{ customer.city }}</span>
                </Link>
            </div>
            <dl class="-my-3 divide-y divide-gray-100 px-6 py-4 text-sm/6">
                <div class="flex justify-between gap-x-4 py-3">
                    <dt class="text-gray-500">Adres</dt>
                    <dd class="flex items-start gap-x-2">
                        <a :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(customer.address + ' ' + customer.postal_code + ' ' + customer.city)}`"
                            class="font-medium text-blue-600 underline" target="_blank">{{ customer.address }}, {{
                                customer.postal_code }}</a>
                    </dd>
                </div>
                <div class="flex justify-between gap-x-4 py-3">
                    <dt class="text-gray-500">Storingen</dt>
                    <dd class="flex flex-wrap items-start justify-end gap-y-2">
                        <div v-if="customer.open_tickets.length > 0"
                            class="text-red-700 bg-red-50 ring-red-600/10 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                            {{ customer.open_tickets.length }} Storingen open</div>
                        <div v-if="customer.pending_tickets.length > 0"
                            class="text-gray-600 bg-gray-50 ring-gray-500/10 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                            {{ customer.pending_tickets.length }} Storingen in behandeling</div>
                        <div v-if="customer.closed_tickets.length > 0"
                            class="text-green-700 bg-green-50 ring-green-600/20 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                            {{ customer.closed_tickets.length }} Storingen gesloten</div>
                    </dd>
                </div>
                <div class="flex justify-between gap-x-4 py-3">
                    <dt class="text-gray-500">Activa</dt>
                    <dd class="flex flex-wrap items-start justify-end gap-y-2">
                        <div v-if="customer.upcoming_assets.length > 0"
                            class="text-green-700 bg-green-50 ring-green-600/20 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                            {{ customer.upcoming_assets.length }} Aankomende keuringen</div>
                    </dd>
                </div>
            </dl>
        </li>
    </ul>
</template>

<script setup>
import { BuildingOfficeIcon } from '@heroicons/vue/24/outline'
import { Link } from '@inertiajs/vue3';

defineProps({
    customers: {
        type: Array,
        required: true,
    },
})
</script>