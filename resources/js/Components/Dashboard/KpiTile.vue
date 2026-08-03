<template>
    <DashCard fill>
        <div class="flex flex-1 flex-col p-5">
            <div class="flex items-start gap-3">
                <span class="flex size-10 flex-none items-center justify-center rounded-xl" :style="tintStyle">
                    <component :is="icon" class="size-5" :style="{ color: accent }" aria-hidden="true" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="line-clamp-2 text-[13px] font-medium text-gray-500 dark:text-slate-400">
                        {{ kpi.label }}
                    </p>

                    <div class="mt-1.5 flex items-end justify-between gap-2">
                        <p class="text-[28px] font-bold leading-none tracking-tight text-gray-900 dark:text-slate-50">
                            {{ formattedValue }}<span v-if="kpi.unit && kpi.value !== null"
                                class="ml-1 text-lg font-semibold">{{ kpi.unit }}</span>
                        </p>

                        <!-- De ring vervangt de sparkline: een aandeel heeft geen verloop over de dagen, wel een maximum. -->
                        <div v-if="isRing" class="relative -mb-1 size-14 flex-none">
                            <svg width="56" height="56" viewBox="0 0 56 56" class="-rotate-90" aria-hidden="true">
                                <circle cx="28" cy="28" r="23" fill="none" stroke="var(--dash-track)"
                                    stroke-width="6" />
                                <circle v-if="kpi.value > 0" cx="28" cy="28" r="23" fill="none" :stroke="accent"
                                    stroke-width="6" stroke-linecap="round" pathLength="100"
                                    :stroke-dasharray="`${kpi.value} 100`"
                                    class="transition-all duration-700 ease-out" />
                            </svg>
                            <span
                                class="absolute inset-0 flex items-center justify-center text-[13px] font-bold text-gray-900 dark:text-slate-100">
                                {{ ringText }}
                            </span>
                        </div>
                    </div>

                    <p v-if="kpi.delta === null" class="mt-2 text-xs font-medium text-gray-400 dark:text-slate-500">
                        Geen vergelijking
                    </p>
                    <template v-else>
                        <p class="mt-2 flex items-center gap-1 text-xs font-semibold" :class="deltaClasses">
                            <component :is="deltaIcon" class="size-3.5" aria-hidden="true" />
                            {{ Math.abs(kpi.delta) }}%
                        </p>
                        <p class="mt-0.5 text-[11px] text-gray-400 dark:text-slate-500">{{ kpi.compare }}</p>
                    </template>
                </div>
            </div>

            <div v-if="hasTrend" class="mt-4 flex flex-1 items-end">
                <TrendChart class="w-full" :values="kpi.trend" :labels="dates" :color="accent" :unit="kpi.unit" />
            </div>
        </div>
    </DashCard>
</template>

<script setup>
import { computed } from 'vue';
import {
    ArrowDownIcon,
    ArrowUpIcon,
    CalendarCheckIcon,
    CircleCheckBigIcon,
    ClockIcon,
    FileTextIcon,
    MinusIcon,
    PhoneCallIcon,
} from '@lucide/vue';
import DashCard from '@/Components/Dashboard/DashCard.vue';
import TrendChart from '@/Components/Dashboard/TrendChart.vue';

const props = defineProps({
    kpi: { type: Object, required: true },
    /** ISO dates matching the trend series, for the sparkline tooltip. */
    dates: { type: Array, default: () => [] },
});

const icons = {
    orders: FileTextIcon,
    hours: ClockIcon,
    tickets: PhoneCallIcon,
    closed: CalendarCheckIcon,
    ontime: CircleCheckBigIcon,
};

const numberFormat = new Intl.NumberFormat('nl-NL');

const icon = computed(() => icons[props.kpi.icon] ?? FileTextIcon);
const accent = computed(() => `var(--dash-series-${props.kpi.accent})`);
const tintStyle = computed(() => ({ backgroundColor: `color-mix(in srgb, ${accent.value} 12%, transparent)` }));

const isRing = computed(() => props.kpi.ring !== undefined);
const hasTrend = computed(() => Array.isArray(props.kpi.trend) && props.kpi.trend.length > 0);

const formattedValue = computed(() => (props.kpi.value === null ? '—' : numberFormat.format(props.kpi.value)));
const ringText = computed(() => (props.kpi.value === null ? '—' : `${props.kpi.value}%`));

/**
 * Gelijk gebleven is geen goed en geen slecht nieuws, dus krijgt het ook geen
 * kleur van de twee: een grijs streepje zegt precies wat er gebeurd is.
 */
const isFlat = computed(() => props.kpi.delta === 0);
const isGoodNews = computed(() => (props.kpi.delta > 0) === props.kpi.higher_is_better);

const deltaIcon = computed(() => {
    if (isFlat.value) return MinusIcon;
    return props.kpi.delta > 0 ? ArrowUpIcon : ArrowDownIcon;
});

const deltaClasses = computed(() => {
    if (isFlat.value) return 'text-gray-400 dark:text-slate-500';
    return isGoodNews.value ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
});
</script>
