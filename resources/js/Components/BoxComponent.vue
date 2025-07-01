<template>
    <div
        :class="[padding, 'bg-white -mx-4 shadow-xs ring-1 ring-gray-900/5 sm:mx-0 sm:rounded-lg lg:col-span-2 lg:row-span-2 lg:row-end-2']">
        <slot></slot>
    </div>
</template>

<script setup>
defineProps({
    padding: {
        type: String,
        default: 'px-4 py-8 xl:px-8 xl:pt-8 xl:pb-8 sm:px-8 sm:pb-14 px-4 py-8',
    },
});
</script>