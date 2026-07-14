<template>
    <div>
        <ul role="list" class="space-y-4">
            <li v-for="ev in mappedEvents" :key="ev.id"
                class="border-l-4 rounded-md bg-gray-50 dark:bg-slate-800/40 pl-3 pr-2 py-2"
                :style="{ borderColor: ev.accentColor }">
                <div class="flex items-start gap-2">
                    <span class="mt-1 size-2.5 flex-none rounded-full" :style="{ backgroundColor: ev.accentColor }" />
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium text-gray-800 dark:text-slate-200 truncate">{{ ev.title }}</span>
                            <span v-if="ev.status" :class="eventStatusBadgeClass(ev.status)">{{ ev.status }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-gray-600 dark:text-slate-400">
                            {{ ev.startFormatted }} – {{ ev.endFormatted }}
                        </p>
                    </div>
                </div>
                <ul v-if="ev.executingUsers.length" class="mt-2 space-y-1.5 pl-5">
                    <li v-for="user in ev.executingUsers" :key="user.id"
                        class="flex items-center gap-2 text-sm" :class="user.isClosed ? 'opacity-50' : ''">
                        <span class="text-gray-700 dark:text-slate-300 truncate">{{ user.name }}</span>
                        <span v-if="user.roles.length" class="flex flex-wrap gap-1">
                            <span v-for="role in user.roles" :key="role.id" v-tooltip="role.name"
                                class="inline-flex items-center rounded px-1 py-px text-[9px] font-semibold leading-none text-white"
                                :style="{ backgroundColor: role.color }">
                                {{ roleInitials(role.name) }}
                            </span>
                        </span>
                        <span class="ml-auto flex items-center gap-2">
                            <span v-if="user.hasActualTimes"
                                class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
                                <Clock class="size-3 shrink-0" />
                                {{ user.actualStart }} – {{ user.actualEnd }}
                            </span>
                            <EventExecutionControls v-if="user.id === currentUserId" :event="ev.raw"
                                :user-id="user.id" @changed="onChanged" />
                        </span>
                    </li>
                </ul>
                <p v-else class="mt-2 pl-5 text-xs text-gray-400 dark:text-slate-500">Geen uitvoerenden</p>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { Clock } from '@lucide/vue'
import { nlDate, nlTime, roleInitials, eventStatusBadgeClass } from '@/Utilities/Utilities'
import { useEventLeadingColor } from '@/Composables/useEventLeadingColor'
import EventExecutionControls from '@/Components/Planner/EventExecutionControls.vue'

const props = defineProps({
    serviceOrderId: { type: [Number, String], required: true },
    events: { type: Array, required: true },
    userRoles: { type: Array, default: () => [] },
    leadingColor: { type: String, default: 'event' },
})

const emit = defineEmits(['times-updated'])

const { rolesForUser, resolveLeadingColor } = useEventLeadingColor()

const currentUserId = usePage().props.auth?.user?.id ?? null

const closedStatusses = ['Afgerond', 'Geannuleerd']

const localEvents = ref(props.events)
watch(() => props.events, (value) => { localEvents.value = value })

const formatDateTime = (iso) => iso ? nlDate(iso) + ' ' + nlTime(iso) : ''

const mappedEvents = computed(() => localEvents.value
    .map(ev => {
        const executing_users = (ev.executing_users || []).map(user => ({
            id: user.id,
            name: user.name,
            roles: rolesForUser(user.user_role_ids, props.userRoles),
            isClosed: closedStatusses.includes(user.completion_status || 'Gepland'),
            hasActualTimes: !!user.actual_start && !!user.actual_end,
            actualStart: user.actual_start ? nlTime(user.actual_start) : null,
            actualEnd: user.actual_end ? nlTime(user.actual_end) : null,
        }))

        return {
            id: ev.id,
            title: `${ev.event_type?.name || 'Afspraak'}${ev.name ? ': ' + ev.name : ''}`,
            status: ev.status || null,
            startFormatted: formatDateTime(ev.start),
            endFormatted: formatDateTime(ev.end),
            accentColor: resolveLeadingColor({
                eventColor: ev.event_type?.color || null,
                roleColor: executing_users[0]?.roles?.[0]?.color || null,
                leadingColor: props.leadingColor,
            }),
            executingUsers: executing_users,
            raw: {
                id: ev.id,
                start: new Date(ev.start),
                end: new Date(ev.end),
                executing_users: ev.executing_users || [],
            },
        }
    }))

const onChanged = async () => {
    const { data } = await axios.get(`/api/serviceorders/${props.serviceOrderId}/event-widget`)
    localEvents.value = data.events
    emit('times-updated', data.users_missing_times)
}
</script>
