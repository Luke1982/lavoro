<template>
    <div
        :class="[padding, 'bg-white shadow-xs ring-1 ring-gray-900/5 sm:rounded-lg lg:col-span-2 lg:row-span-2 lg:row-end-2']">
        <slot></slot>
    </div>
</template>

<script setup>
defineProps({
    padding: {
        type: String,
        default: 'px-4 py-8 xl:px-8 xl:pt-8 xl:pb-8 sm:px-4 sm:pb-4 px-4 py-4',
    },
});
</script>