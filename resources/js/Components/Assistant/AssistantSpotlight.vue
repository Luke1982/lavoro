<template>
    <SpotlightShell ref="shellRef" v-model="question" :open="open" :disabled="asking"
        :divider-above-input="exchanges.length > 0"
        :placeholder="exchanges.length ? 'Stel een vervolgvraag…' : 'Vraag iets over je werkbonnen, klanten of planning…'"
        @close="close" @enter="ask" @up="recall(-1)" @down="recall(1)">
        <template #icon>
            <SparklesIcon class="size-5 text-indigo-500 shrink-0" />
        </template>

        <template #before>
            <!--
                Earlier questions, which until now existed only in the database and
                in the arrow keys. Shown here rather than on a page of its own
                because this is where somebody realises they asked it before.
            -->
            <div v-if="showing_history" class="max-h-[26rem] overflow-y-auto divide-y divide-slate-100">
                <div class="flex items-center justify-between px-4 py-2.5">
                    <p class="text-xs font-medium text-slate-500">Eerder gevraagd</p>
                    <button type="button" class="text-xs text-slate-400 hover:text-slate-600" @click="hideHistory">
                        Terug
                    </button>
                </div>

                <p v-if="history_error" class="px-4 py-3 text-sm text-red-600">{{ history_error }}</p>

                <p v-else-if="loading_history" class="px-4 py-3 text-sm text-slate-400">Bezig met ophalen…</p>

                <p v-else-if="!earlier.length" class="px-4 py-3 text-sm text-slate-400">
                    Je hebt nog niets gevraagd.
                </p>

                <button v-for="entry in earlier" :key="entry.id" type="button"
                    class="block w-full px-4 py-3 text-left hover:bg-slate-50" @click="askAgain(entry)">
                    <p class="text-sm font-medium text-slate-900">{{ entry.question }}</p>
                    <p v-if="entry.answer" class="mt-0.5 line-clamp-2 text-xs text-slate-500">
                        {{ entry.answer }}<template v-if="entry.answer_truncated">…</template>
                    </p>
                    <p v-else-if="entry.failure" class="mt-0.5 text-xs text-red-500">{{ entry.failure }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">{{ askedAt(entry.asked_at) }}</p>
                </button>
            </div>

            <div v-else-if="exchanges.length" ref="threadRef"
                class="max-h-[26rem] overflow-y-auto divide-y divide-slate-100">
                <div v-for="(exchange, index) in exchanges" :key="index" class="px-4 py-3 space-y-2">
                    <p v-if="exchange.question" class="text-sm font-medium text-slate-900">{{ exchange.question }}</p>

                    <!--
                        The tool calls are shown as they happen. A question that
                        takes ten seconds with no sign of life reads as broken, and
                        seeing which records it opened is also how someone decides
                        whether to believe the answer.
                    -->
                    <ul v-if="exchange.tools.length" class="space-y-1">
                        <li v-for="(tool, toolIndex) in exchange.tools" :key="toolIndex"
                            class="flex items-center gap-2 text-xs text-slate-400">
                            <span :class="tool.failed ? 'text-red-500' : 'text-emerald-500'">•</span>
                            <span>{{ toolLabel(tool.name) }}</span>
                        </li>
                    </ul>

                    <p v-if="exchange.error" class="text-sm text-red-600">{{ exchange.error }}</p>

                    <MarkdownText v-if="exchange.answer" :text="exchange.answer" class="text-sm text-slate-800"
                        @navigate="close" />

                    <div v-else-if="exchange.pending" class="flex items-center gap-2 text-sm text-slate-400">
                        <ArrowPathIcon class="size-4 shrink-0 animate-spin" />
                        <span>Aan het opzoeken…</span>
                    </div>

                    <!--
                        Nothing has been changed at this point. The assistant has
                        said what it would do and is waiting; this is the only
                        thing that lets it happen.
                    -->
                    <div v-for="(action, actionIndex) in exchange.pendingActions" :key="'p' + actionIndex"
                        class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 ring-1" :class="[
                            action.done ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : '',
                            action.stale ? 'bg-slate-50 text-slate-500 ring-slate-200' : '',
                            !action.done && !action.stale ? 'bg-amber-50 text-amber-900 ring-amber-200' : '',
                        ]">
                        <div class="min-w-0">
                            <!--
                                The proposal describes itself. By the time somebody
                                has asked two more things, the paragraph explaining
                                this button is halfway up the box.
                            -->
                            <p class="text-xs font-medium">{{ action.done || action.preview }}</p>
                            <p v-if="action.stale" class="text-[11px]">
                                Vervallen — er is daarna iets anders gevraagd. Vraag het opnieuw als je dit alsnog wilt.
                            </p>
                        </div>
                        <button v-if="!action.done && !action.stale" type="button" :disabled="action.busy"
                            class="shrink-0 rounded-md bg-amber-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                            @click="confirmAction(action)">
                            {{ action.busy ? 'Bezig…' : 'Bevestigen' }}
                        </button>
                    </div>

                </div>
            </div>
        </template>

        <template #footer-left>
            <button v-if="!showing_history" type="button" class="hover:text-slate-600" @click="showHistory">
                Eerder gevraagd
            </button>
            <span v-else>De assistent ziet alleen wat jij mag zien.</span>
        </template>

        <template #footer-right>
            <kbd class="font-sans">{{ shortcutLabel }}</kbd> om te openen
        </template>
    </SpotlightShell>
</template>

<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { SparklesIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import MarkdownText from '@/Components/UI/MarkdownText.vue'
import SpotlightShell from '@/Components/UI/SpotlightShell.vue'
import { assistantShortcutLabel, matchesAssistantShortcut } from '@/Composables/useAssistant.js'
import { closeNavigator } from '@/Composables/useNavigator.js'

const open = ref(false)
const question = ref('')
const exchanges = ref([])
const asking = ref(false)
const shellRef = ref(null)
const threadRef = ref(null)
const shortcutLabel = assistantShortcutLabel()

const ASK_TIMEOUT_MS = 180000

/**
 * Questions asked before, newest first, for stepping back through with the
 * arrows the way a shell does. Loaded once when the box first opens rather than
 * per question: it is only ever read to fill the input.
 */
const asked_before = ref([])
const recalled = ref(-1)

const showing_history = ref(false)
const loading_history = ref(false)
const history_error = ref('')
const earlier = ref([])

/** What was being typed before stepping back through earlier questions. */
const draft = ref('')

// What goes back to the model as history. The request rejects more, and a
// follow-up needs what was just said rather than everything ever asked.
const REMEMBERED_EXCHANGES = 6

// The request refuses a longer answer than this, and a refusal would wedge the
// conversation for good: every follow-up would fail on history it cannot change.
// A long answer is worth less as context than the thread is worth alive.
const REMEMBERED_ANSWER_CHARS = 8000

const TOOL_LABELS = {
    find_customer: 'Klant opzoeken',
    search_service_orders: 'Werkbonnen zoeken',
    find_asset: 'Machines zoeken',
    find_tickets: 'Storingen zoeken',
    find_products: 'Producten zoeken',
    find_appointments: 'Agenda nakijken',
    read_documentation: 'Documentatie inlezen',
    research_ticket: 'Storing uitzoeken',
    search_activity: 'Geschiedenis doorzoeken',
    summarize_customer: 'Klantoverzicht ophalen',
    find_available_technician: 'Beschikbaarheid berekenen',
    create_event: 'Afspraak inplannen',
    create_ticket: 'Storing vastleggen',
    create_service_order: 'Werkbon aanmaken',
    add_service_order_task: 'Taak op werkbon zetten',
}

const toolLabel = (name) => TOOL_LABELS[name] || name

const asActions = (pending) => (pending || []).map((action) => ({
    ...action,
    preview: action.preview || 'Nog niet uitgevoerd — bevestig om door te gaan.',
    busy: false,
    stale: false,
    done: '',
}))

/**
 * Earlier questions, fetched once and used for two things: stepping back with the
 * arrows, and the panel that lists them.
 */
async function loadHistory({ force = false } = {}) {
    if (loading_history.value) return
    if (earlier.value.length && !force) return

    loading_history.value = true
    history_error.value = ''

    try {
        const { data } = await axios.get('/assistant/history')
        earlier.value = data.questions || []
        asked_before.value = earlier.value.map((row) => row.question)
    } catch (e) {
        // Not being able to look back is no reason to stop somebody asking
        // something new, so this only ever reports itself in the panel.
        history_error.value = e.response?.data?.message || 'Kon eerdere vragen niet ophalen.'
    } finally {
        loading_history.value = false
    }
}

async function showHistory() {
    showing_history.value = true
    await loadHistory({ force: true })
}

function hideHistory() {
    showing_history.value = false
    shellRef.value?.focus()
}

/**
 * Puts an old question back in the box rather than replaying its answer. What it
 * said then was about the state of things then, and presenting that as current is
 * how somebody acts on a werkbon that has since moved on.
 */
function askAgain(entry) {
    question.value = entry.question
    showing_history.value = false
    recalled.value = -1
    draft.value = ''
    shellRef.value?.focus()
}

function askedAt(iso) {
    if (!iso) return ''

    const asked = new Date(iso)
    const clock = asked.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' })

    return asked.toDateString() === new Date().toDateString()
        ? 'vandaag ' + clock
        : asked.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long' }) + ' ' + clock
}

/**
 * Steps back through earlier questions, the way a shell does.
 *
 * Half a question already typed is kept and handed back on the way out, rather
 * than being thrown away by an arrow key.
 */
function recall(direction) {
    if (!asked_before.value.length) return

    const next = recalled.value + (direction < 0 ? 1 : -1)

    if (recalled.value === -1 && next >= 0) {
        draft.value = question.value
    }

    if (next < 0) {
        recalled.value = -1
        question.value = draft.value
        draft.value = ''

        return
    }

    if (next >= asked_before.value.length) return

    recalled.value = next
    question.value = asked_before.value[next]
}

async function open_() {
    closeNavigator()
    open.value = true
    loadHistory()
    shellRef.value?.focus()
}

function close() {
    open.value = false
    showing_history.value = false
}

async function scrollToLatest() {
    await nextTick()
    if (threadRef.value) threadRef.value.scrollTop = threadRef.value.scrollHeight
}

async function ask() {
    const asked = question.value.trim()
    if (asked.length < 2 || asking.value) return

    const history = historyForModel()

    // A proposal nobody acted on before asking something else has been overtaken.
    // Left clickable it is a button that makes a second copy of something the
    // conversation has already moved past.
    exchanges.value.forEach((exchange) => {
        exchange.pendingActions.forEach((action) => {
            if (!action.done) action.stale = true
        })
    })

    const exchange = { question: asked, answer: '', tools: [], error: '', pending: true, pendingActions: [] }
    exchanges.value.push(exchange)
    asked_before.value.unshift(asked)
    recalled.value = -1
    draft.value = ''
    // The stored list is now a question behind; fetch it fresh next time.
    earlier.value = []
    question.value = ''
    asking.value = true
    scrollToLatest()

    try {
        // The leading slash matters: without it this resolves relative to the
        // current page and 404s on anything nested like /serviceorders/12.
        await axios.get('/sanctum/csrf-cookie')
        // Which page this is asked from is reported, not interpreted: "wie heeft
        // deze order gesloten" is unanswerable without it, and the backend decides
        // what a path means so a made-up one cannot put words into the prompt.
        const { data } = await axios.post(
            '/assistant/ask',
            { question: asked, page: window.location.pathname, history },
            { timeout: ASK_TIMEOUT_MS },
        )
        exchange.answer = data.answer
        exchange.tools = data.tools || []
        exchange.pendingActions = asActions(data.pending)
    } catch (e) {
        // A broad question can take a few rounds, so the wait is long on purpose.
        // Spinning for ever is not an option though: without this it never stops,
        // and someone watching it has no way to tell working from broken.
        if (e.code === 'ECONNABORTED') {
            exchange.error = 'Deze vraag duurde te lang. Probeer hem smaller te stellen.'
        } else if (e.response?.status === 429) {
            exchange.error = 'Even wachten, er zijn te veel vragen achter elkaar gesteld.'
        } else {
            exchange.error = e.response?.data?.message
                || e.response?.data?.errors?.question?.[0]
                || 'Er ging iets mis bij het stellen van de vraag.'
        }
        // A question that failed is not lost work, so it goes back in the box
        // ready to be sent again or reworded.
        question.value = asked
    } finally {
        exchange.pending = false
        asking.value = false
        scrollToLatest()
        shellRef.value?.focus()
    }
}

/** What the model is sent as the story so far, including what has been done. */
function historyForModel() {
    return exchanges.value
        .filter((exchange) => exchange.answer && exchange.question)
        .slice(-REMEMBERED_EXCHANGES)
        .map((exchange) => ({
            question: exchange.question,
            answer: [
                exchange.answer,
                ...exchange.pendingActions
                    .filter((action) => action.done)
                    .map((action) => '[al uitgevoerd] ' + action.done),
            ].join('\n\n').slice(0, REMEMBERED_ANSWER_CHARS),
        }))
}

/**
 * Carries out something the assistant proposed. The token holds what was
 * proposed, so nothing here describes the action — sending our own idea of it
 * would let the button and the words above it drift apart.
 */
async function confirmAction(action) {
    if (action.busy || action.done) return

    action.busy = true

    try {
        await axios.get('/sanctum/csrf-cookie')
        const { data } = await axios.post('/assistant/confirm', { token: action.token })
        action.done = data.message
        proceed()
    } catch (e) {
        action.done = e.response?.data?.message || 'Het is niet gelukt.'
    } finally {
        action.busy = false
        scrollToLatest()
    }
}

/**
 * Picks the conversation up on its own after a confirmation.
 *
 * Clicking the button is an answer, so it should not also need typing out. A
 * conversation that had just said it would add the task and plan a mechanic used
 * to stop dead here, knowing exactly what came next and waiting to be asked.
 */
async function proceed() {
    if (asking.value) return

    const history = historyForModel()

    if (!history.length) return

    const exchange = { question: '', answer: '', tools: [], error: '', pending: true, pendingActions: [] }
    exchanges.value.push(exchange)
    asking.value = true
    scrollToLatest()

    try {
        const { data } = await axios.post(
            '/assistant/continue',
            { page: window.location.pathname, history },
            { timeout: ASK_TIMEOUT_MS },
        )
        exchange.answer = data.answer
        exchange.tools = data.tools || []
        exchange.pendingActions = asActions(data.pending)
    } catch (e) {
        // Nothing was lost if this fails: the action itself already happened, and
        // the next thing typed picks the thread up anyway.
        exchange.error = e.response?.data?.message || 'Kon niet verder; stel je volgende vraag gewoon.'
    } finally {
        exchange.pending = false
        asking.value = false
        scrollToLatest()
    }
}

function onKeydown(event) {
    if (matchesAssistantShortcut(event)) {
        event.preventDefault()
        event.stopPropagation()
        open.value ? close() : open_()
    }
}

/**
 * The conversation belongs to the page it was held on. Carrying it to the next
 * one would leave "deze werkbon" pointing at the previous screen while the
 * assistant is told it is on this one.
 */
const stopListening = router.on('navigate', () => {
    exchanges.value = []
    question.value = ''
})

onMounted(() => {
    window.addEventListener('keydown', onKeydown, { capture: true })
    window.addEventListener('assistant:open', open_)
    window.addEventListener('assistant:close', close)
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown, { capture: true })
    window.removeEventListener('assistant:open', open_)
    window.removeEventListener('assistant:close', close)
    stopListening()
})
</script>
