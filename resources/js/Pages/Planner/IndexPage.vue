<template>
    <!-- Mobile (below md) -->
    <div class="md:hidden h-screen overflow-hidden">
        <MobilePlannerView
            :event-types="eventTypes"
            :all-customers="allCustomers"
            :customers-use-ajax="customersUseAjax"
            :all-service-orders="allServiceOrders"
            :event-statusses="eventStatusses"
            :all-users="allUsers"
            :plannable-users="plannableUsers" />
    </div>

    <!-- Desktop (md and up) -->
    <div class="hidden md:grid grid-cols-12 gap-x-3 p-3">
        <div :class="canPlan ? 'col-span-10' : 'col-span-12'">
            <BoxComponent padding="p-0">
                <ResourcePlannerWidget
                    :event-types="eventTypes"
                    :all-customers="allCustomers"
                    :customers-use-ajax="customersUseAjax"
                    :all-service-orders="allServiceOrders"
                    :event-statusses="eventStatusses"
                    :all-users="allUsers"
                    :plannable-users="plannableUsers"
                    :projects="projects"
                    :default-planner-minutes="props.defaultPlannerMinutes"
                    @service-order-planned="onServiceOrderPlanned"
                    @service-order-unplanned="onServiceOrderUnplanned" />
            </BoxComponent>
        </div>
        <div v-if="canPlan" class="col-span-2">
            <BoxComponent padding="p-0">
                <UnplannedServiceOrdersWidget :service-orders="unplanned" />
            </BoxComponent>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import ResourcePlannerWidget from '@/Components/Planner/ResourcePlannerWidget.vue'
import UnplannedServiceOrdersWidget from '@/Components/Planner/UnplannedServiceOrdersWidget.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import MobilePlannerView from '@/Components/Planner/MobilePlannerView.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    eventTypes: { type: Array, required: true },
    allCustomers: { type: Array, required: true },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, required: true },
    eventStatusses: { type: Array, required: true },
    allUsers: { type: Array, required: true },
    plannableUsers: { type: Array, required: true },
    unplannedServiceOrders: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    defaultPlannerMinutes: { type: Number, default: 120 },
})

const canPlan = computed(() => hasPermission('serviceorder.plan'))

// Track which (initially-unplanned) service orders are currently planned, so we
// can hide them while planned and show them again the moment their event is removed.
const plannedIds = ref(new Set())

const unplanned = computed(() =>
    props.unplannedServiceOrders.filter(so => !plannedIds.value.has(so.id))
)

const projects = computed(() =>
    props.projects.map(p => ({
        ...p,
        service_orders: (p.service_orders || []).filter(so => !plannedIds.value.has(so.id)),
    }))
)

function onServiceOrderPlanned(serviceOrderId) {
    plannedIds.value = new Set(plannedIds.value).add(serviceOrderId)
}

function onServiceOrderUnplanned(serviceOrderId) {
    const next = new Set(plannedIds.value)
    next.delete(serviceOrderId)
    plannedIds.value = next
}
</script>
