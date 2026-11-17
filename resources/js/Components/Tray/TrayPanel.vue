<template>
    <section aria-label="Bakje"
        class="flex flex-col gap-2.5 rounded-3xl bg-sidebar-bg p-4 text-sidebar-text shadow-2xl">
        <div class="flex items-center gap-2.5">
            <InboxIcon class="size-5 text-sidebar-indicator" aria-hidden="true" />
            <h2 class="text-base font-bold text-white">Bakje</h2>
            <span :key="state.items.length" :class="TRAY_COUNT">{{ state.items.length }}</span>
            <button type="button" @click="state.open = false" aria-label="Bakje inklappen"
                class="ml-auto flex size-10 items-center justify-center rounded-xl text-slate-400 transition hover:bg-sidebar-hover hover:text-white active:scale-90">
                <ChevronDownIcon class="size-5" aria-hidden="true" />
            </button>
        </div>
        <p class="text-xs leading-relaxed text-slate-400">
            Sleep iets naar een plek die het aanneemt, of kies <strong class="font-semibold text-slate-300">Plakken</strong>
            en tik daarna op die plek.
        </p>

        <p v-if="!state.items.length" class="py-4 text-center text-sm text-slate-400">Er ligt niets op je bakje.</p>

        <div v-auto-animate class="-mx-1 flex min-h-0 flex-col gap-2 overflow-y-auto p-1">
            <div v-for="group in groups" :key="group.kind">
                <TrayItemCard v-if="group.items.length === 1" :item="group.items[0]" />
                <TrayStack v-else :kind="group.kind" :items="group.items" />
            </div>
        </div>

        <button v-if="state.items.length" type="button" @click="clearTray"
            class="h-10 self-start px-1 text-sm text-slate-300 underline underline-offset-4 transition-all hover:text-white hover:underline-offset-2">
            Bakje leegmaken
        </button>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { ChevronDownIcon, InboxIcon } from '@lucide/vue'
import { useTray, clearTray } from '@/Composables/useTray.js'
import { TRAY_COUNT } from '@/Components/Tray/trayLooks.js'
import TrayItemCard from '@/Components/Tray/TrayItemCard.vue'
import TrayStack from '@/Components/Tray/TrayStack.vue'

/**
 * What lies on the bakje, kind by kind: one item on its own, several of a kind
 * on a stack. The same panel in the corner of a computer screen and in the sheet
 * on a phone.
 */
const { state } = useTray()

const groups = computed(() => {
    const byKind = new Map()
    state.items.forEach(item => byKind.set(item.kind, [...(byKind.get(item.kind) ?? []), item]))
    return [...byKind].map(([kind, items]) => ({ kind, items }))
})
</script>
