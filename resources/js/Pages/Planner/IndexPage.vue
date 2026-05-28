<template>
    <div class="h-[calc(100vh-0px)] flex flex-col">
        <ResourcePlannerWidget
            :event-types="eventTypes"
            :all-customers="allCustomers"
            :all-service-orders="allServiceOrders"
            :event-statusses="eventStatusses"
            :all-users="allUsers"
            :plannable-users="plannableUsers"
        />
    </div>
</template>

<script setup>
import ResourcePlannerWidget from '@/Components/ResourcePlannerWidget.vue'

defineProps({
    eventTypes: { type: Array, required: true },
    allCustomers: { type: Array, required: true },
    allServiceOrders: { type: Array, required: true },
    eventStatusses: { type: Array, required: true },
    allUsers: { type: Array, required: true },
    plannableUsers: { type: Array, required: true },
})
</script>
