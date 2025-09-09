<template>
    <IndexHeaderComponent title="Klanten" addLabel="Nieuwe klant" search-placeholder="Zoek klant... "
        search-url="/customers" @add="() => customerFormRef?.show()">
        <template #right>
            <button @click="importCustomers" :disabled="importingCustomers"
                class="ml-auto px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700 disabled:bg-gray-400 text-right">SnelStart
                klanten importeren</button>
        </template>
    </IndexHeaderComponent>

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="customerFormRef" external-trigger action="/customers" :fields="customerFields"
            add-button-label="Nieuwe klant" submit-label="Opslaan" />
    </div>
    <ul role="list" class="grid grid-cols-1 gap-x-6 gap-y-8 lg:grid-cols-2 xl:grid-cols-3 xl:gap-x-8">
        <li v-for="customer in customers" :key="customer.id"
            class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <Link :href="`/customers/${customer.id}`" class="flex items-center gap-x-4 border-b border-gray-900/5 p-6">
            <BuildingOfficeIcon
                class="size-12 flex-none rounded-lg bg-white object-cover ring-1 ring-gray-900/10 p-2" />
            <div class="flex flex-col">
                <h3 class="text-sm/6 font-medium text-gray-900">{{
                    customer.name
                }}
                </h3>
                <span class="text-gray-500 text-xs">{{ customer.city }}</span>
            </div>
            </Link>
            <dl class="-my-3 divide-y divide-gray-100 px-6 py-4 text-sm/6">
                <div class="flex justify-between gap-x-4 py-3">
                    <dt class="text-gray-500">Adres</dt>
                    <dd class="flex items-start gap-x-2">
                        <a :href="mapsLinkFromCustomer(customer)" class="font-medium text-blue-600 underline"
                            target="_blank">{{ customer.address }}, {{
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
import { mapsLinkFromCustomer } from '@/Utilities/Utilities';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const customerFormRef = ref(null)
const importingCustomers = ref(false)
const importForm = useForm({})
const importCustomers = () => {
    importingCustomers.value = true;
    importForm.post('/imports/snelstart/customers', {
        preserveScroll: true,
        onFinish: () => importingCustomers.value = false,
    });
}

const customerFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'email', label: 'E-mail', type: 'text' },
    { key: 'phone', label: 'Telefoon', type: 'text' },
    { key: 'address', label: 'Adres', type: 'text' },
    { key: 'postal_code', label: 'Postcode', type: 'text' },
    { key: 'city', label: 'Plaats', type: 'text' },
    { key: 'country', label: 'Land', type: 'text' },
    { key: 'location_code', label: 'Locatiecode', type: 'text' },
]

defineProps({
    customers: {
        type: Array,
        required: true,
    },
})
</script>