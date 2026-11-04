<template>
    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-400">
        Toon
        <ComboBox v-model="perPage" :options="options" class="w-24" />
        van {{ total }} {{ label }}
    </div>
</template>

<script>
export const PAGE_SIZES = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100]
</script>

<script setup>
import ComboBox from '@/Components/UI/ComboBox.vue'

defineProps({
    total: { type: Number, required: true },
    label: { type: String, required: true },
})

const perPage = defineModel({ type: Number })

const options = PAGE_SIZES.map(n => ({ id: n, name: String(n) }))
</script>
