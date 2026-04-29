<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="relative z-50" @close="open = false">
            <TransitionChild as="template" enter="ease-in-out duration-500" enter-from="opacity-0" enter-to=""
                leave="ease-in-out duration-500" leave-from="" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/50"></div>
            </TransitionChild>

            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10 sm:pl-16">
                        <TransitionChild as="template"
                            enter="transform transition ease-in-out duration-500 sm:duration-700"
                            enter-from="translate-x-full" enter-to="translate-x-0"
                            leave="transform transition ease-in-out duration-500 sm:duration-700"
                            leave-from="translate-x-0" leave-to="translate-x-full">
                            <DialogPanel :class="['pointer-events-auto w-screen', maxWidthClass]">
                                <div
                                    class="relative flex h-full flex-col bg-white shadow-xl dark:bg-gray-800 dark:after:absolute dark:after:inset-y-0 dark:after:left-0 dark:after:w-px dark:after:bg-white/10">
                                    <div
                                        class="shrink-0 px-4 sm:px-6 py-4 border-b border-gray-200 bg-gray-50 dark:bg-slate-900/40 dark:border-slate-700">
                                        <div class="flex items-start justify-between">
                                            <div class="min-w-0">
                                                <DialogTitle
                                                    class="text-base font-semibold text-gray-900 dark:text-white">
                                                    <slot name="title">{{ title }}</slot>
                                                </DialogTitle>
                                                <p v-if="subtitle || $slots.subtitle"
                                                    class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                                    <slot name="subtitle">{{ subtitle }}</slot>
                                                </p>
                                            </div>
                                            <div class="ml-3 flex h-7 items-center">
                                                <button type="button"
                                                    class="relative rounded-md text-gray-400 hover:text-gray-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:hover:text-white dark:focus-visible:outline-indigo-500"
                                                    @click="open = false">
                                                    <span class="absolute -inset-2.5"></span>
                                                    <span class="sr-only">Sluiten</span>
                                                    <XMarkIcon class="size-6" aria-hidden="true" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="relative flex-1 overflow-y-auto px-4 sm:px-6 py-4">
                                        <slot />
                                    </div>
                                    <div v-if="$slots.footer"
                                        class="shrink-0 px-4 sm:px-6 py-4 border-t border-gray-200 bg-gray-50 dark:bg-slate-900/40 dark:border-slate-700">
                                        <slot name="footer" />
                                    </div>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const open = defineModel({ type: Boolean, default: false })

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    maxWidthClass: { type: String, default: 'max-w-md' },
})
</script>
