<template>
    <IndexHeaderComponent title="Offertes" subtitle="Wat er aan klanten is aangeboden" search-url="/quotes"
        search-label="Zoek binnen offertes" search-placeholder="Zoek op nummer, titel of klant"
        :search-other-params="otherParams" add-label="Nieuwe offerte" @add="showCreateDrawer = true"
        :can-add="hasPermission('quote.create')" :has-active-filters="hasActiveFilters">
        <template #filters>
            <ListFilters :filters="filters" :query="list" class="grid grid-cols-1 gap-2 sm:grid-cols-2" />
        </template>
    </IndexHeaderComponent>

    <QuoteCreateDrawer v-model="showCreateDrawer" :customers="customers" :customers-use-ajax="customersUseAjax" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="rows.length">
            <div role="row"
                class="hidden lg:grid grid-cols-12 gap-x-2 font-bold text-sm rounded-t-lavoro-sm p-4 bg-lavoro-lightgray dark:bg-slate-800/50">
                <div class="col-span-2">Nummer</div>
                <div class="col-span-4">Titel</div>
                <div class="col-span-2">Klant</div>
                <div class="col-span-1">Datum</div>
                <div class="col-span-1">Geldig tot</div>
                <div class="col-span-1 text-right">Bedrag</div>
                <div class="col-span-1 text-right">Status</div>
            </div>
            <Link v-for="quote in rows" :key="quote.id" :href="`/quotes/${quote.id}`" role="row"
                class="grid grid-cols-12 gap-x-2 p-4 text-sm border-b-lavoro-gray-150 dark:border-b-slate-800 border-b-2 items-center hover:bg-gray-50 dark:hover:bg-slate-800/40">
                <div class="hidden lg:block col-span-2 text-xs font-medium text-gray-500 dark:text-slate-400 tabular-nums">
                    {{ quote.number }}
                </div>
                <div class="col-span-8 lg:col-span-4 flex flex-col min-w-0">
                    <span class="font-bold text-lavoro-darkerblue truncate">{{ quote.title }}</span>
                    <span class="text-xs text-gray-500 dark:text-slate-400 lg:hidden">
                        {{ quote.number }} · {{ quote.customer?.name || '—' }}
                    </span>
                </div>
                <div class="hidden lg:block col-span-2 truncate text-slate-700 dark:text-slate-300">
                    {{ quote.customer?.name || '—' }}
                </div>
                <div class="hidden lg:block col-span-1 text-xs">{{ nlDate(quote.quote_date) }}</div>
                <div class="hidden lg:block col-span-1 text-xs">{{ quote.valid_until ? nlDate(quote.valid_until) : '–' }}</div>
                <div class="col-span-2 lg:col-span-1 text-right tabular-nums">{{ nlCurrency(quote.total) }}</div>
                <div class="col-span-2 lg:col-span-1 text-right text-xs" :class="quoteStatusClass(quote.status)">
                    {{ quote.status }}
                </div>
            </Link>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="quotes.total" :per-page="quotes.per_page" label="offertes" />
                <PaginationComponent :paginator="quotes" />
            </div>
        </div>
        <div v-else class="p-6 text-center text-gray-400 dark:text-slate-500">
            <FilePenIcon class="h-12 w-12 mx-auto mb-3" />
            <p class="text-sm">Geen offertes gevonden</p>
        </div>
    </BoxComponent>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { FilePenIcon } from '@lucide/vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ListFilters from '@/Components/UI/ListFilters.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import QuoteCreateDrawer from '@/Components/Quotes/QuoteCreateDrawer.vue'
import { hasPermission, nlCurrency, nlDate, quoteStatusClass } from '@/Utilities/Utilities'
import { useComboSearch } from '@/Composables/useComboSearch'

const props = defineProps({
    quotes: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    statuses: { type: Array, default: () => [] },
    list: { type: Object, required: true },
})

const OPEN = 'open'

const showCreateDrawer = ref(false)
const rows = computed(() => props.quotes?.data ?? [])

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

/** The list opens on the quotes still waiting for an answer, so emptying the status is said out loud as 'all'. */
const filters = computed(() => [
    {
        param: 'status',
        placeholder: 'Alle statussen',
        everything: 'Alle statussen',
        emptied: 'all',
        options: [
            { id: OPEN, name: 'Lopende offertes' },
            ...props.statuses.map(status => ({ id: status.name, name: status.name })),
        ],
    },
    {
        param: 'customer',
        placeholder: 'Alle klanten',
        everything: 'Alle klanten',
        options: customerOptions.value,
        search: props.customersUseAjax ? searchCustomers : null,
        searching: customersSearching.value,
    },
])

const hasActiveFilters = computed(() => props.list.status !== OPEN || props.list.customer !== null)

const otherParams = computed(() => ({
    status: props.list.status === OPEN ? undefined : props.list.status,
    customer: props.list.customer ?? undefined,
}))
</script>
