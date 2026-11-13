<template>
    <section class="relative z-10 flex min-h-0 flex-col">
        <header class="mb-3 flex items-baseline justify-between">
            <h2 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-slate-400">{{ title }}</h2>
            <span class="text-xs text-gray-400 dark:text-slate-500">{{ count }}</span>
        </header>
        <input v-model="search" type="search" :placeholder="searchPlaceholder"
            class="mb-2 w-full rounded-md border-0 px-3 py-1.5 text-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 dark:text-white dark:ring-slate-600" />
        <form v-if="addLabel" class="mb-3" @submit.prevent="add">
            <div class="flex gap-2">
                <input v-model="draft" type="text" :placeholder="addLabel"
                    class="min-w-0 flex-1 rounded-md border-0 px-3 py-1.5 text-sm ring-1 ring-inset ring-dashed ring-gray-300 placeholder:text-lavoro-blue/70 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 dark:text-white dark:ring-slate-600" />
                <button type="submit" :disabled="!draft.trim()"
                    class="rounded-md bg-lavoro-blue px-3 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-40">
                    +
                </button>
            </div>
            <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        </form>
        <div data-scroller class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1 pb-2">
            <slot />
        </div>
    </section>
</template>

<script setup>
/**
 * One column of the checklist editor: its own search, its own way to add a
 * card, and its own scroll, so a long list of checks never pushes the tasks
 * out of view.
 */
defineProps({
    title: { type: String, required: true },
    count: { type: Number, default: 0 },
    addLabel: { type: String, default: null },
    searchPlaceholder: { type: String, default: 'Zoeken...' },
    error: { type: String, default: '' },
})

const emit = defineEmits(['add'])

const search = defineModel('search', { type: String, default: '' })
const draft = defineModel('draft', { type: String, default: '' })

function add() {
    if (draft.value.trim()) emit('add', draft.value.trim())
}
</script>
