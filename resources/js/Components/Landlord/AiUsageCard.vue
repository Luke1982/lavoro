<template>
    <PanelSection>
        <template #title>
            <span class="flex items-baseline justify-between gap-3">
                <span>AI-verbruik deze maand</span>
                <span class="text-sm font-normal text-slate-500 dark:text-slate-400">
                    {{ euro(ai.spent_cents) }} van {{ euro(ai.allowance_cents) }}
                </span>
            </span>
        </template>

        <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
            <div class="h-full rounded-full transition-all" :class="barColor" :style="{ width: barWidth }" />
        </div>

        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ percentage }}% van de limiet
            <template v-if="overCents > 0">
                &middot; <span class="font-semibold text-red-700 dark:text-red-300">{{ euro(overCents) }} erboven</span>, gaat van het bijgekochte tegoed af
            </template>
            <template v-if="ai.is_default"> &middot; standaardlimiet uit de catalogus</template>
        </p>
    </PanelSection>
</template>

<script setup>
import { computed } from 'vue'
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { euro } from './money.js'

const props = defineProps({
    ai: { type: Object, required: true },
})

const percentage = computed(() => (props.ai.allowance_cents > 0
    ? Math.round((props.ai.spent_cents / props.ai.allowance_cents) * 100)
    : (props.ai.spent_cents > 0 ? 100 : 0)))

const barWidth = computed(() => Math.min(100, percentage.value) + '%')

const overCents = computed(() => Math.max(0, props.ai.spent_cents - props.ai.allowance_cents))

const barColor = computed(() => {
    if (percentage.value >= 100) {
        return 'bg-red-500'
    }

    return percentage.value >= 75 ? 'bg-amber-500' : 'bg-emerald-500'
})
</script>
