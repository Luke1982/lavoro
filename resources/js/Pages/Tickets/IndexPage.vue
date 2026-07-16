<template>
    <div
        class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-800/60 shadow-sm dark:shadow-none mb-4 overflow-hidden">
        <h2 class="sr-only">Ticket statistieken</h2>
        <div
            class="grid grid-cols-1 md:grid-cols-3 divide-y divide-gray-100 md:divide-y-0 md:divide-x md:divide-gray-100 dark:divide-slate-700/60">
            <div class="p-6">
                <StatCard label="Open" :value="openCount" :baseline="avgCount" :delta="openPctVsAvg" type="open" />
            </div>
            <div class="p-6">
                <StatCard label="In behandeling" :value="pendingCount" :baseline="avgCount"
                    :delta="pendingPctVsAvg" type="pending" />
            </div>
            <div class="p-6">
                <StatCard label="Gesloten" :value="closedCount" :baseline="avgCount" :delta="closedPctVsAvg"
                    type="closed" />
            </div>
        </div>
    </div>

    <IndexHeaderComponent title="Storingen" subtitle="Overzicht van alle storingen" search-url="/tickets"
        search-placeholder="Onderwerp, product, type, serienummer of klant" :paginator="false"
        :search-other-params="computedOtherParams" :has-active-filters="hasActiveFilters">
        <template #actions>
            <button type="button" @click="openMap"
                class="rounded-lavoro-sm bg-lavoro-blue text-white pl-3 pr-2 sm:px-5 py-3 cursor-pointer text-sm flex items-center">
                <MapIcon class="size-5 inline-block mr-2" />
                <span class="hidden sm:inline">Kaart</span>
            </button>
        </template>
        <template #filters>
            <div class="flex flex-col gap-4 w-full">
                <div class="flex flex-col md:flex-row gap-4 w-full">
                    <div class="flex-1">
                        <ComboBox :options="statusOptions" v-model="selectedStatuses" multiple label="Statussen"
                            placeholder="Filter op status" :initial-ids="selectedStatuses" />
                    </div>
                    <div class="flex-1">
                        <ComboBox :options="priorityOptions" v-model="selectedPriorities" multiple
                            label="Prioriteiten" placeholder="Filter op prioriteit"
                            :initial-ids="selectedPriorities" />
                    </div>
                </div>
                <div class="flex flex-col md:flex-row gap-4 w-full">
                    <div class="flex-1">
                        <TextInput v-model="statusCodeSearch" label="Storingscode"
                            placeholder="Filter op storingscode" />
                    </div>
                    <div class="flex-1">
                        <ComboBox :options="closedByOptions" v-model="selectedClosedByIds" multiple
                            label="Gesloten door" placeholder="Filter op gebruiker"
                            :initial-ids="selectedClosedByIds" />
                    </div>
                </div>
            </div>
        </template>
    </IndexHeaderComponent>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="tickets.data.length">
            <div
                class="hidden lg:flex items-center font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm bg-lavoro-lightgray">
                <div class="w-10 flex-none flex items-center justify-center">
                    <AnimatedCheckbox
                        :model-value="allCurrentPageSelected"
                        @update:model-value="toggleSelectAll"
                    />
                </div>
                <div class="flex-1 grid grid-cols-[repeat(18,minmax(0,1fr))] p-4">
                    <div class="col-span-3">Onderwerp</div>
                    <div class="col-span-2">Product</div>
                    <div class="col-span-2">Type</div>
                    <div class="col-span-1">Serienr.</div>
                    <div class="col-span-2">Klant</div>
                    <div class="col-span-3">Adres</div>
                    <div class="col-span-2">Prioriteit</div>
                    <div class="col-span-2">Status</div>
                    <div class="col-span-1 text-right">Acties</div>
                </div>
            </div>
            <div v-auto-animate>
            <div v-for="ticket in tickets.data" :key="ticket.id" role="row"
                class="flex items-center text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="w-10 flex-none flex items-center justify-center self-stretch">
                    <AnimatedCheckbox
                        :model-value="selectedIds.includes(ticket.id)"
                        @update:model-value="toggleSelectTicket(ticket.id)"
                    />
                </div>
                <div class="flex-1 grid grid-cols-[repeat(18,minmax(0,1fr))] p-4">
                <div class="col-span-[15] lg:col-span-3 flex flex-col">
                    <Link :href="`/tickets/${ticket.id}`" class="font-bold mb-1 text-lavoro-darkerblue">
                        {{ ticket.subject }}
                    </Link>
                    <div class="flex flex-wrap gap-1 lg:hidden mt-1">
                        <span
                            :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset', ticketStatusClasses(ticket.status)]">
                            {{ ticket.status }}
                        </span>
                        <span
                            :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset', ticketPriorityClasses(ticket.priority)]">
                            {{ ticket.priority }}
                        </span>
                    </div>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2 text-slate-700 dark:text-slate-300">
                    {{ ticket.asset.product.brand.name }} {{ ticket.asset.product.model }}
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <Link :href="`/producttypes?search=${ticket.asset.product.product_type.name}`"
                        class="text-lavoro-darkerblue underline">
                        {{ ticket.asset.product.product_type.name }}
                    </Link>
                </div>
                <div class="col-span-1 items-center hidden lg:flex pr-2">
                    <Link :href="`/assets/${ticket.asset.id}`" class="text-lavoro-darkerblue underline truncate"
                        :title="ticket.asset.serial_number">
                        {{ ticket.asset.serial_number }}
                    </Link>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <div class="flex flex-col min-w-0">
                        <Link :href="`/customers/${ticket.asset.customer.id}`"
                            class="text-lavoro-darkerblue underline truncate">
                            {{ ticket.asset.customer.name }}
                        </Link>
                        <Link v-if="ticket.asset.linked_location" :href="`/locations/${ticket.asset.linked_location.id}`"
                            class="text-xs text-lavoro-blue truncate"
                            :title="ticket.asset.linked_location.title">
                            {{ ticket.asset.linked_location.title }}
                        </Link>
                    </div>
                </div>
                <div class="col-span-3 items-center hidden lg:flex pr-2">
                    <a v-if="addressSource(ticket).address" :href="mapsLinkFromCustomer(addressSource(ticket))"
                        target="_blank" rel="noopener"
                        class="text-lavoro-darkerblue underline truncate" :title="fullAddress(addressSource(ticket))">
                        {{ fullAddress(addressSource(ticket)) }}
                    </a>
                    <span v-else class="text-slate-400">&mdash;</span>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <EditableTextField type="combobox" v-model="ticket.priority" :options="priorityRowOptions"
                        :readonly="!hasPermission('ticket.alter_priority')" :decoration="false" class="w-full"
                        @update:modelValue="value => updatePriority(ticket, value)">
                        <template #display>
                            <BadgeComponent :color="priorityBadgeColor(ticket.priority)" :has-dot="false">
                                {{ ticket.priority }}
                            </BadgeComponent>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <span
                        :class="['inline-flex items-center rounded px-2 py-1 text-xs font-medium ring-1 ring-inset', ticketStatusClasses(ticket.status)]">
                        {{ ticket.status }}
                    </span>
                </div>
                <div class="col-span-[3] lg:col-span-1 flex items-center justify-end">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <Link :href="`/tickets/${ticket.id}`" class="text-sm text-lavoro-darkerblue">
                            <EyeIcon class="h-5 w-5" />
                        </Link>
                    </div>
                </div>
                </div>
            </div>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="tickets.total" :per-page="tickets.per_page" label="storingen" />
                <PaginationComponent :paginator="tickets" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <AlertCircleIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen storingen gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="bulkEditOpen"
        title="Storingen bewerken"
        :subtitle="`${selectedIds.length} storingen geselecteerd`">
        <div class="divide-y divide-gray-100 dark:divide-slate-700">
            <div class="px-4 sm:px-6 py-4">
                <p class="text-sm text-gray-500 dark:text-slate-400 bg-gray-50 dark:bg-slate-900/40 rounded-md px-3 py-2 border-l-2 border-gray-200 dark:border-slate-600">
                    Vink de velden aan die je wilt aanpassen. Niet-aangevinkte velden worden niet gewijzigd.
                </p>
            </div>
            <div class="flex items-start gap-3 px-4 sm:px-6 py-4">
                <AnimatedCheckbox
                    v-model="bulkEditChecked['_status_']"
                    class="mt-0.5 flex-shrink-0"
                />
                <div class="flex-1 min-w-0">
                    <div class="flex items-center flex-wrap gap-2 mb-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Status</span>
                    </div>
                    <ComboBox
                        :options="statusOptions"
                        v-model="bulkEditValues['_status_']"
                        placeholder="Selecteer status"
                        :disabled="!bulkEditChecked['_status_']"
                    />
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
                    <span class="font-bold text-base">{{ selectedIds.length }} storingen geselecteerd</span>
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
import { computed, reactive, ref, watch, onMounted } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import AnimatedCheckbox from '@/Components/UI/AnimatedCheckbox.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import { EyeIcon, AlertCircleIcon, MapIcon } from '@lucide/vue'
import { hasPermission, mapsLinkFromCustomer, ticketStatusClasses, ticketPriorityClasses } from '@/Utilities/Utilities'

const props = defineProps({
    tickets: { type: Object, required: true },
    search: { type: String, default: '' },
    openCount: { type: Number, required: true },
    pendingCount: { type: Number, required: true },
    closedCount: { type: Number, required: true },
    avgCount: { type: Number, required: true },
    openPctVsAvg: { type: Number, required: true },
    pendingPctVsAvg: { type: Number, required: true },
    closedPctVsAvg: { type: Number, required: true },
    activeStatuses: { type: Array, default: () => [] },
    activePriorities: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    priorityOptions: { type: Array, default: () => [] },
    activeStatusCodeSearch: { type: String, default: '' },
    activeClosedByIds: { type: Array, default: () => [] },
    closedByOptions: { type: Array, default: () => [] },
})

const selectedStatuses = ref(props.activeStatuses.slice())
const selectedPriorities = ref(props.activePriorities.slice())
const statusCodeSearch = ref(props.activeStatusCodeSearch)
const selectedClosedByIds = ref(props.activeClosedByIds.slice())

watch(selectedStatuses, val => localStorage.setItem('ticketFilter_statuses', val.join(',')))
watch(selectedPriorities, val => localStorage.setItem('ticketFilter_priorities', val.join(',')))
watch(statusCodeSearch, val => localStorage.setItem('ticketFilter_statusCode', val))
watch(selectedClosedByIds, val => localStorage.setItem('ticketFilter_closedByIds', val.join(',')))

const computedOtherParams = computed(() => ({
    statuses: selectedStatuses.value.join(','),
    priorities: selectedPriorities.value.join(','),
    status_code_search: statusCodeSearch.value,
    closed_by_ids: selectedClosedByIds.value.join(','),
}))

const hasActiveFilters = computed(() =>
    selectedStatuses.value.length > 0 || selectedPriorities.value.length > 0
    || !!statusCodeSearch.value || selectedClosedByIds.value.length > 0
)

onMounted(() => {
    if (props.activeStatuses.length || props.activePriorities.length || props.activeStatusCodeSearch || props.activeClosedByIds.length) return
    const lsStatuses = (localStorage.getItem('ticketFilter_statuses') || '').split(',').filter(Boolean)
    const lsPriorities = (localStorage.getItem('ticketFilter_priorities') || '').split(',').filter(Boolean)
    const lsStatusCode = localStorage.getItem('ticketFilter_statusCode') || ''
    const lsClosedByIds = (localStorage.getItem('ticketFilter_closedByIds') || '').split(',').filter(Boolean)
    if (!lsStatuses.length && !lsPriorities.length && !lsStatusCode && !lsClosedByIds.length) return
    selectedStatuses.value = lsStatuses
    selectedPriorities.value = lsPriorities
    statusCodeSearch.value = lsStatusCode
    selectedClosedByIds.value = lsClosedByIds
    router.get('/tickets', {
        statuses: lsStatuses.join(','),
        priorities: lsPriorities.join(','),
        status_code_search: lsStatusCode,
        closed_by_ids: lsClosedByIds.join(','),
    }, { replace: true, preserveState: true, preserveScroll: true })
})

const selectedIds = ref([])

const allCurrentPageSelected = computed(() =>
    props.tickets.data.length > 0 &&
    props.tickets.data.every(t => selectedIds.value.includes(t.id))
)

function toggleSelectTicket(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx === -1) selectedIds.value.push(id)
    else selectedIds.value.splice(idx, 1)
}

function toggleSelectAll() {
    if (allCurrentPageSelected.value) {
        const pageIds = new Set(props.tickets.data.map(t => t.id))
        selectedIds.value = selectedIds.value.filter(id => !pageIds.has(id))
    } else {
        const existing = new Set(selectedIds.value)
        props.tickets.data.forEach(t => { if (!existing.has(t.id)) selectedIds.value.push(t.id) })
    }
}

const bulkEditOpen = ref(false)
const bulkEditChecked = reactive({})
const bulkEditValues = reactive({})

function openBulkEditDrawer() {
    bulkEditChecked['_status_'] = false
    bulkEditValues['_status_'] = null
    bulkEditOpen.value = true
}

function saveBulkEdit() {
    if (!bulkEditChecked['_status_'] || !bulkEditValues['_status_']) return

    const selectedId = bulkEditValues['_status_']
    const statusOption = props.statusOptions.find(o => o.id === selectedId)
    if (!statusOption) return

    router.post('/tickets/bulk-update', {
        ticket_ids: selectedIds.value,
        status: statusOption.name,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            bulkEditOpen.value = false
            selectedIds.value = []
        },
    })
}

// Combobox options for the inline priority editor: id kept equal to the display
// value so it can bind directly to ticket.priority without an id<->name lookup.
const priorityRowOptions = computed(() => props.priorityOptions.map(o => ({ id: o.name, name: o.name })))

function priorityBadgeColor(priority) {
    const p = (priority || '').toLowerCase()
    if (p === 'hoog') return 'red'
    if (p === 'normaal') return 'yellow'
    if (p === 'laag') return 'green'
    return 'gray'
}

function updatePriority(ticket, value) {
    router.patch(`/tickets/${ticket.id}`, { priority: value }, { preserveScroll: true, preserveState: true })
}

/**
 * A ticket's work happens at the asset's location when it has one, otherwise at
 * the customer's own address. Both shapes carry address/postal_code/city.
 */
function addressSource(ticket) {
    return ticket.asset.linked_location ?? ticket.asset.customer
}

function fullAddress(addressable) {
    return [addressable.address, addressable.postal_code, addressable.city].filter(Boolean).join(' ')
}

function openMap() {
    const params = new URLSearchParams(computedOtherParams.value)
    window.open(`/tickets/map?${params.toString()}`, 'ticketsMap', 'width=1200,height=800')
}
</script>
