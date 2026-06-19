<template>
    <!-- Mobile (below md) -->
    <div class="md:hidden h-screen overflow-hidden">
        <MobilePlannerView :event-types="eventTypes" :all-customers="allCustomers"
            :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
            :event-statusses="eventStatusses" :all-users="allUsers" :plannable-users="plannableUsersRef"
            :user-roles="userRoles" :latest-pings="props.latestPings" />
    </div>

    <!-- Desktop (md and up) -->
    <!-- Viewport-bounded single row so the planner fills the screen and scrolls internally (one native scroll container). -->
    <div class="hidden md:grid grid-cols-12 grid-rows-[minmax(0,1fr)] gap-x-3 p-3 h-screen overflow-hidden">
        <div :class="[showSidebar ? 'col-span-10' : 'col-span-12', 'min-h-0']">
            <BoxComponent padding="p-0" fill>
                <ResourcePlannerWidget :event-types="eventTypes" :all-customers="allCustomers"
                    :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
                    :event-statusses="eventStatusses" :all-users="allUsers" :plannable-users="plannableUsersRef"
                    :user-roles="userRoles" :projects="projects" :groups="planGroupsRef"
                    :default-planner-minutes="props.defaultPlannerMinutes"
                    :latest-pings="props.latestPings" @service-order-planned="onServiceOrderPlanned"
                    @service-order-unplanned="onServiceOrderUnplanned" />
            </BoxComponent>
        </div>
        <div v-if="showSidebar" class="col-span-2 flex flex-col gap-3 min-h-0 overflow-y-auto">
            <BoxComponent v-if="canPlan" padding="p-0">
                <UnplannedServiceOrdersWidget :service-orders="unplanned" />
            </BoxComponent>
            <PlanGroupsWidget v-if="canManageGroups" :plan-groups="planGroupsRef" :all-users="allPlanUsersRef"
                @group-created="onGroupCreated" @group-updated="onGroupUpdated" @group-deleted="onGroupDeleted"
                @group-reordered="onGroupReordered" @user-groups-synced="onUserGroupsSynced"
                @plannable-toggled="onPlannableToggled" />
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import ResourcePlannerWidget from '@/Components/Planner/ResourcePlannerWidget.vue'
import UnplannedServiceOrdersWidget from '@/Components/Planner/UnplannedServiceOrdersWidget.vue'
import PlanGroupsWidget from '@/Components/Planner/PlanGroupsWidget.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import MobilePlannerView from '@/Components/Planner/MobilePlannerView.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    eventTypes: { type: Array, required: true },
    allCustomers: { type: Array, required: true },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, required: true },
    allUsers: { type: Array, required: true },
    plannableUsers: { type: Array, required: true },
    userRoles: { type: Array, default: () => [] },
    unplannedServiceOrders: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    defaultPlannerMinutes: { type: Number, default: 120 },
    planGroups: { type: Array, default: () => [] },
    allPlanUsers: { type: Array, default: () => [] },
    latestPings: { type: Object, default: () => ({}) },
})

const page = usePage()

const planGroupsRef = ref(props.planGroups)
const plannableUsersRef = ref(props.plannableUsers)
const allPlanUsersRef = ref(props.allPlanUsers)

const canPlan = computed(() => hasPermission('serviceorder.plan'))
const canManageGroups = computed(() => hasPermission('event.see_all') || hasPermission('event.create_others'))
const showSidebar = computed(() => canPlan.value || canManageGroups.value)

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

// Optimistic hide/show for instant feedback, then reconcile with the backend
// (projects expose only service orders that have no events) so a planned order
// disappears and an unplanned one reappears without a manual page refresh.
function reconcileServiceOrders() {
    router.reload({ only: ['projects', 'unplannedServiceOrders'] })
}
function onServiceOrderPlanned(id) {
    plannedIds.value = new Set(plannedIds.value).add(id)
    reconcileServiceOrders()
}
function onServiceOrderUnplanned(id) {
    const s = new Set(plannedIds.value); s.delete(id); plannedIds.value = s
    reconcileServiceOrders()
}

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
    planGroupsRef.value = planGroupsRef.value.filter(g => g.id !== id)
    allPlanUsersRef.value = allPlanUsersRef.value.map(u => ({
        ...u,
        plan_group_ids: u.plan_group_ids.filter(gid => gid !== id),
    }))
    plannableUsersRef.value = plannableUsersRef.value.map(u => ({
        ...u,
        plan_group_ids: u.plan_group_ids.filter(gid => gid !== id),
    }))
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

async function onUserGroupsSynced(userId, groupIds) {
    const prevAllPlan = allPlanUsersRef.value.map(u => ({ ...u }))
    const prevPlannable = plannableUsersRef.value.map(u => ({ ...u }))

    allPlanUsersRef.value = allPlanUsersRef.value.map(u =>
        u.id === userId ? { ...u, plan_group_ids: groupIds } : u
    )
    plannableUsersRef.value = plannableUsersRef.value.map(u =>
        u.id === userId ? { ...u, plan_group_ids: groupIds } : u
    )

    // Update plan group user_ids lists
    planGroupsRef.value = planGroupsRef.value.map(g => {
        const inGroup = groupIds.includes(g.id)
        const hadUser = g.user_ids.includes(userId)
        if (inGroup && !hadUser) return { ...g, user_ids: [...g.user_ids, userId] }
        if (!inGroup && hadUser) return { ...g, user_ids: g.user_ids.filter(id => id !== userId) }
        return g
    })

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put(`/api/users/${userId}/plan-groups`, { group_ids: groupIds })
    } catch (e) {
        allPlanUsersRef.value = prevAllPlan
        plannableUsersRef.value = prevPlannable
        page.props.flash.error = e.response?.data?.message || 'Kon groepsindeling niet opslaan'
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
