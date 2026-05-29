<template>
    <BoxComponent extra-classes="flex flex-col h-full" padding="flex-1 min-h-0 overflow-y-auto p-3">
        <template #header>
            <div class="flex items-center justify-between">
                <span>Niet ingeplande werkbonnen</span>
                <span class="rounded-full bg-emerald-900/60 px-2 py-0.5 text-xs">{{ serviceOrders.length }}</span>
            </div>
        </template>

        <div class="flex flex-col gap-2">
            <div v-for="so in serviceOrders" :key="so.id"
                draggable="true"
                @dragstart="onDragStart($event, so)"
                @dragend="onDragEnd"
                class="group cursor-grab active:cursor-grabbing select-none rounded-lavoro-sm border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 shadow-sm hover:border-lavoro-blue hover:shadow transition"
                :title="`Sleep naar de planning om in te plannen — werkbon #${so.id}`">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">#{{ so.id }}</span>
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
import BoxComponent from './BoxComponent.vue'
import { ArrowsRightLeftIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { nlDate } from '@/Utilities/Utilities'

defineProps({
    serviceOrders: { type: Array, default: () => [] },
})

function onDragStart(e, so) {
    const payload = {
        name: so.description ? so.description.slice(0, 255) : `Werkbon #${so.id}`,
        description: so.description || '',
        duration_minutes: 120,
        eventable_type: '\\App\\Models\\ServiceOrder',
        eventable_id: so.id,
        customer_id: so.customer_id ?? so.customer?.id ?? null,
    }
    e.dataTransfer.setData('application/x-planner-payload', JSON.stringify(payload))
    e.dataTransfer.effectAllowed = 'copy'
    e.currentTarget.classList.add('opacity-40')
}

function onDragEnd(e) {
    e.currentTarget.classList.remove('opacity-40')
}
</script>
