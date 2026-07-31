<template>
    <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-3 sm:items-center sm:px-6">
        <label class="text-sm font-bold text-gray-900 dark:text-slate-200">{{ label }}</label>
        <div class="sm:col-span-2">
            <slot />
        </div>
    </div>
</template>

<script setup>
/** Eén rij in een la: het label links, het veld rechts, overal even breed. */
defineProps({
    label: { type: String, required: true },
})
</script>
