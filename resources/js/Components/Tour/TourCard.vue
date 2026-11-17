<template>
    <div ref="root" role="dialog" aria-label="Rondleiding" tabindex="-1"
        class="tour-card pointer-events-auto flex max-h-[min(70vh,34rem)] flex-col overflow-hidden rounded-2xl bg-white text-gray-900 shadow-2xl ring-1 ring-black/5 focus:outline-none dark:bg-slate-900 dark:text-slate-100 dark:ring-white/10"
        :class="{ 'tour-card-nudge': nudging }">

        <template v-if="tourState.phase === 'finished' && tourState.finished">
            <div class="p-5">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-full bg-lavoro-green/30 text-lavoro-dark dark:text-lavoro-green">
                        <SparklesIcon class="size-5" />
                    </span>
                    <div>
                        <p class="text-base font-semibold">Klaar!</p>
                        <p class="text-sm text-gray-500 dark:text-slate-400">
                            Je hebt {{ tourState.finished.chapters.length === 1 ? 'één hoofdstuk' : tourState.finished.chapters.length + ' hoofdstukken' }} gezien.
                        </p>
                    </div>
                </div>
                <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-slate-300">
                    <li v-for="title in tourState.finished.chapters" :key="title" class="flex items-center gap-2">
                        <CheckCircleIcon class="size-4 shrink-0 text-emerald-500" />{{ title }}
                    </li>
                </ul>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="tour-secondary" @click="pickOthers">Andere hoofdstukken</button>
                    <button type="button" class="tour-primary" @click="closeFinished">Sluiten</button>
                </div>
            </div>
        </template>

        <template v-else-if="tourState.phase === 'interlude' && tourState.interlude">
            <div class="p-5">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-lavoro-blue">
                    Volgende hoofdstuk · {{ tourState.interlude.chapterIndex + 1 }} van {{ run.chapters.length }}
                </p>
                <p class="mt-1 text-lg font-semibold">{{ tourState.interlude.chapter.title }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">{{ tourState.interlude.chapter.summary }}</p>
                <div class="mt-4 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                    <div class="tour-interlude-bar h-full rounded-full bg-lavoro-blue"
                        :style="{ animationDuration: tourState.interlude.duration + 'ms', animationPlayState: tourState.paused ? 'paused' : 'running' }" />
                </div>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" class="tour-icon" title="Rondleiding stoppen" aria-label="Rondleiding stoppen" @click="stopTour">
                        <XMarkIcon class="size-5" />
                    </button>
                    <div class="flex gap-2">
                        <button type="button" class="tour-secondary" @click="skipChapter">Overslaan</button>
                        <button type="button" class="tour-primary" @click="nextStep">Begin</button>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="run && chapter && step">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4 pt-3 pb-2 dark:border-slate-800">
                <AcademicCapIcon class="size-4 shrink-0 text-lavoro-blue" />
                <p class="min-w-0 flex-1 truncate text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">
                    <template v-if="run.chapters.length > 1">{{ shown.chapterIndex + 1 }}/{{ run.chapters.length }} · </template>{{ chapter.title }}
                </p>
                <button type="button" class="tour-icon -mr-1" title="Rondleiding stoppen (Esc)" aria-label="Rondleiding stoppen" @click="stopTour">
                    <XMarkIcon class="size-5" />
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 pt-3">
                <div class="flex gap-1" aria-hidden="true">
                    <span v-for="(item, index) in chapter.steps" :key="index" class="h-1 rounded-full transition-all duration-300"
                        :class="index < shown.stepIndex ? 'w-3 bg-lavoro-blue' : index === shown.stepIndex ? 'w-7 bg-lavoro-blue' : 'w-3 bg-gray-200 dark:bg-slate-700'" />
                </div>
                <Transition mode="out-in" enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 translate-y-1"
                    leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0">
                    <div :key="chapter.id + shown.stepIndex" aria-live="polite" :class="{ 'opacity-60 transition-opacity': moving }">
                        <h3 class="mt-3 text-base font-semibold leading-snug">{{ step.title }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-slate-300" v-html="rich(step.text)" />
                    </div>
                </Transition>

                <p class="mt-3 flex gap-2 rounded-xl bg-gray-50 px-3 py-2 text-[13px] leading-snug dark:bg-slate-800/70" aria-hidden="true">
                    <component :is="tourState.muted ? SpeakerXMarkIcon : SpeakerWaveIcon"
                        class="mt-0.5 size-4 shrink-0" :class="speaking ? 'text-lavoro-blue tour-speaking' : 'text-gray-400'" />
                    <span>
                        <span v-for="(word, index) in words" :key="index"
                            class="transition-colors duration-150"
                            :class="index < spokenWords ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-slate-500'">{{ word + ' ' }}</span>
                    </span>
                </p>

                <button v-if="step.cta" type="button" class="tour-primary mt-3 w-full justify-center" @click="followCallToAction(step.cta)">
                    {{ step.cta.label }}
                    <ArrowRightIcon class="size-4" />
                </button>
            </div>

            <div class="flex items-center gap-1 px-3 py-3">
                <button type="button" class="tour-icon" :disabled="atStart" title="Vorige (←)" aria-label="Vorige stap" @click="previousStep">
                    <ChevronLeftIcon class="size-5" />
                </button>
                <button type="button" class="tour-icon" :title="tourState.paused ? 'Verder (spatie)' : 'Pauze (spatie)'"
                    :aria-label="tourState.paused ? 'Verder' : 'Pauze'" @click="togglePause">
                    <PlayIcon v-if="tourState.paused" class="size-5" />
                    <PauseIcon v-else class="size-5" />
                </button>
                <button type="button" class="tour-icon" :title="tourState.muted ? 'Geluid aan (M)' : 'Geluid uit (M)'"
                    :aria-label="tourState.muted ? 'Geluid aan' : 'Geluid uit'" @click="toggleMute">
                    <SpeakerXMarkIcon v-if="tourState.muted" class="size-5" />
                    <SpeakerWaveIcon v-else class="size-5" />
                </button>
                <button type="button" class="ml-auto rounded-md px-2 py-1.5 text-xs font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                    @click="skipChapter">
                    Hoofdstuk overslaan
                </button>
                <button type="button" class="tour-primary" @click="nextStep">
                    {{ isLast ? 'Klaar' : 'Volgende' }}
                    <ChevronRightIcon class="size-4" />
                </button>
            </div>
            <div class="h-0.5 bg-gray-100 dark:bg-slate-800">
                <div class="h-full bg-lavoro-blue/70" :style="{ width: (tourState.caption.progress * 100) + '%' }" />
            </div>
        </template>

        <div v-else-if="run" class="flex items-center gap-3 p-5 text-sm text-gray-500 dark:text-slate-400">
            <AcademicCapIcon class="tour-speaking size-5 text-lavoro-blue" />
            Even klaarzetten…
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import {
    AcademicCapIcon, ArrowRightIcon, ChevronLeftIcon, ChevronRightIcon, PauseIcon, PlayIcon,
    SpeakerWaveIcon, SpeakerXMarkIcon, XMarkIcon, SparklesIcon, CheckCircleIcon,
} from '@heroicons/vue/24/outline'
import {
    tourState, nextStep, previousStep, skipChapter, stopTour,
    togglePause, toggleMute, followCallToAction, closeFinished, openPicker,
} from '@/Tours/engine'

const root = ref(null)
defineExpose({ root })

const run = computed(() => tourState.run)
/**
 * What the card shows is the step that is ready, not the one being prepared: a
 * step whose spot turns out to be missing is skipped before anybody reads it.
 */
const shown = computed(() => tourState.shown)
const chapter = computed(() => (run.value && shown.value ? run.value.chapters[shown.value.chapterIndex] ?? null : null))
const step = computed(() => (chapter.value ? chapter.value.steps[shown.value.stepIndex] ?? null : null))
const moving = computed(() => tourState.phase === 'moving')
const atStart = computed(() => !shown.value || (shown.value.chapterIndex === 0 && shown.value.stepIndex === 0))
const isLast = computed(() => run.value && shown.value
    && shown.value.chapterIndex === run.value.chapters.length - 1
    && shown.value.stepIndex === chapter.value.steps.length - 1)

const words = computed(() => (tourState.caption.text || '').split(/\s+/).filter(Boolean))

/** The caption runs on characters, so a long word takes as long to light up as it takes to say. */
const spokenWords = computed(() => {
    const target = tourState.caption.progress * (tourState.caption.text || '').length
    let count = 0
    let position = 0
    for (const word of words.value) {
        if (position >= target) break
        position += word.length + 1
        count++
    }

    return count
})

const speaking = computed(() => tourState.phase === 'step' && !tourState.paused && !tourState.narrated && !tourState.muted)

const nudging = ref(false)
watch(() => tourState.nudge, () => {
    nudging.value = false
    requestAnimationFrame(() => { nudging.value = true })
    setTimeout(() => { nudging.value = false }, 600)
})

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character])
}

/** Storyboard text knows **bold** and *italic*, nothing more. */
function rich(text) {
    return escapeHtml(text || '')
        .replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold text-gray-900 dark:text-white">$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
}

function pickOthers() {
    const tourId = tourState.finished?.tourId
    closeFinished()
    if (tourId) openPicker(tourId)
}
</script>

<style>
.tour-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 0.5rem;
    background: var(--color-lavoro-blue);
    padding: 0.4rem 0.8rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: white;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.1);
}

.tour-primary:hover {
    opacity: 0.92;
}

.tour-secondary {
    border-radius: 0.5rem;
    padding: 0.4rem 0.8rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: rgb(55 65 81);
    box-shadow: inset 0 0 0 1px rgb(209 213 219);
}

.tour-secondary:hover {
    background: rgb(249 250 251);
}

.tour-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    padding: 0.35rem;
    color: rgb(107 114 128);
}

.tour-icon:hover:not(:disabled) {
    background: rgb(243 244 246);
    color: rgb(17 24 39);
}

@media (prefers-color-scheme: dark) {
    .tour-secondary {
        color: rgb(226 232 240);
        box-shadow: inset 0 0 0 1px rgb(71 85 105);
    }

    .tour-secondary:hover {
        background: rgb(30 41 59);
    }

    .tour-icon:hover:not(:disabled) {
        background: rgb(30 41 59);
        color: white;
    }
}

.tour-icon:disabled {
    opacity: 0.35;
}

.tour-card-nudge {
    animation: tour-nudge 0.5s ease-in-out;
}

@keyframes tour-nudge {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-4px); }
    80% { transform: translateX(3px); }
}

.tour-interlude-bar {
    width: 0;
    animation-name: tour-fill;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}

@keyframes tour-fill {
    to { width: 100%; }
}

.tour-speaking {
    animation: tour-speak 1s ease-in-out infinite;
}

@keyframes tour-speak {
    50% { opacity: 0.45; }
}

@media (prefers-reduced-motion: reduce) {
    .tour-card-nudge,
    .tour-speaking {
        animation: none;
    }
}
</style>
