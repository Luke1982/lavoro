<template>
    <div class="flow-root">
        <div v-if="!eventsToShow.length" class="text-xs text-gray-500 dark:text-slate-500 px-1 py-0.5">Geen afspraken
        </div>
        <div v-else :class="['timeline-wrapper', expanded ? 'expanded' : 'collapsed']" aria-live="polite">
            <ul role="list" class="-mb-5" v-auto-animate>
                <li v-for="(ev, idx) in eventsToShow" :key="ev.id">
                    <div class="relative pb-5">
                        <span v-if="idx !== eventsToShow.length - 1"
                            class="absolute top-3 left-3 -ml-px h-full w-0.5 bg-gray-200 dark:bg-slate-700/60"
                            aria-hidden="true" />
                        <div class="relative flex space-x-3">
                            <div>
                                <span
                                    :class="[ev.bg, 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm text-[10px] font-bold uppercase tracking-tight text-white']">
                                    {{ ev.short }}
                                </span>
                            </div>
                            <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                <div class="text-sm text-gray-600 dark:text-slate-400">
                                    <p class="flex items-center gap-2 flex-wrap">
                                        <span v-if="ev.name" class="font-medium text-gray-800 dark:text-slate-200">{{
                                            ev.name }}</span>
                                        <span v-if="ev.status" :class="statusBadgeClass(ev.status)"
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium tracking-wide">{{
                                                ev.status }}</span>
                                    </p>
                                    <p class="text-gray-600 dark:text-slate-400" v-if="ev.service_order_id">
                                        Werkbon
                                        <Link :href="`/serviceorders/${ev.service_order_id}`"
                                            class="underline text-indigo-600 dark:text-indigo-400">#{{
                                                ev.service_order_id }}</Link>
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-500" v-if="ev.description"
                                        v-html="ev.description" />
                                    <p class="mt-1 text-[11px] leading-snug text-gray-700 dark:text-slate-300">
                                        <span class="font-medium dark:text-slate-200">Start:</span> {{ ev.startFormatted
                                        }}<br>
                                        <span class="font-medium dark:text-slate-200">Einde:</span> {{ ev.endFormatted
                                        }}
                                    </p>
                                </div>
                                <div class="text-right text-[11px] whitespace-nowrap text-gray-500 dark:text-slate-500">
                                    <time :datetime="ev.start">{{ ev.compactDate }}</time>
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
        <div v-if="showToggle && eventsToShow.length" class="mt-1 -mx-3">
            <button type="button" @click="expanded = !expanded" :aria-expanded="expanded.toString()"
                class="group w-full flex items-center justify-start gap-2 text-xs font-medium text-indigo-600 dark:text-indigo-400 px-3 py-2 rounded-md hover:bg-indigo-50 dark:hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-slate-900">
                <span class="select-none">{{ expanded ? 'Toon minder' : 'Toon alle ' + internalEvents.length }}</span>
                <svg v-if="!expanded" class="size-3 text-indigo-500 group-hover:translate-y-0.5 transition-transform"
                    viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M10 14a1 1 0 0 1-.707-.293l-5-5a1 1 0 1 1 1.414-1.414L10 11.586l4.293-4.293a1 1 0 0 1 1.414 1.414l-5 5A1 1 0 0 1 10 14Z"
                        clip-rule="evenodd" />
                </svg>
                <svg v-else class="size-3 text-indigo-500 group-hover:-translate-y-0.5 transition-transform"
                    viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M10 6a1 1 0 0 1 .707.293l5 5a1 1 0 0 1-1.414 1.414L10 8.414 5.707 12.707a1 1 0 0 1-1.414-1.414l5-5A1 1 0 0 1 10 6Z"
                        clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { nlDate, nlTime } from '@/Utilities/Utilities'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    events: { type: Array, required: true }, // array of events with eventType and optional pivot
    limit: { type: Number, default: 5 }
})

const colorFallback = 'bg-gray-400'
const colorClasses = [
    'bg-blue-600', 'bg-indigo-600', 'bg-emerald-600', 'bg-amber-600', 'bg-cyan-600', 'bg-purple-600', 'bg-pink-600', 'bg-fuchsia-600', 'bg-green-600', 'bg-rose-600'
]

const mapColor = (color) => {
    if (!color) return colorFallback
    // If already a Tailwind bg-* class, return as-is
    if (color.startsWith('bg-')) return color
    // simple hash to pick stable color index
    const idx = [...color].reduce((acc, c) => acc + c.charCodeAt(0), 0) % colorClasses.length
    return colorClasses[idx]
}

const formatDateTime = (iso) => {
    if (!iso) return ''
    return nlDate(iso) + ' ' + nlTime(iso)
}

const compactFormat = (iso) => {
    if (!iso) return ''
    const d = new Date(iso)
    const today = new Date()
    if (d.toDateString() === today.toDateString()) return nlTime(d)
    return nlDate(d)
}

const internalEvents = computed(() => props.events.slice().sort((a, b) => new Date(b.start) - new Date(a.start)).map(ev => {
    const type = ev.event_type || ev.eventType || {}
    return {
        id: ev.id,
        name: ev.name || type.name || 'Afspraak',
        description: ev.description || '',
        start: ev.start,
        end: ev.end,
        startFormatted: formatDateTime(ev.start),
        endFormatted: formatDateTime(ev.end),
        compactDate: compactFormat(ev.start),
        bg: mapColor(type.color),
        short: (type.name || 'A')[0]?.toUpperCase() || 'A',
        service_order_id: ev.service_orders?.[0]?.id || ev.service_order_id || (ev.serviceOrders?.[0]?.id) || null,
        status: ev.status || null,
    }
}))

const expanded = ref(false)
const showToggle = computed(() => internalEvents.value.length > props.limit)
const eventsToShow = computed(() => expanded.value ? internalEvents.value : internalEvents.value.slice(0, props.limit))

// Map status to badge classes
const statusBadgeClass = (status) => {
    const base = 'inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium'
    switch (status) {
        case 'Gepland': return base + ' bg-blue-50 text-blue-700 border-blue-200'
        case 'Gaande': return base + ' bg-amber-50 text-amber-700 border-amber-200'
        case 'Afgerond': return base + ' bg-green-50 text-green-700 border-green-200'
        case 'Geannuleerd': return base + ' bg-red-50 text-red-700 border-red-200'
        default: return base + ' bg-gray-100 text-gray-600 border-gray-200'
    }
}
</script>

<style scoped>
.timeline-wrapper {
    overflow: hidden;
    transition: max-height .35s ease;
}

.timeline-wrapper.collapsed {
    max-height: 500px;
}

.timeline-wrapper.expanded {
    max-height: 3000px;
}
</style>
