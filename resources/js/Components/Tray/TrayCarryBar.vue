<template>
    <Transition enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
        enter-from-class="translate-y-4 opacity-0" leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
        leave-to-class="translate-y-4 opacity-0">
        <div v-if="state.carrying?.by === 'tap'" role="status"
            class="fixed inset-x-3 z-[55] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-sidebar-bg py-2 pr-2 pl-3 text-sm text-sidebar-text shadow-2xl ring-2 ring-sidebar-indicator"
            :style="{ bottom: `${floor + 12}px` }">
            <span :class="[look?.tile, 'flex size-9 flex-none items-center justify-center rounded-xl']">
                <ClipboardPasteIcon class="size-5" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-semibold text-white">{{ heading }} opgepakt</span>
                <span class="block text-xs text-slate-400">Tik op de plek waar het heen moet.</span>
            </span>
            <button type="button" @click="putDownTray"
                class="h-10 flex-none rounded-xl bg-sidebar-card px-3 font-semibold text-white transition hover:bg-sidebar-hover active:scale-95">
                Annuleren
            </button>
        </div>
    </Transition>
</template>

<script setup>
import { computed } from 'vue'
import { ClipboardPasteIcon } from '@lucide/vue'
import { useTray, putDownTray, trayCounted } from '@/Composables/useTray.js'
import { useBottomBars } from '@/Composables/useBottomBars.js'
import { trayLook } from '@/Components/Tray/trayLooks.js'

/**
 * While something is picked up by tapping Plakken, this says so and how to get
 * rid of it again. It floats above the bar at the bottom of a phone, which is
 * where the thumb already is.
 */
const { state } = useTray()
const { floor } = useBottomBars()

const look = computed(() => trayLook(state.carrying?.kind))

const heading = computed(() => {
    const words = trayCounted(state.carrying?.kind, state.carrying?.itemIds.length ?? 0)
    return words.charAt(0).toUpperCase() + words.slice(1)
})
</script>
