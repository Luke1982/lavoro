<template>
    <div class="fixed bottom-8 right-2 z-[999] rounded-lg bg-white/95 px-3 py-2 text-xs text-gray-700 shadow-md ring-1 ring-black/10">
        <p class="mb-1 font-medium text-gray-900">{{ title }}</p>
        <ul class="space-y-1">
            <li v-for="entry in entries" :key="entry.label" class="flex items-center gap-2">
                <svg viewBox="0 0 28 38" class="h-4 w-3 shrink-0">
                    <path :d="PIN_PATH" class="stroke-white" :style="{ fill: entry.color }" stroke-width="2" />
                </svg>
                {{ entry.label }}
            </li>
            <li class="flex items-center gap-2">
                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full" :style="{ background: ring }">
                    <span class="h-2 w-2 rounded-full bg-white" />
                </span>
                Groep adressen; klik om in te zoomen
            </li>
            <li v-if="badge" class="flex items-center gap-2">
                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full"
                    :style="{ background: BADGE_COLOR }">
                    <svg viewBox="0 0 12 12" class="h-2.5 w-2.5 fill-white">
                        <path :d="SHIELD_PATH" />
                    </svg>
                </span>
                {{ badge }}
            </li>
        </ul>
        <p class="mt-1 text-gray-500">
            Het cijfer is het aantal {{ counted }} op dat adres<br>
            of in die groep; in een route het volgnummer.
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { BADGE_COLOR, PIN_PATH, SHIELD_PATH, ringBackground } from '@/Components/Map/mapPin';

const props = defineProps({
    title: { type: String, required: true },
    entries: { type: Array, required: true },
    counted: { type: String, required: true },
    badge: { type: String, default: null },
});

const ring = computed(() => {
    const colors = props.entries.map(({ color }) => color);
    return ringBackground(colors, colors);
});
</script>
