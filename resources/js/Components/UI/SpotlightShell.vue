<template>
    <TransitionRoot :show="open" as="template" appear>
        <Dialog as="div" class="relative z-50" @close="emit('close')">
            <TransitionChild as="template" enter="ease-out duration-150" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-100" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-[2px]" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto p-4 sm:p-6 md:p-20">
                <TransitionChild as="template" enter="ease-out duration-150" enter-from="opacity-0 scale-95"
                    enter-to="opacity-100 scale-100" leave="ease-in duration-100" leave-from="opacity-100 scale-100"
                    leave-to="opacity-0 scale-95">
                    <DialogPanel
                        class="mx-auto max-w-2xl transform overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 transition-all">
                        <slot name="before" />

                        <div class="flex items-start gap-3 px-4 py-[0.9375rem]"
                            :class="dividerAboveInput && 'border-t border-slate-100'">
                            <div class="flex h-[1.625rem] shrink-0 items-center">
                                <slot name="icon" />
                            </div>
                            <!--
                                A textarea rather than an input so a long question wraps
                                instead of scrolling away to the left. It grows with the
                                text and stops at a third of the panel, after which it
                                scrolls — the box must not push its own footer off screen.
                            -->
                            <textarea ref="inputRef" :value="modelValue" :disabled="disabled" rows="1"
                                :placeholder="placeholder"
                                class="max-h-40 w-full resize-none self-center overflow-y-auto border-0 bg-transparent py-0 leading-relaxed text-slate-900 placeholder:text-slate-400 shadow-none outline-none ring-0 focus:border-0 focus:shadow-none focus:outline-none focus:ring-0 sm:text-sm disabled:opacity-50"
                                @input="onInput" @keydown.enter.exact.prevent="emit('enter')"
                                @keydown.esc="emit('close')" @keydown.down="onArrow($event, 'down')"
                                @keydown.up="onArrow($event, 'up')" />
                            <div class="flex h-[1.625rem] shrink-0 items-center">
                                <kbd
                                    class="hidden sm:inline-flex items-center rounded border border-slate-200 px-1.5 font-sans text-[10px] text-slate-400">
                                    esc
                                </kbd>
                            </div>
                        </div>

                        <slot name="after" />

                        <div class="flex items-center justify-between border-t border-slate-100 px-4 py-2.5">
                            <p class="text-[11px] text-slate-400">
                                <slot name="footer-left" />
                            </p>
                            <p class="text-[11px] text-slate-400">
                                <slot name="footer-right" />
                            </p>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { ref, nextTick, watch } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'

/**
 * The chrome every spotlight shares: the overlay, the panel, the one input and
 * the footer. What goes above the input (a conversation) or below it (a result
 * list) is the caller's business, which is the only thing the two spotlights in
 * this application actually disagree about.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    dividerAboveInput: { type: Boolean, default: false },
    /**
     * Whether the arrow keys drive a list instead of the caret. Off by default:
     * the box holds a textarea, and swallowing up and down would strand the caret
     * on the first line of a question that wraps onto several.
     */
    interceptArrows: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'close', 'enter', 'up', 'down'])

const inputRef = ref(null)

/**
 * A textarea has no idea how tall its own content is, so it is measured: reset to
 * nothing, then grown to whatever the text actually needs. Without the reset it
 * would only ever get taller, never shorter again.
 */
function resize() {
    const field = inputRef.value
    if (!field) return

    field.style.height = 'auto'
    field.style.height = field.scrollHeight + 'px'
}

function onInput(event) {
    emit('update:modelValue', event.target.value)
    resize()
}

function onArrow(event, direction) {
    if (!props.interceptArrows) return

    event.preventDefault()
    emit(direction)
}

/** Focus has to wait for the transition to put the input in the document. */
async function focus() {
    await nextTick()
    inputRef.value?.focus()
    resize()
}

/** Clearing the text from outside has to shrink the box back down with it. */
watch(() => props.modelValue, () => nextTick(resize))

defineExpose({ focus })
</script>
