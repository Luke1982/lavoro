<template>
    <!--
        Vanaf xl vult het dashboard het scherm: de twee onderste rijen delen wat
        er overblijft, zodat er onderaan geen strook leeg blijft. De 3.5rem is de
        boven- en ondermarge die MainLayout zelf om de pagina zet.
    -->
    <div class="flex flex-col gap-4 pb-4 xl:h-[calc(100dvh-3.5rem)] xl:pb-0">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-slate-50">Dashboard</h1>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">
                    Welkom terug, {{ firstName }}! <span aria-hidden="true">👋</span>
                </p>
            </div>

            <div class="flex flex-none items-center gap-2">
            <Menu as="div" class="relative flex-none">
                <MenuButton
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-800 shadow-lavoro-box hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:shadow-none dark:hover:bg-slate-800">
                    <CalendarIcon class="size-4 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                    <span>{{ period.range_label }}</span>
                    <ChevronDownIcon class="size-4 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                </MenuButton>
                <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                    leave-to-class="opacity-0">
                    <MenuItems
                        class="absolute right-0 z-30 mt-2 w-56 origin-top-right overflow-hidden rounded-lg bg-white py-1 shadow-lg outline-1 outline-black/5 dark:bg-slate-800 dark:outline-white/10">
                        <MenuItem v-for="option in periodOptions" :key="option.value" v-slot="{ active }">
                        <button type="button" @click="selectPeriod(option.value)"
                            :class="['flex w-full items-center justify-between px-4 py-2.5 text-left text-sm', active ? 'bg-gray-50 dark:bg-slate-700' : '', option.value === period.value ? 'font-semibold text-gray-900 dark:text-slate-50' : 'text-gray-700 dark:text-slate-200']">
                            {{ option.title }}
                            <CheckIcon v-if="option.value === period.value" class="size-4 text-lavoro-blue"
                                aria-hidden="true" />
                        </button>
                        </MenuItem>
                    </MenuItems>
                </transition>
            </Menu>

            <!--
                Dezelfde vier soorten en dezelfde la als de plusknop op mobiel:
                dit is één plek erbij om ze te openen, geen tweede manier om ze
                aan te maken. Staat er niets in dat deze persoon mag aanmaken,
                dan is er ook geen knop.
            -->
            <Menu v-if="createActions.length" as="div" class="relative flex-none">
                <MenuButton
                    class="inline-flex items-center gap-2 rounded-lg bg-lavoro-blue px-3.5 py-2.5 text-sm font-semibold text-white shadow-lavoro-box hover:brightness-110 dark:shadow-none">
                    <PlusIcon class="size-4" aria-hidden="true" />
                    <span>Nieuw</span>
                    <ChevronDownIcon class="size-4 opacity-70" aria-hidden="true" />
                </MenuButton>
                <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                    leave-to-class="opacity-0">
                    <MenuItems
                        class="absolute right-0 z-30 mt-2 w-56 origin-top-right overflow-hidden rounded-lg bg-white py-1 shadow-lg outline-1 outline-black/5 dark:bg-slate-800 dark:outline-white/10">
                        <MenuItem v-for="action in createActions" :key="action.id" v-slot="{ active }">
                        <button type="button" @click="startCreating(action)"
                            :class="['flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 dark:text-slate-200', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                            <span class="flex size-7 flex-none items-center justify-center rounded-lg bg-lavoro-blue/10">
                                <component :is="navIcon(action.icon)" class="size-4 text-lavoro-blue" />
                            </span>
                            {{ action.label }}
                        </button>
                        </MenuItem>
                    </MenuItems>
                </transition>
            </Menu>
            </div>
        </header>

        <CreateDrawer v-model="creatingOpen" :action="creatingAction" />

        <section v-if="kpis" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <KpiTile v-for="kpi in kpis" :key="kpi.key" :kpi="kpi" :dates="trendDates" />
        </section>

        <section v-if="openOrders || mapPoints || agenda"
            class="grid gap-4 xl:min-h-[18rem] xl:flex-[5] xl:grid-cols-10">
            <DashCard v-if="openOrders" fill class="overflow-hidden xl:col-span-4">
                <WidgetHeader title="Werkbonnen overzicht" subtitle="Alle openstaande werkbonnen"
                    link-href="/serviceorders" />
                <div v-if="openOrders.total > 0" ref="ringBox"
                    class="flex min-h-0 flex-1 flex-col items-center justify-center gap-6 px-5 pb-5 sm:flex-row">
                    <CircularCounter :segments="donutSegments" :total="openOrders.total" label="Totaal"
                        :size="ringSize" :stroke="ringStroke" selectable :highlight="hoveredSegment" @select="openStage" />
                    <ul class="w-full min-w-0 max-w-72 flex-1 space-y-2.5 self-center overflow-y-auto">
                        <li v-for="(segment, index) in openOrders.segments" :key="segment.name">
                            <component :is="segment.stage_ids.length ? Link : 'div'"
                                :href="segment.stage_ids.length ? stageFilterUrl(segment) : undefined"
                                class="flex w-full items-center gap-2.5 rounded-md px-1.5 py-1 text-left text-sm transition-colors"
                                :class="segment.stage_ids.length ? 'hover:bg-gray-50 dark:hover:bg-slate-800' : ''"
                                @mouseenter="hoveredSegment = index" @mouseleave="hoveredSegment = null">
                            <span class="size-2.5 flex-none rounded-full"
                                :style="{ backgroundColor: slotColor(segment.slot) }" aria-hidden="true" />
                            <span class="line-clamp-2 min-w-0 flex-1 leading-tight text-gray-600 dark:text-slate-300">
                                {{ segment.name }}
                            </span>
                            <span class="flex-none font-semibold tabular-nums text-gray-900 dark:text-slate-100">
                                {{ segment.count }}
                            </span>
                            <span class="w-9 flex-none text-right tabular-nums text-gray-400 dark:text-slate-500">
                                {{ segment.share }}%
                            </span>
                            </component>
                        </li>
                    </ul>
                </div>
                <p v-else class="flex flex-1 items-center justify-center px-5 pb-8 text-sm text-gray-500 dark:text-slate-400">
                    Geen openstaande werkbonnen
                </p>

                <!--
                    Deze liggen over alle fases verspreid, dus in de verdeling
                    zelf zie je ze nooit als groep — terwijl het juist de stapel
                    is waar iemand iets mee moet.
                -->
                <Link v-if="openOrders.needs_closing > 0" href="/serviceorders?onlyNeedsClosing=1"
                    class="flex items-center justify-between gap-3 border-t border-gray-100 px-5 py-3 transition-colors hover:bg-gray-50 dark:border-slate-800 dark:hover:bg-slate-800">
                <span class="flex min-w-0 items-center gap-2">
                    <CircleAlertIcon class="size-4 flex-none text-amber-500" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-gray-900 dark:text-slate-100">Te sluiten</span>
                        <span class="block truncate text-xs text-gray-400 dark:text-slate-500">
                            Alle afspraken geweest, fase nog niet gesloten
                        </span>
                    </span>
                </span>
                <span class="flex flex-none items-center gap-1.5">
                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-slate-100">
                        {{ openOrders.needs_closing }}
                    </span>
                    <ArrowRightIcon class="size-4 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                </span>
                </Link>
            </DashCard>

            <DashCard v-if="mapPoints" fill class="overflow-hidden xl:col-span-3">
                <WidgetHeader title="Werkbonnen op locatie" subtitle="Ingepland in deze periode" />
                <div class="min-h-[15rem] flex-1">
                    <DashboardMap :points="mapPoints.points" :planned="mapPoints.planned"
                        :unplaced="mapPoints.unplaced" />
                </div>
            </DashCard>

            <DashCard v-if="agenda" fill class="overflow-hidden xl:col-span-3">
                <WidgetHeader title="Agenda (vandaag)" link-href="/planner" link-label="Bekijk planner" />
                <ol v-if="agenda.length" class="min-h-0 flex-1 overflow-y-auto px-5">
                    <li v-for="(item, index) in agenda" :key="item.id" class="flex gap-2.5">
                        <span class="w-10 flex-none pt-0.5 text-xs font-semibold tabular-nums text-gray-500 dark:text-slate-400">
                            {{ nlTime(item.start) }}
                        </span>
                        <span class="flex flex-none flex-col items-center" aria-hidden="true">
                            <span class="mt-1 size-2.5 rounded-full"
                                :style="{ backgroundColor: eventStatusColor(item.status) }" />
                            <span v-if="index < agenda.length - 1" class="mt-1 w-0.5 flex-1 rounded-full"
                                :style="{ backgroundColor: eventStatusColor(item.status), opacity: 0.3 }" />
                        </span>
                        <div class="min-w-0 flex-1 pb-4">
                            <div class="flex items-start justify-between gap-2">
                                <Link v-if="item.service_order_id" :href="`/serviceorders/${item.service_order_id}`"
                                    class="truncate text-[13px] font-semibold text-gray-900 hover:underline dark:text-slate-100">
                                {{ formatWbNumber(item.service_order_id) }}
                                </Link>
                                <span v-else class="truncate text-[13px] font-semibold text-gray-900 dark:text-slate-100">
                                    {{ item.type || 'Afspraak' }}
                                </span>
                                <span :class="eventStatusBadgeClass(item.status)">{{ item.status }}</span>
                            </div>
                            <p class="truncate text-sm text-gray-700 dark:text-slate-300">{{ item.name || '—' }}</p>
                            <p v-if="item.customer" class="truncate text-xs text-gray-400 dark:text-slate-500">
                                {{ item.customer }}
                            </p>
                        </div>
                    </li>
                </ol>
                <p v-else class="flex flex-1 items-center justify-center px-5 pb-8 text-sm text-gray-500 dark:text-slate-400">
                    Vandaag staat er niets gepland
                </p>
                <div class="border-t border-gray-100 px-5 py-3 dark:border-slate-800">
                    <Link href="/planner"
                        class="inline-flex items-center gap-1 text-sm font-medium text-lavoro-blue hover:underline dark:text-blue-400">
                    Bekijk volledige agenda
                    <ArrowRightIcon class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </DashCard>
        </section>

        <section v-if="upcomingInspections || recentOrders || openTickets"
            class="grid gap-4 lg:grid-cols-3 xl:min-h-[13rem] xl:flex-[4]">
            <DashCard v-if="upcomingInspections" fill class="@container overflow-hidden">
                <WidgetHeader title="Aankomende keuringen" link-href="/servicejobs" />
                <ul v-if="upcomingInspections.length"
                    class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-slate-800">
                    <li v-for="item in upcomingInspections" :key="item.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="flex size-9 flex-none items-center justify-center rounded-lg"
                            :style="tint('--dash-series-1')">
                            <CalendarDaysIcon class="size-4.5" :style="{ color: 'var(--dash-series-1)' }"
                                aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <Link :href="`/assets/${item.id}`"
                                class="block truncate text-[13px] font-semibold text-gray-900 hover:underline dark:text-slate-100">
                            {{ item.title }}
                            </Link>
                            <p class="truncate text-xs text-gray-500 dark:text-slate-400">{{ item.subtitle || '—' }}</p>
                        </div>
                        <span class="hidden flex-none whitespace-nowrap text-xs text-gray-400 @sm:block dark:text-slate-500">
                            {{ nlDate(item.date) }}
                        </span>
                        <span :class="['flex-none rounded-full px-2.5 py-1 text-[11px] font-medium ring-1 ring-inset', dueClasses(item.days)]">
                            {{ dueLabel(item.days) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="flex flex-1 items-center px-5 pb-6 text-sm text-gray-500 dark:text-slate-400">Geen geplande keuringen</p>
            </DashCard>

            <DashCard v-if="recentOrders" fill class="@container overflow-hidden">
                <WidgetHeader title="Recente werkbonnen" link-href="/serviceorders" />
                <ul v-if="recentOrders.length"
                    class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-slate-800">
                    <li v-for="order in recentOrders" :key="order.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="flex size-9 flex-none items-center justify-center rounded-lg"
                            :style="tint(order.is_closed ? '--dash-series-2' : '--dash-series-1')">
                            <FileTextIcon class="size-4.5"
                                :style="{ color: `var(${order.is_closed ? '--dash-series-2' : '--dash-series-1'})` }"
                                aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <Link :href="`/serviceorders/${order.id}`"
                                class="block truncate text-[13px] font-semibold text-gray-900 hover:underline dark:text-slate-100">
                            {{ formatWbNumber(order.id) }}
                            </Link>
                            <p class="truncate text-xs text-gray-500 dark:text-slate-400">
                                {{ order.description || order.customer || '—' }}
                            </p>
                        </div>
                        <span class="hidden flex-none whitespace-nowrap text-xs text-gray-400 @sm:block dark:text-slate-500">
                            {{ nlRelativeDateTime(order.updated_at) }}
                        </span>
                        <span
                            class="flex-none rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                            {{ order.stage || 'Geen fase' }}
                        </span>
                    </li>
                </ul>
                <p v-else class="flex flex-1 items-center px-5 pb-6 text-sm text-gray-500 dark:text-slate-400">Geen werkbonnen</p>
            </DashCard>

            <DashCard v-if="openTickets" fill class="@container overflow-hidden">
                <WidgetHeader title="Actieve storingen" link-href="/tickets" />
                <ul v-if="openTickets.length"
                    class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-slate-800">
                    <li v-for="ticket in openTickets" :key="ticket.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="flex size-9 flex-none items-center justify-center rounded-lg"
                            :style="tint('--dash-series-5')">
                            <PhoneCallIcon class="size-4.5" :style="{ color: 'var(--dash-series-5)' }"
                                aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <Link :href="`/tickets/${ticket.id}`"
                                class="block truncate text-[13px] font-semibold text-gray-900 hover:underline dark:text-slate-100">
                            {{ ticket.subject || `Storing #${ticket.id}` }}
                            </Link>
                            <p class="truncate text-xs text-gray-500 dark:text-slate-400">{{ ticket.customer || '—' }}</p>
                        </div>
                        <span class="hidden flex-none whitespace-nowrap text-xs text-gray-400 @sm:block dark:text-slate-500">
                            {{ nlRelativeDateTime(ticket.created_at) }}
                        </span>
                        <span
                            :class="['flex-none rounded-full px-2.5 py-1 text-[11px] font-medium ring-1 ring-inset', ticketPriorityClasses(ticket.priority)]">
                            {{ ticket.priority || 'Onbekend' }}
                        </span>
                    </li>
                </ul>
                <p v-else class="flex flex-1 items-center px-5 pb-6 text-sm text-gray-500 dark:text-slate-400">Geen openstaande storingen</p>
            </DashCard>
        </section>

        <DashCard v-if="nothingVisible">
            <p class="p-6 text-sm text-gray-500 dark:text-slate-400">
                Je hebt (nog) geen rechten op een van de dashboardblokken. Vraag een beheerder om toegang.
            </p>
        </DashCard>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useElementSize } from '@vueuse/core';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    ArrowRightIcon,
    CalendarDaysIcon,
    CalendarIcon,
    CheckIcon,
    ChevronDownIcon,
    CircleAlertIcon,
    PlusIcon,
    FileTextIcon,
    PhoneCallIcon,
} from '@lucide/vue';
import dayjs from '@/Utilities/dayjs';
import { useMenu } from '@/Composables/useMenu.js';
import { navIcon } from '@/Navigation/icons';
import CreateDrawer from '@/Components/Layout/CreateDrawer.vue';
import CircularCounter from '@/Components/UI/CircularCounter.vue';
import DashCard from '@/Components/Dashboard/DashCard.vue';
import DashboardMap from '@/Components/Dashboard/DashboardMap.vue';
import KpiTile from '@/Components/Dashboard/KpiTile.vue';
import WidgetHeader from '@/Components/Dashboard/WidgetHeader.vue';
import {
    eventStatusBadgeClass,
    formatWbNumber,
    nlDate,
    nlRelativeDateTime,
    nlTime,
    ticketPriorityClasses,
} from '@/Utilities/Utilities';

const props = defineProps({
    period: { type: Object, required: true },
    periodOptions: { type: Array, default: () => [] },
    kpis: { type: Array, default: null },
    openOrders: { type: Object, default: null },
    mapPoints: { type: Object, default: null },
    agenda: { type: Array, default: null },
    upcomingInspections: { type: Array, default: null },
    recentOrders: { type: Array, default: null },
    openTickets: { type: Array, default: null },
});

/**
 * De soorten records die deze persoon mag aanmaken komen uit het menu, net als
 * bij de plusknop op mobiel. Wie er een bijzet, zet hem op beide plekken bij.
 */
const { createActions } = useMenu();

const creatingOpen = ref(false);
const creatingAction = ref(null);

const startCreating = (action) => {
    creatingAction.value = action;
    creatingOpen.value = true;
};

const firstName = computed(() => (usePage().props.auth?.user?.name ?? '').split(' ')[0] || 'collega');

const nothingVisible = computed(() => !props.kpis && !props.openOrders && !props.mapPoints
    && !props.agenda && !props.upcomingInspections && !props.recentOrders && !props.openTickets);

const selectPeriod = (value) => {
    router.get('/', { period: value }, { preserveScroll: true, replace: true });
};

/**
 * Eén datum per staafje, zodat de tooltip de dag bij de naam kan noemen.
 *
 * Bewust via dayjs en niet via toISOString(): die rekent naar UTC om, en ten
 * oosten van Greenwich is lokale middernacht daar de dag ervoor — dan schuift de
 * hele reeks een dag op en noemt de tooltip de verkeerde dag.
 */
const trendDates = computed(() => {
    const dates = [];
    const end = dayjs(props.period.end);

    for (let day = dayjs(props.period.start); !day.isAfter(end); day = day.add(1, 'day')) {
        dates.push(day.format('YYYY-MM-DD'));
    }

    return dates;
});

/**
 * De ring meet zich naar de kaart in plaats van naar een vast getal: de kaart
 * groeit met het scherm mee, en een ring van 156 pixels daarin ziet er op een
 * hoog scherm uit als een speldenknop. De ondergrens houdt hem leesbaar op een
 * smalle kaart, de bovengrens voorkomt dat hij de legenda wegdrukt.
 */
const ringBox = ref(null);
const { width: ringBoxWidth, height: ringBoxHeight } = useElementSize(ringBox);

const ringSize = computed(() => {
    const width = ringBoxWidth.value;
    const height = ringBoxHeight.value;

    if (!width) return 176;

    /*
     * De hoogte telt alleen mee zolang de kaart hem oplegt, en dat is zo bij een
     * brede rij: ring naast legenda. Staat de legenda eronder, dan bepaalt de
     * ring de hoogte van het vak zelf, en meerekenen zou een lus zijn — groter
     * meten, groter tekenen, opnieuw meten.
     */
    const byWidth = width * 0.46;
    const room = width > height ? Math.min(byWidth, height - 16) : byWidth;

    return Math.round(Math.min(260, Math.max(140, room)));
});

const ringStroke = computed(() => Math.round(ringSize.value * 0.14));

const slotColor = (slot) => `var(--dash-series-${slot})`;

const tint = (variable) => ({ backgroundColor: `color-mix(in srgb, var(${variable}) 12%, transparent)` });

const hoveredSegment = ref(null);

const donutSegments = computed(() => (props.openOrders?.segments ?? [])
    .map((segment) => ({
        value: segment.count,
        color: slotColor(segment.slot),
        title: `${segment.name}: ${segment.count} werkbonnen (${segment.share}%)`,
    })));

/**
 * De werkbonnenlijst leest zijn fasefilter uit `onlyStage` in de adresbalk, dus
 * een schijf hoeft alleen zijn fases mee te geven om daar gefilterd aan te komen.
 */
const stageFilterUrl = (segment) => `/serviceorders?onlyStage=${segment.stage_ids.join(',')}`;

const openStage = (index) => {
    const segment = props.openOrders?.segments?.[index];

    if (segment?.stage_ids.length) {
        router.visit(stageFilterUrl(segment));
    }
};

const eventStatusColors = {
    Gepland: '#3b82f6',
    Gaande: '#f59e0b',
    Afgerond: '#22c55e',
    Geannuleerd: '#ef4444',
};

const eventStatusColor = (status) => eventStatusColors[status] ?? '#94a3b8';

const dueLabel = (days) => {
    if (days <= 0) return 'Vandaag';
    if (days === 1) return 'Binnen 1 dag';
    return `Binnen ${days} dagen`;
};

const dueClasses = (days) => {
    if (days <= 7) {
        return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-700/50';
    }
    if (days <= 13) {
        return 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-900/30 dark:text-orange-300 dark:ring-orange-700/50';
    }
    return 'bg-lavoro-lightblue text-lavoro-blue ring-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:ring-blue-700/50';
};
</script>
