<template>
    <div class="relative" style="height: calc(100vh - 4rem)" v-if="props.pings.length > 0">
        <TechnicianMapCanvas :pings="props.pings" :init-delay="0" />
        <div class="absolute top-4 left-4 z-[1000] bg-white dark:bg-slate-800 rounded-lg shadow-lg p-3 min-w-40">
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">Technici (laatste 8u)</p>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ props.pings.length }} zichtbaar</p>
        </div>
    </div>
    <div v-else class="flex items-center justify-center h-full">
        <p class="text-slate-500 text-xs pb-5 pt-3 dark:text-slate-400 px-5 text-center">Monteurlocaties: Geen data
            beschikbaar</p>
    </div>
</template>

<script setup>
import TechnicianMapCanvas from '@/Components/Planner/TechnicianMapCanvas.vue';

const props = defineProps({
    pings: { type: Array, required: true },
});
</script>
