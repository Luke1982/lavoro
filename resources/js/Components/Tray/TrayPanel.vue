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

        <button v-if="current && !current.onTray" type="button" data-tour="tray.place" @click="placeCurrentOnTray($event.currentTarget)"
            class="group flex min-h-11 items-center gap-2.5 rounded-2xl border border-dashed border-sidebar-border px-3 py-2 text-left text-sm font-semibold text-white transition hover:border-sidebar-indicator hover:bg-sidebar-hover active:scale-[0.98]">
            <PlusIcon class="size-5 flex-none text-sidebar-indicator transition-transform duration-300 group-hover:rotate-90" aria-hidden="true" />
            Deze {{ current.one }} op het bakje leggen
        </button>
        <p v-else-if="current" class="px-1 text-xs text-slate-400" data-tour="tray.place">Deze {{ current.one }} ligt al op je bakje.</p>

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
import { ChevronDownIcon, InboxIcon, PlusIcon } from '@lucide/vue'
import { useTray, clearTray, placeCurrentOnTray } from '@/Composables/useTray.js'
import { TRAY_COUNT } from '@/Components/Tray/trayLooks.js'
import TrayItemCard from '@/Components/Tray/TrayItemCard.vue'
import TrayStack from '@/Components/Tray/TrayStack.vue'

/**
 * What lies on the bakje, kind by kind: one item on its own, several of a kind
 * on a stack, and the record the page is about to put on it. The same panel in
 * the corner of a computer screen and in the sheet on a phone.
 */
const { state, currentRecord: current } = useTray()

const groups = computed(() => {
    const byKind = new Map()
    state.items.forEach(item => byKind.set(item.kind, [...(byKind.get(item.kind) ?? []), item]))
    return [...byKind].map(([kind, items]) => ({ kind, items }))
})
</script>
