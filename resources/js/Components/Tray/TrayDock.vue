<template>
    <!-- On a computer: the bakje in the corner, as a button or opened up, with its messages above it. -->
    <Transition v-bind="RISE">
        <div v-if="state.items.length || state.toast" data-tray-dock :style="{ bottom: `${floor + 24}px` }"
            class="fixed right-6 z-40 hidden w-[22rem] flex-col items-end gap-3 transition-[bottom] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none lg:flex">
            <TrayToast />

            <Transition mode="out-in" v-bind="GROW">
                <TrayPanel v-if="state.open && state.items.length" key="panel"
                    :class="[state.carrying ? 'pointer-events-none invisible translate-y-4 scale-95 opacity-0' : '',
                        'max-h-[calc(100dvh-12rem)] w-full origin-bottom-right transition-[opacity,translate,scale,visibility] duration-200 ease-out motion-reduce:transition-none']" />

                <button v-else-if="state.items.length" key="pill" type="button" @click="state.open = true"
                    class="group flex origin-bottom-right items-center gap-3 rounded-full bg-sidebar-bg py-2 pr-5 pl-2 text-sidebar-text shadow-xl transition duration-200 hover:-translate-y-0.5 hover:bg-sidebar-hover hover:shadow-2xl active:scale-95 motion-reduce:transition-none">
                    <span class="flex size-10 items-center justify-center rounded-full bg-sidebar-card">
                        <InboxIcon class="size-5 text-sidebar-indicator transition-transform duration-300 group-hover:-translate-y-0.5" aria-hidden="true" />
                    </span>
                    <span class="font-bold text-white">Bakje</span>
                    <span :key="state.items.length" :class="TRAY_COUNT">{{ state.items.length }}</span>
                </button>
            </Transition>
        </div>
    </Transition>

    <!--
        On a phone: the bakje opens from its button in the bar at the bottom, as a
        sheet above that bar, and its messages float in the same place.
    -->
    <div class="lg:hidden">
        <Transition enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150 ease-in" leave-to-class="opacity-0">
            <div v-if="state.open" class="fixed inset-0 z-40 bg-slate-950/60" @click="state.open = false"></div>
        </Transition>

        <div class="fixed inset-x-3 z-[45] flex flex-col gap-3" :style="{ bottom: `${floor + 12}px` }">
            <TrayToast v-if="!state.carrying" />
            <Transition v-bind="RISE">
                <TrayPanel v-if="state.open" class="max-h-[70dvh]" />
            </Transition>
        </div>
    </div>

    <TrayCarryBar />
    <TrayPasteDialog />
</template>

<script setup>
import { onMounted, onBeforeUnmount, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { InboxIcon } from '@lucide/vue'
import { useTray, loadTray, endTrayDrag } from '@/Composables/useTray.js'
import { useBottomBars } from '@/Composables/useBottomBars.js'
import { TRAY_COUNT } from '@/Components/Tray/trayLooks.js'
import TrayCarryBar from '@/Components/Tray/TrayCarryBar.vue'
import TrayPanel from '@/Components/Tray/TrayPanel.vue'
import TrayPasteDialog from '@/Components/Tray/TrayPasteDialog.vue'
import TrayToast from '@/Components/Tray/TrayToast.vue'

const { state } = useTray()
const { floor } = useBottomBars()

const EASE_OUT = 'ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none'
const EASE_IN = 'ease-in motion-reduce:transition-none'

/** The bakje coming up from below. */
const RISE = {
    enterActiveClass: `transition duration-300 ${EASE_OUT}`,
    enterFromClass: 'translate-y-6 opacity-0',
    leaveActiveClass: `transition duration-200 ${EASE_IN}`,
    leaveToClass: 'translate-y-6 opacity-0',
}

/** The button growing into the open bakje from its corner, and shrinking back. */
const GROW = {
    enterActiveClass: `transition duration-300 ${EASE_OUT}`,
    enterFromClass: 'translate-y-3 scale-90 opacity-0',
    leaveActiveClass: `transition duration-150 ${EASE_IN}`,
    leaveToClass: 'translate-y-2 scale-95 opacity-0',
}

/**
 * On a phone, going to another page closes the sheet over it, but keeps hold of
 * whatever was picked up: carrying it there is the point. On a computer the
 * bakje in the corner stays open, to drag from on the next page.
 */
const page = usePage()
watch(() => page.url, () => {
    if (!window.matchMedia('(min-width: 1024px)').matches) state.open = false
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
