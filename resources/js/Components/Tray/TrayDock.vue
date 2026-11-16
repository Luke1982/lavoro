<template>
    <Transition v-bind="RISE">
        <div v-if="state.items.length || state.toast" data-tray-dock
            class="fixed right-6 bottom-6 z-40 hidden w-[22rem] flex-col items-end gap-3 lg:flex">
            <Transition mode="out-in" v-bind="SLIDE_IN">
                <div v-if="state.toast" :key="state.toast.id" role="status"
                    :class="[state.toast.error ? 'bg-red-700' : 'bg-sidebar-bg', 'relative flex w-full items-center gap-3 overflow-hidden rounded-2xl py-2 pr-2 pl-4 text-sm text-sidebar-text shadow-xl']">
                    <span class="min-w-0 flex-1">{{ state.toast.message }}</span>
                    <button v-if="state.toast.undo" type="button" :disabled="state.busy" @click="undoTrayPaste"
                        class="group inline-flex h-10 flex-none items-center gap-1.5 rounded-xl bg-sidebar-card px-3 font-semibold text-white transition hover:bg-sidebar-hover active:scale-95 disabled:opacity-50">
                        <Undo2Icon class="size-4 transition-transform duration-300 group-hover:-rotate-45" aria-hidden="true" />
                        Ongedaan maken
                    </button>
                    <span v-if="state.toast.undo" aria-hidden="true"
                        class="absolute inset-x-0 bottom-0 h-0.5 origin-left bg-sidebar-indicator motion-reduce:hidden"
                        :style="{ animation: `trayCountdown ${state.toast.duration}ms linear forwards` }"></span>
                </div>
            </Transition>

            <Transition mode="out-in" v-bind="GROW">
                <div v-if="state.open && state.items.length" key="panel" class="w-full origin-bottom-right">
                    <section aria-label="Bakje"
                        :class="[state.dragging ? 'pointer-events-none invisible translate-y-4 scale-95 opacity-0' : '',
                            'flex max-h-[calc(100dvh-12rem)] w-full origin-bottom-right flex-col gap-2.5 rounded-3xl bg-sidebar-bg p-4 text-sidebar-text shadow-2xl transition-[opacity,translate,scale,visibility] duration-200 ease-out motion-reduce:transition-none']">
                        <div class="flex items-center gap-2.5">
                            <InboxIcon class="size-5 text-sidebar-indicator" aria-hidden="true" />
                            <h2 class="text-base font-bold text-white">Bakje</h2>
                            <span :key="state.items.length" :class="COUNT">{{ state.items.length }}</span>
                            <button type="button" @click="state.open = false" aria-label="Bakje inklappen"
                                class="ml-auto flex size-10 items-center justify-center rounded-xl text-slate-400 transition hover:bg-sidebar-hover hover:text-white active:scale-90">
                                <ChevronDownIcon class="size-5" aria-hidden="true" />
                            </button>
                        </div>
                        <p class="text-xs leading-relaxed text-slate-400">
                            Sleep iets naar de planner, een werkbon, een machine, een klant, een locatie, een contract of een project.
                        </p>

                        <div v-auto-animate class="-mx-1 flex flex-col gap-2 overflow-y-auto p-1">
                            <div v-for="group in groups" :key="group.kind">
                                <TrayItemCard v-if="group.items.length === 1" :item="group.items[0]" />
                                <TrayStack v-else :kind="group.kind" :items="group.items" />
                            </div>
                        </div>

                        <button type="button" @click="clearTray"
                            class="h-10 self-start px-1 text-sm text-slate-300 underline underline-offset-4 transition-all hover:text-white hover:underline-offset-2">
                            Bakje leegmaken
                        </button>
                    </section>
                </div>

                <button v-else-if="state.items.length" key="pill" type="button" @click="state.open = true"
                    class="group flex origin-bottom-right items-center gap-3 rounded-full bg-sidebar-bg py-2 pr-5 pl-2 text-sidebar-text shadow-xl transition duration-200 hover:-translate-y-0.5 hover:bg-sidebar-hover hover:shadow-2xl active:scale-95 motion-reduce:transition-none">
                    <span class="flex size-10 items-center justify-center rounded-full bg-sidebar-card">
                        <InboxIcon class="size-5 text-sidebar-indicator transition-transform duration-300 group-hover:-translate-y-0.5" aria-hidden="true" />
                    </span>
                    <span class="font-bold text-white">Bakje</span>
                    <span :key="state.items.length" :class="COUNT">{{ state.items.length }}</span>
                </button>
            </Transition>
        </div>
    </Transition>

    <TrayPasteDialog />
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue'
import { ChevronDownIcon, InboxIcon, Undo2Icon } from '@lucide/vue'
import { useTray, loadTray, clearTray, endTrayDrag, undoTrayPaste } from '@/Composables/useTray.js'
import TrayItemCard from '@/Components/Tray/TrayItemCard.vue'
import TrayStack from '@/Components/Tray/TrayStack.vue'
import TrayPasteDialog from '@/Components/Tray/TrayPasteDialog.vue'

const { state } = useTray()

const EASE_OUT = 'ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none'
const EASE_IN = 'ease-in motion-reduce:transition-none'

/** The bakje coming up from below the first time something is put on it. */
const RISE = {
    enterActiveClass: `transition duration-300 ${EASE_OUT}`,
    enterFromClass: 'translate-y-6 opacity-0',
    leaveActiveClass: `transition duration-200 ${EASE_IN}`,
    leaveToClass: 'translate-y-6 opacity-0',
}

/** A message sliding in from the side, and out again before the next one. */
const SLIDE_IN = {
    enterActiveClass: `transition duration-300 ${EASE_OUT}`,
    enterFromClass: 'translate-x-6 opacity-0',
    leaveActiveClass: `transition duration-150 ${EASE_IN}`,
    leaveToClass: 'translate-x-6 opacity-0',
}

/** The button growing into the open bakje from its corner, and shrinking back. */
const GROW = {
    enterActiveClass: `transition duration-300 ${EASE_OUT}`,
    enterFromClass: 'translate-y-3 scale-90 opacity-0',
    leaveActiveClass: `transition duration-150 ${EASE_IN}`,
    leaveToClass: 'translate-y-2 scale-95 opacity-0',
}

/** Remounted on every change of the count, so it pops each time. */
const COUNT = 'inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-sidebar-indicator px-1.5 text-xs font-bold text-sidebar-bg animate-[trayBump_450ms_ease-out] motion-reduce:animate-none'

const groups = computed(() => {
    const byKind = new Map()
    state.items.forEach(item => byKind.set(item.kind, [...(byKind.get(item.kind) ?? []), item]))
    return [...byKind].map(([kind, items]) => ({ kind, items }))
})

/**
 * A drag that ends anywhere, dropped or not, is over. The card it started on
 * may have left the page by then, so the window is the one place sure to hear.
 */
onMounted(() => {
    loadTray()
    window.addEventListener('dragend', endTrayDrag)
    window.addEventListener('drop', endTrayDrag)
})

onBeforeUnmount(() => {
    window.removeEventListener('dragend', endTrayDrag)
    window.removeEventListener('drop', endTrayDrag)
})
</script>
