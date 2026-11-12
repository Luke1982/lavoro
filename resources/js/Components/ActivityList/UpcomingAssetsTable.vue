<template>
    <BoxComponent padding="p-0">
        <div role="table" aria-label="Machines die gepland moeten worden">
            <div role="row"
                :class="[COLUMNS, 'hidden lg:grid items-center gap-x-4 px-4 py-3 rounded-t-lavoro-sm bg-lavoro-lightgray dark:bg-slate-800 text-sm font-bold text-gray-700 dark:text-slate-200 border-b border-gray-200 dark:border-slate-700']">
                <span role="columnheader" class="col-span-2"><span class="sr-only">Selecteer</span></span>
                <SortableColumnHeader v-for="column in SORTABLE_COLUMNS" :key="column.key" :column="column.key"
                    :sort="table.query.sort" :direction="table.query.direction" @sort="visit">
                    {{ column.label }}
                </SortableColumnHeader>
                <span role="columnheader">Status</span>
                <span role="columnheader" class="text-center">Storingen</span>
            </div>

            <div v-for="row in table.rows.data" :key="row.id" role="row"
                :class="[COLUMNS, 'flex items-start gap-3 lg:grid lg:items-center lg:gap-x-4 px-4 py-3 text-sm border-b border-gray-100 dark:border-slate-700/60 transition-colors', isSelected(row) ? 'bg-lavoro-lightblue/60 dark:bg-sky-950/40' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60']">
                <label role="cell" class="flex self-stretch items-center cursor-pointer">
                    <input type="checkbox" :checked="isSelected(row)" @change="toggle(row)"
                        :aria-label="`Selecteer ${row.machine} ${row.serial_number}`"
                        class="cursor-pointer size-4 accent-lavoro-blue" />
                </label>
                <div role="cell" class="hidden sm:block">
                    <img :src="row.image_id ? `/files/images/${row.image_id}` : '/img/placeholder.png'" alt=""
                        class="size-11 rounded-sm border border-gray-200 dark:border-slate-700 object-contain bg-white" />
                </div>
                <div role="cell" class="hidden lg:block min-w-0">
                    <span :title="row.serial_number"
                        class="inline-block max-w-full truncate rounded-md bg-gray-100 dark:bg-slate-800 px-2 py-1 text-xs text-gray-700 dark:text-slate-300 ring-1 ring-gray-200 dark:ring-slate-700">
                        {{ row.serial_number || '—' }}
                    </span>
                </div>
                <div role="cell" class="min-w-0 flex-1">
                    <Link :href="`/assets/${row.id}`"
                        class="font-semibold text-gray-900 dark:text-slate-100 hover:underline">
                        {{ row.machine }}
                    </Link>
                    <div class="text-xs text-gray-500 dark:text-slate-400">
                        {{ row.product_type }}
                        <template v-if="row.parent">
                            &middot; onderdeel van
                            <Link :href="`/assets/${row.parent.id}`" class="underline">{{ row.parent.serial_number }}</Link>
                        </template>
                    </div>
                    <div class="lg:hidden mt-1 text-xs text-gray-600 dark:text-slate-300">
                        {{ [row.serial_number, customerWithOthers(row), row.location, nlDate(row.next_service_date)].filter(Boolean).join(' · ') }}
                    </div>
                    <div v-if="row.service_orders.length" class="mt-1.5 flex flex-wrap gap-1">
                        <BadgeComponent v-for="order in row.service_orders" :key="order.id" color="blue" size="sm"
                            :has-dot="false" :url="`/serviceorders/${order.id}`"
                            :tooltip="`Ga naar werkbon ${order.id}`">
                            <CalendarIcon class="size-3.5" aria-hidden="true" />
                            Werkbon {{ order.id }}<template v-if="order.planned_on"> &middot; gepland {{
                                nlDate(order.planned_on) }}</template>
                        </BadgeComponent>
                    </div>
                </div>
                <div role="cell" class="hidden lg:flex items-center gap-2 min-w-0">
                    <Building2Icon class="size-4 shrink-0 text-lavoro-blue" aria-hidden="true" />
                    <Link :href="`/customers/${row.customer_id}`" :title="row.customer"
                        class="truncate text-gray-700 dark:text-slate-200 hover:underline">
                        {{ row.customer }}
                    </Link>
                    <span v-if="othersOf(row).length" v-tooltip="othersLabel(row)"
                        class="shrink-0 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                        +{{ othersOf(row).length }}
                    </span>
                </div>
                <div role="cell" class="hidden lg:flex items-center gap-2 min-w-0 text-gray-600 dark:text-slate-300">
                    <template v-if="row.location">
                        <MapPinIcon class="size-4 shrink-0 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                        <span class="truncate" :title="row.location">{{ row.location }}</span>
                    </template>
                    <span v-else class="text-gray-300 dark:text-slate-600">—</span>
                </div>
                <div role="cell" class="hidden lg:flex items-center gap-2 whitespace-nowrap text-gray-700 dark:text-slate-300">
                    <CalendarIcon class="size-4 shrink-0 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                    {{ nlDate(row.next_service_date) }}
                </div>
                <div class="flex flex-col items-end gap-1 lg:contents">
                    <div role="cell">
                        <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium text-white"
                            :style="{ backgroundColor: dueGroup(row).color }">
                            {{ dueGroup(row).name }}
                        </span>
                    </div>
                    <div role="cell" class="lg:flex lg:justify-center">
                        <span v-if="row.tickets.length" v-tooltip="ticketTooltip(row)"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset"
                            :class="ticketsRideAlong(row) ? 'bg-lavoro-blue text-white ring-lavoro-blue' : 'bg-lavoro-lightblue text-lavoro-blue ring-lavoro-blue/20 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-500/30'">
                            <WrenchIcon class="size-3.5" aria-hidden="true" />
                            {{ row.tickets.length }}
                        </span>
                        <span v-else class="hidden lg:inline text-xs text-gray-300 dark:text-slate-600">—</span>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="!table.rows.data.length" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
            Geen machines gevonden die aan deze filters voldoen.
        </p>

        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
            <PageRecordCountComponent :total="table.rows.total" :per-page="table.rows.per_page" label="machines" />
            <PaginationComponent :paginator="table.rows" preserve-state />
        </div>
    </BoxComponent>

    <ModalDialog v-model:open="offerOpen" title="Ook meenemen op de werkbon?">
        <div v-if="offer" class="space-y-5">
            <section v-if="offer.tickets.length">
                <label class="flex items-center gap-2 text-sm font-medium cursor-pointer dark:text-slate-200">
                    <input type="checkbox" class="size-4 accent-lavoro-blue" :checked="allChosen('tickets')"
                        :indeterminate="someChosen('tickets')" @change="toggleAll('tickets')" />
                    {{ offer.tickets.length === 1 ? 'De storing van deze machine' : `De ${offer.tickets.length} storingen van deze machine` }}
                </label>
                <div class="mt-2">
                    <TicketSelectCard v-for="ticket in offer.tickets" :key="ticket.id" v-model="chosen.tickets"
                        :ticket="ticket" :customer-id="offer.row.customer_id" />
                </div>
            </section>
            <section v-if="offer.machines.length">
                <label class="flex items-center gap-2 text-sm font-medium cursor-pointer dark:text-slate-200">
                    <input type="checkbox" class="size-4 accent-lavoro-blue" :checked="allChosen('machines')"
                        :indeterminate="someChosen('machines')" @change="toggleAll('machines')" />
                    {{ othersLabel(offer.row, offer.machines.length) }}
                </label>
                <ul
                    class="mt-2 max-h-64 overflow-y-auto divide-y divide-gray-100 rounded-md ring-1 ring-gray-200 dark:divide-slate-700 dark:ring-slate-700">
                    <li v-for="machine in offer.machines" :key="machine.id">
                        <label class="flex items-center gap-3 px-3 py-2 text-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-800">
                            <input v-model="chosen.machines" type="checkbox"
                                :value="{ id: machine.id, customer_id: offer.row.customer_id }"
                                class="size-4 shrink-0 accent-lavoro-blue" />
                            <span class="min-w-0 flex-1 truncate">
                                <span class="font-medium text-gray-900 dark:text-slate-100">{{ machine.machine }}</span>
                                <span class="ml-1.5 text-xs text-gray-500 dark:text-slate-400">{{ machine.serial_number }}</span>
                            </span>
                            <span class="shrink-0 whitespace-nowrap text-xs text-gray-600 dark:text-slate-300">
                                {{ nlDate(machine.next_service_date) }}
                            </span>
                        </label>
                    </li>
                </ul>
            </section>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="offerOpen = false"
                    class="px-4 py-2 text-sm font-medium rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600">
                    Alleen deze machine
                </button>
                <button type="button" @click="acceptOffer"
                    class="px-4 py-2 text-sm font-medium rounded-md bg-lavoro-blue text-white hover:opacity-90">
                    Meenemen
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Building2Icon, CalendarIcon, MapPinIcon, WrenchIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import TicketSelectCard from '@/Components/TicketSelectCard.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import SortableColumnHeader from '@/Components/UI/SortableColumnHeader.vue'
import { useQueryParams } from '@/Composables/useQueryParams'
import { nlDate } from '@/Utilities/Utilities'

/**
 * The upcoming activities as one row per machine. Sorting and paging happen on the server
 * and live in the URL; the selection belongs to the page around it, which turns it into a
 * werkbon. Ticking a machine offers what would make the same trip: its storingen that still
 * wait for a werkbon, and the customer's other machines in the list, on this page or a later
 * one.
 */
const props = defineProps({
    /** { rows: paginator, customerMachines: { [customer_id]: machines in the list }, filters, query } */
    table: { type: Object, required: true },
    selectedAssets: { type: Array, required: true },
    selectedTickets: { type: Array, required: true },
})

const emit = defineEmits(['update:selectedAssets', 'update:selectedTickets'])

const COLUMNS = 'lg:grid-cols-[1rem_2.75rem_8rem_minmax(0,2fr)_minmax(0,1.4fr)_minmax(0,1fr)_7.5rem_9.5rem_4.5rem]'

const SORTABLE_COLUMNS = [
    { key: 'serial', label: 'Serienummer' },
    { key: 'machine', label: 'Machine' },
    { key: 'customer', label: 'Klant' },
    { key: 'location', label: 'Locatie' },
    { key: 'date', label: 'Keuring' },
]

const { visit } = useQueryParams()

const dueGroups = computed(() => Object.fromEntries(props.table.filters.due.map(group => [group.id, group])))
/** The pill takes the colour of the map pin for the same machine, chosen to carry white text. */
const dueGroup = row => dueGroups.value[row.due]

const selectedAssetIds = computed(() => new Set(props.selectedAssets.map(asset => asset.id)))
const selectedTicketIds = computed(() => new Set(props.selectedTickets.map(ticket => ticket.id)))
const isSelected = row => selectedAssetIds.value.has(row.id)
const ticketsRideAlong = row => row.tickets.every(ticket => selectedTicketIds.value.has(ticket.id))
const ticketTooltip = row => row.tickets.length === 1
    ? '1 storing wacht nog op een werkbon'
    : `${row.tickets.length} storingen wachten nog op een werkbon`

/** The customer's other machines in the list, wherever in the list they sit. */
const othersOf = row => (props.table.customerMachines[row.customer_id] ?? []).filter(machine => machine.id !== row.id)
const othersLabel = (row, count = othersOf(row).length) => count === 1
    ? `Nog 1 machine van ${row.customer} in deze lijst`
    : `Nog ${count} machines van ${row.customer} in deze lijst`
const customerWithOthers = (row) => {
    const count = othersOf(row).length
    return count ? `${row.customer} (+${count})` : row.customer
}

const offerOpen = ref(false)
const offer = ref(null)
/** What is ticked in the offer, in the shape the selection keeps. */
const chosen = reactive({ tickets: [], machines: [] })

/** The selection keeps { id, customer_id }; a part counts as the customer its row shows. */
const selectionFor = row => item => ({ id: item.id, customer_id: row.customer_id })
const allChosen = kind => chosen[kind].length === offer.value[kind].length
const someChosen = kind => chosen[kind].length > 0 && !allChosen(kind)
const toggleAll = (kind) => {
    chosen[kind] = allChosen(kind) ? [] : offer.value[kind].map(selectionFor(offer.value.row))
}

/** Unticking a machine takes its storingen off the werkbon with it; they only came along for it. */
function toggle(row) {
    if (isSelected(row)) {
        const rowTicketIds = new Set(row.tickets.map(ticket => ticket.id))
        emit('update:selectedAssets', props.selectedAssets.filter(asset => asset.id !== row.id))
        emit('update:selectedTickets', props.selectedTickets.filter(ticket => !rowTicketIds.has(ticket.id)))
        return
    }

    const select = selectionFor(row)
    emit('update:selectedAssets', [...props.selectedAssets, select(row)])

    const machines = othersOf(row).filter(machine => !selectedAssetIds.value.has(machine.id))
    if (row.tickets.length || machines.length) {
        offer.value = { row, tickets: row.tickets, machines }
        chosen.tickets = row.tickets.map(select)
        chosen.machines = machines.map(select)
        offerOpen.value = true
    }
}

function acceptOffer() {
    emit('update:selectedAssets', [...props.selectedAssets, ...chosen.machines])
    emit('update:selectedTickets', [...props.selectedTickets, ...chosen.tickets])
    offerOpen.value = false
}
</script>
