<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border dark:border-slate-800">
        <IndexHeaderComponent title="Klanten" :addLabel="canCreate ? 'Nieuwe klant' : null"
            search-placeholder="Zoek klant... " search-url="/customers"
            @add="() => canCreate && customerFormRef?.show()">
            <template #right>
                <button @click="importCustomers" :disabled="importingCustomers"
                    class="ml-auto px-3 py-2 bg-indigo-600 dark:bg-indigo-500 text-white text-xs font-semibold rounded hover:bg-indigo-700 dark:hover:bg-indigo-400 disabled:bg-gray-400 dark:disabled:bg-slate-600/50 text-right focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400 transition">SnelStart
                    klanten importeren</button>
            </template>
        </IndexHeaderComponent>
    </div>

    <div class="mb-4" v-auto-animate v-if="canCreate">
        <CreateRecordForm ref="customerFormRef" external-trigger action="/customers" :fields="customerFields"
            add-button-label="Nieuwe klant" submit-label="Opslaan" />
    </div>
    <div class="bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700/60 sm:rounded-lg overflow-hidden">
        <ul role="list" class="divide-y divide-gray-100 dark:divide-slate-800/70">
            <li v-for="customer in customers" :key="customer.id">
                <Link :href="`/customers/${customer.id}`"
                    class="group grid w-full grid-cols-[minmax(0,1fr)_180px_20px] items-center gap-x-6 px-4 py-5 hover:bg-gray-50 dark:hover:bg-slate-800/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-500 transition even:bg-gray-50 even:dark:bg-slate-800/40">
                <!-- Left section: avatar + name/email -->
                <div class="flex min-w-0 gap-x-4">
                    <span
                        class="size-12 flex-none rounded-full bg-gray-200 dark:bg-slate-700 ring-1 ring-gray-300 dark:ring-slate-600 flex items-center justify-center text-sm font-medium text-gray-600 dark:text-slate-200 select-none">
                        {{ (customer.name || '?').slice(0, 2).toUpperCase() }}
                    </span>
                    <div class="min-w-0 flex-auto">
                        <p
                            class="text-sm font-semibold leading-6 text-gray-900 dark:text-slate-100 group-hover:underline">
                            {{ customer.name
                            }}
                        </p>
                        <p class="mt-1 truncate text-xs leading-5 text-gray-500 dark:text-slate-400">
                            {{ customer.email || 'Geen e-mailadres' }}
                        </p>
                    </div>
                </div>
                <!-- Middle section: city + ticket status -->
                <div class="flex flex-col items-start justify-center">
                    <p class="text-sm leading-6 text-gray-900 dark:text-slate-200">{{ customer.city || '—' }}</p>
                    <p class="mt-1 text-xs leading-5 text-left text-gray-600 dark:text-slate-400">
                        <span v-if="customer.open_tickets?.length" class="text-red-600 dark:text-red-400 font-medium">{{
                            customer.open_tickets.length }} open stor.</span>
                        <span v-else-if="customer.pending_tickets?.length"
                            class="text-amber-600 dark:text-amber-400 font-medium">{{
                                customer.pending_tickets.length }} in beh.</span>
                        <span v-else class="text-green-600 dark:text-green-400">Geen open storingen</span>
                    </p>
                </div>
                <!-- Right section: chevron -->
                <div class="flex justify-end">
                    <ChevronRightIcon
                        class="size-5 text-gray-400 dark:text-slate-500 group-hover:text-gray-500 dark:group-hover:text-slate-400"
                        aria-hidden="true" />
                    <span class="sr-only">Bekijk {{ customer.name }}</span>
                </div>
                </Link>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ChevronRightIcon } from '@heroicons/vue/24/outline'
import { Link } from '@inertiajs/vue3';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { hasPermission } from '@/Utilities/Utilities';

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

const canCreate = computed(() => hasPermission('customer.create'))
</script>