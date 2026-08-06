<template>
    <div :class="serviceOrderGridColumns" @contextmenu="onContextMenu"
        class="group relative flex flex-col gap-3 rounded-lg border border-gray-100 bg-white p-3 shadow-xs transition hover:border-gray-200 dark:border-slate-700/60 dark:bg-slate-800/40 dark:hover:border-slate-600 @2xl:grid @2xl:items-start @2xl:px-4">
        <span :class="['absolute inset-y-0 left-0 w-1 rounded-l-lg', accentClass]" />
        <Link :href="`/serviceorders/${serviceorder.id}`" :aria-label="`Werkbon ${serviceorder.id} bekijken`"
            class="absolute inset-0 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-500" />
        <div class="flex items-start justify-between gap-2 @2xl:block">
            <div>
                <span class="text-sm font-bold text-gray-900 dark:text-slate-100">#{{ serviceorder.id }}</span>
                <p class="text-xs text-gray-500 dark:text-slate-400">
                    Aangemaakt: <span class="whitespace-nowrap">{{ nlDate(serviceorder.created_at) }}</span>
                </p>
            </div>
            <BadgeComponent :color="stageColor" :has-dot="false" class="@2xl:hidden">
                {{ stage?.name ?? 'Geen fase' }}
            </BadgeComponent>
        </div>
        <div class="flex min-w-0 items-center gap-2.5">
            <span
                class="flex size-8 flex-none items-center justify-center rounded-lg bg-lavoro-lightblue text-lavoro-blue dark:bg-blue-900/20 dark:text-blue-300">
                <BuildingOffice2Icon class="size-4" />
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {{ serviceorder.customer?.name ?? '—' }}
                </p>
                <p v-if="locationText" class="flex items-center gap-1 text-xs text-gray-500 dark:text-slate-400">
                    <MapPinIcon class="size-3 flex-none" />
                    <span class="truncate">{{ locationText }}</span>
                </p>
                <p v-else-if="serviceorder.customer?.city" class="truncate text-xs text-gray-500 dark:text-slate-400">
                    {{ serviceorder.customer.city }}
                </p>
            </div>
        </div>
        <div class="flex min-w-0 items-start gap-2.5 @2xl:hidden @4xl:flex" @mouseleave="showAllEvents = false">
            <CalendarIcon class="mt-0.5 size-4.5 flex-none text-gray-400 dark:text-slate-500" />
            <div v-if="plannedEvents.length" class="min-w-0 space-y-1.5" v-auto-animate>
                <div v-for="event in visibleEvents" :key="event.id">
                    <p class="text-sm font-medium whitespace-nowrap text-gray-900 dark:text-slate-100">
                        {{ nlDate(event.start) }}
                    </p>
                    <p class="text-xs whitespace-nowrap text-gray-500 dark:text-slate-400">
                        {{ nlTime(event.start) }} – {{ nlTime(event.end) }}
                    </p>
                </div>
                <span v-if="hiddenEventCount > 0" key="more"
                    class="relative z-10 flex size-6 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-2 ring-white dark:bg-slate-700 dark:text-slate-300 dark:ring-slate-800"
                    @mouseenter="showAllEvents = true" @click="showAllEvents = true">
                    +{{ hiddenEventCount }}
                </span>
            </div>
            <span v-else class="text-xs text-gray-400 dark:text-slate-500">Niet ingepland</span>
        </div>
        <div class="min-w-0 @2xl:pr-2" :class="statusSubtext ? '' : 'hidden @2xl:block'">
            <div class="hidden @2xl:block">
                <BadgeComponent :color="stageColor" :has-dot="false">
                    {{ stage?.name ?? 'Geen fase' }}
                </BadgeComponent>
            </div>
            <p v-if="statusSubtext" class="truncate text-xs text-gray-500 dark:text-slate-400 @2xl:mt-1">
                {{ statusSubtext }}
            </p>
        </div>
        <div
            class="flex min-w-0 items-center gap-2 @2xl:hidden @4xl:flex @4xl:flex-col @4xl:items-start @4xl:gap-1.5">
            <template v-if="monteurs.length">
                <div class="flex -space-x-1.5">
                    <span v-for="user in monteurs.slice(0, maxAvatars)" :key="user.id"
                        class="flex size-6 items-center justify-center overflow-hidden rounded-full bg-lavoro-lightblue text-[10px] font-semibold text-lavoro-blue ring-2 ring-white dark:bg-blue-900/40 dark:text-blue-300 dark:ring-slate-800">
                        <img v-if="user.avatar" :src="user.avatar" :alt="user.name"
                            class="h-full w-full object-cover" />
                        <span v-else>{{ initials(user.name) }}</span>
                    </span>
                    <span v-if="monteurs.length > maxAvatars" v-tooltip="extraMonteurNames"
                        class="relative z-10 flex size-6 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-2 ring-white dark:bg-slate-700 dark:text-slate-300 dark:ring-slate-800">
                        +{{ monteurs.length - maxAvatars }}
                    </span>
                </div>
                <p class="min-w-0 max-w-full text-xs text-gray-500 dark:text-slate-400">{{ monteurNames }}</p>
            </template>
            <span v-else class="text-xs text-gray-400 dark:text-slate-500">—</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500 dark:text-slate-400 @2xl:hidden">Taken:</span>
            <CheckCircleIcon v-if="allTasksDone" class="size-5 flex-none text-green-500" />
            <svg v-else viewBox="0 0 36 36" class="size-5 flex-none -rotate-90">
                <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3"
                    class="stroke-gray-200 dark:stroke-slate-600" />
                <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3" stroke-linecap="round"
                    class="stroke-amber-500"
                    :stroke-dasharray="`${(taskCounts.done / taskCounts.total) * 97.4} 97.4`" />
            </svg>
            <span class="text-sm font-medium"
                :class="allTasksDone ? 'text-green-600 dark:text-green-400' : 'text-gray-700 dark:text-slate-300'">
                {{ taskCounts.done }} van {{ taskCounts.total }}
            </span>
        </div>
        <div class="items-center justify-end gap-1" :class="canDelete || showPlanButton ? 'flex' : 'hidden @2xl:flex'">
            <PlanInPlannerButton :serviceorder="serviceorder" />
            <TrashIcon v-if="canDelete"
                class="relative z-10 size-4.5 shrink-0 cursor-pointer text-red-500 opacity-70 transition hover:opacity-100 dark:text-red-400"
                @click.stop="deleteServiceOrder" />
            <ChevronRightIcon class="hidden size-5 shrink-0 text-gray-300 dark:text-slate-600 @2xl:block" />
        </div>
    </div>
</template>

<script>
export const serviceOrderGridColumns =
    '@2xl:grid-cols-[7.5rem_1.6fr_1.4fr_5.5rem_4.5rem] @4xl:grid-cols-[7.5rem_1.5fr_1fr_1.2fr_1fr_5.5rem_4.5rem]';
</script>

<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import ContextMenu from '@imengyu/vue3-context-menu';
import { nlDate, nlTime, initials, hasPermission } from '@/Utilities/Utilities';
import { serviceOrderIsPlannable, patchServiceOrderStage } from '@/Utilities/serviceOrders';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import PlanInPlannerButton from '@/Components/ServiceOrders/PlanInPlannerButton.vue';
import {
    BuildingOffice2Icon,
    CalendarIcon,
    CheckCircleIcon,
    ChevronRightIcon,
    MapPinIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    serviceorder: {
        type: Object,
        required: true
    },
    stages: {
        type: Array,
        default: () => []
    }
});

const stage = computed(() => props.serviceorder.service_order_stage);

const stageColor = computed(() => {
    if (props.serviceorder.is_closed) return 'green';
    if (stage.value?.is_planning_cancelled_state) return 'red';
    if (stage.value?.is_incomplete_state) return 'orange';
    if (stage.value?.is_planned_state) return 'blue';
    return 'gray';
});

const accentClass = computed(() => ({
    green: 'bg-green-500',
    red: 'bg-red-500',
    orange: 'bg-orange-400',
    blue: 'bg-lavoro-blue',
    gray: 'bg-gray-300 dark:bg-slate-600',
}[stageColor.value]));

const locationText = computed(() => {
    const location = props.serviceorder.linked_location;
    if (location) return [location.address, location.city].filter(Boolean).join(', ') || null;
    return props.serviceorder.execution_location || props.serviceorder.project?.location || null;
});

const plannedEvents = computed(() =>
    [...(props.serviceorder.events || [])]
        .filter(event => event.status !== 'Geannuleerd')
        .sort((a, b) => new Date(a.start) - new Date(b.start))
);

const maxEvents = 2;
const showAllEvents = ref(false);

const visibleEvents = computed(() =>
    showAllEvents.value ? plannedEvents.value : plannedEvents.value.slice(0, maxEvents)
);

const hiddenEventCount = computed(() => plannedEvents.value.length - visibleEvents.value.length);

const statusSubtext = computed(() => {
    const so = props.serviceorder;
    if (so.closed_on) return 'Afgesloten ' + nlDate(so.closed_on);
    if (so.actual_start_time) return 'Gestart: ' + so.actual_start_time.slice(0, 5);
    return null;
});

const monteurs = computed(() => {
    const from_events = (props.serviceorder.events || []).flatMap(event => event.executing_users || []);
    const seen = new Set();
    return [...(props.serviceorder.executing_users || []), ...from_events].filter(user => {
        if (seen.has(user.id)) return false;
        seen.add(user.id);
        return true;
    });
});

const maxAvatars = 2;

const monteurNames = computed(() => monteurs.value.length === 1
    ? monteurs.value[0].name
    : monteurs.value.map(user => user.name.split(' ')[0]).join(', '));

const extraMonteurNames = computed(() => monteurs.value.slice(maxAvatars).map(user => user.name).join(', '));

const taskCounts = computed(() => {
    const active = (props.serviceorder.task_instances || []).filter(instance => !instance.is_cancelled);
    return {
        done: active.filter(instance => instance.is_complete).length,
        total: active.length,
    };
});

const allTasksDone = computed(() => taskCounts.value.done >= taskCounts.value.total);

const canDelete = computed(() =>
    hasPermission('serviceorder.delete') && !props.serviceorder.sent_to_administration
);

const showPlanButton = computed(() =>
    hasPermission('serviceorder.plan') && serviceOrderIsPlannable(props.serviceorder)
);

const mayMoveToStage = (target_stage) =>
    hasPermission('serviceorder.update')
    || (hasPermission('serviceorder.close') && target_stage.is_closed_state)
    || (hasPermission('serviceorder.mark_partially_complete') && target_stage.is_incomplete_state);

const stageMenuStages = computed(() => props.stages.filter(mayMoveToStage));

function onContextMenu(event) {
    const current_stage_id = props.serviceorder.service_order_stage_id;
    if (!stageMenuStages.value.some(target_stage => target_stage.id !== current_stage_id)) return;
    event.preventDefault();
    ContextMenu.showContextMenu({
        x: event.clientX,
        y: event.clientY,
        items: [
            { label: `Werkbon #${props.serviceorder.id} — fase`, disabled: true },
            ...stageMenuStages.value.map(target_stage => ({
                label: target_stage.id === current_stage_id ? `${target_stage.name}  ✓` : target_stage.name,
                disabled: target_stage.id === current_stage_id,
                onClick: () => patchServiceOrderStage(props.serviceorder, target_stage.id),
            })),
        ],
    });
}

const form = useForm({});

const deleteServiceOrder = () => {
    if (!confirm('Weet je zeker dat je deze werkbon wilt verwijderen? Alle keuringen en gegevens worden ook verwijderd.')) {
        return;
    }

    form.delete(`/serviceorders/${props.serviceorder.id}`, {
        preserveScroll: true,
    });
};
</script>
