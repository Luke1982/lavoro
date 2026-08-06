<template>
    <TransitionRoot :show="open" as="template" appear>
        <Dialog as="div" class="relative z-50" @close="requestClose">
            <TransitionChild as="template" enter="ease-out duration-150" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-100" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-[2px]" />
            </TransitionChild>

            <!--
                Padded rather than centred, and not by much: twenty rems of it on a
                desktop was the box being small so the space around it could be
                large. A conversation of any length wants the height.
            -->
            <div class="fixed inset-0 overflow-y-auto p-4 sm:p-6 md:py-10 md:px-20">
                <TransitionChild as="template" enter="ease-out duration-150" enter-from="opacity-0 scale-95"
                    enter-to="opacity-100 scale-100" leave="ease-in duration-100" leave-from="opacity-100 scale-100"
                    leave-to="opacity-0 scale-95">
                    <DialogPanel class="mx-auto max-w-2xl transform transition-all">
                        <AssistantPanel ref="panelRef" variant="modal" @close="close" />
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'
import AssistantPanel from '@/Components/Assistant/AssistantPanel.vue'
import { matchesAssistantShortcut } from '@/Composables/useAssistant.js'
import { closeNavigator } from '@/Composables/useNavigator.js'

/**
 * The dialog the assistant hangs in, and nothing else.
 *
 * Everything the assistant does — the chapters, the conversation, the photos —
 * lives in AssistantPanel now, because none of it was ever about being a modal.
 * What is left is what only a modal has: an overlay, a shortcut, and a way to be
 * opened from anywhere. A sidebar wanting the same assistant mounts the panel
 * itself rather than inheriting a dialog it has no use for.
 */
const open = ref(false)
const panelRef = ref(null)

async function show() {
    /** Two dialogs fighting over one focus trap is a box that will not take a keystroke. */
    closeNavigator()
    open.value = true
    await nextTick()
    /** The dialog's own focus trap grabs the first button; the question box is the point. */
    panelRef.value?.focus()
}

function close() {
    open.value = false
}

/**
 * Escape and a click outside ask the panel first, they do not decide.
 *
 * Photos that were sent but not decided about hold the door once, and the panel
 * is the only thing that knows. Closing the dialog over its head would throw the
 * question away along with the answer.
 */
function requestClose() {
    panelRef.value ? panelRef.value.close() : close()
}

function onKeydown(event) {
    if (!matchesAssistantShortcut(event)) return

    event.preventDefault()
    event.stopPropagation()
    open.value ? requestClose() : show()
}

onMounted(() => {
    window.addEventListener('assistant:open', show)
    window.addEventListener('assistant:close', close)
    window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
    window.removeEventListener('assistant:open', show)
    window.removeEventListener('assistant:close', close)
    window.removeEventListener('keydown', onKeydown)
})
</script>
