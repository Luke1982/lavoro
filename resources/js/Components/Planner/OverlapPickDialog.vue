<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="relative z-50" @close="$emit('cancel')">
            <TransitionChild as="template"
                enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <DialogPanel
                            class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div
                                        class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/40 sm:mx-0 sm:size-10">
                                        <CircleAlert class="size-6 text-red-600 dark:text-red-400" aria-hidden="true" />
                                    </div>
                                    <div class="mt-3 min-w-0 flex-1 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                        <DialogTitle as="h3"
                                            class="text-base font-semibold text-gray-900 dark:text-slate-100">
                                            Overlappende afspraken
                                        </DialogTitle>
                                        <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">
                                            Je klikte op een overlap tussen {{ countLabel }} afspraken. Welke wilde je
                                            openen?
                                        </p>
                                        <div class="mt-4 flex flex-col gap-2">
                                            <button v-for="event in sortedEvents" :key="event.id" type="button"
                                                class="w-full rounded-md border border-l-4 border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-left hover:border-lavoro-blue dark:hover:border-lavoro-blue transition"
                                                :style="{ borderLeftColor: leadingColorFor(event) }"
                                                @click="$emit('select', event)">
                                                <div
                                                    class="text-sm font-semibold leading-tight truncate text-gray-900 dark:text-slate-100">
                                                    #{{ event.id }} {{ event.customer_name || event.name || event.title }}
                                                </div>
                                                <div
                                                    class="mt-0.5 flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300">
                                                    <ClockIcon class="size-3 shrink-0" />
                                                    <span>{{ timeLabel(event) }}</span>
                                                </div>
                                                <div
                                                    class="mt-0.5 flex items-center gap-1 text-xs text-gray-500 dark:text-slate-400">
                                                    <MapPinIcon class="size-3 shrink-0" />
                                                    <span class="truncate">{{ event.location || 'Geen locatie' }}</span>
                                                </div>
                                                <div
                                                    class="mt-0.5 flex items-center gap-1 text-xs text-gray-500 dark:text-slate-400">
                                                    <BuildingOfficeIcon class="size-3 shrink-0" />
                                                    <span v-if="event.eventable_id">
                                                        WB-{{ String(event.eventable_id).padStart(4, '0') }}
                                                    </span>
                                                    <span v-else>Geen werkbon</span>
                                                </div>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-slate-900/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                                <button type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-800 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-slate-100 shadow-xs ring-1 ring-inset ring-gray-300 dark:ring-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 sm:w-auto"
                                    @click="$emit('cancel')">
                                    Annuleren
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { computed } from 'vue'
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { CircleAlert } from '@lucide/vue'
import { ClockIcon, MapPinIcon, BuildingOfficeIcon } from '@heroicons/vue/24/outline'
import { effectiveMinutesFor, formatTimeLabel } from '@/Utilities/plannerOverlaps'
import { useEventLeadingColor } from '@/Composables/useEventLeadingColor'

const { resolveLeadingColor, firstRoleColor } = useEventLeadingColor()

const props = defineProps({
    open: { type: Boolean, required: true },
    events: { type: Array, default: () => [] },
    /** Lane the overlap was clicked in — decides which diverging times apply */
    userId: { type: Number, default: null },
    userRoles: { type: Array, default: () => [] },
    leadingColor: { type: String, default: 'event' },
})

defineEmits(['select', 'cancel'])

const countLabel = computed(() => props.events.length === 2 ? 'twee' : 'meerdere')

// Listed left to right as the lane draws them, so the first button is the card
// that starts first.
const sortedEvents = computed(() => [...props.events].sort((a, b) =>
    effectiveMinutesFor(a, props.userId, 0).startMin - effectiveMinutesFor(b, props.userId, 0).startMin
))

function executingUser(event) {
    return event.executing_users?.find(u => u.id === props.userId) ?? null
}

function timeLabel(event) {
    const { startMin, endMin } = effectiveMinutesFor(event, props.userId, 0)
    return `${formatTimeLabel(startMin)} – ${formatTimeLabel(endMin)}`
}

// Mirrors the card so the accent points at the box the user was aiming for.
function leadingColorFor(event) {
    const executing = executingUser(event)
    return resolveLeadingColor({
        eventColor: event.color,
        roleColor: firstRoleColor(executing?.user_role_ids, props.userRoles),
        leadingColor: props.leadingColor,
        isClosed: executing?.completion_status === 'Afgerond',
    })
}
</script>
