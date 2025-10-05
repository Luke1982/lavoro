<template>
    <div class="p-3">
        <CalendarWidget :eventTypes="eventTypes" :allCustomers="allCustomers" :allServiceOrders="allServiceOrders"
            :eventStatusses="eventStatusses" :allUsers="allUsers" :height="'96vh'" :readOnly="false" />
    </div>
</template>

<script setup>
import { defineProps } from 'vue'
import CalendarWidget from '@/Components/CalendarWidget.vue'

const { eventTypes, allCustomers, allServiceOrders, eventStatusses, allUsers } = defineProps({
    eventTypes: {
        type: Array,
        required: true
    },
    allCustomers: {
        type: Array,
        required: true
    },
    allServiceOrders: {
        type: Array,
        required: true
    },
    eventStatusses: {
        type: Array,
        required: true
    },
    allUsers: {
        type: Array,
        required: true
    }
})
</script>
<style scoped>
/* Page-level overrides can live here if needed, but CalendarWidget provides its own styles */
</style>