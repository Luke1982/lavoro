<template>
    <div class="select-none text-sm">
        <div class="flex items-center justify-between mb-2">
            <button type="button" class="rounded-md p-1 hover:bg-gray-100 dark:hover:bg-slate-700"
                aria-label="Vorige maand" @click="shiftMonth(-1)">
                <ChevronLeftIcon class="size-4" />
            </button>
            <div class="font-semibold">{{ monthTitle }}</div>
            <button type="button" class="rounded-md p-1 hover:bg-gray-100 dark:hover:bg-slate-700"
                aria-label="Volgende maand" @click="shiftMonth(1)">
                <ChevronRightIcon class="size-4" />
            </button>
        </div>

        <div class="grid grid-cols-[1.75rem_repeat(7,2rem)] gap-y-0.5 text-center leading-8">
            <div class="text-[10px] font-medium text-gray-400 dark:text-slate-500">wk</div>
            <div v-for="name in weekdayNames" :key="name" class="text-xs font-medium text-gray-500 dark:text-slate-400">
                {{ name }}
            </div>

            <template v-for="week in weeks" :key="week.days[0].iso">
                <div class="text-[10px] text-gray-400 dark:text-slate-500">{{ week.number }}</div>
                <button v-for="day in week.days" :key="day.iso" type="button" :disabled="day.disabled"
                    class="h-8 tabular-nums transition-colors disabled:cursor-not-allowed disabled:opacity-30"
                    :class="dayClasses(day)" :aria-label="day.label" :aria-current="day.today ? 'date' : undefined"
                    @click="emit('select', day.date)">
                    {{ day.number }}
                </button>
            </template>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import dayjs from '@/Utilities/dayjs'

/**
 * A month grid for picking one day. The `selectedDays` days from
 * `selectedStart` on are marked as the current selection, so a week view can
 * mark its whole week; days after `maxDate` cannot be picked.
 */
const props = defineProps({
    selectedStart: { type: Date, default: null },
    selectedDays: { type: Number, default: 1 },
    maxDate: { type: Date, default: null },
})

const emit = defineEmits(['select'])

const weekdayNames = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo']
const monthFormatter = new Intl.DateTimeFormat('nl-NL', { month: 'long', year: 'numeric' })
const dayFormatter = new Intl.DateTimeFormat('nl-NL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

const viewMonth = ref(monthOf(props.selectedStart))

watch(() => props.selectedStart, (date) => { viewMonth.value = monthOf(date) })

const monthTitle = computed(() => monthFormatter.format(viewMonth.value.toDate()))

const selection = computed(() => {
    if (!props.selectedStart) return null
    const start = dayjs(props.selectedStart).startOf('day')
    return { start, end: start.add(props.selectedDays - 1, 'day') }
})

const weeks = computed(() => {
    const firstMonday = viewMonth.value.startOf('isoWeek')
    return Array.from({ length: 6 }, (_, weekIndex) => {
        const monday = firstMonday.add(weekIndex, 'week')
        return {
            number: monday.isoWeek(),
            days: Array.from({ length: 7 }, (_, dayIndex) => describeDay(monday.add(dayIndex, 'day'))),
        }
    })
})

function describeDay(day) {
    const range = selection.value
    const selected = !!range && !day.isBefore(range.start, 'day') && !day.isAfter(range.end, 'day')
    return {
        date: day.toDate(),
        iso: day.format('YYYY-MM-DD'),
        number: day.date(),
        label: dayFormatter.format(day.toDate()),
        outside: !day.isSame(viewMonth.value, 'month'),
        today: day.isSame(dayjs(), 'day'),
        disabled: !!props.maxDate && day.isAfter(props.maxDate, 'day'),
        selected,
        selectionStart: selected && day.isSame(range.start, 'day'),
        selectionEnd: selected && day.isSame(range.end, 'day'),
    }
}

function monthOf(date) {
    return (date ? dayjs(date) : dayjs()).startOf('month')
}

function shiftMonth(direction) {
    viewMonth.value = viewMonth.value.add(direction, 'month')
}

function dayClasses(day) {
    if (day.selected) {
        return {
            'bg-lavoro-blue text-white font-semibold': true,
            'rounded-l-md': day.selectionStart,
            'rounded-r-md': day.selectionEnd,
            'underline underline-offset-4 decoration-2': day.today,
        }
    }
    return {
        'rounded-md hover:bg-gray-100 dark:hover:bg-slate-700': true,
        'text-gray-400 dark:text-slate-500': day.outside,
        'font-semibold text-lavoro-blue ring-1 ring-inset ring-lavoro-blue': day.today,
    }
}
</script>
