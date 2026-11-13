<template>
    <div class="absolute z-30 w-64 rounded-lavoro-sm bg-white p-3 text-sm shadow-lg ring-1 ring-black/10 dark:bg-slate-800 dark:ring-slate-600"
        :style="{ left: position.x + 'px', top: position.y + 'px' }" @click.stop>
        <p class="mb-2 truncate font-semibold text-gray-800 dark:text-slate-100">{{ label }}</p>
        <div class="grid grid-cols-2 gap-1 rounded-md bg-gray-100 p-0.5 dark:bg-slate-900">
            <button v-for="option in options" :key="option.value" type="button" @click="choose(option.value)" :class="[
                'rounded px-2 py-1 text-xs font-semibold transition-colors',
                link.required === option.value
                    ? 'bg-white text-gray-900 shadow-sm dark:bg-slate-700 dark:text-white'
                    : 'text-gray-500 hover:text-gray-800 dark:text-slate-400',
            ]">
                {{ option.label }}
            </button>
        </div>
        <p v-if="link.kind === 'checklist'" class="mt-2 text-xs text-gray-500 dark:text-slate-400">
            Optioneel maakt de hele checklist optioneel voor deze taak.
        </p>
        <button type="button" @click="$emit('unlink')"
            class="mt-3 w-full rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950">
            Ontkoppelen
        </button>
    </div>
</template>

<script setup>
/**
 * What a line can be: required or optional, or gone. A line has exactly two
 * states, so the one already chosen stays chosen when clicked.
 */
const props = defineProps({
    link: { type: Object, required: true },
    label: { type: String, default: '' },
    position: { type: Object, required: true },
})

const emit = defineEmits(['set-required', 'unlink'])

const options = [
    { value: true, label: 'Verplicht' },
    { value: false, label: 'Optioneel' },
]

function choose(value) {
    if (value !== props.link.required) emit('set-required', value)
}
</script>
