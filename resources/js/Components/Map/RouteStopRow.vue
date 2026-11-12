<template>
    <li class="py-2">
        <div class="flex items-center gap-2">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                :style="{ background: stopColor(stop) }">
                {{ stop.number }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium text-gray-900">{{ stop.item.name }}</span>
                <span class="block truncate text-xs text-gray-500">{{ stop.item.city }}</span>
            </span>
            <span v-if="stop.arrival" class="whitespace-nowrap text-xs" title="Aankomst, zonder files"
                :class="stop.late ? 'font-medium text-red-700' : 'text-gray-500'">
                {{ stop.arrival }}
            </span>
            <button type="button" title="Uit de route halen" aria-label="Uit de route halen"
                class="shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" @click="emit('remove')">
                <XMarkIcon class="h-4 w-4" />
            </button>
        </div>
        <div class="mt-1 ml-8 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600">
            <label class="flex items-center gap-1" title="Tijd op locatie, in minuten">
                <input type="number" :value="stop.minutes" min="0" step="15" aria-label="Tijd op locatie, in minuten"
                    class="w-14 rounded-md border border-gray-300 px-1.5 py-0.5 text-right"
                    @change="emit('minutes', $event.target.value === '' ? null : Number($event.target.value))" />
                min
            </label>
            <label class="flex items-center gap-1" title="Uiterlijk om deze tijd hier beginnen; leeg is de hele dag">
                vóór
                <input type="time" :value="stop.before ?? ''" aria-label="Uiterlijk beginnen om"
                    class="rounded-md border border-gray-300 px-1 py-0.5"
                    @change="emit('before', $event.target.value || null)" />
            </label>
            <button type="button" :aria-pressed="stop.urgent"
                title="Past niet alles in de dag, dan valt eerst een gewoon adres af"
                class="rounded-md px-2 py-0.5 font-medium ring-1"
                :class="stop.urgent ? 'bg-red-600 text-white ring-red-600' : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50'"
                @click="emit('urgent')">
                Spoed
            </button>
        </div>
        <p v-if="stop.reason" class="mt-1 ml-8 text-xs" :class="stop.urgent || stop.late ? 'font-medium text-red-700' : 'text-gray-600'">
            {{ stop.reason }}
        </p>
    </li>
</template>

<script setup>
import { XMarkIcon } from '@heroicons/vue/20/solid';
import { stopColor } from '@/Components/Map/routeStopColor';

defineProps({
    stop: { type: Object, required: true },
});

const emit = defineEmits(['remove', 'minutes', 'urgent', 'before']);
</script>
