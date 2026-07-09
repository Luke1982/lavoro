<template>
    <IndexHeaderComponent title="Klanten" subtitle="Zoek en beheer klanten"
        search-placeholder="Zoek klant..." search-url="/customers" :paginator="false"
        :add-label="canCreate && !importPreview ? 'Nieuwe klant' : ''"
        @add="() => canCreate && (addCustomerDrawerOpen = true)">
        <template v-if="!importPreview && canCreate" #actions>
            <div class="relative" ref="actionsMenuRef">
                <button @click="actionsOpen = !actionsOpen"
                    class="cursor-pointer inline-flex items-center gap-x-1.5 px-3 py-3 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 text-xs font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 transition">
                    <ArrowUpTrayIcon class="h-5 w-5" />
                    <span class="hidden sm:inline">Importeren</span>
                    <ChevronDownIcon class="h-4 w-4 text-gray-400 dark:text-slate-400 transition-transform"
                        :class="actionsOpen ? 'rotate-180' : ''" />
                </button>
                <div v-if="actionsOpen"
                    class="absolute right-0 top-full mt-1 z-50 w-60 bg-white dark:bg-slate-800 rounded-lg shadow-lg ring-1 ring-gray-200 dark:ring-slate-700 py-1">
                    <a v-if="canCreate" href="/customers/import/example" @click="actionsOpen = false"
                        class="w-full flex items-center gap-x-3 px-4 py-2.5 text-sm text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60 transition">
                        <ArrowDownTrayIcon class="h-4 w-4 text-gray-400 dark:text-slate-400 shrink-0" />
                        Download voorbeeldbestand
                    </a>
                    <button v-if="canCreate" @click="triggerFileInput(); actionsOpen = false"
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

    <ModalDialog v-model:open="snelStartPromptOpen" title="SnelStart klanten importeren?" max-width-class="sm:max-w-md">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Dit bestand lijkt een klantenexport uit SnelStart te zijn, geen bestand in ons eigen formaat.
            Wil je dit bestand converteren en als klanten importeren?
        </p>
        <template #footer>
            <div class="flex gap-3 justify-end">
                <button type="button"
                    class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                    @click="declineSnelStartImport">
                    Nee
                </button>
                <button type="button" :disabled="previewForm.processing"
                    class="rounded-md bg-lavoro-green px-3 py-2 text-sm font-semibold text-gray-900 hover:opacity-90 disabled:opacity-60"
                    @click="confirmSnelStartImport">
                    Ja, importeren
                </button>
            </div>
        </template>
    </ModalDialog>

    <DrawerComponent v-if="canCreate" v-model="addCustomerDrawerOpen" title="Nieuwe klant toevoegen"
        subtitle="Vul onderstaande velden in om een nieuwe klant toe te voegen.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Naam <span class="text-red-500">*</span></label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newCustomerForm.name" placeholder="Naam klant"
                        :hasError="Boolean(newCustomerForm.errors.name)"
                        :errorMessage="newCustomerForm.errors.name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">E-mail</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newCustomerForm.email" type="email" placeholder="info@bedrijf.nl"
                        :hasError="Boolean(newCustomerForm.errors.email)"
                        :errorMessage="newCustomerForm.errors.email" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Telefoon</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newCustomerForm.phone" placeholder="0612345678"
                        :hasError="Boolean(newCustomerForm.errors.phone)"
                        :errorMessage="newCustomerForm.errors.phone" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Adres</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newCustomerForm.address" placeholder="Straatnaam 1"
                        :hasError="Boolean(newCustomerForm.errors.address)"
                        :errorMessage="newCustomerForm.errors.address" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Postcode / Plaats</label>
                <div class="sm:col-span-2 grid grid-cols-2 gap-2">
                    <TextInput v-model="newCustomerForm.postal_code" placeholder="1234AB"
                        :hasError="Boolean(newCustomerForm.errors.postal_code)"
                        :errorMessage="newCustomerForm.errors.postal_code" />
                    <TextInput v-model="newCustomerForm.city" placeholder="Plaats"
                        :hasError="Boolean(newCustomerForm.errors.city)"
                        :errorMessage="newCustomerForm.errors.city" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Land</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newCustomerForm.country" placeholder="Nederland"
                        :hasError="Boolean(newCustomerForm.errors.country)"
                        :errorMessage="newCustomerForm.errors.country" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Locatiecode</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newCustomerForm.location_code" placeholder="Locatiecode"
                        :hasError="Boolean(newCustomerForm.errors.location_code)"
                        :errorMessage="newCustomerForm.errors.location_code" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeCustomerDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitCustomer" :disabled="newCustomerForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Toevoegen
                </button>
            </div>
        </template>
    </DrawerComponent>

    <div v-if="!importPreview">
        <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
            <div
                class="hidden lg:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-4">Klant</div>
                <div class="col-span-3">Adres</div>
                <div class="col-span-2">Telefoon</div>
                <div class="col-span-1 text-center">Open stor.</div>
                <div class="col-span-1 text-center">Lopend stor.</div>
                <div class="col-span-1 text-center">Ges. stor.</div>
            </div>
            <div v-auto-animate>
                <div v-for="customer in customers.data" :key="customer.id" role="row"
                    class="relative grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-300">
                    <div class="col-span-10 lg:col-span-4 flex items-center gap-3">
                        <span
                            class="size-10 flex-none rounded-full bg-gray-200 dark:bg-slate-700 ring-1 ring-gray-300 dark:ring-slate-600 flex items-center justify-center text-xs font-medium text-gray-600 dark:text-slate-200 select-none">
                            {{ (customer.name || '?').slice(0, 2).toUpperCase() }}
                        </span>
                        <div class="min-w-0">
                            <Link :href="`/customers/${customer.id}`"
                                class="font-semibold text-gray-900 dark:text-gray-200 hover:underline">
                                <span class="absolute inset-0" />
                                {{ customer.name }}
                            </Link>
                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                {{ customer.email || 'Geen e-mailadres' }}
                            </div>
                        </div>
                    </div>
                    <div class="col-span-3 hidden lg:flex flex-col justify-center text-xs text-gray-600 dark:text-gray-300">
                        <span>{{ customer.city || '—' }}</span>
                        <span v-if="customer.address"
                            class="text-gray-400 dark:text-gray-500 truncate">{{ customer.address }}</span>
                    </div>
                    <div class="col-span-2 hidden lg:flex items-center text-xs text-gray-600 dark:text-gray-300">
                        {{ customer.phone || '—' }}
                    </div>
                    <div class="col-span-1 hidden lg:flex items-center justify-center">
                        <span v-if="customer.open_tickets_count > 0"
                            class="inline-flex items-center justify-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-600/20 ring-inset min-w-[1.75rem]">
                            {{ customer.open_tickets_count }}
                        </span>
                        <span v-else class="text-gray-300 text-xs">—</span>
                    </div>
                    <div class="col-span-1 hidden lg:flex items-center justify-center">
                        <span v-if="customer.pending_tickets_count > 0"
                            class="inline-flex items-center justify-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset min-w-[1.75rem]">
                            {{ customer.pending_tickets_count }}
                        </span>
                        <span v-else class="text-gray-300 text-xs">—</span>
                    </div>
                    <div class="col-span-1 hidden lg:flex items-center justify-center">
                        <span v-if="customer.closed_tickets_count > 0"
                            class="inline-flex items-center justify-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-400/20 ring-inset min-w-[1.75rem]">
                            {{ customer.closed_tickets_count }}
                        </span>
                        <span v-else class="text-gray-300 text-xs">—</span>
                    </div>
                    <div class="col-span-2 lg:hidden flex flex-col gap-1 items-end justify-center">
                        <span v-if="customer.open_tickets_count > 0"
                            class="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-600/20 ring-inset">
                            {{ customer.open_tickets_count }} open
                        </span>
                        <span v-else-if="customer.pending_tickets_count > 0"
                            class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset">
                            {{ customer.pending_tickets_count }} lopend
                        </span>
                        <span v-else class="text-xs text-gray-300">—</span>
                    </div>
                    <ChevronRightIcon class="absolute right-4 top-1/2 -translate-y-1/2 size-5 text-gray-400"
                        aria-hidden="true" />
                </div>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="customers.total" :per-page="customers.per_page" label="klanten" />
                <PaginationComponent v-if="customers.data.length" :paginator="customers" />
            </div>
        </BoxComponent>
    </div>

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
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-300">
                            {{ row.city || '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-amber-600 dark:text-amber-400">
                            {{ row.warnings?.join(', ') || '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center gap-x-4">
            <button @click="handleCancel"
                class="px-4 py-2 text-sm text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 transition">
                Annuleren
            </button>
            <button @click="handleConfirm" :disabled="confirmForm.processing"
                class="px-4 py-2 text-sm text-white bg-lavoro-blue rounded hover:opacity-90 disabled:bg-gray-400 dark:disabled:bg-slate-600/50 focus:outline-none transition">
                {{ confirmForm.processing ? 'Bezig...' : 'Import bevestigen' }}
            </button>
            <span class="text-xs text-gray-500 dark:text-slate-400">
                {{ importPreview.filter(r => !r.fatal).length }} klanten worden verwerkt
                <span v-if="importPreview.filter(r => r.fatal).length">
                    · {{ importPreview.filter(r => r.fatal).length }} overgeslagen
                </span>
            </span>
        </div>
    </div>
</template>

<script setup>
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import { ChevronDownIcon, ArrowUpTrayIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    customers: {
        type: Object,
        required: true,
    },
    importPreview: {
        type: Array,
        default: null,
    },
    snelStartImportDetected: {
        type: Boolean,
        default: false,
    },
})

const addCustomerDrawerOpen = ref(false)
const fileInputRef = ref(null)
const actionsMenuRef = ref(null)
const actionsOpen = ref(false)

const handleClickOutside = (e) => {
    if (actionsMenuRef.value && !actionsMenuRef.value.contains(e.target)) {
        actionsOpen.value = false
    }
}
onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))

const previewForm = useForm({ file: null, convert_from_snelstart: false, skip_snelstart_prompt: false })
const triggerFileInput = () => fileInputRef.value?.click()
const handleFileUpload = (e) => {
    const file = e.target.files[0]
    if (!file) return
    previewForm.file = file
    previewForm.convert_from_snelstart = false
    previewForm.skip_snelstart_prompt = false
    e.target.value = ''
    previewForm.post('/customers/import/preview', { forceFormData: true })
}

const snelStartPromptOpen = ref(false)
watch(() => props.snelStartImportDetected, (detected) => {
    if (detected) snelStartPromptOpen.value = true
})

const confirmSnelStartImport = () => {
    snelStartPromptOpen.value = false
    previewForm.convert_from_snelstart = true
    previewForm.post('/customers/import/preview', { forceFormData: true, preserveScroll: true })
}

const declineSnelStartImport = () => {
    snelStartPromptOpen.value = false
    previewForm.skip_snelstart_prompt = true
    previewForm.post('/customers/import/preview', { forceFormData: true, preserveScroll: true })
}

const confirmForm = useForm({})
const handleConfirm = () => {
    confirmForm.post('/customers/import/confirm')
}

const handleCancel = () => {
    router.get('/customers')
}

const newCustomerForm = useForm({
    name: '',
    email: '',
    phone: '',
    address: '',
    postal_code: '',
    city: '',
    country: '',
    location_code: '',
})

function closeCustomerDrawer() {
    addCustomerDrawerOpen.value = false
    newCustomerForm.reset()
    newCustomerForm.clearErrors()
}

function submitCustomer() {
    newCustomerForm.post('/customers', {
        preserveScroll: true,
        onSuccess: () => closeCustomerDrawer(),
    })
}

const canCreate = computed(() => hasPermission('customer.create'))
</script>
