<template>
    <button type="button" :disabled="ids.length === 0" @click="place($event.currentTarget)"
        class="group inline-flex items-center gap-2 rounded-md bg-sidebar-bg px-4 py-2 text-sm font-bold text-white transition hover:opacity-90 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50">
        <InboxIcon class="size-4 text-sidebar-indicator transition-transform duration-300 group-hover:translate-y-0.5" aria-hidden="true" />
        Op het bakje
    </button>
</template>

<script setup>
import { InboxIcon } from '@lucide/vue'
import { placeOnTray } from '@/Composables/useTray.js'

/**
 * Puts what is chosen in a list on the bakje, from the bar with what can be done
 * with all of it. Says `placed` once it lies there, so the list can let go of it.
 */
const props = defineProps({
    kind: { type: String, required: true },
    ids: { type: Array, required: true },
})

const emit = defineEmits(['placed'])

async function place(button) {
    if (await placeOnTray(props.kind, props.ids, button)) emit('placed')
}
</script>
