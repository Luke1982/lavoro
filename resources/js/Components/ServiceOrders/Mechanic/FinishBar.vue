<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-30 px-4 sm:px-6 lg:px-8"
        :class="collapsed ? 'lg:left-[4.5rem]' : 'lg:left-72'" :style="placement">
        <div class="mx-auto max-w-3xl">
            <button type="button" :aria-hidden="typing" :tabindex="typing ? -1 : undefined"
                class="pointer-events-auto flex w-full items-center justify-center gap-2 rounded-lavoro-sm py-3.5 text-base font-semibold shadow-[0_8px_30px_rgba(2,6,23,0.25)] transition-colors cursor-pointer"
                :class="TONES[tone] ?? TONES.neutral"
                @click="$emit('click')">
                <component :is="icon" class="size-5" />
                {{ label }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useTabBar } from '@/Composables/useTabBar.js'
import { useMenu } from '@/Composables/useMenu.js'

/**
 * De ene knop waar een bezoek mee eindigt, vast onderin binnen duimbereik.
 *
 * Hij staat op dezelfde onderlijn als de menubalk en schuift er precies zo ver
 * boven als de balk hoog is. Veeg je de balk opzij, dan zakt de knop met
 * dezelfde beweging de vrijgekomen ruimte in, en houdt hij plaats vrij voor het
 * lipje waarmee je de balk terughaalt. Op een computer volgt hij de zijbalk.
 *
 * Zolang er getypt wordt verdwijnt hij: met het toetsenbord open zou hij precies
 * over het veld komen te liggen waar je in schrijft.
 */
defineProps({
    label: { type: String, required: true },
    icon: { type: [Object, Function], required: true },
    tone: { type: String, default: 'green' },
})

defineEmits(['click'])

/** Dekkend, ook bij hover: de knop zweeft boven de inhoud van de werkbon. */
const TONES = {
    blue: 'bg-lavoro-blue text-white hover:brightness-110',
    green: 'bg-lavoro-green text-gray-800 hover:brightness-95',
    neutral: 'bg-white text-gray-800 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-600',
}

const { tucked, clearance } = useTabBar()
const { collapsed } = useMenu()

/** Ruimte tussen de knop en de bovenkant van de balk, in pixels. */
const GAP = 10

/** Plaats voor het lipje dat de balk terughaalt: zijn breedte plus wat lucht. */
const TAB_ROOM = '3.25rem'

/** Dezelfde beweging als de balk, zodat ze samen gaan; die van de zijbalk voor de linkerrand. */
const TRANSITION = [
    'transform 300ms cubic-bezier(0.22,1,0.36,1)',
    'padding 300ms cubic-bezier(0.22,1,0.36,1)',
    'opacity 200ms ease',
    'left var(--menu-duration) var(--menu-ease)',
].join(', ')

const typing = ref(false)

const barShown = computed(() => clearance.value > 0 && !tucked.value)
const tabSide = computed(() => (clearance.value > 0 ? tucked.value : null))

const placement = computed(() => ({
    transform: typing.value
        ? 'translateY(calc(100% + 2rem))'
        : 'translateY(-' + (barShown.value ? clearance.value + GAP : 0) + 'px)',
    opacity: typing.value ? 0 : 1,
    paddingLeft: tabSide.value === 'left' ? TAB_ROOM : undefined,
    paddingRight: tabSide.value === 'right' ? TAB_ROOM : undefined,
    transition: TRANSITION,
}))

const TEXT_ENTRY = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), textarea, select, [contenteditable=""], [contenteditable=true]'

/** Alleen een aanraakscherm haalt een toetsenbord tevoorschijn dat de pagina onder de knop opduwt. */
const touch = window.matchMedia?.('(pointer: coarse)').matches ?? false

const entersText = (element) => touch && element instanceof Element && element.matches(TEXT_ENTRY)

function onFocusIn(event) {
    if (entersText(event.target)) typing.value = true
}

/** Wie van veld naar veld gaat verlaat het ene voordat het volgende focus heeft, dus kijk daarna pas. */
function onFocusOut() {
    setTimeout(() => { typing.value = entersText(document.activeElement) })
}

onMounted(() => {
    document.addEventListener('focusin', onFocusIn)
    document.addEventListener('focusout', onFocusOut)
})

onBeforeUnmount(() => {
    document.removeEventListener('focusin', onFocusIn)
    document.removeEventListener('focusout', onFocusOut)
})
</script>
