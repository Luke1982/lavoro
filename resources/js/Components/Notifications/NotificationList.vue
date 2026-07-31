<template>
    <div>
        <p v-if="loading && !groups.length" class="px-4 py-8 text-center text-sm text-sidebar-muted">Bezig met laden…</p>
        <p v-else-if="!groups.length" class="px-4 py-8 text-center text-sm text-sidebar-muted">{{ emptyLabel }}</p>

        <div v-for="group in groups" :key="group.label">
            <p class="px-4 pt-4 pb-2 text-[13px] font-semibold text-sidebar-muted">{{ group.label }}</p>

            <button v-for="notification in group.items" :key="notification.id" type="button"
                @click="$emit('follow', notification)" :class="[
                    'flex w-full items-start gap-x-3 rounded-xl px-3 py-3 text-left transition-colors',
                    notification.read_at ? 'hover:bg-sidebar-hover' : 'bg-sidebar-card hover:bg-sidebar-hover',
                ]">
                <!--
                    Het bolletje staat links, zoals in het ontwerp, en is meteen de
                    knop om iets weer ongelezen te maken: wat je per ongeluk
                    wegstreept moet terug kunnen zonder een tweede knop ernaast.
                -->
                <span role="button" tabindex="0" class="mt-4 flex size-3 shrink-0 items-center justify-center"
                    :aria-label="notification.read_at ? 'Markeer als ongelezen' : 'Markeer als gelezen'"
                    @click.stop="$emit('acknowledge', notification)"
                    @keydown.enter.stop.prevent="$emit('acknowledge', notification)">
                    <span class="size-2 rounded-full transition-colors"
                        :class="notification.read_at ? 'bg-transparent' : 'bg-lavoro-blue'"></span>
                </span>

                <span class="relative flex size-10 shrink-0 items-center justify-center rounded-full"
                    :class="colorClass(notification.color)">
                    <component :is="navIcon(notification.icon)" v-if="navIcon(notification.icon)" class="size-5" />
                    <Bell v-else class="size-5" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="flex items-baseline gap-x-2">
                        <span class="flex-1 truncate text-sm font-semibold text-sidebar-text">{{ notification.title }}</span>
                        <span class="shrink-0 text-[11px] text-sidebar-muted">{{ stamp(notification.created_at) }}</span>
                    </span>
                    <span class="mt-0.5 line-clamp-2 block text-[13px] leading-snug text-sidebar-muted">{{
                        notification.body }}</span>
                </span>

            </button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import dayjs from 'dayjs'
import { Bell } from '@lucide/vue'
import { navIcon } from '@/Navigation/icons.js'
import { nlDate, nlTime } from '@/Utilities/Utilities'

/**
 * De lijst zelf, zonder te weten of hij in een uitklapper of op een hele pagina
 * staat. Groepeert op dag, want een reeks losse tijdstippen leest als ruis.
 */
const props = defineProps({
    items: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    emptyLabel: { type: String, default: 'Geen meldingen.' },
})

defineEmits(['follow', 'acknowledge'])

const COLORS = {
    blue: 'bg-blue-500/15 text-blue-400',
    amber: 'bg-amber-500/15 text-amber-400',
    green: 'bg-emerald-500/15 text-emerald-400',
    purple: 'bg-violet-500/15 text-violet-400',
    red: 'bg-red-500/15 text-red-400',
}

const colorClass = (color) => COLORS[color] ?? COLORS.blue

/**
 * Op de dag zoals hier gelezen wordt, niet zoals de server hem in UTC opschrijft:
 * een melding van half elf 's avonds hoort onder Vandaag en niet onder morgen.
 */
const dayKey = (value) => dayjs(value).format('YYYY-MM-DD')

const dayLabel = (value) => {
    if (dayKey(value) === dayjs().format('YYYY-MM-DD')) return 'Vandaag'
    if (dayKey(value) === dayjs().subtract(1, 'day').format('YYYY-MM-DD')) return 'Gisteren'

    return nlDate(value)
}

/** Van vandaag is het uur genoeg; ouder dan dat zegt de datum meer. */
const stamp = (value) => (dayKey(value) === dayjs().format('YYYY-MM-DD') ? nlTime(value) : nlDate(value))

const groups = computed(() => {
    const buckets = new Map()

    props.items.forEach((notification) => {
        const key = dayKey(notification.created_at)
        if (!buckets.has(key)) buckets.set(key, { label: dayLabel(notification.created_at), items: [] })
        buckets.get(key).items.push(notification)
    })

    return [...buckets.values()]
})
</script>
