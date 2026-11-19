<template>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm text-gray-700 dark:text-slate-300">{{ label }}</p>
            <p v-if="hint" class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ hint }}</p>
            <p v-if="error" class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ error }}</p>
        </div>
        <SwitchComponent v-model="value" :title="label" />
    </div>
</template>

<script setup>
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
})

const value = defineModel({ type: Boolean, default: false })
</script>
