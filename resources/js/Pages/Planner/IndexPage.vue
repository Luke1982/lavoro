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
            :plannable-users="plannableUsersRef" />
    </div>

    <!-- Desktop (md and up) -->
    <div class="hidden md:grid grid-cols-12 gap-x-3 p-3">
        <div :class="showSidebar ? 'col-span-10' : 'col-span-12'">
            <BoxComponent padding="p-0">
                <ResourcePlannerWidget
                    :event-types="eventTypes"
                    :all-customers="allCustomers"
                    :customers-use-ajax="customersUseAjax"
                    :all-service-orders="allServiceOrders"
                    :event-statusses="eventStatusses"
                    :all-users="allUsers"
                    :plannable-users="plannableUsersRef"
                    :projects="projects"
                    :groups="planGroupsRef"
                    :default-planner-minutes="props.defaultPlannerMinutes"
                    @service-order-planned="onServiceOrderPlanned"
                    @service-order-unplanned="onServiceOrderUnplanned" />
            </BoxComponent>
        </div>
        <div v-if="showSidebar" class="col-span-2 flex flex-col gap-3">
            <BoxComponent v-if="canPlan" padding="p-0">
                <UnplannedServiceOrdersWidget :service-orders="unplanned" />
            </BoxComponent>
            <PlanGroupsWidget
                v-if="canManageGroups"
                :plan-groups="planGroupsRef"
                :all-users="allPlanUsersRef"
                @group-created="onGroupCreated"
                @group-updated="onGroupUpdated"
                @group-deleted="onGroupDeleted"
                @group-reordered="onGroupReordered"
                @user-assigned="onUserAssigned"
                @user-unassigned="onUserUnassigned"
                @plannable-toggled="onPlannableToggled" />
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import ResourcePlannerWidget from '@/Components/Planner/ResourcePlannerWidget.vue'
import UnplannedServiceOrdersWidget from '@/Components/Planner/UnplannedServiceOrdersWidget.vue'
import PlanGroupsWidget from '@/Components/Planner/PlanGroupsWidget.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import MobilePlannerView from '@/Components/Planner/MobilePlannerView.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    eventTypes:             { type: Array, required: true },
    allCustomers:           { type: Array, required: true },
    customersUseAjax:       { type: Boolean, default: false },
    allServiceOrders:       { type: Array, required: true },
    eventStatusses:         { type: Array, required: true },
    allUsers:               { type: Array, required: true },
    plannableUsers:         { type: Array, required: true },
    unplannedServiceOrders: { type: Array, default: () => [] },
    projects:               { type: Array, default: () => [] },
    defaultPlannerMinutes:  { type: Number, default: 120 },
    planGroups:             { type: Array, default: () => [] },
    allPlanUsers:           { type: Array, default: () => [] },
})

const page = usePage()

const planGroupsRef     = ref(props.planGroups)
const plannableUsersRef = ref(props.plannableUsers)
const allPlanUsersRef   = ref(props.allPlanUsers)

const canPlan         = computed(() => hasPermission('serviceorder.plan'))
const canManageGroups = computed(() => hasPermission('event.see_all') || hasPermission('event.create_others'))
const showSidebar     = computed(() => canPlan.value || canManageGroups.value)

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

function onServiceOrderPlanned(id)   { plannedIds.value = new Set(plannedIds.value).add(id) }
function onServiceOrderUnplanned(id) { const s = new Set(plannedIds.value); s.delete(id); plannedIds.value = s }

async function onGroupCreated(data) {
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post('/api/plan-groups', data)
        planGroupsRef.value = [...planGroupsRef.value, { ...r.data, user_ids: [] }]
    } catch (e) {
        page.props.flash.error = e.response?.data?.message || 'Kon groep niet aanmaken'
    }
}

async function onGroupUpdated(id, patch) {
    const idx = planGroupsRef.value.findIndex(g => g.id === id)
    if (idx === -1) return
    const original = { ...planGroupsRef.value[idx] }
    planGroupsRef.value[idx] = { ...planGroupsRef.value[idx], ...patch }
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put(`/api/plan-groups/${id}`, patch)
    } catch (e) {
        planGroupsRef.value[idx] = original
        page.props.flash.error = e.response?.data?.message || 'Kon groep niet bijwerken'
    }
}

async function onGroupDeleted(id) {
    const originalGroups = [...planGroupsRef.value]
    planGroupsRef.value   = planGroupsRef.value.filter(g => g.id !== id)
    allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.plan_group_id === id ? { ...u, plan_group_id: null } : u)
    plannableUsersRef.value = plannableUsersRef.value.map(u => u.plan_group_id === id ? { ...u, plan_group_id: null } : u)
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.delete(`/api/plan-groups/${id}`)
    } catch (e) {
        planGroupsRef.value = originalGroups
        page.props.flash.error = e.response?.data?.message || 'Kon groep niet verwijderen'
    }
}

async function onGroupReordered(ids) {
    const original = [...planGroupsRef.value]
    const map = Object.fromEntries(planGroupsRef.value.map(g => [g.id, g]))
    planGroupsRef.value = ids.map(id => map[id]).filter(Boolean)
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put('/api/plan-groups/reorder', { ids })
    } catch (e) {
        planGroupsRef.value = original
        page.props.flash.error = e.response?.data?.message || 'Kon volgorde niet opslaan'
    }
}

async function onUserAssigned(groupId, userId) {
    const oldGroup    = planGroupsRef.value.find(g => g.user_ids.includes(userId))
    const targetGroup = planGroupsRef.value.find(g => g.id === groupId)
    if (!targetGroup) return

    if (oldGroup) oldGroup.user_ids = oldGroup.user_ids.filter(id => id !== userId)
    targetGroup.user_ids = [...targetGroup.user_ids, userId]
    allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: groupId } : u)
    plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: groupId } : u)

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put(`/api/plan-groups/${groupId}/users/${userId}`)
    } catch (e) {
        if (oldGroup) oldGroup.user_ids = [...oldGroup.user_ids, userId]
        targetGroup.user_ids = targetGroup.user_ids.filter(id => id !== userId)
        const prev = oldGroup?.id ?? null
        allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: prev } : u)
        plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: prev } : u)
        page.props.flash.error = e.response?.data?.message || 'Kon monteur niet toewijzen'
    }
}

async function onUserUnassigned(userId) {
    const oldGroup = planGroupsRef.value.find(g => g.user_ids.includes(userId))
    if (!oldGroup) return

    oldGroup.user_ids = oldGroup.user_ids.filter(id => id !== userId)
    allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: null } : u)
    plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: null } : u)

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.delete(`/api/plan-groups/${oldGroup.id}/users/${userId}`)
    } catch (e) {
        oldGroup.user_ids = [...oldGroup.user_ids, userId]
        allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: oldGroup.id } : u)
        plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: oldGroup.id } : u)
        page.props.flash.error = e.response?.data?.message || 'Kon monteur niet uit groep halen'
    }
}

async function onPlannableToggled(userId, value) {
    allPlanUsersRef.value = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plannable: value } : u)
    if (value) {
        const user = allPlanUsersRef.value.find(u => u.id === userId)
        if (user && !plannableUsersRef.value.find(u => u.id === userId)) {
            plannableUsersRef.value = [...plannableUsersRef.value, { ...user }]
        }
    } else {
        plannableUsersRef.value = plannableUsersRef.value.filter(u => u.id !== userId)
    }

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.patch(`/api/users/${userId}/plannable`, { plannable: value })
    } catch (e) {
        allPlanUsersRef.value = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plannable: !value } : u)
        if (!value) {
            const user = allPlanUsersRef.value.find(u => u.id === userId)
            if (user && !plannableUsersRef.value.find(u => u.id === userId)) {
                plannableUsersRef.value = [...plannableUsersRef.value, { ...user }]
            }
        } else {
            plannableUsersRef.value = plannableUsersRef.value.filter(u => u.id !== userId)
        }
        page.props.flash.error = e.response?.data?.message || 'Kon inplanbaar-status niet bijwerken'
    }
}
</script>
