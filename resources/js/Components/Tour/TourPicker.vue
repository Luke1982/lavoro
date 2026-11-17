<template>
    <ModalDialog :open="picker.open" :title="tour?.title ?? 'Rondleiding'" max-width-class="sm:max-w-xl"
        @update:open="(open) => { if (!open) closePicker() }">
        <div class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-slate-300">
                {{ picker.auto ? 'Welkom! ' : '' }}{{ tour?.intro }}
            </p>
            <p v-if="allSeen" class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                Je hebt alles al gezien. Klik aan wat je nog eens wilt zien.
            </p>

            <ul class="space-y-2" role="list">
                <li v-for="(chapter, index) in picker.chapters" :key="chapter.id">
                    <button type="button" role="checkbox" :aria-checked="isPicked(chapter)"
                        class="group flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left ring-1 transition-colors"
                        :class="isPicked(chapter)
                            ? 'bg-lavoro-lightblue/70 ring-lavoro-blue dark:bg-blue-950/40'
                            : 'ring-gray-200 hover:bg-gray-50 dark:ring-slate-700 dark:hover:bg-slate-800'"
                        @click="togglePickedChapter(chapter.id)">
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border transition-colors"
                            :class="isPicked(chapter)
                                ? 'border-lavoro-blue bg-lavoro-blue text-white'
                                : 'border-gray-300 bg-white dark:border-slate-600 dark:bg-slate-900'">
                            <CheckIcon v-if="isPicked(chapter)" class="size-3.5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ index + 1 }}. {{ chapter.title }}
                                </span>
                                <BadgeComponent :color="badge(chapter).color">{{ badge(chapter).label }}</BadgeComponent>
                            </span>
                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-slate-400">{{ chapter.summary }}</span>
                        </span>
                        <span class="shrink-0 whitespace-nowrap text-xs tabular-nums text-gray-400 dark:text-slate-500">
                            {{ durationLabel(chapterSeconds(chapter)) }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <template #footer>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-300 sm:mr-auto">
                    <SwitchComponent :model-value="!tourState.muted" title="Gesproken uitleg"
                        @update:model-value="toggleMute" />
                    Met geluid
                </label>
                <button type="button"
                    class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600 dark:hover:bg-slate-700"
                    @click="closePicker">
                    Niet nu
                </button>
                <button type="button" :disabled="!picker.selected.length"
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="startFromPicker">
                    <PlayIcon class="size-4" />
                    {{ startLabel }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed } from 'vue'
import { CheckIcon, PlayIcon } from '@heroicons/vue/24/solid'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { tourById, chapterSeconds, durationLabel } from '@/Tours/catalog'
import { tourState, togglePickedChapter, closePicker, startFromPicker, toggleMute } from '@/Tours/engine'

/**
 * Which chapters to watch. The first time everything is ticked; after that only
 * what is new, or changed since it was seen. A click on a chapter ticks it or
 * unticks it — there is no separate button to clear the list.
 */
const picker = computed(() => tourState.picker)
const tour = computed(() => tourById(picker.value.tourId))
const progress = computed(() => tourState.progress[picker.value.tourId] ?? { seen: {} })

const isPicked = (chapter) => picker.value.selected.includes(chapter.id)

function badge(chapter) {
    const seen = progress.value.seen?.[chapter.id]
    if (seen === undefined) return { label: 'Nieuw', color: 'green' }
    if (seen !== chapter.version) return { label: 'Bijgewerkt', color: 'yellow' }

    return { label: 'Gezien', color: 'gray' }
}

const allSeen = computed(() => progress.value.started
    && picker.value.chapters.length > 0
    && picker.value.chapters.every((chapter) => progress.value.seen?.[chapter.id] === chapter.version))

const startLabel = computed(() => {
    const picked = picker.value.chapters.filter(isPicked)
    if (!picked.length) return 'Kies een hoofdstuk'
    const seconds = picked.reduce((total, chapter) => total + chapterSeconds(chapter), 0)

    return 'Start (' + picked.length + (picked.length === 1 ? ' hoofdstuk, ' : ' hoofdstukken, ') + durationLabel(seconds) + ')'
})
</script>
