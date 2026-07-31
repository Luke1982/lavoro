<template>
    <div>
        <p v-if="loading && !groups.length" class="px-4 py-8 text-center text-sm" :class="skin.muted">Bezig met laden…</p>
        <p v-else-if="!groups.length" class="px-4 py-8 text-center text-sm" :class="skin.muted">{{ emptyLabel }}</p>

        <div v-for="group in groups" :key="group.label">
            <p class="px-4 pt-4 pb-2 text-[13px] font-semibold" :class="skin.muted">{{ group.label }}</p>

            <button v-for="notification in group.items" :key="notification.id" type="button"
                @click="$emit('follow', notification)" :class="[
                    'flex w-full items-start gap-x-3 rounded-xl px-3 py-3 text-left transition-colors',
                    notification.read_at ? skin.read : skin.unread,
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
                        <span class="flex-1 truncate text-sm font-semibold" :class="skin.title">{{ notification.title
                            }}</span>
                        <span class="shrink-0 text-[11px]" :class="skin.muted">{{ stamp(notification.created_at)
                            }}</span>
                    </span>
                    <span class="mt-0.5 line-clamp-2 block text-[13px] leading-snug" :class="skin.muted">{{
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
 * De lijst zelf. Groepeert op dag, want een reeks losse tijdstippen leest als ruis.
 *
 * Waar hij hangt bepaalt zijn kleuren en verder niets. Aan het belletje hangt hij
 * onder het donkere menu en is hij zelf donker, op een lichte pagina net zo goed;
 * op een eigen pagina hoort hij bij de pagina en volgt hij het thema van de
 * browser. Dat is één keuze, dus één prop, en geen tweede component.
 */
const props = defineProps({
    items: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    emptyLabel: { type: String, default: 'Geen meldingen.' },
    tone: { type: String, default: 'panel' },
})

defineEmits(['follow', 'acknowledge'])

const TONES = {
    panel: {
        title: 'text-sidebar-text',
        muted: 'text-sidebar-muted',
        unread: 'bg-sidebar-card hover:bg-sidebar-hover',
        read: 'hover:bg-sidebar-hover',
        colors: {
            blue: 'bg-blue-500/15 text-blue-400',
            amber: 'bg-amber-500/15 text-amber-400',
            green: 'bg-emerald-500/15 text-emerald-400',
            purple: 'bg-violet-500/15 text-violet-400',
            red: 'bg-red-500/15 text-red-400',
        },
    },
    page: {
        title: 'text-gray-900 dark:text-slate-100',
        muted: 'text-gray-500 dark:text-slate-400',
        unread: 'bg-gray-50 hover:bg-gray-100 dark:bg-slate-800/60 dark:hover:bg-slate-800',
        read: 'hover:bg-gray-50 dark:hover:bg-slate-800/60',
        colors: {
            blue: 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
            amber: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
            green: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
            purple: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
            red: 'bg-red-500/15 text-red-600 dark:text-red-400',
        },
    },
}

const skin = computed(() => TONES[props.tone] ?? TONES.panel)

const colorClass = (color) => skin.value.colors[color] ?? skin.value.colors.blue

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
