<template>
    <div :class="[
        'bg-white dark:bg-slate-900 text-gray-800 dark:text-slate-100 lg:col-span-2 lg:row-span-2 lg:row-end-2 border-lavoro-box shadow-lavoro-box rounded-lavoro-sm',
        fill ? 'h-full' : '',
        extraClasses
    ]">
        <div :class="[padding, fill ? 'h-full' : '']">
            <slot />
        </div>
    </div>
</template>

<script setup>
defineProps({
    padding: {
        type: String,
        default: 'p-6',
    },
    extraClasses: {
        type: String,
        default: ''
    },
    /** Opt-in: stretch the box and its content to the parent's full height (for viewport-bounded, internally scrolling content like the planner). */
    fill: {
        type: Boolean,
        default: false,
    }
});
</script>