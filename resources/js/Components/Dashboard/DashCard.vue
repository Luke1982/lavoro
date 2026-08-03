<template>
    <div
        :class="['rounded-xl bg-white dark:bg-slate-900 border border-gray-200/80 dark:border-slate-800 shadow-lavoro-box dark:shadow-none', fill ? 'flex flex-col h-full' : '']">
        <slot />
    </div>
</template>

<script setup>
defineProps({
    /** Stretch to the row's height, for cards that sit beside a taller neighbour. */
    fill: { type: Boolean, default: false },
});
</script>
