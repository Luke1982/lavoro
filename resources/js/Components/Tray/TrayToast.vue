<template>
    <Transition mode="out-in" enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
        enter-from-class="translate-x-6 opacity-0" leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
        leave-to-class="translate-x-6 opacity-0">
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
</template>

<script setup>
import { Undo2Icon } from '@lucide/vue'
import { useTray, undoTrayPaste } from '@/Composables/useTray.js'

/** What the last paste did, with Ongedaan maken while it can still be taken back. */
const { state } = useTray()
</script>
