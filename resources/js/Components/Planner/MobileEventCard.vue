<template>
    <div class="rounded-xl overflow-hidden border" :class="canEdit ? 'cursor-pointer' : ''" :style="{
        backgroundColor: `color-mix(in srgb, ${cardColor} 6%, white)`,
        borderColor: `color-mix(in srgb, ${cardColor} 9%, #e5e7eb)`,
        ...(isClosed ? { backgroundImage: COMPLETED_PATTERN } : {}),
    }" @click="$emit('tap', event)">
        <div class="p-3">
            <!-- Title + avatars at top right -->
            <div class="flex items-start gap-2">
                <div class="font-semibold text-sm leading-tight flex-1 min-w-0">{{ event.name || event.title }}</div>
                <div class="flex items-center flex-shrink-0">
                    <span v-if="selectedUserId === null && canSeeAll"
                        class="text-xs text-gray-500 dark:text-slate-400 truncate max-w-[7rem]">
                        {{executingUsers.map(u => u.name).join(', ')}}
                    </span>
                    <div v-else class="flex items-center">
                        <template v-for="(u, i) in executingUsers.slice(0, MAX_AVATARS)" :key="u.id">
                            <div class="size-6 rounded-full ring-2 ring-white dark:ring-slate-800 bg-gray-300 dark:bg-slate-600 flex items-center justify-center text-[10px] font-semibold overflow-hidden"
                                :style="{ marginLeft: i > 0 ? '-0.375rem' : '0' }">
                                <img v-if="u.avatar" :src="u.avatar" class="w-full h-full object-cover" :alt="u.name" />
                                <span v-else>{{ initials(u.name) }}</span>
                            </div>
                        </template>
                        <div v-if="executingUsers.length > MAX_AVATARS"
                            class="size-6 rounded-full ring-2 ring-white dark:ring-slate-800 bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold -ml-1.5">
                            +{{ executingUsers.length - MAX_AVATARS }}
                        </div>
                    </div>
                </div>
                <EventExecutionControls :event="event" :user-id="relevantUserId" @changed="$emit('changed')" />
                <button v-if="hasPermission('event.provide_feedback') && !event.eventable_id"
                    @click.stop="$emit('feedback', event)" class="p-1 text-gray-400 hover:text-lavoro-blue relative"
                    title="Terugkoppeling">
                    <MessageCircleReply class="size-4" />
                    <span v-if="feedbackCount > 0"
                        class="absolute -top-0.5 -right-0.5 min-w-[14px] h-[14px] px-0.5 rounded-full bg-lavoro-blue text-white text-[9px] leading-[14px] text-center font-semibold">
                        {{ feedbackCount }}
                    </span>
                </button>
            </div>

            <!-- Current user's roles -->
            <div v-if="currentUserRoles.length" class="mt-1 flex flex-wrap gap-1">
                <span v-for="role in currentUserRoles" :key="role.id"
                    class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full text-white"
                    :style="{ backgroundColor: role.color }">
                    {{ role.name }}
                </span>
            </div>

            <div v-if="event.customer_name" class="text-xs text-gray-600 dark:text-slate-400 mt-1 leading-snug">
                {{ event.customer_name }}
            </div>

            <div v-if="event.location" class="text-xs text-gray-500 dark:text-slate-400 leading-snug">
                {{ event.location }}
            </div>

            <div v-if="event.description"
                class="text-xs text-gray-500 dark:text-slate-400 mt-1 leading-snug whitespace-pre-line">
                {{ event.description }}
            </div>

            <!-- Coworkers + roles -->
            <div v-if="coworkers.length > 0" class="mt-2 flex flex-col gap-1">
                <div v-for="u in coworkers" :key="u.id" class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-xs text-gray-600 dark:text-slate-300 font-medium">{{ u.name }}</span>
                    <span v-for="r in u.roles" :key="r.id" class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full"
                        :style="{
                            backgroundColor: `color-mix(in srgb, ${r.color} 18%, white)`,
                            color: `color-mix(in srgb, ${r.color} 70%, black)`,
                        }">
                        {{ r.name }}
                    </span>
                </div>
            </div>

            <!-- WB badge -->
            <div class="mt-2 flex items-center gap-2 flex-wrap">
                <button v-if="event.eventable_id"
                    class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1 shadow-sm hover:border-gray-300 transition"
                    @click.stop="router.visit(`/serviceorders/${event.eventable_id}`)">
                    <BuildingOfficeIcon class="size-3.5 shrink-0" />
                    <span>{{ formatWbNumber(event.eventable_id) }}</span>
                    <TriangleAlert v-if="event.is_incomplete" class="size-3.5 shrink-0 text-amber-500"
                        v-tooltip="'Werkbon gedeeltelijk afgerond'" />
                    <CircleCheck v-else-if="event.is_closed" class="size-3.5 shrink-0 text-green-600"
                        v-tooltip="'Werkbon afgerond'" />
                    <ArrowTopRightOnSquareIcon class="size-3 shrink-0" />
                </button>
                <span v-else class="text-xs text-gray-400 dark:text-slate-500 italic">
                    Eigen planning
                </span>
                <span v-if="travelTime > 0"
                    class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1 shadow-sm">
                    <Car class="size-3.5 shrink-0" />
                    <span>{{ travelTime }} min reistijd</span>
                </span>
            </div>

            <!-- Task instances -->
            <div v-if="event.task_instances?.length" class="mt-2 space-y-2">
                <div v-for="ti in event.task_instances" :key="ti.id">
                    <div class="flex items-start gap-1.5 text-xs">
                        <span class="size-1 rounded-full bg-gray-400 dark:bg-slate-500 shrink-0 mt-1.5"></span>
                        <div class="flex-1 min-w-0">
                            <span class="text-gray-600 dark:text-slate-300 font-medium">{{ ti.title }}</span>
                            <template v-if="ti.product">
                                <div class="text-gray-500 dark:text-slate-400 mt-0.5">
                                    {{ ti.product.name }}
                                    <span class="text-gray-400 dark:text-slate-500">× {{ ti.quantity }}</span>
                                </div>
                                <div v-if="ti.product.specific_attributes?.length"
                                    class="text-gray-400 dark:text-slate-500 mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5">
                                    <span v-for="attr in ti.product.specific_attributes" :key="attr.name">
                                        {{ attr.name }}: {{ attr.value }}
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { BuildingOfficeIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'
import { TriangleAlert, CircleCheck, MessageCircleReply, Car } from '@lucide/vue'
import { hasPermission, initials, formatWbNumber } from '@/Utilities/Utilities'
import { isClosedForUser } from '@/Utilities/plannerOverlaps'
import EventExecutionControls from '@/Components/Planner/EventExecutionControls.vue'

const props = defineProps({
    event: { type: Object, required: true },
    selectedUserId: { type: Number, default: null },
    relevantUserId: { type: Number, default: null },
    canSeeAll: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    plannableUsers: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
})

defineEmits(['tap', 'feedback', 'changed'])

const COMPLETED_PATTERN = 'repeating-linear-gradient(-45deg, transparent, transparent 6px, rgba(107,114,128,0.07) 6px, rgba(107,114,128,0.07) 12px)'
const MAX_AVATARS = 3

const roleById = computed(() => Object.fromEntries(props.userRoles.map(r => [r.id, r])))

const isClosed = computed(() => isClosedForUser(props.event, props.relevantUserId))

const cardColor = computed(() => (isClosed.value ? '#6b7280' : (props.event.color || '#3b82f6')))

const feedbackCount = computed(() => (props.event.remarks_count || 0) + (props.event.images_count || 0))

const executingUsers = computed(() =>
    props.event.executing_users.map(u => ({
        id: u.id,
        name: u.name,
        avatar: props.plannableUsers.find(p => p.id === u.id)?.avatar ?? null,
        roles: (u.user_role_ids ?? []).map(id => roleById.value[id]).filter(Boolean),
    }))
)

const coworkers = computed(() => {
    if (props.selectedUserId === null) return executingUsers.value.filter(u => u.roles.length)
    return executingUsers.value.filter(u => u.id !== props.selectedUserId)
})

const currentUserRoles = computed(() => {
    if (props.selectedUserId === null) return []
    return executingUsers.value.find(u => u.id === props.selectedUserId)?.roles ?? []
})

const travelTime = computed(() =>
    props.event.executing_users.find(u => u.id === props.relevantUserId)?.travel_time_minutes ?? 0
)
</script>
