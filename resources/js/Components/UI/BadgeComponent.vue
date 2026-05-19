<template>
    <Link v-if="url" :href="url" v-tooltip="tooltip">
        <span
            :class="['inline-flex items-center gap-x-1.5 rounded-full px-4 py-2 text-xs font-medium ring-1 ring-inset', theme.span]">
            <svg :class="['w-2.5 h-2.5', theme.dot]" viewBox="0 0 6 6" aria-hidden="true">
                <circle cx="3" cy="3" r="3" />
            </svg>
            <slot />
        </span>
    </Link>
    <span v-else v-tooltip="tooltip"
        :class="['inline-flex items-center gap-x-1.5 rounded-full px-4 py-2 text-xs font-medium ring-1 ring-inset', theme.span]">
        <svg :class="['w-2.5 h-2.5', theme.dot]" viewBox="0 0 6 6" aria-hidden="true">
            <circle cx="3" cy="3" r="3" />
        </svg>
        <slot />
    </span>
</template>
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    color: {
        type: String,
        default: 'red',
    },
    url: {
        type: String,
        default: null,
    },
    tooltip: {
        type: String,
        default: '',
    },
    text: {
        type: String,
        default: 'Badge',
    },
});

const themes = {
    red: { span: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-500/30', dot: 'fill-red-500 dark:fill-red-400' },
    orange: { span: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-500/30', dot: 'fill-orange-500 dark:fill-orange-400' },
    yellow: { span: 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-900/20 dark:text-yellow-300 dark:ring-yellow-500/30', dot: 'fill-yellow-500 dark:fill-yellow-400' },
    green: { span: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30', dot: 'fill-green-500 dark:fill-green-400' },
    blue: { span: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-500/30', dot: 'fill-blue-500 dark:fill-blue-400' },
    indigo: { span: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-500/30', dot: 'fill-indigo-500 dark:fill-indigo-400' },
    purple: { span: 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-900/20 dark:text-purple-300 dark:ring-purple-500/30', dot: 'fill-purple-500 dark:fill-purple-400' },
    pink: { span: 'bg-pink-50 text-pink-700 ring-pink-600/20 dark:bg-pink-900/20 dark:text-pink-300 dark:ring-pink-500/30', dot: 'fill-pink-500 dark:fill-pink-400' },
    gray: { span: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-900/20 dark:text-gray-300 dark:ring-gray-500/30', dot: 'fill-gray-500 dark:fill-gray-400' },
};

const theme = computed(() => themes[props.color] ?? themes.red);
</script>
