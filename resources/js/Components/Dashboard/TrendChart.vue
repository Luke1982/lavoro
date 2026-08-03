<template>
    <figure class="w-full">
        <!--
            Eén streep als schaal: de hoogste dag van de periode, met zijn waarde
            ernaast. Samen met de nullijn eronder weet je waar een staaf tussen
            staat, zonder dat er een heel assenstelsel in een tegel moet passen.
        -->
        <figcaption class="flex items-center gap-1.5">
            <span class="text-[10px] font-medium tabular-nums text-gray-400 dark:text-slate-500">
                {{ formatValue(max) }}
            </span>
            <span class="h-0 flex-1 border-t border-dashed border-gray-200 dark:border-slate-700" />
        </figcaption>

        <div class="relative mt-1" @pointerleave="active = null">
            <!--
                items-end op het spoor zelf, niet op de rij: een percentage-hoogte
                groeit vanaf de bovenkant van zijn ouder, dus zonder deze regel
                hangen de staafjes in de lucht in plaats van op de nullijn.
            -->
            <div class="flex h-9 gap-0.5">
                <div v-for="(value, index) in values" :key="index" class="flex h-full flex-1 items-end">
                    <div class="mx-auto w-full max-w-4.5 rounded-t-[3px] transition-opacity duration-150"
                        :style="barStyle(value, index)" />
                </div>
            </div>

            <div class="h-px bg-gray-200 dark:bg-slate-700" />

            <!-- Trefvlakken over de volle hoogte: een staaf van drie pixels is te laag om aan te wijzen. -->
            <div class="absolute inset-0 flex">
                <button v-for="(value, index) in values" :key="index" type="button" class="flex-1 cursor-default"
                    :aria-label="`${dayLabel(index)}: ${formatValue(value)}`" @pointerenter="active = index"
                    @focus="active = index" @blur="active = null" />
            </div>

            <div v-if="active !== null"
                class="pointer-events-none absolute -top-8 z-10 -translate-x-1/2 whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg dark:bg-slate-700"
                :style="{ left: tooltipLeft }">
                {{ dayLabel(active) }} · {{ formatValue(values[active]) }}
            </div>
        </div>

        <div class="mt-1 flex justify-between text-[10px] text-gray-400 dark:text-slate-500">
            <span>{{ edgeLabel(0) }}</span>
            <span v-if="values.length > 1">{{ edgeLabel(values.length - 1) }}</span>
        </div>
    </figure>
</template>

<script setup>
import { computed, ref } from 'vue';
import dayjs from '@/Utilities/dayjs';
import { nlDayName } from '@/Utilities/Utilities';

const props = defineProps({
    /** Eén waarde per dag van de periode. */
    values: { type: Array, default: () => [] },
    /** ISO-datums die bij de waarden horen. */
    labels: { type: Array, default: () => [] },
    color: { type: String, default: 'var(--dash-series-1)' },
    unit: { type: String, default: '' },
});

const MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

const active = ref(null);

const max = computed(() => Math.max(0, ...props.values));

const barStyle = (value, index) => {
    const share = max.value > 0 ? (value / max.value) * 100 : 0;
    const empty = !value;

    return {
        height: empty ? '2px' : `max(3px, ${share}%)`,
        backgroundColor: empty ? 'var(--dash-track)' : props.color,
        opacity: active.value === null || active.value === index ? 1 : 0.45,
    };
};

const tooltipLeft = computed(() => {
    if (active.value === null || !props.values.length) return '50%';

    return `${((active.value + 0.5) / props.values.length) * 100}%`;
});

const shortDate = (iso) => {
    const day = dayjs(iso);

    return day.isValid() ? `${day.date()} ${MONTHS[day.month()]}` : '';
};

const dayLabel = (index) => {
    const iso = props.labels[index];

    return iso ? `${nlDayName(iso).slice(0, 2)} ${shortDate(iso)}` : `Dag ${index + 1}`;
};

const edgeLabel = (index) => shortDate(props.labels[index]);

/**
 * Uren komen met een decimaal binnen, aantallen niet. Beide worden hier gerond
 * getoond zodat de schaalwaarde en de tooltip dezelfde taal spreken.
 */
const formatValue = (value) => {
    const number = Number.isInteger(value) ? value : Math.round(value * 10) / 10;
    const text = String(number).replace('.', ',');

    return props.unit ? `${text} ${props.unit}` : text;
};
</script>
