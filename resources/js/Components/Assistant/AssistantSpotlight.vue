<template>
    <SpotlightShell ref="shellRef" v-model="question" :open="open" :disabled="asking" accepts-files
        @files="readFiles"
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
            <div v-if="showing_history" class="max-h-[65vh] overflow-y-auto divide-y divide-slate-100">
                <div class="flex items-center justify-between px-4 py-2.5">
                    <p class="text-xs font-medium text-slate-500">Eerdere gesprekken</p>
                    <button type="button" class="text-xs text-slate-400 hover:text-slate-600" @click="hideHistory">
                        Terug
                    </button>
                </div>

                <p v-if="history_error" class="px-4 py-3 text-sm text-red-600">{{ history_error }}</p>

                <p v-else-if="loading_history" class="px-4 py-3 text-sm text-slate-400">Bezig met ophalen…</p>

                <p v-else-if="!earlier.length" class="px-4 py-3 text-sm text-slate-400">
                    Je hebt nog geen gesprekken gehad.
                </p>

                <button v-for="thread in earlier" :key="thread.id" type="button"
                    class="block w-full px-4 py-3 text-left hover:bg-slate-50" @click="reopen(thread)">
                    <p class="text-sm font-medium text-slate-900">{{ thread.title }}</p>
                    <p v-if="thread.preview" class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ thread.preview }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">
                        {{ askedAt(thread.last_at) }} · {{ thread.turns }} {{ thread.turns === 1 ? 'bericht' : 'berichten' }}
                    </p>
                </button>
            </div>

            <!--
                Managing the saved questions: all of them, where they belong, and
                what they actually ask. Inline in the box because that is where
                somebody realises a question is missing or wrong.
            -->
            <div v-else-if="managing_prompts" class="max-h-[65vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                    <p class="text-xs font-medium text-slate-500">Vaste vragen</p>
                    <button type="button" class="text-xs text-slate-400 hover:text-slate-600"
                        @click="managing_prompts = false">
                        Terug
                    </button>
                </div>

                <div v-for="prompt in all_prompts" :key="prompt.id"
                    class="border-b border-slate-100 px-4 py-2.5">
                    <div v-if="editing?.id === prompt.id" class="space-y-1.5">
                        <input v-model="editing.label" type="text" placeholder="Naam op de knop"
                            class="w-full rounded-md border-slate-300 text-sm">
                        <textarea v-model="editing.question" rows="2" placeholder="De vraag zelf"
                            class="w-full rounded-md border-slate-300 text-sm" />
                        <select v-model="editing.context" class="w-full rounded-md border-slate-300 text-sm">
                            <option v-for="page in PAGES" :key="page.value ?? 'all'" :value="page.value">
                                {{ page.label }}
                            </option>
                        </select>
                        <div class="flex gap-2">
                            <button type="button"
                                class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700"
                                @click="savePrompt">
                                Bewaren
                            </button>
                            <button type="button" class="text-xs text-slate-500 hover:text-slate-700"
                                @click="editing = null">
                                Annuleren
                            </button>
                        </div>
                    </div>

                    <div v-else class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ prompt.label }}</p>
                            <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ prompt.question }}</p>
                            <p class="mt-0.5 text-[11px] text-slate-400">
                                {{ pageLabel(prompt.context) }}{{ prompt.mine ? '' : ' · standaardvraag' }}
                            </p>
                        </div>
                        <div v-if="prompt.mine" class="flex shrink-0 gap-2 text-[11px]">
                            <button type="button" class="text-indigo-600 hover:text-indigo-800"
                                @click="editing = { ...prompt }">
                                wijzigen
                            </button>
                            <button type="button" class="text-slate-400 hover:text-red-600"
                                @click="forgetPrompt(prompt)">
                                verwijderen
                            </button>
                        </div>
                    </div>
                </div>

                <div class="px-4 py-3">
                    <button v-if="!editing || editing.id" type="button"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                        @click="editing = { id: null, label: '', question: question.trim(), context: pageContext() }">
                        Vraag toevoegen
                    </button>
                    <div v-else class="space-y-1.5">
                        <input v-model="editing.label" type="text" placeholder="Naam op de knop"
                            class="w-full rounded-md border-slate-300 text-sm">
                        <textarea v-model="editing.question" rows="2" placeholder="De vraag zelf"
                            class="w-full rounded-md border-slate-300 text-sm" />
                        <select v-model="editing.context" class="w-full rounded-md border-slate-300 text-sm">
                            <option v-for="page in PAGES" :key="page.value ?? 'all'" :value="page.value">
                                {{ page.label }}
                            </option>
                        </select>
                        <div class="flex gap-2">
                            <button type="button"
                                class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700"
                                @click="savePrompt">
                                Bewaren
                            </button>
                            <button type="button" class="text-xs text-slate-500 hover:text-slate-700"
                                @click="editing = null">
                                Annuleren
                            </button>
                        </div>
                    </div>
                    <p v-if="prompt_error" class="mt-1.5 text-[11px] text-red-600">{{ prompt_error }}</p>
                </div>
            </div>

            <div v-else-if="exchanges.length" ref="threadRef"
                class="max-h-[65vh] overflow-y-auto divide-y divide-slate-100">
                <!--
                    The question that started it, pinned. Three exchanges in, the
                    opener is off the top of the scroll and every answer below
                    reads without its context — which klant, which klus. It rides
                    along as a slim title so the thread always says what it is
                    about.
                -->
                <p v-if="openingQuestion" :title="openingQuestion"
                    class="sticky top-0 z-10 truncate bg-white/95 px-4 py-2 text-xs font-medium text-slate-500 shadow-sm backdrop-blur">
                    {{ openingQuestion }}
                </p>
                <div v-for="(exchange, index) in exchanges" :key="index" class="px-4 py-3 space-y-2">
                    <p v-if="exchange.question" class="text-sm font-medium text-slate-900">{{ exchange.question }}</p>

                    <div v-if="exchange.images?.length" class="flex gap-2">
                        <img v-for="(image, imageIndex) in exchange.images" :key="imageIndex" :src="image" alt=""
                            class="h-14 w-14 rounded-md object-cover ring-1 ring-slate-200">
                    </div>

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

                    <!--
                        Records the answer named that no lookup returned. It has
                        happened: six Mitsubishi model numbers invented whole
                        against the ids of a fan, a thermostat and a light switch,
                        with working links. Better to say so than to let it read
                        like everything else.
                    -->
                    <p v-if="exchange.unverified.length" class="rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900 ring-1 ring-amber-200">
                        Let op: {{ exchange.unverified.join(', ') }}
                        {{ exchange.unverified.length === 1 ? 'is' : 'zijn' }} niet uit een zoekopdracht
                        gekomen. Controleer dat zelf voordat je erop afgaat.
                    </p>

                    <MarkdownText v-if="exchange.answer" :text="exchange.answer" class="text-sm text-slate-800"
                        @navigate="close" />

                    <div v-else-if="exchange.pending" class="flex items-center gap-2 text-sm text-slate-400">
                        <ArrowPathIcon class="size-4 shrink-0 animate-spin" />
                        <span>Aan het opzoeken…</span>
                    </div>

                    <!--
                        Several records matching one description is the ordinary
                        case. A link goes to the record and takes the reader out of
                        the conversation that needed the answer, and "de eerste" is
                        not reliably understood — so the options are options.
                    -->
                    <!--
                        What was read off the photo, each part with how sure it
                        is. Bars rather than prose: "waarschijnlijk" reads the
                        same at 55 as at 90, and a bar at 45 tells somebody
                        exactly where to point the lens next.
                    -->
                    <div v-for="group in byMachine(exchange.findings)" :key="group.subject"
                        class="rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                        <p class="text-xs font-medium text-slate-700">{{ group.subject }}</p>
                        <ul class="mt-2 space-y-1.5">
                            <li v-for="(finding, findingIndex) in group.findings" :key="findingIndex">
                                <div class="flex items-baseline justify-between gap-2 text-xs">
                                    <span class="text-slate-500">{{ finding.field }}</span>
                                    <span class="min-w-0 flex-1 truncate font-medium text-slate-900">
                                        {{ finding.value }}
                                    </span>
                                    <span class="shrink-0 tabular-nums text-slate-400">
                                        {{ finding.confidence }}%
                                    </span>
                                </div>
                                <div class="mt-0.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full transition-all"
                                        :class="confidenceColour(finding.confidence)"
                                        :style="{ width: finding.confidence + '%' }" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <p v-if="exchange.unreadable?.length" class="text-[11px] text-slate-500">
                        Niet te lezen: {{ exchange.unreadable.join(' · ') }}
                    </p>

                    <div v-for="(choice, choiceIndex) in exchange.choices" :key="'c' + choiceIndex"
                        class="rounded-lg bg-indigo-50 px-3 py-2.5 ring-1 ring-indigo-200">
                        <p class="text-xs font-medium text-indigo-900">
                            {{ choice.chosen && !choice.stale ? 'Gekozen: ' + choice.chosen : choice.question }}
                        </p>
                        <p v-if="choice.stale" class="mt-1 text-[11px] text-slate-500">
                            Vervallen — er is daarna iets anders gevraagd. Vraag het opnieuw als dit alsnog moet.
                        </p>
                    <!--
                        Two things per option on purpose. The button is the answer
                        to the question being asked; the link is for checking the
                        record first. Offering only the link was what made people
                        read "open this" as "choose this".
                    -->
                    <ul v-if="!choice.chosen && !choice.stale" class="mt-2 space-y-1">
                        <li v-for="(option, optionIndex) in choice.options" :key="optionIndex"
                            class="flex items-center gap-2">
                            <button type="button" :disabled="asking"
                                class="min-w-0 flex-1 truncate rounded-md bg-white px-2.5 py-1 text-left text-xs font-medium text-indigo-900 ring-1 ring-indigo-300 hover:bg-indigo-100 disabled:opacity-50"
                                @click="choose(choice, option)">
                                {{ option.label }}
                            </button>
                            <a v-if="option.link" :href="option.link"
                                class="shrink-0 text-[11px] text-indigo-700 underline hover:text-indigo-900"
                                @click.prevent="openRecord(option.link)">
                                bekijken
                            </a>
                        </li>
                    </ul>
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

            <!--
                Questions worth asking on this page. Shown while the box is still
                empty, because that is the moment somebody does not know what to
                type — half of what this assistant can do is invisible from a
                blank prompt.
            -->
            <div v-if="!showing_history && !exchanges.length && prompts.length"
                class="border-t border-slate-100 px-4 py-3">
                <p class="text-xs font-medium text-slate-500">Veelgestelde vragen op deze pagina</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button v-for="prompt in prompts" :key="prompt.id" type="button"
                        class="max-w-full truncate rounded-full bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-indigo-50 hover:text-indigo-900 hover:ring-indigo-300"
                        :title="prompt.question"
                        @click="askPrompt(prompt)">
                        {{ prompt.label }}
                    </button>
                </div>
            </div>

            <!--
                Photos riding with the next question. Clicking a thumbnail removes
                it — selecting toggles here, there are no separate crosses.
            -->
            <div v-if="pending_images.length && !showing_history"
                class="flex items-center gap-2 border-t border-slate-100 px-4 py-2">
                <button v-for="(image, imageIndex) in pending_images" :key="imageIndex" type="button"
                    class="h-12 w-12 shrink-0 overflow-hidden rounded-md ring-1 ring-slate-200 hover:ring-red-400"
                    title="Klik om te verwijderen"
                    @click="pending_images.splice(imageIndex, 1)">
                    <img :src="image" alt="" class="h-full w-full object-cover">
                </button>
                <p class="text-[11px] text-slate-400">Deze foto's gaan mee met je volgende vraag.</p>
            </div>

            <!--
                The parked photos, asked about when the conversation closes. Their
                storage is theirs and counts against their limits, so nothing
                lands in it without them choosing — and closing again just leaves
                the photos parked; they are cleaned up after a few days.
            -->
            <div v-if="asking_about_photos && !showing_history"
                class="border-t border-slate-100 bg-indigo-50/60 px-4 py-3">
                <p class="text-xs font-medium text-slate-700">
                    Er horen {{ photos_sent }} foto('s) bij dit gesprek. In je opslag bewaren?
                    Dit telt mee voor je opslaglimiet.
                </p>
                <div class="mt-1.5 flex items-center gap-2">
                    <button type="button" :disabled="deciding_photos"
                        class="rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        @click="decidePhotos(true)">
                        Bewaren
                    </button>
                    <button type="button" :disabled="deciding_photos"
                        class="rounded-md bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-50"
                        @click="decidePhotos(false)">
                        Weggooien
                    </button>
                    <p v-if="photo_outcome" class="text-[11px] text-slate-500">{{ photo_outcome }}</p>
                </div>
            </div>

            <!--
                Why it is being reported, asked before anything is sent. The
                transcript says what happened; only the melder can say what
                should have happened instead — which is the investigator's brief.
            -->
            <div v-if="asking_reason && !showing_history" class="border-t border-slate-100 bg-amber-50/60 px-4 py-3">
                <label class="text-xs font-medium text-slate-700" for="report-reason">
                    Wat ging er mis, of wat had je verwacht? (mag leeg blijven)
                </label>
                <div class="mt-1.5 flex items-center gap-2">
                    <input id="report-reason" ref="reasonRef" v-model="report_reason" type="text"
                        class="min-w-0 flex-1 rounded-md border-slate-300 text-sm focus:border-amber-500 focus:ring-amber-500"
                        placeholder="Bijvoorbeeld: hij noemde de verkeerde klant"
                        @keydown.enter.prevent="reportConversation"
                        @keydown.esc.stop.prevent="asking_reason = false">
                    <button type="button" :disabled="reporting"
                        class="shrink-0 rounded-md bg-amber-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                        @click="reportConversation">
                        {{ reporting ? 'Bezig…' : 'Verstuur melding' }}
                    </button>
                </div>
            </div>
        </template>

        <!--
            Always there, whatever the answer says. An assistant that sounds
            certain is the one worth doubting, and the sentence costs nothing on
            the turns where it happens to be right.
        -->
        <template #notice>
            De AI assistent kan fouten maken, controleer de gegevens altijd
        </template>

        <template #footer-left>
            <button v-if="!showing_history" type="button" class="hover:text-slate-600" @click="showHistory">
                Eerdere gesprekken
            </button>
            <!--
                Only once something has been said, because an empty conversation
                is nothing to look at.
            -->
            <button v-if="!showing_history && exchanges.length" type="button"
                class="ml-3 hover:text-slate-600" :disabled="reporting" @click="startReport">
                {{ reported || (asking_reason ? 'Annuleren' : 'Gesprek melden') }}
            </button>
            <button v-if="!showing_history" type="button" class="ml-3 hover:text-slate-600"
                @click="imageInputRef?.click()">
                Foto toevoegen
            </button>
            <button v-if="!showing_history" type="button" class="ml-3 hover:text-slate-600"
                @click="managePrompts">
                Vragen beheren
            </button>
            <input ref="imageInputRef" type="file" accept="image/jpeg,image/png,image/webp,image/gif"
                multiple class="hidden" @change="addImages">
            <span v-if="showing_history">De assistent ziet alleen wat jij mag zien.</span>
        </template>

        <template #footer-right>
            <kbd class="font-sans">{{ shortcutLabel }}</kbd> om te openen
        </template>
    </SpotlightShell>
</template>

<script setup>
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
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

const reporting = ref(false)
const reported = ref('')
const asking_reason = ref(false)
const pending_images = ref([])
const prompts = ref([])

/**
 * Which kind of page this is, as "serviceorders.show" — the same shape the
 * saved questions are filed under. A numeric segment means a record is being
 * looked at; anything else is an overview.
 */
function pageContext() {
    const parts = window.location.pathname.split('/').filter(Boolean)

    if (!parts.length) return 'dashboard'

    return parts[0] + (parts[1] && /^\d+$/.test(parts[1]) ? '.show' : '.index')
}

const managing_prompts = ref(false)
const all_prompts = ref([])
const editing = ref(null)
const prompt_error = ref('')

/** Where a question can live. The value is the context string it is filed under. */
const PAGES = [
    { value: null, label: 'Op elke pagina' },
    { value: 'serviceorders.show', label: 'Op een werkbon' },
    { value: 'serviceorders.index', label: 'In het werkbonoverzicht' },
    { value: 'assets.show', label: 'Op een machine' },
    { value: 'customers.show', label: 'Op een klant' },
    { value: 'products.index', label: 'In het assortiment' },
    { value: 'tickets.show', label: 'Op een storing' },
    { value: 'planner.index', label: 'In de planning' },
]

const pageLabel = (context) => PAGES.find((page) => page.value === context)?.label ?? context

async function managePrompts() {
    managing_prompts.value = true
    editing.value = null
    prompt_error.value = ''

    try {
        const { data } = await axios.get('/assistant/prompts', { params: { context: 'all' } })
        all_prompts.value = data.prompts || []
    } catch {
        all_prompts.value = []
    }
}

/** One save for both roads: a new question and an edited one. */
async function savePrompt() {
    const draft = editing.value

    if (!draft || draft.label.trim().length < 1 || draft.question.trim().length < 2) {
        prompt_error.value = 'Geef een korte naam en de vraag zelf.'

        return
    }

    prompt_error.value = ''

    try {
        await axios.get('/sanctum/csrf-cookie')
        const body = { label: draft.label.trim(), question: draft.question.trim(), context: draft.context }
        const { data } = draft.id
            ? await axios.patch('/assistant/prompts/' + draft.id, body)
            : await axios.post('/assistant/prompts', body)

        all_prompts.value = draft.id
            ? all_prompts.value.map((entry) => (entry.id === draft.id ? data.prompt : entry))
            : [...all_prompts.value, data.prompt]

        editing.value = null
        loadPrompts()
    } catch (e) {
        prompt_error.value = e.response?.data?.message || 'Bewaren lukte niet.'
    }
}

async function loadPrompts() {
    try {
        const { data } = await axios.get('/assistant/prompts', { params: { context: pageContext() } })
        prompts.value = data.prompts || []
    } catch {
        /** No suggestions is a quieter box, never a broken one. */
        prompts.value = []
    }
}

function askPrompt(prompt) {
    question.value = prompt.question
    ask()
}

async function forgetPrompt(prompt) {
    try {
        await axios.get('/sanctum/csrf-cookie')
        await axios.delete('/assistant/prompts/' + prompt.id)
        prompts.value = prompts.value.filter((entry) => entry.id !== prompt.id)
        all_prompts.value = all_prompts.value.filter((entry) => entry.id !== prompt.id)
    } catch (e) {
        history_error.value = e.response?.data?.message || 'Verwijderen lukte niet.'
    }
}
const imageInputRef = ref(null)

/** Four photos of ~3 MB is a typeplaatje from every angle; more is a mistake. */
const MAX_IMAGES = 4

/** One reader for all three roads in: the picker, a drop and a paste. */
function readFiles(files) {
    Array.from(files || [])
        .filter((file) => file.type?.startsWith('image/'))
        .slice(0, MAX_IMAGES - pending_images.value.length)
        .forEach((file) => {
            const reader = new FileReader()
            reader.onload = () => pending_images.value.push(reader.result)
            reader.readAsDataURL(file)
        })
}

function addImages(event) {
    readFiles(event.target.files)
    event.target.value = ''
}
const report_reason = ref('')
const reasonRef = ref(null)

/** Opens the why-field first; the same button cancels it again. */
function startReport() {
    if (reporting.value) return

    reported.value = ''
    asking_reason.value = !asking_reason.value

    if (asking_reason.value) nextTick(() => reasonRef.value?.focus())
}

/**
 * Writes this conversation out to a file somebody can hand over.
 *
 * What makes it worth having is not the prose — that can be copied off the
 * screen — but the arguments the tools were called with and what they gave back.
 * Every fault found in this assistant so far was in there, behind an answer that
 * read perfectly well.
 */
async function reportConversation() {
    if (reporting.value) return

    reporting.value = true
    reported.value = ''

    try {
        await axios.get('/sanctum/csrf-cookie')
        const { data } = await axios.post('/assistant/report', {
            conversation: conversation.value,
            reason: report_reason.value.trim() || null,
        })
        reported.value = data.message || 'Gesprek opgeslagen.'
        asking_reason.value = false
        report_reason.value = ''
    } catch (e) {
        reported.value = e.response?.data?.message || 'Melden is niet gelukt.'
    } finally {
        reporting.value = false
    }
}

const ASK_TIMEOUT_MS = 180000

/** crypto.randomUUID is not there over plain http on a phone; this is the fallback. */
function newConversationId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
    })
}

/**
 * Questions asked before, newest first, for stepping back through with the
 * arrows the way a shell does. Loaded once when the box first opens rather than
 * per question: it is only ever read to fill the input.
 */
const asked_before = ref([])
const recalled = ref(-1)

/**
 * The thread these turns belong to. One id for as long as the conversation lasts,
 * so the stored turns can be read back together instead of as loose questions.
 */
const conversation = ref(newConversationId())

const showing_history = ref(false)

/** What this conversation is about: the first real question, skipping continuations. */
const openingQuestion = computed(() => exchanges.value.find((exchange) => exchange.question)?.question ?? '')
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

/**
 * Green when it is worth acting on, amber when it wants confirming, red when it
 * is barely more than a shape. The thresholds match what the tool tells the
 * model they mean.
 */
/**
 * Findings grouped by the machine they describe, in the order first mentioned.
 *
 * Grouped by tool call — which is how they arrive — a set read in two batches
 * drew a box with an outdoor unit and one indoor unit, then a box with a second
 * indoor unit and an unrelated Tosot. That grouping tells you when the model
 * spoke, which nobody asked. The same field twice for one machine keeps the
 * surer reading.
 */
function byMachine(findings) {
    const groups = new Map()

    for (const finding of findings || []) {
        const subject = finding.subject || 'Apparaat'

        if (!groups.has(subject)) groups.set(subject, new Map())

        const fields = groups.get(subject)
        const seen = fields.get(finding.field)

        if (!seen || finding.confidence > seen.confidence) fields.set(finding.field, finding)
    }

    return [...groups].map(([subject, fields]) => ({ subject, findings: [...fields.values()] }))
}

const confidenceColour = (confidence) =>
    confidence >= 80 ? 'bg-emerald-500' : confidence >= 50 ? 'bg-amber-500' : 'bg-red-400'

const asChoices = (choices) => (choices || []).map((choice) => ({ ...choice, chosen: '', reference: '', sent: false, stale: false }))

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
        earlier.value = data.conversations || []
        asked_before.value = earlier.value.map((thread) => thread.title)
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
 * Opens an old conversation and puts you back inside it.
 *
 * Filling the box with the first question was the wrong shape: it threw the thread
 * away and left somebody to type their way back to where they had been. The turns
 * come back so the next question is a follow-up, on the same conversation.
 *
 * Buttons from before are deliberately not restored. Their approvals expired with
 * them, and offering one that cannot work is worse than offering none.
 */
async function reopen(thread) {
    loading_history.value = true
    history_error.value = ''

    try {
        const { data } = await axios.get('/assistant/history/' + thread.id)

        exchanges.value = (data.turns || []).map((turn) => ({
            question: turn.question,
            answer: turn.answer || '',
            error: turn.failure || '',
            tools: (turn.tools || []).map((name) => ({ name, failed: false })),
            pending: false,
            pendingActions: [],
            choices: [],
            unverified: [],
        }))

        conversation.value = data.id
        showing_history.value = false
        question.value = ''
        draft.value = ''
        recalled.value = -1
        scrollToLatest()
        shellRef.value?.focus()
    } catch (e) {
        history_error.value = e.response?.data?.message || 'Kon dat gesprek niet openen.'
    } finally {
        loading_history.value = false
    }
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
    loadPrompts()
    closeNavigator()
    open.value = true
    loadHistory()
    shellRef.value?.focus()
}

const photos_sent = ref(0)
const asking_about_photos = ref(false)
const deciding_photos = ref(false)
const photo_outcome = ref('')

/**
 * Keeps or discards the parked photos, then lets the box close.
 *
 * Kept ones land attached to whatever record the conversation settled — the
 * backend knows, the box does not have to.
 */
async function decidePhotos(keep) {
    if (deciding_photos.value) return

    deciding_photos.value = true

    try {
        await axios.get('/sanctum/csrf-cookie')
        const { data } = keep
            ? await axios.post('/assistant/photos/keep', { conversation: conversation.value })
            : await axios.delete('/assistant/photos', { data: { conversation: conversation.value } })

        photo_outcome.value = data.message || 'Gebeurd.'
        photos_sent.value = 0

        setTimeout(() => {
            asking_about_photos.value = false
            photo_outcome.value = ''
            close()
        }, 1200)
    } catch (e) {
        photo_outcome.value = e.response?.data?.message || 'Dat lukte niet.'
        deciding_photos.value = false

        return
    }

    deciding_photos.value = false
}

function close() {
    managing_prompts.value = false
    /**
     * Photos nobody decided about hold the door once. Closing again closes
     * anyway — the question is a question, not a lock — and undecided photos
     * are cleaned up by themselves after a few days.
     */
    if (photos_sent.value > 0 && !asking_about_photos.value) {
        asking_about_photos.value = true

        return
    }

    open.value = false
    showing_history.value = false
    asking_about_photos.value = false
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

        // A picker left behind goes the same way. Clicked later it would answer
        // a question the conversation has moved past — and one clicked but never
        // sent must not keep saying "Gekozen" as though the model heard it.
        exchange.choices.forEach((entry) => {
            if (!entry.sent) entry.stale = true
        })
    })

    const images = pending_images.value.slice()
    pending_images.value = []

    const exchange = { question: asked, images, answer: '', tools: [], error: '', pending: true, pendingActions: [], choices: [], findings: [], unreadable: [], unverified: [] }
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
            {
                question: asked,
                page: window.location.pathname,
                history,
                conversation: conversation.value,
                images: images.length ? images : undefined,
            },
            { timeout: ASK_TIMEOUT_MS },
        )
        photos_sent.value += images.length
        exchange.answer = data.answer
        exchange.tools = data.tools || []
        exchange.pendingActions = asActions(data.pending)
        exchange.choices = asChoices(data.choices)
        exchange.findings = data.findings || []
        exchange.unreadable = data.unreadable || []
        exchange.unverified = data.unverified || []
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
        // ready to be sent again or reworded — photos included.
        question.value = asked
        pending_images.value = images
    } finally {
        exchange.pending = false
        asking.value = false
        scrollToLatest()
        shellRef.value?.focus()
    }
}

/**
 * Answers a choice on the person's behalf.
 *
 * Sent as a question, because that is what it is: clicking is the answer, and the
 * reference travels with it so there is nothing left to guess about which record
 * was meant.
 *
 * Sent only when it is the last unanswered choice of its turn. A turn can carry
 * two pickers — which product, which werkbon — and firing on the first click
 * shipped half an answer and killed the other question unasked. So the clicks
 * collect, and the last one sends them together.
 */
function choose(choice, option) {
    if (asking.value || choice.chosen || choice.stale) return

    choice.chosen = option.label
    choice.reference = option.reference

    const owner = exchanges.value.find((exchange) => exchange.choices.includes(choice))
    const answered = (owner?.choices || []).filter((entry) => entry.chosen && !entry.stale)

    if ((owner?.choices || []).some((entry) => !entry.chosen && !entry.stale)) return

    answered.forEach((entry) => { entry.sent = true })
    question.value = 'Ik bedoel: ' + answered.map((entry) => entry.chosen + ' (' + entry.reference + ')').join(' en ')
    ask()
}

/** Opens the record without answering the question that is still standing. */
function openRecord(path) {
    close()
    router.visit(path)
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
        const { data } = await axios.post('/assistant/confirm', { token: action.token, conversation: conversation.value })
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

    const exchange = { question: '', answer: '', tools: [], error: '', pending: true, pendingActions: [], choices: [], unverified: [] }
    exchanges.value.push(exchange)
    asking.value = true
    scrollToLatest()

    try {
        const { data } = await axios.post(
            '/assistant/continue',
            { page: window.location.pathname, history, conversation: conversation.value },
            { timeout: ASK_TIMEOUT_MS },
        )
        exchange.answer = data.answer
        exchange.tools = data.tools || []
        exchange.pendingActions = asActions(data.pending)
        exchange.choices = asChoices(data.choices)
        exchange.unverified = data.unverified || []
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
    /** A new page is a new conversation; the old one is readable in the panel. */
    conversation.value = newConversationId()
    photos_sent.value = 0
    asking_about_photos.value = false
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
