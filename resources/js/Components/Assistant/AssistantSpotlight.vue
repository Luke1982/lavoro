<template>
    <TransitionRoot :show="open" as="template" appear>
        <Dialog as="div" class="relative z-50" @close="close">
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
                        <div class="flex items-center gap-3 px-4">
                            <SparklesIcon class="size-5 text-indigo-500 shrink-0" />
                            <input ref="inputRef" v-model="question" type="text" :disabled="asking"
                                placeholder="Vraag iets over je werkbonnen, klanten of planning…"
                                class="h-14 w-full border-0 bg-transparent text-slate-900 placeholder:text-slate-400 focus:ring-0 sm:text-sm disabled:opacity-50"
                                @keydown.enter.prevent="ask" @keydown.esc="close" />
                            <kbd v-if="!asking"
                                class="hidden sm:inline-flex items-center rounded border border-slate-200 px-1.5 font-sans text-[10px] text-slate-400">
                                esc
                            </kbd>
                            <ArrowPathIcon v-else class="size-4 shrink-0 animate-spin text-slate-400" />
                        </div>

                        <div v-if="tools.length || answer || error" class="max-h-[26rem] overflow-y-auto border-t border-slate-100">
                            <!--
                                The tool calls are shown as they happen. A question that
                                takes ten seconds with no sign of life reads as broken, and
                                seeing which records it opened is also how someone decides
                                whether to believe the answer.
                            -->
                            <ul v-if="tools.length" class="px-4 pt-3 space-y-1">
                                <li v-for="(tool, index) in tools" :key="index"
                                    class="flex items-center gap-2 text-xs text-slate-400">
                                    <span :class="tool.failed ? 'text-red-500' : 'text-emerald-500'">•</span>
                                    <span>{{ toolLabel(tool.name) }}</span>
                                </li>
                            </ul>

                            <p v-if="error" class="px-4 py-3 text-sm text-red-600">{{ error }}</p>

                            <div v-if="answer" class="px-4 py-3 text-sm text-slate-800 whitespace-pre-wrap">{{ answer }}</div>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 px-4 py-2.5">
                            <p class="text-[11px] text-slate-400">
                                De assistent ziet alleen wat jij mag zien.
                            </p>
                            <p class="text-[11px] text-slate-400">
                                <kbd class="font-sans">{{ shortcutLabel }}</kbd> om te openen
                            </p>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { SparklesIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import axios from 'axios'
import { assistantShortcutLabel, matchesAssistantShortcut } from '@/Composables/useAssistant.js'

const open = ref(false)
const question = ref('')
const answer = ref('')
const error = ref('')
const tools = ref([])
const asking = ref(false)
const inputRef = ref(null)
const shortcutLabel = assistantShortcutLabel()

const TOOL_LABELS = {
    find_customer: 'Klant opzoeken',
    search_service_orders: 'Werkbonnen zoeken',
    find_asset: 'Machines zoeken',
    search_activity: 'Geschiedenis doorzoeken',
    summarize_customer: 'Klantoverzicht ophalen',
    find_available_technician: 'Beschikbaarheid berekenen',
}

const toolLabel = (name) => TOOL_LABELS[name] || name

async function open_() {
    open.value = true
    await nextTick()
    inputRef.value?.focus()
}

function close() {
    open.value = false
}

function reset() {
    answer.value = ''
    error.value = ''
    tools.value = []
}

async function ask() {
    const asked = question.value.trim()
    if (asked.length < 2 || asking.value) return

    reset()
    asking.value = true

    try {
        // The leading slash matters: without it this resolves relative to the
        // current page and 404s on anything nested like /serviceorders/12.
        await axios.get('/sanctum/csrf-cookie')
        const { data } = await axios.post('/assistant/ask', { question: asked })
        answer.value = data.answer
        tools.value = data.tools || []
    } catch (e) {
        error.value = e.response?.data?.message
            || e.response?.data?.errors?.question?.[0]
            || 'Er ging iets mis bij het stellen van de vraag.'
    } finally {
        asking.value = false
    }
}

function onKeydown(event) {
    if (matchesAssistantShortcut(event)) {
        event.preventDefault()
        open.value ? close() : open_()
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('assistant:open', open_)
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('assistant:open', open_)
})
</script>
