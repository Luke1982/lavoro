<template>
    <IndexHeaderComponent title="Leveranciers" add-label="Nieuwe leverancier"
        search-placeholder="Zoek leverancier..." search-url="/suppliers"
        :can-add="canCreate && !importPreview"
        @add="supplierDrawerOpen = true">
        <template v-if="!importPreview && canCreate" #actions>
            <div class="relative" ref="actionsMenuRef">
                <button @click="actionsOpen = !actionsOpen"
                    class="cursor-pointer inline-flex items-center gap-x-1.5 px-3 py-3 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 text-xs font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none transition">
                    <ArrowUpTrayIcon class="h-5 w-5" />
                    <span class="hidden sm:inline">Importeren</span>
                    <ChevronDownIcon class="h-4 w-4 text-gray-400 dark:text-slate-400 transition-transform"
                        :class="actionsOpen ? 'rotate-180' : ''" />
                </button>
                <div v-if="actionsOpen"
                    class="absolute right-0 top-full mt-1 z-50 w-60 bg-white dark:bg-slate-800 rounded-lg shadow-lg ring-1 ring-gray-200 dark:ring-slate-700 py-1">
                    <a href="/suppliers/import/example" @click="actionsOpen = false"
                        class="w-full flex items-center gap-x-3 px-4 py-2.5 text-sm text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60 transition">
                        <ArrowDownTrayIcon class="h-4 w-4 text-gray-400 dark:text-slate-400 shrink-0" />
                        Download voorbeeldbestand
                    </a>
                    <button @click="triggerFileInput(); actionsOpen = false"
                        :disabled="previewForm.processing"
                        class="w-full flex items-center gap-x-3 px-4 py-2.5 text-sm text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60 disabled:opacity-50 transition text-left">
                        <ArrowUpTrayIcon class="h-4 w-4 text-emerald-500 shrink-0" />
                        {{ previewForm.processing ? 'Bezig...' : 'Importeer uit Excel' }}
                    </button>
                </div>
            </div>
            <input ref="fileInputRef" type="file" accept=".xlsx,.xls" class="hidden" @change="handleFileUpload" />
        </template>
    </IndexHeaderComponent>

    <div v-if="!importPreview">
        <PaginationComponent v-if="(suppliers.links || []).length" :paginator="suppliers"
            :params="{ search: searchParam }" class="border-b border-gray-200 dark:border-slate-700/60" />
        <div
            class="bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700/60 sm:rounded-lg overflow-hidden">
            <ul role="list" class="divide-y divide-gray-100 dark:divide-slate-800/70">
                <li v-for="supplier in suppliers.data" :key="supplier.id">
                    <Link :href="`/suppliers/${supplier.id}`"
                        class="group grid w-full grid-cols-[minmax(0,1fr)_24px] sm:grid-cols-[minmax(0,1fr)_180px_20px] items-start sm:items-center gap-y-2 sm:gap-y-0 gap-x-6 px-4 py-5 hover:bg-gray-50 dark:hover:bg-slate-800/60 focus-visible:outline-none transition even:bg-gray-50 even:dark:bg-slate-800/40">
                        <div class="flex min-w-0 gap-x-4 col-start-1 row-start-1">
                            <span
                                class="size-12 flex-none rounded-full bg-gray-200 dark:bg-slate-700 ring-1 ring-gray-300 dark:ring-slate-600 flex items-center justify-center text-sm font-medium text-gray-600 dark:text-slate-200 select-none">
                                {{ (supplier.name || '?').slice(0, 2).toUpperCase() }}
                            </span>
                            <div class="min-w-0 flex-auto">
                                <p
                                    class="text-sm font-semibold leading-6 text-gray-900 dark:text-slate-100 group-hover:underline">
                                    {{ supplier.name }}
                                </p>
                                <p class="mt-1 truncate text-xs leading-5 text-gray-500 dark:text-slate-400">
                                    {{ supplier.email || 'Geen e-mailadres' }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex flex-col items-start justify-center col-start-1 row-start-2 sm:col-start-2 sm:row-start-1 pl-16 sm:pl-0">
                            <p class="text-sm leading-6 text-gray-900 dark:text-slate-200">{{ supplier.city || '—' }}</p>
                            <p class="mt-1 text-xs leading-5 text-left text-gray-600 dark:text-slate-400">
                                {{ supplier.contact_person || '—' }}
                            </p>
                        </div>
                        <div class="flex justify-end col-start-2 sm:col-start-3 row-span-2 sm:row-span-1 self-center">
                            <ChevronRightIcon
                                class="size-5 text-gray-400 dark:text-slate-500 group-hover:text-gray-500 dark:group-hover:text-slate-400"
                                aria-hidden="true" />
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
        <PaginationComponent v-if="(suppliers.links || []).length" :paginator="suppliers"
            :params="{ search: searchParam }" class="border-t border-gray-200 dark:border-slate-700/60" />
    </div>

    <!-- Import preview -->
    <div v-else class="mt-4 space-y-4">
        <div
            class="bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700/60 sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                <thead class="bg-gray-50 dark:bg-slate-800">
                    <tr>
                        <th
                            class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">
                            Naam</th>
                        <th
                            class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">
                            Actie</th>
                        <th
                            class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">
                            Stad</th>
                        <th
                            class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wider">
                            Waarschuwingen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                    <tr v-for="(row, idx) in importPreview" :key="idx"
                        :class="row.fatal ? 'bg-red-50 dark:bg-red-900/20' : ''">
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-slate-100">
                            {{ row.name || '— (naam ontbreekt)' }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span v-if="row.fatal"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">
                                Overgeslagen
                            </span>
                            <span v-else-if="row.action === 'update'"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                Bijwerken
                            </span>
                            <span v-else
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">
                                Nieuw
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-300">{{ row.city || '—' }}</td>
                        <td class="px-4 py-3 text-sm text-amber-600 dark:text-amber-400">
                            {{ row.warnings?.join(', ') || '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex items-center gap-x-4">
            <button @click="handleCancel"
                class="px-4 py-2 text-sm text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none transition">
                Annuleren
            </button>
            <button @click="handleConfirm" :disabled="confirmForm.processing"
                class="px-4 py-2 text-sm text-white bg-lavoro-blue rounded hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition">
                {{ confirmForm.processing ? 'Bezig...' : 'Import bevestigen' }}
            </button>
            <span class="text-xs text-gray-500 dark:text-slate-400">
                {{ importPreview.filter(r => !r.fatal).length }} leveranciers worden verwerkt
                <span v-if="importPreview.filter(r => r.fatal).length">
                    · {{ importPreview.filter(r => r.fatal).length }} overgeslagen
                </span>
            </span>
        </div>
    </div>

    <DrawerComponent v-if="canCreate" v-model="supplierDrawerOpen" title="Nieuwe leverancier toevoegen"
        subtitle="Vul de gegevens in van de nieuwe leverancier.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Naam <span class="text-red-500">*</span></label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.name"
                        :hasError="Boolean(newSupplierForm.errors.name)"
                        :errorMessage="newSupplierForm.errors.name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">E-mail</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.email"
                        :hasError="Boolean(newSupplierForm.errors.email)"
                        :errorMessage="newSupplierForm.errors.email" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Telefoon</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.phone"
                        :hasError="Boolean(newSupplierForm.errors.phone)"
                        :errorMessage="newSupplierForm.errors.phone" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Contactpersoon</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.contact_person"
                        :hasError="Boolean(newSupplierForm.errors.contact_person)"
                        :errorMessage="newSupplierForm.errors.contact_person" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Adres</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.address"
                        :hasError="Boolean(newSupplierForm.errors.address)"
                        :errorMessage="newSupplierForm.errors.address" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Postcode</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.postal_code"
                        :hasError="Boolean(newSupplierForm.errors.postal_code)"
                        :errorMessage="newSupplierForm.errors.postal_code" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Plaats</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.city"
                        :hasError="Boolean(newSupplierForm.errors.city)"
                        :errorMessage="newSupplierForm.errors.city" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Land</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newSupplierForm.country"
                        :hasError="Boolean(newSupplierForm.errors.country)"
                        :errorMessage="newSupplierForm.errors.country" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeSupplierDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewSupplier" :disabled="newSupplierForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ChevronRightIcon, ChevronDownIcon, ArrowUpTrayIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import { Link, router, useForm } from '@inertiajs/vue3';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

defineProps({
    suppliers: { type: Object, required: true },
    importPreview: { type: Array, default: null },
})

const supplierDrawerOpen = ref(false)
const fileInputRef       = ref(null)
const actionsMenuRef     = ref(null)
const actionsOpen        = ref(false)

const handleClickOutside = (e) => {
    if (actionsMenuRef.value && !actionsMenuRef.value.contains(e.target)) {
        actionsOpen.value = false
    }
}
onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))

const newSupplierForm = useForm({
    name:           '',
    email:          '',
    phone:          '',
    contact_person: '',
    address:        '',
    postal_code:    '',
    city:           '',
    country:        '',
})

function closeSupplierDrawer() {
    supplierDrawerOpen.value = false
    newSupplierForm.reset()
    newSupplierForm.clearErrors()
}

function submitNewSupplier() {
    newSupplierForm.post('/suppliers', {
        onSuccess: () => closeSupplierDrawer(),
    })
}

const previewForm = useForm({ file: null })
const triggerFileInput = () => fileInputRef.value?.click()
const handleFileUpload = (e) => {
    const file = e.target.files[0]
    if (!file) return
    previewForm.file = file
    e.target.value = ''
    previewForm.post('/suppliers/import/preview', { forceFormData: true })
}

const confirmForm = useForm({})
const handleConfirm = () => { confirmForm.post('/suppliers/import/confirm') }
const handleCancel  = () => { router.get('/suppliers') }

const searchParam = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('search') || ''
    : ''
const canCreate = computed(() => hasPermission('supplier.create'))
</script>
