<template>
    <BoxComponent padding="p-4 sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <div
                    class="flex size-12 flex-none items-center justify-center rounded-lavoro-sm bg-lavoro-blue/10 text-lavoro-blue dark:text-sky-400">
                    <Building2 class="size-6" />
                </div>
                <div class="min-w-0">
                    <component :is="canOpenCustomer ? Link : 'p'"
                        :href="canOpenCustomer ? '/customers/' + customer.id : undefined"
                        class="block text-lg font-bold leading-tight text-gray-900 dark:text-slate-100">
                        {{ customer.name }}
                    </component>
                    <template v-if="address">
                        <p class="mt-1 flex items-start gap-1.5 text-sm text-gray-700 dark:text-slate-300">
                            <MapPin class="mt-0.5 size-4 flex-none text-gray-400" />
                            {{ address }}
                        </p>
                        <p v-if="addressReason" class="mt-0.5 pl-5.5 text-xs text-gray-400 dark:text-slate-500">
                            {{ addressReason }}
                        </p>
                    </template>
                    <p v-else class="mt-1 text-sm text-gray-400 dark:text-slate-500">Geen adres bekend</p>
                </div>
            </div>
            <div v-if="address || customerPhone" class="flex flex-none gap-2">
                <a v-if="address" :href="mapsLinkFromAddress(address)" target="_blank" rel="noopener"
                    :class="QUICK_ACTION">
                    <Navigation class="size-4" />
                    Navigeer
                    <ExternalLink class="size-3.5 opacity-70" />
                </a>
                <a v-if="customerPhone" :href="phoneLink(customerPhone)" :title="customerPhone" :class="QUICK_ACTION">
                    <Phone class="size-4" />
                    Bel klant
                </a>
            </div>
        </div>

        <div class="mt-4 rounded-lavoro-sm bg-gray-50 p-3 dark:bg-slate-800/60">
            <div ref="slideEl" class="relative touch-pan-y select-none overflow-hidden" @pointerdown.capture="onPointerDown"
                @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerUp"
                @click.capture="onClickCapture">
                <Transition :name="slideDirection">
                    <div :key="index" :style="slideStyle"
                        :class="dragging ? '' : 'transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]'">
                        <div class="flex items-start gap-3">
                            <CalendarDays class="mt-0.5 size-5 flex-none text-lavoro-blue dark:text-sky-400" />
                            <div v-if="selected" class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ when }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-slate-400">{{ eventTitle(selected) }}</p>
                            </div>
                            <p v-else class="flex-1 text-sm text-gray-500 dark:text-slate-400">Nog niet ingepland</p>
                        </div>

                        <template v-if="ownState">
                            <div class="mt-3 pl-8">
                                <BadgeComponent v-if="ownState === 'open'" color="orange">Geen tijden ingevuld</BadgeComponent>
                                <BadgeComponent v-else-if="ownState === 'cancelled'" color="gray" :has-dot="false">
                                    Je hebt deze afspraak geannuleerd
                                </BadgeComponent>
                                <p v-else class="text-xs text-gray-600 dark:text-slate-300">Jouw tijden: {{ ownTimes }}</p>
                            </div>
                            <EventExecutionControls labelled class="mt-3 flex-wrap justify-between empty:hidden"
                                :event="executionEvent" :user-id="currentUserId" @changed="onChanged" />
                        </template>
                    </div>
                </Transition>
            </div>

            <div v-if="events.length > 1"
                class="mt-3 flex items-center justify-between rounded-full bg-white ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
                <button type="button" class="p-2 text-gray-500 disabled:opacity-30 dark:text-slate-400"
                    :disabled="index === 0" aria-label="Vorige afspraak" @click="go(-1)">
                    <ChevronLeft class="size-4" />
                </button>
                <span class="text-xs text-gray-600 dark:text-slate-300">
                    Afspraak {{ index + 1 }} van {{ events.length }}
                </span>
                <button type="button" class="p-2 text-gray-500 disabled:opacity-30 dark:text-slate-400"
                    :disabled="index === events.length - 1" aria-label="Volgende afspraak" @click="go(1)">
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Building2, CalendarDays, ChevronLeft, ChevronRight, ExternalLink, MapPin, Navigation, Phone } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import EventExecutionControls from '@/Components/Planner/EventExecutionControls.vue'
import { eventTitle, hasPermission, locationSourceLabel, mapsLinkFromAddress, nlTime, phoneLink } from '@/Utilities/Utilities'
import { useServiceOrderEvents } from '@/Composables/useServiceOrderEvents.js'
import { useSwipe } from '@/Composables/useSwipe.js'

/**
 * Waar de monteur heen moet, wanneer, en welke tijden er voor die afspraak staan.
 *
 * Het adres hoort bij de afspraak die je bekijkt: een afspraak kan een eigen
 * locatie hebben, dus bladeren naar een andere afspraak kan je ook naar een
 * ander adres sturen. Zonder afspraak geldt het adres van de werkbon zelf.
 */
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    customer: { type: Object, required: true },
    initialEvents: { type: Array, default: () => [] },
    /** Het adres van de werkbon zelf, voor als er geen afspraak is om het van te nemen. */
    fallbackLocation: { type: Object, default: () => ({ address: null, source: null }) },
})

const emit = defineEmits(['times-updated'])

const currentUserId = usePage().props.auth?.user?.id ?? null
const canOpenCustomer = hasPermission('customer.read')

const QUICK_ACTION = 'inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lavoro-sm bg-lavoro-blue/10 px-4 py-2.5 text-sm font-semibold text-lavoro-blue transition-colors hover:bg-lavoro-blue/15 sm:flex-none dark:text-sky-300'

/** Op locatie wil je iemand aan de lijn, dus het mobiele nummer gaat voor het vaste. */
const customerPhone = computed(() => props.customer.mobile || props.customer.phone || null)

const { events, refresh } = useServiceOrderEvents(() => props.serviceOrderId, () => props.initialEvents)

/** De afspraak van vandaag of de eerstvolgende; liggen ze allemaal achter ons, dan de laatste. */
function startingIndex(list) {
    const now = Date.now()
    const upcoming = list.findIndex(event => new Date(event.end ?? event.start).getTime() >= now)

    return upcoming >= 0 ? upcoming : Math.max(0, list.length - 1)
}

const index = ref(startingIndex(events.value))

watch(() => events.value.length, (length) => {
    if (index.value > length - 1) index.value = startingIndex(events.value)
})

const selected = computed(() => events.value[index.value] ?? null)

/**
 * Bladeren door de afspraken, met de pijltjes of door de strook opzij te vegen.
 * Naar links haalt de volgende binnen, naar rechts de vorige, net als de
 * hoofdstukken van een pagina. Aan het eind geeft de strook nog maar een kwart
 * mee en veert terug: daar is niets meer.
 */
const slideEl = ref(null)
const slideDirection = ref('slide-left')

function go(step) {
    const next = index.value + step

    if (next < 0 || next >= events.value.length) return

    slideDirection.value = step > 0 ? 'slide-left' : 'slide-right'
    index.value = next
}

const { dragging, offset, onPointerDown, onPointerMove, onPointerUp, onClickCapture } =
    useSwipe(slideEl, (direction) => go(direction === 'left' ? 1 : -1))

const slideStyle = computed(() => {
    if (!dragging.value) return {}

    const at_end = offset.value < 0 ? index.value >= events.value.length - 1 : index.value <= 0

    return { transform: 'translateX(' + offset.value * (at_end ? 0.25 : 1) + 'px)' }
})

const address = computed(() => selected.value ? selected.value.location : props.fallbackLocation.address)
const addressReason = computed(() => locationSourceLabel(
    selected.value ? selected.value.location_source : props.fallbackLocation.source
))

function dayLabel(date) {
    const midnight = (value) => new Date(value).setHours(0, 0, 0, 0)
    const days_away = Math.round((midnight(date) - midnight(Date.now())) / 86400000)
    const nearby = { '-1': 'Gisteren', 0: 'Vandaag', 1: 'Morgen' }

    return nearby[days_away]
        ?? new Date(date).toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' })
}

/** "Vandaag 10:00 – 12:00", of met beide dagen erbij als de afspraak over middernacht loopt. */
const when = computed(() => {
    const { start, end } = selected.value ?? {}
    if (!start) return ''

    const from = dayLabel(start) + ' ' + nlTime(start)
    if (!end) return from

    const end_day = dayLabel(end)

    return from + ' – ' + (end_day === dayLabel(start) ? '' : end_day + ' ') + nlTime(end)
})

const ownExecution = computed(() =>
    selected.value?.executing_users?.find(user => user.id === currentUserId) ?? null
)

/** Waar jij staat met deze afspraak: nog te doen, geannuleerd, of je tijden staan erin. */
const ownState = computed(() => {
    const execution = ownExecution.value

    if (!execution) return null
    if (execution.completion_status === 'Geannuleerd') return 'cancelled'

    return execution.actual_start && execution.actual_end ? 'registered' : 'open'
})

/** "08:02 – 16:10 · 30 min pauze · 20 min reistijd", zonder wat nul is. */
const ownTimes = computed(() => {
    const execution = ownExecution.value

    return ownState.value !== 'registered' ? '' : [
        nlTime(execution.actual_start) + ' – ' + nlTime(execution.actual_end),
        execution.actual_breaktime_minutes && execution.actual_breaktime_minutes + ' min pauze',
        execution.travel_time_minutes && execution.travel_time_minutes + ' min reistijd',
    ].filter(Boolean).join(' · ')
})

const executionEvent = computed(() => selected.value && {
    id: selected.value.id,
    start: new Date(selected.value.start),
    end: new Date(selected.value.end),
    executing_users: selected.value.executing_users,
})

async function onChanged() {
    emit('times-updated', await refresh())
}
</script>
