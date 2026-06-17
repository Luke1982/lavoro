<template>
    <IndexHeaderComponent title="Werkbonnen" subtitle="Overzicht van alle werkbonnen" search-url="/serviceorders"
        search-label="Zoek binnen werkbonnen" search-placeholder="Zoek op klant, beschrijving of inkoopordernr."
        :search-other-params="filterParams" :paginator="false" :has-active-filters="activeFilters.length > 0">
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-y-4 sm:gap-y-0">
                <div class="flex-grow">
                    <ComboBox :options="stages" v-model="stageFilter" multiple placeholder="Selecteer fase(n)"
                        class="w-full" label="Filter op fase" />
                </div>
                <div class="hidden sm:flex w-1/6 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
            <div v-if="activeFilters.length" class="flex flex-wrap gap-2 mt-3" v-auto-animate>
                <span v-for="filter in activeFilters" :key="filter.key"
                    class="inline-flex items-center gap-x-1.5 rounded-md px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 bg-white dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <span class="text-gray-400 dark:text-slate-400">{{ filter.label }}:</span>
                    {{ filter.value }}
                    <button type="button" @click="filter.clear()"
                        class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                        <span class="sr-only">Verwijder filter</span>
                        <svg viewBox="0 0 14 14"
                            class="h-3.5 w-3.5 stroke-gray-600/75 group-hover:stroke-gray-600 dark:stroke-slate-400 dark:group-hover:stroke-slate-300">
                            <path d="M4 4l6 6m0-6l-6 6" />
                        </svg>
                        <span class="absolute -inset-1" />
                    </button>
                </span>
                <div class="flex sm:hidden p-2 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
        </template>
    </IndexHeaderComponent>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="serviceOrders.data.length">
            <div
                class="hidden md:flex items-center font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm bg-lavoro-lightgray">
                <div class="w-10 flex-none flex items-center justify-center">
                    <AnimatedCheckbox :model-value="allCurrentPageSelected" @update:model-value="toggleSelectAll" />
                </div>
                <div class="flex-1 grid grid-cols-12 p-4">
                    <div class="col-span-2">Klant</div>
                    <div class="col-span-2">Beschrijving</div>
                    <div class="col-span-2">Fase</div>
                    <div class="col-span-2">Extern factuurnr.</div>
                    <div class="col-span-2">Verzending</div>
                    <div class="col-span-1">Aangemaakt</div>
                    <div class="col-span-1 text-right">Acties</div>
                </div>
            </div>
            <div v-auto-animate>
                <div v-for="so in serviceOrders.data" :key="so.id" role="row"
                    class="flex items-center text-sm border-b-lavoro-gray-150 border-b-2">
                    <div class="w-10 flex-none flex items-center justify-center self-stretch">
                        <AnimatedCheckbox :model-value="selectedIds.includes(so.id)"
                            @update:model-value="toggleSelectServiceOrder(so.id)" />
                    </div>
                    <div class="flex-1 grid grid-cols-12 p-4">
                        <div class="col-span-10 sm:col-span-2 flex flex-col">
                            <Link :href="`/serviceorders/${so.id}`" class="font-bold mb-1">
                                {{ so.customer?.name ?? '—' }}
                            </Link>
                            <span v-if="so.external_purchaseorder_no" class="text-slate-600 text-xs">
                                Inkoopordernr.: {{ so.external_purchaseorder_no }}
                            </span>
                            <div class="flex flex-wrap gap-2 mt-2 sm:hidden">
                                <BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">
                                    {{ so.service_order_stage?.name ?? 'Geen fase' }}
                                </BadgeComponent>
                                <BadgeComponent :color="badgeColorFor(so)" :has-dot="false">
                                    {{ serviceOrderPillText(so) }}
                                </BadgeComponent>
                            </div>
                        </div>
                        <div class="col-span-2 items-center hidden sm:flex pr-2 text-slate-700 dark:text-slate-300">
                            <span class="line-clamp-2">{{ so.description || '—' }}</span>
                        </div>
                        <div class="col-span-2 items-center hidden sm:flex pr-2">
                            <EditableTextField type="combobox" :model-value="so.service_order_stage_id"
                                :options="stages" :decoration="false" @update="(val) => updateStage(so, val)">
                                <template #display>
                                    <BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">
                                        {{ so.service_order_stage?.name ?? 'Geen fase' }}
                                    </BadgeComponent>
                                </template>
                            </EditableTextField>
                        </div>
                        <div class="col-span-2 items-center hidden sm:flex pr-2 text-slate-700 dark:text-slate-300">
                            <EditableTextField type="input" :decoration="false" :model-value="so.external_invoice_no"
                                placeholder="—" @update="(val) => updateInvoiceNo(so, val)" />
                        </div>
                        <div class="col-span-2 items-center hidden sm:flex pr-2">
                            <BadgeComponent :color="badgeColorFor(so)" :has-dot="false">
                                {{ serviceOrderPillText(so) }}
                            </BadgeComponent>
                        </div>
                        <div class="col-span-1 items-center hidden sm:flex pr-2 text-slate-700 dark:text-slate-300">
                            {{ nlDate(so.created_at) }}
                        </div>
                        <div class="col-span-2 sm:col-span-1 items-center flex justify-end">
                            <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
                                <Link :href="`/serviceorders/${so.id}`" class="text-sm text-lavoro-darkerblue">
                                    <EyeIcon class="h-5 w-5" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-between bg-white rounded-b-lavoro-sm p-4 dark:bg-slate-900">
                <PageRecordCountComponent :total="serviceOrders.total" :per-page="perPage" label="werkbonnen" />
                <PaginationComponent :paginator="serviceOrders" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <ClipboardDocumentListIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen werkbonnen gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="bulkEditOpen" title="Werkbonnen bewerken"
        :subtitle="`${selectedIds.length} werkbonnen geselecteerd`">
        <div class="divide-y divide-gray-100 dark:divide-slate-700">
            <div class="px-4 sm:px-6 py-4">
                <p
                    class="text-sm text-gray-500 dark:text-slate-400 bg-gray-50 dark:bg-slate-900/40 rounded-md px-3 py-2 border-l-2 border-gray-200 dark:border-slate-600">
                    Vink de velden aan die je wilt aanpassen. Niet-aangevinkte velden worden niet gewijzigd.
                </p>
            </div>
            <div class="flex items-start gap-3 px-4 sm:px-6 py-4">
                <AnimatedCheckbox v-model="bulkEditChecked['_stage_']" class="mt-0.5 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                    <div class="flex items-center flex-wrap gap-2 mb-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Fase</span>
                    </div>
                    <ComboBox :options="stages" v-model="bulkEditValues['_stage_']" placeholder="Selecteer fase"
                        :disabled="!bulkEditChecked['_stage_']" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="bulkEditOpen = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="saveBulkEdit"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>

    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="translate-y-full opacity-0"
            enter-to-class="translate-y-0 opacity-100" leave-active-class="transition ease-in duration-150"
            leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-full opacity-0">
            <div v-if="selectedIds.length"
                class="fixed bottom-0 left-0 right-0 lg:left-72 z-40 bg-gray-100 text-gray-800 border-t border-gray-200 px-6 py-4 flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-4 text-sm">
                    <span class="font-bold text-base">{{ selectedIds.length }} werkbonnen geselecteerd</span>
                    <button type="button" @click="selectedIds = []"
                        class="text-xs text-gray-500 underline hover:text-gray-700">
                        Deselecteer alles
                    </button>
                </div>
                <button type="button" @click="openBulkEditDrawer"
                    class="bg-lavoro-blue text-white font-bold text-sm px-5 py-2 rounded-md hover:opacity-90">
                    Bewerken
                </button>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { ref, computed, reactive, watch, onMounted } from 'vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import AnimatedCheckbox from '@/Components/UI/AnimatedCheckbox.vue'
import { ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'
import { EyeIcon, RotateCcwIcon } from '@lucide/vue'
import { nlDate, serviceOrderPillText, serviceOrderSentState } from '@/Utilities/Utilities'

const { serviceOrders, stages, perPage } = defineProps({
    serviceOrders: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    onlyStage: { type: Array, default: () => [] },
    perPage: { type: Number, default: 25 },
})

const stagesFromUrl = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('onlyStage') || '').split(',').map(Number).filter(Boolean)
    : []
const stageFilter = ref(stagesFromUrl)

watch(stageFilter, val => {
    if (val.length) localStorage.setItem('serviceOrderFilter_stage', val.join(','))
    else localStorage.removeItem('serviceOrderFilter_stage')
})

onMounted(() => {
    if (stagesFromUrl.length) return
    const ls = (localStorage.getItem('serviceOrderFilter_stage') || '').split(',').map(Number).filter(Boolean)
    if (!ls.length) return
    stageFilter.value = ls
    router.get('/serviceorders', { onlyStage: ls.join(',') }, { replace: true, preserveState: true, preserveScroll: true })
})

const filterParams = computed(() => ({
    onlyStage: stageFilter.value.join(','),
}))

const activeFilters = computed(() => {
    return stageFilter.value.flatMap(id => {
        const match = stages.find(s => s.id === id)
        return match ? [{
            key: `stage-${id}`,
            label: 'Fase',
            value: match.name,
            clear: () => { stageFilter.value = stageFilter.value.filter(x => x !== id) },
        }] : []
    })
})

function clearAllFilters() {
    stageFilter.value = []
    localStorage.removeItem('serviceOrderFilter_stage')
}

const selectedIds = ref([])

const allCurrentPageSelected = computed(() =>
    serviceOrders.data.length > 0 &&
    serviceOrders.data.every(so => selectedIds.value.includes(so.id))
)

function toggleSelectServiceOrder(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx === -1) selectedIds.value.push(id)
    else selectedIds.value.splice(idx, 1)
}

function toggleSelectAll() {
    if (allCurrentPageSelected.value) {
        const pageIds = new Set(serviceOrders.data.map(so => so.id))
        selectedIds.value = selectedIds.value.filter(id => !pageIds.has(id))
    } else {
        const existing = new Set(selectedIds.value)
        serviceOrders.data.forEach(so => { if (!existing.has(so.id)) selectedIds.value.push(so.id) })
    }
}

const bulkEditOpen = ref(false)
const bulkEditChecked = reactive({})
const bulkEditValues = reactive({})

function openBulkEditDrawer() {
    bulkEditChecked['_stage_'] = false
    bulkEditValues['_stage_'] = null
    bulkEditOpen.value = true
}

function saveBulkEdit() {
    if (!bulkEditChecked['_stage_'] || !bulkEditValues['_stage_']) return

    router.post('/serviceorders/bulk-update', {
        service_order_ids: selectedIds.value,
        service_order_stage_id: bulkEditValues['_stage_']?.id ?? bulkEditValues['_stage_'],
    }, {
        preserveScroll: true,
        onSuccess: () => {
            bulkEditOpen.value = false
            selectedIds.value = []
        },
    })
}

function updateStage(so, stage_id) {
    router.patch(`/serviceorders/${so.id}`, {
        customer_id: so.customer_id,
        service_order_stage_id: stage_id,
    }, { preserveScroll: true })
}

function updateInvoiceNo(so, val) {
    router.patch(`/serviceorders/${so.id}`, {
        customer_id: so.customer_id,
        external_invoice_no: val,
    }, { preserveScroll: true })
}

function badgeColorFor(so) {
    switch (serviceOrderSentState(so)) {
        case 'both':
        case 'administration':
            return 'green'
        case 'customer':
            return 'blue'
        default:
            return 'gray'
    }
}
</script>
