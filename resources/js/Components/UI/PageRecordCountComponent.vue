<template>
    <PageSizeSelect :model-value="perPage" :total="total" :label="label" @update:model-value="onChange" />
</template>

<script setup>
import { router, usePage } from '@inertiajs/vue3'
import PageSizeSelect from '@/Components/UI/PageSizeSelect.vue'

defineProps({
    total: { type: Number, required: true },
    perPage: { type: Number, default: 20 },
    label: { type: String, default: 'records' },
})

function onChange(value) {
    const page = usePage()
    const current = page.url
    const url = new URL(current, window.location.origin)
    url.searchParams.set('perPage', value)
    url.searchParams.delete('page')
    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true })
}
</script>
