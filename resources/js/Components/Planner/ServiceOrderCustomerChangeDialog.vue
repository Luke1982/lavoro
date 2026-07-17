<template>
    <TransitionRoot as="template" :show="open">
        <!--
            Dialog portals itself to body, so this sits beside the event modal
            rather than inside it. It has to outrank that modal's own z-50: DOM
            order alone would decide it, and the shared headlessui portal root
            is created once and reused, so that order is not ours to rely on.
        -->
        <Dialog class="relative z-[60]" @close="$emit('cancel')">
            <TransitionChild as="template"
                enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <DialogPanel
                            class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                            <div class="bg-white dark:bg-slate-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div
                                        class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40 sm:mx-0 sm:size-10">
                                        <AlertTriangle class="size-6 text-amber-600" aria-hidden="true" />
                                    </div>
                                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                        <DialogTitle as="h3"
                                            class="text-base font-semibold text-gray-900 dark:text-white">
                                            Werkbon {{ serviceOrderId }} hoort bij {{ oldCustomerName }}
                                        </DialogTitle>
                                        <div class="mt-2">
                                            <p class="text-sm text-gray-500 dark:text-slate-400">
                                                Je zet deze afspraak op {{ newCustomerName }}. Wat moet er met de
                                                gekoppelde werkbon gebeuren?
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="bg-gray-50 dark:bg-slate-900/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                                <button type="button"
                                    class="inline-flex w-full min-w-0 justify-center rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white shadow-xs hover:opacity-90 sm:ml-3 sm:w-auto sm:max-w-md"
                                    @click="$emit('move')">
                                    <span class="truncate">Werkbon meeverhuizen naar {{ newCustomerName }}</span>
                                </button>
                                <button type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-800 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-slate-100 shadow-xs ring-1 ring-inset ring-gray-300 dark:ring-slate-500 hover:bg-gray-50 dark:hover:bg-slate-700 sm:mt-0 sm:ml-3 sm:w-auto"
                                    @click="$emit('detach')">
                                    Werkbon loskoppelen
                                </button>
                                <button type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-800 px-3 py-2 text-sm font-semibold text-gray-500 dark:text-slate-400 shadow-xs ring-1 ring-inset ring-gray-300 dark:ring-slate-500 hover:bg-gray-50 dark:hover:bg-slate-700 sm:mt-0 sm:mr-auto sm:w-auto"
                                    @click="$emit('cancel')">
                                    Annuleren
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { AlertTriangle } from '@lucide/vue'

defineProps({
    open: { type: Boolean, required: true },
    serviceOrderId: { type: [String, Number], default: null },
    oldCustomerName: { type: String, default: '' },
    newCustomerName: { type: String, default: '' },
})

defineEmits(['move', 'detach', 'cancel'])
</script>
