<template>
    <IndexHeaderComponent title="Werkbonnen" subtitle="Overzicht van alle werkbonnen"
        search-url="/serviceorders" search-label="Zoek binnen werkbonnen"
        search-placeholder="Zoek op klant, beschrijving of inkoopordernr."
        :search-other-params="filterParams" :paginator="false"
        :has-active-filters="activeFilters.length > 0">
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-y-4 sm:gap-y-0">
                <div class="flex-grow">
                    <div class="flex items-end gap-2">
                        <ComboBox :options="stages" v-model="stageFilter"
                            placeholder="Selecteer fase" class="w-full" label="Filter op fase" />
                        <button type="button" @click="stageFilter = null"
                            class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600"
                            v-tooltip="'Reset filter op fase'">
                            <XCircleIcon class="h-5 w-5" />
                        </button>
                    </div>
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
            <div class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Klant</div>
                <div class="col-span-3">Beschrijving</div>
                <div class="col-span-2">Fase</div>
                <div class="col-span-2">Verzending</div>
                <div class="col-span-1">Aangemaakt</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="so in serviceOrders.data" :key="so.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
                <div class="col-span-10 sm:col-span-3 flex flex-col">
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
                <div class="col-span-3 items-center hidden sm:flex pr-2 text-slate-700 dark:text-slate-300">
                    <span class="line-clamp-2">{{ so.description || '—' }}</span>
                </div>
                <div class="col-span-2 items-center hidden sm:flex pr-2">
                    <EditableTextField type="combobox" :model-value="so.service_order_stage_id" :options="stages"
                        :decoration="false"
                        @update="(val) => updateStage(so, val)">
                        <template #display>
                            <BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">
                                {{ so.service_order_stage?.name ?? 'Geen fase' }}
                            </BadgeComponent>
                        </template>
                    </EditableTextField>
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
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import { XCircleIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'
import { EyeIcon, RotateCcwIcon } from '@lucide/vue'
import { nlDate, serviceOrderPillText, serviceOrderSentState } from '@/Utilities/Utilities'

const { serviceOrders, stages, perPage } = defineProps({
    serviceOrders: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    onlyStage: { type: [Number, String, null], default: null },
    perPage: { type: Number, default: 25 },
})

const stageFromUrl = typeof window !== 'undefined'
    ? Number(new URLSearchParams(window.location.search).get('onlyStage')) || null
    : null
const stageFilter = ref(stageFromUrl)

const filterParams = computed(() => ({
    onlyStage: stageFilter.value ?? '',
}))

const activeFilters = computed(() => {
    const out = []
    if (stageFilter.value) {
        const match = stages.find(s => s.id === stageFilter.value)
        if (match) {
            out.push({
                key: `stage-${match.id}`,
                label: 'Fase',
                value: match.name,
                clear: () => { stageFilter.value = null },
            })
        }
    }
    return out
})

function clearAllFilters() {
    stageFilter.value = null
}

function updateStage(so, stage_id) {
    router.patch(`/serviceorders/${so.id}`, {
        customer_id: so.customer_id,
        service_order_stage_id: stage_id,
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
