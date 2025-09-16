<template>
    <div :class="[
        padding,
        'bg-white dark:bg-slate-900 shadow-xs dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800 text-gray-800 dark:text-slate-100 sm:rounded-lg lg:col-span-2 lg:row-span-2 lg:row-end-2',
        extraClasses
    ]">
        <slot />
    </div>
</template>

<script setup>
defineProps({
    padding: {
        type: String,
        default: 'px-4 py-8 xl:px-8 xl:pt-8 xl:pb-8 sm:px-4 sm:pb-4 px-4 py-4',
    },
    extraClasses: {
        type: String,
        default: ''
    }
});
</script>