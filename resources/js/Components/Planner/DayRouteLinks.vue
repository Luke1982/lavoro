<template>
    <span class="inline-flex items-center gap-0.5" @pointerdown.stop @click.stop>
        <a v-for="(link, index) in route.links" :key="link" :href="link" target="_blank" rel="noopener"
            :title="titleFor(index)"
            class="inline-flex items-center gap-1 rounded bg-blue-700 px-1.5 py-0.5 text-[11px] font-medium normal-case tracking-normal text-white shadow-sm hover:bg-blue-800">
            <MapIcon v-if="index === 0" class="h-3.5 w-3.5" />
            {{ route.links.length > 1 ? `Route ${index + 1}` : 'Route' }}
        </a>
        <button v-if="route.can_remove" type="button" title="Route uit de planning halen"
            aria-label="Route uit de planning halen"
            class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"
            @click="emit('remove', route)">
            <TrashIcon class="h-3.5 w-3.5" />
        </button>
    </span>
</template>

<script setup>
import { MapIcon, TrashIcon } from '@heroicons/vue/20/solid';

/**
 * The route someone was given for a day, as one link per part of it: Google
 * Maps takes ten addresses at a time. Lives on top of the planner's own click
 * handling, so a click here never starts a new appointment underneath.
 */
const props = defineProps({
    route: { type: Object, required: true },
});

const emit = defineEmits(['remove']);

const titleFor = (index) => {
    const part = props.route.links.length > 1 ? `, deel ${index + 1}` : '';
    return `Route: ${props.route.summary}. Openen in Google Maps${part}`;
};
</script>
