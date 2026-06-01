<template>
    <BoxComponent extra-classes="flex flex-col h-full" padding="flex-1 min-h-0 overflow-y-auto">
        <div class="flex flex-col gap-2">
            <div class="text-xs px-3 text-lavoro-dark pt-2">Niet ingeplande werkbonnen</div>
            <div v-for="so in serviceOrders" :key="so.id" draggable="true" @dragstart="onDragStart($event, so)"
                @dragend="onDragEnd"
                class="group cursor-grab active:cursor-grabbing select-none border-b-1 p-3 border-b-lavoro-gray-150 transition"
                :title="`Sleep naar de planning om in te plannen — werkbon #${so.id}`">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-1">
                        #{{ so.id }}
                        <ExclamationTriangleIcon v-if="so.events_count > 0"
                            class="size-4 text-amber-500"
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
                <div class="mt-1.5 flex items-center gap-1 text-[10px] text-gray-400 dark:text-slate-500">
                    <ArrowsRightLeftIcon class="size-3" />
                    <span>Sleep naar de planning (2 uur)</span>
                </div>
            </div>

            <div v-if="serviceOrders.length === 0"
                class="flex flex-col items-center justify-center gap-2 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                <CheckCircleIcon class="size-8 text-emerald-500" />
                Alle werkbonnen zijn ingepland.
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue'
import { ArrowsRightLeftIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { nlDate } from '@/Utilities/Utilities'
import { setServiceOrderDragData } from '@/Utilities/plannerDnd'

defineProps({
    serviceOrders: { type: Array, default: () => [] },
})

function onDragStart(e, so) {
    setServiceOrderDragData(e, so)
    e.currentTarget.classList.add('opacity-40')
}

function onDragEnd(e) {
    e.currentTarget.classList.remove('opacity-40')
}
</script>
