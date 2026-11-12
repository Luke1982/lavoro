<template>
    <div role="tablist" class="flex gap-1 rounded-lavoro-sm bg-gray-100 p-1 dark:bg-slate-800">
        <button v-for="option in options" :key="option.value" type="button" role="tab"
            :aria-selected="model === option.value"
            class="flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold transition-colors cursor-pointer"
            :class="model === option.value
                ? 'bg-white text-lavoro-blue shadow-sm dark:bg-slate-700 dark:text-sky-300'
                : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
            @click="model = option.value">
            <component v-if="option.icon" :is="option.icon" class="size-4" />
            {{ option.label }}
            <span v-if="option.count"
                class="inline-flex min-w-5 items-center justify-center rounded-full bg-lavoro-blue/10 px-1.5 text-xs text-lavoro-blue dark:text-sky-300">
                {{ option.count }}
            </span>
        </button>
    </div>
</template>

<script setup>
/**
 * Twee of drie standen naast elkaar, waarvan er altijd precies één aan staat:
 * de weergave van een pagina, intern tegenover extern. Er valt niets uit te
 * zetten, dus nogmaals op de actieve stand tikken doet niets.
 */
defineProps({
    /** [{ value, label, icon?, count? }] */
    options: { type: Array, required: true },
})

const model = defineModel({ required: true })
</script>
