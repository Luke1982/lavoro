<template>
    <BoxComponent extra-classes="flex flex-col h-full" padding="flex-1 min-h-0 overflow-y-auto">
        <div class="flex flex-col gap-2">
            <div class="text-xs px-3 text-lavoro-dark pt-2">Niet ingeplande werkbonnen</div>

            <div v-if="serviceOrders.length" class="px-3">
                <label class="flex items-center gap-1.5 border border-gray-300 dark:border-slate-700 rounded px-2 py-1 focus-within:border-lavoro-blue">
                    <MagnifyingGlassIcon class="size-3.5 text-gray-400 shrink-0" />
                    <input v-model="searchQuery" type="text" placeholder="Zoeken op klant of omschrijving..."
                        class="flex-1 min-w-0 text-xs dark:bg-slate-800 focus:outline-none" />
                </label>
            </div>

            <div class="flex flex-col divide-y divide-lavoro-gray-150" v-auto-animate>
                <div v-for="so in visibleServiceOrders" :key="so.id" draggable="true" @dragstart="onDragStart($event, so)"
                    @dragend="onDragEnd"
                    class="group cursor-grab active:cursor-grabbing select-none p-3 transition"
                    :title="`Sleep naar de planning om in te plannen — werkbon #${so.id}`">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-1">
                            #{{ so.id }}
                            <ExclamationTriangleIcon v-if="so.events_count > 0" class="size-4 text-amber-500"
                                v-tooltip="`Let op: deze werkbon heeft al ${so.events_count} eerdere afspra(a)k(en) gekoppeld`" />
                        </span>
                        <span class="text-xs text-gray-400 dark:text-slate-500 shrink-0">{{ nlDate(so.created_at) }}</span>
                    </div>
                    <div v-if="so.customer" class="text-xs font-medium text-lavoro-blue truncate">
                        {{ so.customer.name }}
                    </div>
                    <p v-if="so.description" class="mt-1 text-xs text-gray-600 dark:text-slate-400 line-clamp-2">
                        {{ so.description }}
                    </p>
                    <ul v-if="so.task_instances?.length" class="mt-1.5 space-y-0.5">
                        <li v-for="ti in so.task_instances.slice(0, maxVisibleTasks)" :key="ti.id"
                            class="text-xs text-gray-600 dark:text-slate-400 flex items-start gap-1.5">
                            <span class="size-1 rounded-full bg-gray-400 dark:bg-slate-500 shrink-0 mt-1.5"></span>
                            <span class="min-w-0">
                                {{ effectiveTitle(ti) }}
                                <span v-if="ti.product" class="text-indigo-500 dark:text-indigo-400">
                                    — {{ ti.quantity }}× {{ ti.product.brand.name }} {{ ti.product.model }}
                                </span>
                            </span>
                        </li>
                        <li v-if="so.task_instances.length > maxVisibleTasks"
                            class="text-xs text-gray-400 dark:text-slate-500 pl-2.5">
                            +{{ so.task_instances.length - maxVisibleTasks }} meer
                        </li>
                    </ul>
                    <div class="mt-1.5 flex items-center gap-1 text-[10px] text-gray-400 dark:text-slate-500">
                        <ArrowsRightLeftIcon class="size-3" />
                        <span>Sleep naar de planning (standaard min.)</span>
                    </div>
                </div>

                <div v-if="serviceOrders.length === 0"
                    class="flex flex-col items-center justify-center gap-2 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                    <CheckCircleIcon class="size-8 text-emerald-500" />
                    Alle werkbonnen zijn ingepland.
                </div>

                <div v-else-if="filteredServiceOrders.length === 0"
                    class="flex flex-col items-center justify-center gap-2 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                    <MagnifyingGlassIcon class="size-8 text-gray-300 dark:text-slate-600" />
                    Geen werkbonnen gevonden voor "{{ searchQuery }}".
                </div>
            </div>

            <button v-if="filteredServiceOrders.length > maxVisible" type="button"
                class="flex items-center justify-center gap-1 px-3 py-2 text-xs font-medium text-lavoro-blue hover:underline"
                @click="isExpanded = !isExpanded">
                <template v-if="isExpanded">
                    <ChevronUpIcon class="size-3.5" />
                    Toon minder
                </template>
                <template v-else>
                    <ChevronDownIcon class="size-3.5" />
                    Toon alle ({{ filteredServiceOrders.length }})
                </template>
            </button>
        </div>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue'
import {
    ArrowsRightLeftIcon, CheckCircleIcon, ExclamationTriangleIcon,
    MagnifyingGlassIcon, ChevronDownIcon, ChevronUpIcon,
} from '@heroicons/vue/24/outline'
import { nlDate } from '@/Utilities/Utilities'
import { setServiceOrderDragData } from '@/Utilities/plannerDnd'
import { useExpandableFilter } from '@/Composables/useExpandableFilter'

const props = defineProps({
    serviceOrders: { type: Array, default: () => [] },
})

const maxVisible = 4
const maxVisibleTasks = 5

const {
    searchQuery,
    isExpanded,
    filteredItems: filteredServiceOrders,
    visibleItems: visibleServiceOrders,
} = useExpandableFilter(() => props.serviceOrders, (so, query) =>
    so.customer?.name?.toLowerCase().includes(query) ||
    so.description?.toLowerCase().includes(query),
    maxVisible)

function effectiveTitle(ti) {
    return ti.title || ti.service_order_task?.title || '(geen titel)'
}

function onDragStart(e, so) {
    setServiceOrderDragData(e, so)
    e.currentTarget.classList.add('opacity-40')
}

function onDragEnd(e) {
    e.currentTarget.classList.remove('opacity-40')
}
</script>
