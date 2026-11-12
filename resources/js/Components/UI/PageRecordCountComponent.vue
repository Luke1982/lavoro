<template>
    <PageSizeSelect :model-value="perPage" :total="total" :label="label" @update:model-value="size => visit({ perPage: size })" />
</template>

<script setup>
import PageSizeSelect from '@/Components/UI/PageSizeSelect.vue'
import { useQueryParams } from '@/Composables/useQueryParams'

defineProps({
    total: { type: Number, required: true },
    perPage: { type: Number, default: 20 },
    label: { type: String, default: 'records' },
})

const { visit } = useQueryParams()
</script>
