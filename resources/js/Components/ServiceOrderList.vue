<template>
    <div class="@container">
        <div :class="serviceOrderGridColumns"
            class="mb-2 hidden items-center gap-3 px-4 text-[11px] font-semibold tracking-wider text-gray-400 uppercase dark:text-slate-500 @2xl:grid">
            <span>Werkbon</span>
            <span>Klant / locatie</span>
            <span class="hidden @4xl:block">Planning</span>
            <span>Status</span>
            <span class="hidden @4xl:block">Monteur(s)</span>
            <span>Taken</span>
            <span />
        </div>
        <div class="space-y-2" v-auto-animate>
            <ServiceOrderRow v-for="serviceorder in serviceorders" :key="serviceorder.id"
                :serviceorder="serviceorder" :stages="stages" />
        </div>
    </div>
</template>

<script setup>
import ServiceOrderRow, { serviceOrderGridColumns } from '@/Components/ServiceOrderRow.vue';

defineProps({
    serviceorders: {
        type: Array,
        default: () => []
    },
    stages: {
        type: Array,
        default: () => []
    }
});
</script>
