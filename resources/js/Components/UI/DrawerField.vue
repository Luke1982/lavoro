<template>
    <div :class="['grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-3 sm:px-6', tall ? 'sm:items-start' : 'sm:items-center']">
        <label :class="['text-sm font-bold text-gray-900 dark:text-slate-200', tall && 'sm:pt-2']">
            <slot name="label">{{ label }}</slot>
        </label>
        <div class="sm:col-span-2">
            <slot />
        </div>
    </div>
</template>

<script setup>
/**
 * Eén rij in een la: het label links, het veld rechts, overal even breed. Een
 * veld dat hoger is dan één regel (`tall`) houdt zijn label bovenaan in plaats
 * van halverwege.
 */
defineProps({
    label: { type: String, default: '' },
    tall: { type: Boolean, default: false },
})
</script>
