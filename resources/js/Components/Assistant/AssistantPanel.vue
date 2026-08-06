<template>
    <div class="flex flex-col overflow-hidden bg-white"
        :class="variant === 'sidebar' ? 'h-full' : 'max-h-[70vh] rounded-2xl shadow-xl'">
        <div
            class="flex items-center justify-between gap-2 border-b border-slate-100 bg-gradient-to-r from-lavoro-blue/10 via-violet-500/5 to-transparent px-3 py-2">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="shrink-0 rounded-xl bg-gradient-to-br from-lavoro-blue via-indigo-500 to-violet-500 p-1.5 shadow-sm shadow-lavoro-blue/40">
                    <SparklesIcon class="size-5 text-white" />
                </span>
                <div class="min-w-0 leading-tight">
                    <p class="text-sm font-semibold text-slate-900">Lavoro AI</p>
                    <p class="truncate text-[11px] text-slate-500">Je slimme assistent voor werkbonnen en planning.</p>
                </div>
            </div>
            <button type="button" title="Sluiten"
                class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-white/70 hover:text-slate-600" @click="close">
                <XMarkIcon class="size-5" />
            </button>
        </div>

        <ChaptersComponent v-model="chapter" dense class="flex min-h-0 flex-1 flex-col">
            <ChapterHeaders>
                <ChapterHeader :index="CHAT">Nieuwe chat</ChapterHeader>
                <ChapterHeader :index="HISTORY">Gesprekken</ChapterHeader>
                <ChapterHeader :index="PROMPTS">Veelgestelde vragen</ChapterHeader>
            </ChapterHeaders>

            <div ref="threadRef" class="min-h-0 flex-1 overflow-y-auto">
                <ChapterContents>
                    <!-- Nieuwe chat -->
                    <template #chapter-0>
                        <div v-if="!exchanges.length" class="space-y-3 px-3 py-3">
                            <p class="text-sm font-semibold text-slate-900">Hallo! Waarmee kan ik je vandaag helpen?</p>

                            <div v-if="prompts.length" class="space-y-1.5">
                                <button v-for="(prompt, promptIndex) in prompts" :key="prompt.id" type="button"
                                    class="flex w-full items-center gap-2.5 rounded-xl border border-slate-200/80 bg-white px-2.5 py-2 text-left transition hover:-translate-y-px hover:border-transparent hover:shadow-md hover:shadow-slate-900/5"
                                    :class="tile(promptIndex).hover" @click="askPrompt(prompt)">
                                    <span class="shrink-0 rounded-lg p-1.5" :class="tile(promptIndex).tile">
                                        <component :is="tile(promptIndex).icon" class="size-4" />
                                    </span>
                                    <span class="min-w-0 leading-tight">
                                        <span class="block truncate text-[13px] font-semibold text-slate-900">
                                            {{ prompt.label }}
                                        </span>
                                        <span class="block truncate text-[11px] text-slate-500">
                                            {{ prompt.question }}
                                        </span>
                                    </span>
                                </button>
                            </div>

                            <div v-if="earlier.length">
                                <div class="flex items-baseline justify-between">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        Recente gesprekken
                                    </p>
                                    <button type="button" class="text-[11px] font-medium text-lavoro-blue hover:underline"
                                        @click="chapter = HISTORY">
                                        Bekijk alles
                                    </button>
                                </div>
                                <ul class="mt-1">
                                    <li v-for="thread in earlier.slice(0, RECENT)" :key="thread.id">
                                        <button type="button"
                                            class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-left hover:bg-lavoro-blue/5"
                                            @click="reopen(thread)">
                                            <ClockIcon class="size-3.5 shrink-0 text-lavoro-blue/70" />
                                            <span class="min-w-0 flex-1 truncate text-xs text-slate-700">
                                                {{ thread.title }}
                                            </span>
                                            <span class="shrink-0 text-[11px] text-slate-400">
                                                {{ askedAt(thread.last_at) }}
                                            </span>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div v-else>
                            <p v-if="openingQuestion" :title="openingQuestion"
                                class="sticky top-0 z-10 truncate border-b border-slate-100 bg-white/95 px-3 py-1.5 text-[11px] font-medium text-slate-500 backdrop-blur">
                                {{ openingQuestion }}
                            </p>

                            <div v-for="(exchange, index) in exchanges" :key="index" class="space-y-1.5 px-3 py-2.5">
                                <p v-if="exchange.question"
                                    class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-lavoro-blue px-3 py-1.5 text-[13px] font-medium text-white">
                                    {{ exchange.question }}
                                </p>

                                <div v-if="exchange.images?.length" class="flex justify-end gap-1.5">
                                    <img v-for="(image, imageIndex) in exchange.images" :key="imageIndex" :src="image"
                                        alt="" class="size-12 rounded-lg object-cover ring-1 ring-slate-200">
                                </div>

                                <div v-if="exchange.files?.length" class="flex flex-wrap justify-end gap-1.5">
                                    <span v-for="(name, fileIndex) in exchange.files" :key="fileIndex"
                                        class="inline-flex max-w-[70%] items-center gap-1 rounded-lg bg-violet-50 px-2 py-1 text-[11px] font-medium text-violet-700 ring-1 ring-violet-200">
                                        <PaperClipIcon class="size-3 shrink-0" />
                                        <span class="truncate">{{ name }}</span>
                                    </span>
                                </div>

                                <ul v-if="exchange.tools.length" class="flex flex-wrap gap-1">
                                    <li v-for="(tool, toolIndex) in exchange.tools" :key="toolIndex"
                                        class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                        :class="tool.failed
                                            ? 'bg-red-50 text-red-600 ring-1 ring-red-100'
                                            : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'">
                                        <span class="size-1 rounded-full"
                                            :class="tool.failed ? 'bg-red-500' : 'bg-emerald-500'" />
                                        {{ toolLabel(tool.name) }}
                                    </li>
                                </ul>

                                <p v-if="exchange.pending"
                                    class="flex items-center gap-1.5 text-xs font-medium text-lavoro-blue">
                                    <ArrowPathIcon class="size-3.5 animate-spin" />
                                    Aan het opzoeken…
                                </p>

                                <p v-if="exchange.error" class="text-sm text-red-600">{{ exchange.error }}</p>

                                <MarkdownText v-if="exchange.answer" :text="exchange.answer" @navigate="close" />

                                <p v-for="(reference, referenceIndex) in exchange.unverified" :key="'u' + referenceIndex"
                                    class="rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                                    Let op: {{ reference }} kwam niet uit een zoekopdracht. Controleer dat zelf.
                                </p>

                                <div v-for="group in byMachine(exchange.findings, exchange.photos)" :key="group.subject"
                                    :data-machine="group.subject"
                                    class="rounded-xl bg-gradient-to-br from-slate-50 to-lavoro-blue/5 px-2.5 py-2 ring-1 ring-slate-200">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                            {{ group.subject }}
                                        </p>
                                        <div v-if="group.photos.length" class="flex shrink-0 gap-1">
                                            <a v-for="photo in group.photos" :key="photo" :href="photo" target="_blank"
                                                rel="noopener noreferrer" title="De foto waar dit vanaf gelezen is">
                                                <img :src="photo" alt=""
                                                    class="size-9 rounded object-cover ring-1 ring-slate-300 hover:ring-lavoro-blue">
                                            </a>
                                        </div>
                                    </div>
                                    <ul class="mt-1.5 space-y-1">
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

                                <div v-for="(choice, choiceIndex) in exchange.choices" :key="'c' + choiceIndex
                                    " class="rounded-lg bg-lavoro-blue/5 px-3 py-2.5 ring-1 ring-lavoro-blue/30">
                                    <p class="text-xs font-medium text-slate-800">
                                        {{ choice.chosen && !choice.stale ? 'Gekozen: ' + choice.chosen : choice.question }}
                                    </p>
                                    <p v-if="choice.stale" class="mt-1 text-[11px] text-slate-500">
                                        Vervallen — er is daarna iets anders gevraagd.
                                    </p>
                                    <ul v-if="!choice.chosen && !choice.stale" class="mt-2 space-y-1">
                                        <li v-for="(option, optionIndex) in choice.options" :key="optionIndex"
                                            class="flex items-center gap-2">
                                            <button type="button" :disabled="asking"
                                                class="min-w-0 flex-1 truncate rounded-md bg-white px-2.5 py-1 text-left text-xs font-medium text-slate-800 ring-1 ring-lavoro-blue/40 hover:bg-lavoro-blue/10 disabled:opacity-50"
                                                @click="choose(choice, option)">
                                                {{ option.label }}
                                            </button>
                                            <a v-if="option.link" :href="option.link"
                                                class="shrink-0 text-[11px] text-lavoro-blue underline"
                                                @click.prevent="openRecord(option.link)">bekijken</a>
                                        </li>
                                    </ul>
                                </div>

                                <div v-for="(action, actionIndex) in exchange.pendingActions" :key="'p' + actionIndex"
                                    class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 ring-1" :class="[
                                        action.done ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : '',
                                        action.stale ? 'bg-slate-50 text-slate-500 ring-slate-200' : '',
                                        !action.done && !action.stale ? 'bg-amber-50 text-amber-900 ring-amber-200' : '',
                                    ]">
                                    <div class="min-w-0">
                                        <p class="truncate text-xs font-medium">{{ action.done || action.preview }}</p>
                                        <p v-if="action.stale" class="text-[11px]">
                                            Vervallen — er is daarna iets anders gevraagd.
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

                    <!-- Gesprekken -->
                    <template #chapter-1>
                        <div class="divide-y divide-slate-100">
                            <p v-if="history_error" class="px-4 py-3 text-sm text-red-600">{{ history_error }}</p>
                            <p v-else-if="loading_history" class="px-4 py-3 text-sm text-slate-400">
                                Bezig met ophalen…
                            </p>
                            <p v-else-if="!earlier.length" class="px-4 py-3 text-sm text-slate-400">
                                Je hebt nog geen gesprekken gehad.
                            </p>
                            <button v-for="thread in earlier" v-else :key="thread.id" type="button"
                                class="flex w-full items-start gap-3 px-4 py-2.5 text-left hover:bg-slate-50"
                                @click="reopen(thread)">
                                <ChatBubbleLeftRightIcon class="mt-0.5 size-4 shrink-0 text-slate-400" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-900">
                                        {{ thread.title }}
                                    </span>
                                    <span class="block truncate text-xs text-slate-500">{{ thread.preview }}</span>
                                </span>
                                <span class="shrink-0 text-right text-[11px] text-slate-400">
                                    <span class="block">{{ askedAt(thread.last_at) }}</span>
                                    <span class="block">{{ thread.turns }} {{ thread.turns === 1 ? 'bericht' : 'berichten' }}</span>
                                </span>
                            </button>
                        </div>
                    </template>

                    <!-- Veelgestelde vragen -->
                    <template #chapter-2>
                        <div>
                            <div v-for="prompt in all_prompts" :key="prompt.id"
                                class="border-b border-slate-100 px-4 py-2.5">
                                <AssistantPromptForm v-if="editing?.id === prompt.id" :initial="editing" :pages="PAGES"
                                    @save="savePrompt" @cancel="editing = null" />
                                <div v-else class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900">{{ prompt.label }}</p>
                                        <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ prompt.question }}</p>
                                        <p class="mt-0.5 text-[11px] text-slate-400">
                                            {{ pageLabel(prompt.context) }}{{ prompt.mine ? '' : ' · standaardvraag' }}
                                        </p>
                                    </div>
                                    <div v-if="prompt.mine" class="flex shrink-0 gap-2 text-[11px]">
                                        <button type="button" class="text-lavoro-blue hover:underline"
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
                                <AssistantPromptForm v-if="editing && !editing.id" :initial="editing" :pages="PAGES"
                                    @save="savePrompt" @cancel="editing = null" />
                                <button v-else type="button"
                                    class="text-xs font-medium text-lavoro-blue hover:underline" @click="addPrompt">
                                    Vraag toevoegen
                                </button>
                                <p v-if="prompt_error" class="mt-1.5 text-[11px] text-red-600">{{ prompt_error }}</p>
                            </div>
                        </div>
                    </template>
                </ChapterContents>
            </div>
        </ChaptersComponent>

        <template v-if="chapter === CHAT">
            <div v-if="pending_images.length || pending_files.length || attachment_error"
                class="space-y-1.5 border-t border-slate-100 bg-slate-50/60 px-3 py-2">
                <div v-if="pending_images.length || pending_files.length" class="flex flex-wrap items-center gap-1.5">
                    <button v-for="(image, imageIndex) in pending_images" :key="'i' + imageIndex" type="button"
                        class="size-11 shrink-0 overflow-hidden rounded-lg ring-1 ring-slate-200 hover:ring-2 hover:ring-red-400"
                        title="Klik om deze foto te verwijderen" @click="pending_images.splice(imageIndex, 1)">
                        <img :src="image" alt="" class="size-full object-cover">
                    </button>
                    <button v-for="(file, fileIndex) in pending_files" :key="'f' + fileIndex" type="button"
                        class="inline-flex max-w-[60%] items-center gap-1 rounded-lg bg-violet-100 px-2 py-1.5 text-[11px] font-medium text-violet-800 ring-1 ring-violet-200 hover:ring-2 hover:ring-red-400"
                        title="Klik om dit bestand te verwijderen" @click="pending_files.splice(fileIndex, 1)">
                        <PaperClipIcon class="size-3.5 shrink-0" />
                        <span class="truncate">{{ file.name }}</span>
                    </button>
                    <p class="text-[11px] text-slate-500">Gaat mee met je volgende vraag.</p>
                </div>
                <p v-if="attachment_error" class="text-[11px] font-medium text-red-600">{{ attachment_error }}</p>
            </div>

            <div v-if="asking_about_photos"
                class="border-t border-slate-100 bg-gradient-to-r from-lavoro-blue/10 to-violet-500/5 px-3 py-2.5">
                <p class="text-xs font-medium text-slate-700">
                    Er horen {{ photos_sent }} foto('s) bij dit gesprek. In je opslag bewaren? Dit telt mee voor je
                    opslaglimiet.
                </p>
                <div class="mt-1.5 flex items-center gap-2">
                    <button type="button" :disabled="deciding_photos"
                        class="rounded-lg bg-lavoro-blue px-2.5 py-1 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50"
                        @click="decidePhotos(true)">
                        Bewaren
                    </button>
                    <button type="button" :disabled="deciding_photos"
                        class="rounded-lg bg-white px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-50"
                        @click="decidePhotos(false)">
                        Weggooien
                    </button>
                    <p v-if="photo_outcome" class="text-[11px] text-slate-500">{{ photo_outcome }}</p>
                </div>
            </div>

            <div v-if="asking_reason" class="border-t border-slate-100 bg-amber-50 px-3 py-2.5">
                <label class="text-xs font-medium text-amber-900" for="report-reason">
                    Wat ging er mis, of wat had je verwacht? (mag leeg blijven)
                </label>
                <div class="mt-1.5 flex items-center gap-2">
                    <input id="report-reason" ref="reasonRef" v-model="report_reason" type="text"
                        class="min-w-0 flex-1 rounded-lg border-amber-300 text-sm focus:border-amber-500 focus:ring-amber-500"
                        placeholder="Bijvoorbeeld: hij noemde de verkeerde klant"
                        @keydown.enter.prevent="reportConversation" @keydown.esc.stop.prevent="asking_reason = false">
                    <button type="button" :disabled="reporting"
                        class="shrink-0 rounded-lg bg-amber-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 disabled:opacity-50"
                        @click="reportConversation">
                        {{ reporting ? 'Bezig…' : 'Verstuur melding' }}
                    </button>
                </div>
            </div>

            <div class="border-t border-slate-100 p-2">
                <div class="rounded-2xl bg-white ring-1 ring-slate-200 focus-within:ring-2 focus-within:ring-lavoro-blue"
                    @dragover.prevent @drop.prevent="readFiles($event.dataTransfer?.files)"
                    @paste="readFiles($event.clipboardData?.files)">
                    <textarea ref="inputRef" v-model="question" rows="2" :disabled="asking" placeholder="Stel je vraag…"
                        class="block w-full resize-none rounded-t-2xl border-0 bg-transparent px-3 py-2 text-sm placeholder:text-slate-400 focus:ring-0 disabled:opacity-60"
                        @keydown.enter.exact.prevent="ask" @keydown.up="onArrow($event, -1)"
                        @keydown.down="onArrow($event, 1)" @keydown.esc.prevent="close" />
                    <div class="flex items-center justify-between gap-2 px-1.5 pb-1.5">
                        <div class="flex gap-1">
                            <button type="button" title="Foto meesturen"
                                class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-lavoro-blue/5 hover:text-lavoro-blue hover:ring-lavoro-blue/40"
                                @click="imageInputRef?.click()">
                                <PhotoIcon class="size-4 text-lavoro-blue" />
                                Foto
                            </button>
                            <input ref="imageInputRef" type="file" accept="image/jpeg,image/png,image/webp,image/gif"
                                multiple class="hidden" @change="addAttachments">
                            <button type="button" title="Pdf of tekstbestand meesturen"
                                class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-violet-50 hover:text-violet-700 hover:ring-violet-300"
                                @click="fileInputRef?.click()">
                                <PaperClipIcon class="size-4 text-violet-600" />
                                Bestand
                            </button>
                            <input ref="fileInputRef" type="file" accept="application/pdf,text/plain,text/csv" multiple
                                class="hidden" @change="addAttachments">
                            <button v-if="exchanges.length" type="button" :disabled="reporting || !!reported"
                                class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-amber-50 hover:text-amber-700 hover:ring-amber-300 disabled:opacity-50"
                                @click="startReport">
                                <FlagIcon class="size-4 text-amber-600" />
                                {{ reported || (asking_reason ? 'Annuleren' : 'Melden') }}
                            </button>
                            <button v-if="exchanges.length" type="button" title="Nieuw gesprek"
                                class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-300"
                                @click="newThread">
                                <PlusIcon class="size-4 text-emerald-600" />
                                Nieuw
                            </button>
                        </div>
                        <button type="button" :disabled="asking || question.trim().length < 2" title="Versturen"
                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-lavoro-blue to-indigo-500 text-white shadow-sm shadow-lavoro-blue/40 transition hover:opacity-90 disabled:from-slate-300 disabled:to-slate-300 disabled:shadow-none"
                            @click="ask">
                            <PaperAirplaneIcon class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <div class="border-t border-slate-100 px-3 py-1.5">
            <p v-if="costingMore" class="mb-1 rounded-lg bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-800">
                Foto's gaan mee met deze vraag. Dat kost al gauw tientallen keren zoveel als een vraag zonder foto's, en
                gaat dus sneller door je tegoed heen.
            </p>
            <p v-else-if="costingSome"
                class="mb-1 rounded-lg bg-violet-50 px-2 py-1 text-[11px] font-medium text-violet-800">
                Een bestand gaat mee met deze vraag. Een pdf van tientallen pagina's kost een veelvoud van een gewone
                vraag.
            </p>
            <div class="flex items-center justify-between gap-3">
                <p class="text-[11px] text-slate-400">De AI assistent kan fouten maken, controleer de gegevens altijd</p>
                <button v-if="chapter !== PROMPTS" type="button"
                    class="flex shrink-0 items-center gap-0.5 text-[11px] font-medium text-lavoro-blue hover:underline"
                    @click="chapter = PROMPTS">
                    Vragen beheren
                    <ChevronRightIcon class="size-3" />
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import {
    ArrowPathIcon,
    CalendarDaysIcon,
    ChatBubbleLeftRightIcon,
    ChevronRightIcon,
    ClipboardDocumentListIcon,
    ClockIcon,
    CubeIcon,
    FlagIcon,
    PaperClipIcon,
    PhotoIcon,
    PlusIcon,
    SparklesIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline'
import { PaperAirplaneIcon } from '@heroicons/vue/24/solid'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AssistantPromptForm from '@/Components/Assistant/AssistantPromptForm.vue'
import ChapterContents from '@/Components/Chapters/ChapterContents.vue'
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue'
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue'
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue'
import MarkdownText from '@/Components/UI/MarkdownText.vue'
import { CHAT, HISTORY, PROMPTS, startOver, useAssistantThread } from '@/Composables/useAssistantThread.js'

/**
 * The assistant itself, with no opinion about the frame around it.
 *
 * It was welded into a dialog: the box owned whether it was open, listened for
 * the shortcut, and drew itself inside a shared spotlight shell. None of that is
 * the assistant — it is the modal — and a sidebar wanting the same thing had
 * nowhere to start. So the frame is somebody else's job now, and this renders
 * the same either way; only the height and the corners follow the variant.
 */
defineProps({
    /** 'modal' hangs in a dialog and is capped; 'sidebar' fills the column it is given. */
    variant: {
        type: String,
        default: 'modal',
    },
})

const emit = defineEmits(['close'])

/** How many earlier conversations the empty chat offers before "bekijk alles". */
const RECENT = 4

/**
 * A colour and an icon per suggestion, by position on the list.
 *
 * Four grey cards in a column read as one paragraph nobody scans; four coloured
 * tiles read as four choices. The icon is not claiming to know what the question
 * is about — anybody can write their own — it is there to make the row a shape
 * rather than a line of text.
 */
const TILES = [
    { icon: ClipboardDocumentListIcon, tile: 'bg-lavoro-blue/10 text-lavoro-blue', hover: 'hover:bg-lavoro-blue/5' },
    { icon: CalendarDaysIcon, tile: 'bg-emerald-100 text-emerald-600', hover: 'hover:bg-emerald-50' },
    { icon: CubeIcon, tile: 'bg-violet-100 text-violet-600', hover: 'hover:bg-violet-50' },
    { icon: SparklesIcon, tile: 'bg-amber-100 text-amber-600', hover: 'hover:bg-amber-50' },
]

const tile = (index) => TILES[index % TILES.length]

const {
    chapter,
    question,
    exchanges,
    asking,
    conversation,
    pending_images,
    pending_files,
    attachment_error,
    photos_sent,
    looked_at_photos,
    asking_about_photos,
    reporting,
    reported,
    asking_reason,
    report_reason,
    asked_before,
    recalled,
    draft,
    earlier,
    loading_history,
    history_error,
    prompts,
    all_prompts,
    editing,
    prompt_error,
} = useAssistantThread()

/**
 * One number decides which chapter is drawn, wherever the move came from: a tab,
 * the "bekijk alles" link, the footer, or code closing up after an answer. The
 * loading hangs off a watch for the same reason — otherwise every one of those
 * places would have to remember to fetch, and one of them would forget.
 */
watch(chapter, (now) => {
    if (now === HISTORY) loadHistory({ force: true })
    if (now === PROMPTS) loadEveryPrompt()
})

const inputRef = ref(null)
const threadRef = ref(null)

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

/** Every question, the seeded ones included — the chapter that manages them shows the lot. */
async function loadEveryPrompt() {
    editing.value = null
    prompt_error.value = ''

    try {
        const { data } = await axios.get('/assistant/prompts', { params: { context: 'all' } })
        all_prompts.value = data.prompts || []
    } catch {
        all_prompts.value = []
    }
}

/** Opens an empty form, prefilled with whatever was already typed in the box. */
function addPrompt() {
    prompt_error.value = ''
    editing.value = { id: null, label: '', question: question.value.trim(), context: pageContext() }
}

/** One save for both roads: a new question and an edited one. */
async function savePrompt(draft) {
    const id = editing.value?.id ?? null

    if (draft.label.trim().length < 1 || draft.question.trim().length < 2) {
        prompt_error.value = 'Geef een korte naam en de vraag zelf.'

        return
    }

    prompt_error.value = ''

    try {
        await axios.get('/sanctum/csrf-cookie')
        const body = { label: draft.label.trim(), question: draft.question.trim(), context: draft.context }
        const { data } = id
            ? await axios.patch('/assistant/prompts/' + id, body)
            : await axios.post('/assistant/prompts', body)

        all_prompts.value = id
            ? all_prompts.value.map((entry) => (entry.id === id ? data.prompt : entry))
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
const fileInputRef = ref(null)

/** Four photos of ~3 MB is a typeplaatje from every angle; more is a mistake. */
const MAX_IMAGES = 4

/** Two is comparing one datasheet with another, which is the question people ask. */
const MAX_FILES = 2

/** What the model can really open. A .docx sent along comes back as bytes. */
const READABLE = ['application/pdf', 'text/plain', 'text/csv']

/**
 * One reader for every road in: the two pickers, a drop and a paste.
 *
 * A photo and a datasheet arrive the same way and are told apart by what they
 * are, not by which button was pressed — dragging a pdf onto the box should work
 * whether or not somebody found the right button first.
 */
function readFiles(files) {
    for (const file of Array.from(files || [])) {
        if (file.type?.startsWith('image/')) {
            if (pending_images.value.length >= MAX_IMAGES) continue

            const reader = new FileReader()
            reader.onload = () => pending_images.value.push(reader.result)
            reader.readAsDataURL(file)

            continue
        }

        if (!READABLE.includes(file.type) || pending_files.value.length >= MAX_FILES) {
            /** Said out loud rather than dropped: a file that vanishes reads as one that was read. */
            if (!READABLE.includes(file.type)) {
                attachment_error.value = 'Alleen foto\'s, pdf- en tekstbestanden kunnen mee. '
                    + 'Sla een Word- of Excel-bestand op als pdf.'
            }

            continue
        }

        attachment_error.value = ''

        const reader = new FileReader()
        reader.onload = () => pending_files.value.push({ name: file.name, data: reader.result })
        reader.readAsDataURL(file)
    }
}

function addAttachments(event) {
    readFiles(event.target.files)
    event.target.value = ''
}
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

/** What this conversation is about: the first real question, skipping continuations. */
const openingQuestion = computed(() => exchanges.value.find((exchange) => exchange.question)?.question ?? '')

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
function byMachine(findings, photos) {
    const groups = new Map()

    for (const finding of findings || []) {
        const subject = finding.subject || 'Apparaat'

        if (!groups.has(subject)) groups.set(subject, { fields: new Map(), photos: new Set() })

        const group = groups.get(subject)
        const seen = group.fields.get(finding.field)

        if (!seen || finding.confidence > seen.confidence) group.fields.set(finding.field, finding)

        for (const id of finding.image_ids || []) {
            if (photos?.[id]) group.photos.add(photos[id])
        }
    }

    return [...groups].map(([subject, group]) => ({
        subject,
        findings: [...group.fields.values()],
        photos: [...group.photos],
    }))
}

/**
 * Whether pictures are going up with the next question.
 *
 * Three roads lead there and the warning has to cover all of them: photos
 * waiting to be sent, photos parked with this conversation that ride along with
 * every follow-up, and a tool that fetched the ones already on a record.
 */

/**
 * Notes that this conversation has looked at photographs.
 *
 * Kept in one place because there are three ways in — asking, carrying on after
 * a confirmation, and reopening an old thread — and the warning was set on only
 * the first of them, so it went quiet on precisely the conversations that had
 * already run up the bill.
 */
function notePhotoTools(tools) {
    if ((tools || []).some((tool) => tool?.name === 'view_images')) {
        looked_at_photos.value = true
    }
}

const costingMore = computed(() =>
    pending_images.value.length > 0 || photos_sent.value > 0 || looked_at_photos.value)

/** A datasheet is cheaper than a photo and far from free: twenty pages is tens of thousands of tokens. */
const costingSome = computed(() => pending_files.value.length > 0)

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

        const turns = data.turns || []

        /** A reopened thread that once looked at photos still costs what it costs. */
        turns.forEach((turn) => notePhotoTools((turn.tools || []).map((name) => ({ name }))))

        exchanges.value = turns.map((turn) => ({
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
        chapter.value = CHAT
        question.value = ''
        draft.value = ''
        recalled.value = -1
        scrollToLatest()
        inputRef.value?.focus()
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
 * The arrows belong to the caret first.
 *
 * They only carry a question back once the caret has nowhere further to go in
 * that direction — up from the very start, down from the very end. Taking them
 * outright is how a multi-line question becomes impossible to edit: one press
 * of up on the second line and what you were writing is gone.
 */
function onArrow(event, direction) {
    const field = event.target
    const at_start = field.selectionStart === 0 && field.selectionEnd === 0
    const at_end = field.selectionStart === field.value.length && field.selectionEnd === field.value.length

    if ((direction < 0 && at_start) || (direction > 0 && at_end)) {
        event.preventDefault()
        recall(direction)
    }
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

/** Everything the panel needs the moment it becomes visible, wherever it lives. */
function start() {
    loadPrompts()
    loadHistory()
    focus()
}

/** Waits for whatever frame this is in to finish putting the field on screen. */
async function focus() {
    await nextTick()
    inputRef.value?.focus()
}

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
    /**
     * Photos nobody decided about hold the door once. Closing again closes
     * anyway — the question is a question, not a lock — and undecided photos
     * are cleaned up by themselves after a few days.
     */
    if (photos_sent.value > 0 && !asking_about_photos.value) {
        asking_about_photos.value = true

        return
    }

    chapter.value = CHAT
    asking_about_photos.value = false
    emit('close')
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
    const files = pending_files.value.slice()
    pending_images.value = []
    pending_files.value = []

    /**
     * Reactive before it is pushed, not after.
     *
     * A plain object handed to a ref array stays plain in the variable holding
     * it: the array reads back a proxy, but `exchange.answer = ...` writes to
     * the raw one and nothing is told. It repainted anyway while one component
     * drew the whole box — some other ref always changed in the same breath —
     * and stopped the moment the conversation moved into a slot of its own,
     * which left an answer sitting in memory under "aan het opzoeken".
     */
    const exchange = reactive({ question: asked, images, files: files.map((file) => file.name), answer: '', tools: [], error: '', pending: true, pendingActions: [], choices: [], findings: [], unreadable: [], photos: {}, unverified: [] })
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
                documents: files.length ? files : undefined,
            },
            { timeout: ASK_TIMEOUT_MS },
        )
        photos_sent.value += images.length

        /**
         * Tracked on its own rather than read back off the exchange: that object
         * is pushed before its tools are filled in, so a computed watching it
         * never hears about the change and the warning stayed dark on exactly the
         * turns that cost the most.
         */
        exchange.answer = data.answer
        exchange.tools = data.tools || []
        notePhotoTools(data.tools)
        exchange.pendingActions = asActions(data.pending)
        exchange.choices = asChoices(data.choices)
        exchange.findings = data.findings || []
        exchange.unreadable = data.unreadable || []
        exchange.photos = data.photos || {}
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
        inputRef.value?.focus()
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

    const exchange = reactive({ question: '', answer: '', tools: [], error: '', pending: true, pendingActions: [], choices: [], findings: [], unreadable: [], unverified: [] })
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
        notePhotoTools(data.tools)
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

/** A fresh thread, and the cursor back where the next question goes. */
function newThread() {
    startOver()
    inputRef.value?.focus()
}

onMounted(start)

defineExpose({ start, focus, close })
</script>
