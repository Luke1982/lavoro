<template>
    <div>
        <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-200 mb-2">Uitgevoerde werkzaamheden</h3>

        <p v-if="rows.length === 0" class="text-sm text-gray-400 dark:text-slate-500">
            Er zijn geen taken op deze werkbon.
        </p>

        <ul v-else class="divide-y divide-gray-100 dark:divide-slate-800/60">
            <li v-for="row in rows" :key="row.id" class="flex items-start gap-3 py-3">
                <component :is="row.status.icon" :class="['w-5 h-5 mt-0.5 flex-none', row.status.classes]" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-gray-800 dark:text-slate-200">{{ row.title }}</p>
                    <p v-if="row.description" class="text-sm text-gray-600 dark:text-slate-400 mt-0.5">
                        {{ row.description }}
                    </p>
                    <p v-if="row.product" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ row.product }}</p>
                    <p v-if="row.serials.length" class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                        Serienummer{{ row.serials.length > 1 ? 's' : '' }}: {{ row.serials.join(' · ') }}
                    </p>
                    <p v-if="row.reason" class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                        Reden: {{ row.reason }}
                    </p>
                    <p v-if="row.signature" class="text-xs text-gray-500 dark:text-slate-400 mt-1">
                        {{ row.signature }}
                    </p>
                </div>
                <span :class="['flex-none text-xs font-medium mt-0.5', row.status.classes]">
                    {{ row.status.label }}
                </span>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { CircleCheckBig, CircleSlash, Circle } from '@lucide/vue'
import { nlDate, nlTime, taskInstanceTitle, taskInstanceDescription } from '@/Utilities/Utilities'

const props = defineProps({
    instances: { type: Array, default: () => [] },
})

const STATUSES = {
    cancelled: { icon: CircleSlash, label: 'Geannuleerd', classes: 'text-gray-400 dark:text-slate-500' },
    complete: { icon: CircleCheckBig, label: 'Uitgevoerd', classes: 'text-green-600 dark:text-green-500' },
    open: { icon: Circle, label: 'Niet afgerond', classes: 'text-amber-500 dark:text-amber-400' },
}

/**
 * Worked out once instead of per field in the template, so each task is asked about only
 * once and the markup is left with nothing to do but show what is there.
 *
 * Serial numbers come from the machines actually registered, not from the slots the task
 * expected: what stands there is what the customer is handed, even after the quantity was
 * adjusted. Same source as the werkbon PDF.
 */
const rows = computed(() => props.instances.map(instance => ({
    id: instance.id,
    title: taskInstanceTitle(instance),
    description: taskInstanceDescription(instance),
    product: instance.product
        ? `${instance.quantity}× ${instance.product.brand?.name ?? ''} ${instance.product.model}`.replace(/\s+/g, ' ')
        : null,
    serials: (instance.assets ?? []).map(asset => asset.serial_number).filter(Boolean),
    reason: instance.is_cancelled ? instance.cancellation_reason : null,
    signature: instance.signed_by
        ? `Ondertekend door ${instance.signed_by} op ${nlDate(instance.signed_at)} om ${nlTime(instance.signed_at)}`
        : null,
    status: instance.is_cancelled
        ? STATUSES.cancelled
        : (instance.is_complete ? STATUSES.complete : STATUSES.open),
})))
</script>
