<template>
    <div v-if="summary.expected || summary.checks.total" class="mt-1 flex flex-wrap gap-1.5">
        <SerialProgressBadge v-if="summary.expected" :filled="summary.filled" :expected="summary.expected" clickable
            @click="$emit('open')" />
        <CheckProgressBadge v-if="summary.checks.total" :done="summary.checks.done" :total="summary.checks.total"
            @click="$emit('open')" />
    </div>
</template>

<script setup>
import SerialProgressBadge from '@/Components/ServiceOrders/SerialProgressBadge.vue'
import CheckProgressBadge from '@/Components/ServiceOrders/Tasks/CheckProgressBadge.vue'

/** How far a task is: its machines registered and its checks ticked. Either badge opens the task. */
defineProps({
    summary: { type: Object, required: true },
})

defineEmits(['open'])
</script>
