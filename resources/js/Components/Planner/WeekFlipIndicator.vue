<template>
    <div v-if="pull !== 0" class="absolute inset-y-0 z-40 flex items-center pointer-events-none"
        :class="pull > 0 ? 'left-0' : 'right-0'">
        <div class="flex items-center gap-1.5 px-3 py-2 shadow-lg text-xs font-semibold whitespace-nowrap"
            :class="[
                pull > 0 ? 'rounded-r-full' : 'rounded-l-full',
                armed
                    ? 'bg-lavoro-green text-gray-900'
                    : 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-300',
            ]" :style="{ opacity: 0.35 + progress * 0.65, transform: `scale(${0.9 + progress * 0.1})` }">
            <ChevronLeftIcon v-if="pull > 0" class="size-4 shrink-0" />
            <span>{{ pull > 0 ? 'Vorige week' : 'Volgende week' }}</span>
            <ChevronRightIcon v-if="pull < 0" class="size-4 shrink-0" />
        </div>
    </div>
</template>

<script setup>
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'

defineProps({
    pull: { type: Number, required: true },
    armed: { type: Boolean, default: false },
    progress: { type: Number, default: 0 },
})
</script>
