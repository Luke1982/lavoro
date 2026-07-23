<template>
    <div :class="['flex items-center', $slots.actions ? 'justify-between' : '', border ? 'mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-3' : 'mb-4']">
        <div class="flex items-center min-w-0">
            <div :class="['flex-none flex items-center justify-center size-10 rounded-lavoro-sm mr-3', theme.bg]">
                <component :is="icon" :class="['size-6 stroke-2', theme.text]" />
            </div>
            <h2 class="font-semibold text-base dark:text-slate-200 truncate">
                <slot>{{ title }}</slot>
            </h2>
        </div>
        <div v-if="$slots.actions" class="flex-none ml-3">
            <slot name="actions" />
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    icon: { required: true },
    title: { type: String, default: '' },
    color: { type: String, default: 'blue' },
    border: { type: Boolean, default: false },
});

const themes = {
    blue: { bg: 'bg-lavoro-blue/10', text: 'text-lavoro-blue' },
    red: { bg: 'bg-red-500/10', text: 'text-red-600 dark:text-red-400' },
    orange: { bg: 'bg-orange-500/10', text: 'text-orange-600 dark:text-orange-400' },
    green: { bg: 'bg-green-500/10', text: 'text-green-600 dark:text-green-400' },
    indigo: { bg: 'bg-indigo-500/10', text: 'text-indigo-600 dark:text-indigo-400' },
    violet: { bg: 'bg-violet-600/10', text: 'text-violet-600 dark:text-violet-400' },
    amber: { bg: 'bg-amber-500/10', text: 'text-amber-600 dark:text-amber-400' },
    gray: { bg: 'bg-gray-500/10', text: 'text-gray-600 dark:text-slate-300' },
};

const theme = computed(() => themes[props.color] ?? themes.blue);
</script>
