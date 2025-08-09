<template>
    <div
        class="group relative inline-flex w-11 shrink-0 rounded-full bg-red-400 p-0.5 inset-ring inset-ring-gray-900/5 outline-offset-2 outline-green-600 transition-colors duration-200 ease-in-out has-checked:bg-green-600 has-focus-visible:outline-2 dark:bg-red-400 dark:inset-ring-white/10 dark:outline-green-500 dark:has-checked:bg-green-500">
        <span
            class="size-5 rounded-full bg-white shadow-xs ring-1 ring-gray-900/5 transition-transform duration-200 ease-in-out group-has-checked:translate-x-5" />
        <input type="checkbox" class="absolute inset-0 appearance-none focus:outline-hidden" v-model="value" />
    </div>
</template>

<script setup>
const value = defineModel({ type: Boolean, default: false })
</script>