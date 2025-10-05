<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border dark:border-slate-800">
        <IndexHeaderComponent title="Bedrijven" subtitle="Beheer de bedrijfsgegevens" :paginator="null"
            :show-search="false" add-label="Voeg bedrijf toe" @add="() => formRef?.show()" />
    </div>
    <div v-auto-animate class="mb-4">
        <CreateRecordForm ref="formRef" external-trigger action="/companies" :fields="companyFields"
            add-button-label="Voeg bedrijf toe" submit-label="Toevoegen" enctype="multipart/form-data" />
    </div>
    <BoxComponent padding="px-0 py-0">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700/60">
            <thead class="bg-gray-50 dark:bg-slate-800">
                <tr>
                    <th
                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-300 uppercase tracking-wider">
                        Naam</th>
                    <th
                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-300 uppercase tracking-wider">
                        Adres
                    </th>
                    <th
                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-300 uppercase tracking-wider">
                        Plaats
                    </th>
                    <th
                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-300 uppercase tracking-wider">
                        Land</th>
                    <th
                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-300 uppercase tracking-wider">
                        Logo</th>
                    <th
                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-300 uppercase tracking-wider">
                        Hoofd
                    </th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-slate-900 divide-y divide-gray-200 dark:divide-slate-800/60">
                <tr v-for="company in companies" :key="company.id" class="hover:bg-gray-50 dark:hover:bg-slate-800/60">
                    <td class="px-4 py-2 text-gray-900 dark:text-slate-100">{{ company.name }}</td>
                    <td class="px-4 py-2">
                        <div class="text-gray-900 dark:text-slate-100">{{ company.address_line1 }}</div>
                        <div v-if="company.address_line2" class="text-gray-500 dark:text-slate-400 text-xs">{{
                            company.address_line2 }}
                        </div>
                        <div class="text-gray-500 dark:text-slate-400 text-xs">{{ company.postal_code }}</div>
                    </td>
                    <td class="px-4 py-2 text-gray-900 dark:text-slate-100">{{ company.city }}</td>
                    <td class="px-4 py-2 text-gray-900 dark:text-slate-100">{{ company.country }}</td>
                    <td class="px-4 py-2">
                        <img v-if="company.logo_path" :src="`/storage/${company.logo_path}`"
                            class="h-10 w-10 object-contain rounded" />
                        <span v-else class="text-xs text-gray-400 dark:text-slate-500">Geen</span>
                    </td>
                    <td class="px-4 py-2">
                        <span v-if="company.is_main"
                            class="inline-flex items-center gap-1 rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 px-2.5 py-0.5 text-xs font-medium">Hoofd</span>
                    </td>
                    <td class="px-4 py-2 text-right text-xs space-x-2">
                        <button type="button" @click="edit(company)"
                            class="inline-flex items-center justify-center rounded-md p-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 hover:text-indigo-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transition">
                            <PencilSquareIcon class="w-4 h-4" />
                            <span class="sr-only">Bewerk</span>
                        </button>
                        <button type="button" @click="deleteCompany(company)"
                            class="inline-flex items-center justify-center rounded-md p-2 bg-red-50 hover:bg-red-100 text-red-600 hover:text-red-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-red-300 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1 transition">
                            <TrashIcon class="w-4 h-4" />
                            <span class="sr-only">Verwijder</span>
                        </button>
                    </td>
                </tr>
                <tr v-if="companies.length === 0">
                    <td colspan="7" class="px-4 py-4 text-center text-gray-500 dark:text-slate-400">Geen bedrijven
                        gevonden.</td>
                </tr>
            </tbody>
        </table>
    </BoxComponent>
    <EditModal v-if="editCompany" :company="editCompany" @close="editCompany = null" />
</template>
<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditModal from './Partials/EditCompanyModal.vue'
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline'

defineProps({
    companies: { type: Array, required: true }
})

const formRef = ref(null)
const editCompany = ref(null)

const companyFields = [
    { key: 'name', label: 'Naam', type: 'text', required: true },
    { key: 'address_line1', label: 'Adresregel 1', type: 'text' },
    { key: 'address_line2', label: 'Adresregel 2', type: 'text' },
    { key: 'postal_code', label: 'Postcode', type: 'text' },
    { key: 'city', label: 'Plaats', type: 'text' },
    { key: 'country', label: 'Land', type: 'text', default: 'NL' },
    { key: 'is_main', label: 'Hoofd bedrijf?', type: 'boolean' },
    { key: 'logo', label: 'Logo', type: 'file' }
]

const deleteForm = useForm({})

function edit(company) { editCompany.value = company }

function deleteCompany(company) {
    if (!confirm('Verwijder dit bedrijf?')) return
    deleteForm.delete(`/companies/${company.id}`, { preserveScroll: true })
}
</script>
