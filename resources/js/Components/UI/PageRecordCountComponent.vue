<template>
    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-400">
        Toon
        <ComboBox :options="options" :model-value="perPage" @update:modelValue="onChange" class="w-24" />
        van {{ total }} {{ label }}
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import ComboBox from '@/Components/UI/ComboBox.vue'

const props = defineProps({
    total: { type: Number, required: true },
    perPage: { type: Number, default: 20 },
    label: { type: String, default: 'records' },
})

const options = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100].map(n => ({ id: n, name: String(n) }))

function onChange(value) {
    const page = usePage()
    const current = page.url
    const url = new URL(current, window.location.origin)
    url.searchParams.set('perPage', value)
    url.searchParams.delete('page')
    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true })
}
</script>
