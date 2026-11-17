<template>
    <div class="w-[24rem] p-4 text-gray-900 dark:text-slate-100">
        <div class="flex items-end justify-between text-[10px] font-semibold uppercase tracking-wide text-gray-400">
            <span>Week 47</span>
            <span>Week 48</span>
        </div>
        <div class="relative mt-1 grid grid-cols-10 gap-0.5">
            <div v-for="(day, index) in days" :key="index"
                class="flex h-20 flex-col items-center rounded bg-gray-50 pt-1 text-[10px] text-gray-400 dark:bg-slate-800"
                :class="index === 5 ? 'ml-1.5' : ''">
                {{ day }}
            </div>
            <div class="scene-original absolute top-6 h-10 rounded border-l-4 border-sky-500 bg-sky-200 shadow-sm"
                :style="{ left: column(1), width: 'calc(10% - 4px)' }" />
            <div v-for="(target, index) in targets" :key="target" class="scene-copy absolute top-6 h-10 rounded border-l-4 border-sky-500 bg-sky-100"
                :style="{ left: column(1), width: 'calc(10% - 4px)', '--distance': distance(target), animationDelay: (index * 0.3) + 's' }" />
        </div>
        <p class="mt-3 text-center text-xs font-medium text-lavoro-blue">{{ caption }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue'

/**
 * An afspraak on Tuesday being copied: to Wednesday, to the rest of the week, or
 * to Tuesday a week later. A real planner day is wider than the screen, so the
 * copy is shown on a small week instead of on the planner itself.
 */
const props = defineProps({
    variant: { type: String, default: 'tomorrow' },
})

const days = ['MA', 'DI', 'WO', 'DO', 'VR', 'MA', 'DI', 'WO', 'DO', 'VR']

const targets = computed(() => ({ tomorrow: [2], rest: [2, 3, 4], 'next-week': [6] })[props.variant] ?? [2])

const caption = computed(() => ({
    tomorrow: 'Naar morgen',
    rest: 'Rest van de week',
    'next-week': 'Naar volgende week',
})[props.variant] ?? '')

function column(index) {
    return 'calc(' + index * 10 + '% + ' + (index >= 5 ? 6 : 0) + 'px + 2px)'
}

function distance(target) {
    return 'calc(' + (target - 1) * 100 + '% + ' + (target - 1) * 4.2 + 'px + ' + (target >= 5 ? 6 : 0) + 'px)'
}
</script>

<style scoped>
.scene-original {
    box-shadow: 0 0 0 2px rgb(198 255 0 / 0.8);
}

.scene-copy {
    transform: translateX(var(--distance));
    animation: copy 3.6s cubic-bezier(.45, .05, .25, 1) infinite;
}

@keyframes copy {
    0%, 12% { transform: translate(0, 0) scale(1); opacity: 0; }
    18% { transform: translate(0, -6px) scale(1.06); opacity: 0.95; }
    55% { transform: translate(var(--distance), -10px) scale(1.06); opacity: 0.95; }
    65%, 90% { transform: translate(var(--distance), 0) scale(1); opacity: 1; box-shadow: 0 0 0 2px rgb(198 255 0 / 0.8); }
    100% { transform: translate(var(--distance), 0) scale(1); opacity: 0; }
}
</style>
