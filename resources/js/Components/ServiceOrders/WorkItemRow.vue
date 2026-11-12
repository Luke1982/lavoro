<template>
    <div class="flex items-center gap-3 rounded-lavoro-sm border p-3 transition-colors" :class="settled
        ? 'border-gray-200/70 bg-gray-50/70 dark:border-slate-700/60 dark:bg-slate-800/40'
        : 'border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-900'">
        <div class="flex size-11 flex-none items-center justify-center rounded-lg" :class="badge.bg">
            <component :is="done ? CheckIcon : icon" class="size-6" :class="badge.text" />
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold line-clamp-2"
                :class="settled ? 'text-gray-500 dark:text-slate-400' : 'text-gray-900 dark:text-slate-100'">
                {{ title }}
            </p>
            <p v-if="subtitle" class="mt-0.5 text-xs text-gray-500 dark:text-slate-400 line-clamp-2">{{ subtitle }}</p>
            <slot />
        </div>
        <span v-if="muted" class="flex-none text-xs font-medium text-gray-400 dark:text-slate-500">{{ mutedLabel }}</span>
        <component v-else :is="href ? Link : 'button'" :href="href" :type="href ? undefined : 'button'"
            :disabled="href ? undefined : disabled"
            class="inline-flex flex-none items-center gap-1.5 whitespace-nowrap rounded-lavoro-sm px-3 py-2 text-sm font-semibold transition-opacity disabled:cursor-not-allowed disabled:opacity-50"
            :class="done
                ? 'border border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-400'
                : 'bg-lavoro-blue text-white hover:opacity-90'" @click="onAction">
            <component :is="done ? CheckIcon : actionIcon" class="size-4" />
            {{ done ? doneLabel : actionLabel }}
        </component>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Check as CheckIcon } from '@lucide/vue'

/**
 * Eén ding dat er op een werkbon moet gebeuren, met de ene knop die het doet:
 * een keuring uitvoeren, een storing oplossen, een taak afvinken. Wat af is
 * blijft staan, grijs en met een vinkje, zodat de lijst laat zien hoe ver je bent.
 */
const props = defineProps({
    icon: { type: [Object, Function], required: true },
    tone: { type: String, default: 'blue' },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    actionLabel: { type: String, required: true },
    actionIcon: { type: [Object, Function], required: true },
    doneLabel: { type: String, required: true },
    /** Een link in plaats van een knop: het werk zelf gebeurt op een andere pagina. */
    href: { type: String, default: null },
    done: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Van de lijst zonder af te zijn, zoals een geannuleerde taak. */
    muted: { type: Boolean, default: false },
    mutedLabel: { type: String, default: '' },
})

const emit = defineEmits(['action'])

const TONES = {
    blue: { bg: 'bg-lavoro-blue/10', text: 'text-lavoro-blue dark:text-sky-400' },
    red: { bg: 'bg-red-500/10', text: 'text-red-600 dark:text-red-400' },
    green: { bg: 'bg-green-500/10', text: 'text-green-600 dark:text-green-400' },
    gray: { bg: 'bg-gray-500/10', text: 'text-gray-400' },
}

const settled = computed(() => props.done || props.muted)
const badge = computed(() => TONES[props.done ? 'green' : props.muted ? 'gray' : props.tone])

function onAction() {
    if (!props.href) emit('action')
}
</script>
