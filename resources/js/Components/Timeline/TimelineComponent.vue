<template>
    <div class="flow-root" v-if="visibleItems.length">
        <ul role="list" class="-mb-5" v-auto-animate>
            <li v-for="(event, idx) in visibleItems" :key="event.id">
                <div class="relative pb-5">
                    <span v-if="idx !== visibleItems.length - 1"
                        class="absolute top-3 left-3 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-400"
                        aria-hidden="true" />
                    <div class="relative flex space-x-3">
                        <div>
                            <span
                                :class="[event.iconBackground, 'flex size-7 items-center justify-center rounded-full ring-2 ring-white dark:ring-slate-400']"
                                :style="event.iconStyle">
                                <component :is="event.icon" class="size-4 text-white" aria-hidden="true" />
                            </span>
                        </div>
                        <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                    <span v-html="event.rendered"></span>
                                </div>
                                <dl v-if="event.fieldChanges.length"
                                    class="mt-1 space-y-0.5 border-l-2 border-gray-200 pl-2 dark:border-slate-600">
                                    <div v-for="change in event.fieldChanges" :key="change.id"
                                        class="flex flex-wrap items-baseline gap-x-1.5 text-[11px] leading-snug">
                                        <dt class="text-gray-500 dark:text-slate-400">{{ change.label }}</dt>
                                        <dd class="text-gray-400 line-through dark:text-slate-500">
                                            {{ change.old_label ?? '—' }}
                                        </dd>
                                        <span class="text-gray-300 dark:text-slate-600">&rarr;</span>
                                        <dd class="font-medium text-gray-700 dark:text-slate-200">
                                            {{ change.new_label ?? '—' }}
                                        </dd>
                                    </div>
                                </dl>
                                <div v-if="event.actorName || event.actorType !== 'user'"
                                    class="mt-0.5 text-[11px] text-gray-400 dark:text-slate-500">
                                    {{ NAMED_ACTORS.includes(event.actorType) ? event.actorName : 'Systeem' }}
                                </div>
                                <div v-if="event.serviceOrderId" class="mt-0.5 text-[11px]">
                                    <a :href="`/serviceorders/${event.serviceOrderId}`"
                                       class="text-blue-500 hover:underline">
                                        Werkbon #{{ event.serviceOrderNumber }}
                                    </a>
                                </div>
                                <div v-if="event.executing_users?.length" class="mt-1 flex flex-wrap gap-2">
                                    <div v-for="u in event.executing_users" :key="u.id"
                                        class="inline-flex items-center gap-1">
                                        <img v-if="u.avatar" :src="u.avatar" :alt="u.name"
                                            class="h-4 w-4 rounded-full ring-1 ring-gray-300 object-cover" />
                                        <span v-else
                                            class="h-4 w-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[9px] font-medium ring-1 ring-gray-300">{{
                                                initials(u.name) }}</span>
                                        <span class="text-[11px] leading-none text-gray-600 dark:text-gray-400">{{
                                            u.name }}</span>
                                    </div>
                                </div>
                                <div v-if="event.thumbnailPath" class="mt-2">
                                    <img :src="`/files/images/${event.thumbnailImageId}`" alt=""
                                        class="h-16 w-24 object-cover rounded-md border border-gray-200 dark:border-slate-700" />
                                </div>
                            </div>
                            <div class="text-right text-xs whitespace-nowrap text-gray-500 dark:text-gray-400 relative">
                                <span class="absolute right-0 top-5">
                                    <ClockIcon v-if="event.is_event && !event.completed" class="size-4 text-blue-500" />
                                    <CheckIcon v-if="event.is_event && event.completed" class="size-4 text-green-600" />
                                </span>
                                <time :datetime="event.datetime">{{ event.date }}</time>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
        <div v-if="showToggle" class="mt-1 -mx-3">
            <button type="button" @click="toggle" :aria-expanded="expanded.toString()"
                class="group w-full flex items-center justify-start gap-2 text-xs font-medium text-indigo-600 px-3 py-2 rounded-md hover:bg-indigo-50 focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-1">
                <span class="select-none">{{ expanded ? 'Toon minder' : 'Toon alle ' + raw.length }}</span>
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
    <div v-else class="text-xs text-gray-500">Geen activiteiten</div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { CheckIcon, ExclamationTriangleIcon, ArrowUpTrayIcon, PencilSquareIcon, ChatBubbleLeftRightIcon, PlusIcon, WrenchScrewdriverIcon, TicketIcon, EnvelopeIcon, EllipsisHorizontalIcon, CalendarDaysIcon, ClockIcon, PhotoIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';
import { nlDate, nlTime, initials } from '@/Utilities/Utilities';

const props = defineProps({
    activities: { type: Array, required: true },
    limit: { type: Number, default: 5 }
});

/**
 * Wie er met naam en toenaam staat. Een klant die via een aanleverlink iets
 * opstuurt is geen gebruiker, maar hij is ook niet het systeem: zonder naam zou de
 * tijdlijn zeggen dat wij het zelf gedaan hebben. Werk van de assistent blijft wél
 * als systeem lezen — dat staat al op naam van wie het vroeg.
 */
const NAMED_ACTORS = ['user', 'customer'];

const CATEGORY_MAP = {
    created: { icon: PlusIcon, bg: 'bg-blue-500' },
    updated: { icon: PencilSquareIcon, bg: 'bg-amber-500' },
    comment: { icon: ChatBubbleLeftRightIcon, bg: 'bg-indigo-500' },
    status: { icon: CheckIcon, bg: 'bg-green-500' },
    warning: { icon: ExclamationTriangleIcon, bg: 'bg-red-500' },
    export: { icon: ArrowUpTrayIcon, bg: 'bg-fuchsia-500' },
    material: { icon: WrenchScrewdriverIcon, bg: 'bg-emerald-600' },
    ticket: { icon: TicketIcon, bg: 'bg-cyan-600' },
    email: { icon: EnvelopeIcon, bg: 'bg-purple-600' },
    event: { icon: CalendarDaysIcon, bg: 'bg-slate-500' },
    image: { icon: PhotoIcon, bg: 'bg-teal-500' },
    document: { icon: DocumentTextIcon, bg: 'bg-sky-600' },
    other: { icon: EllipsisHorizontalIcon, bg: 'bg-gray-400' },
};

const fallback = { icon: CheckIcon, bg: 'bg-gray-400' };

const formatDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) {
        return nlTime(d);
    }
    return nlDate(d) + ' ' + nlTime(d);
};

/**
 * Entries written by the event layer carry their changes as data, so the headline
 * only has to name the fields. The before and after values are rendered below it
 * rather than glued into a sentence.
 */
const summarise = (a) => {
    const changes = a.field_changes ?? [];
    if (!changes.length) return a.description;
    return changes.map(c => c.label).join(', ') + ' gewijzigd';
};

const raw = computed(() => props.activities.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));
const expanded = ref(false);
const showToggle = computed(() => raw.value.length > props.limit);
const visibleItems = computed(() => (expanded.value ? raw.value : raw.value.slice(0, props.limit)).map(a => {
    const meta = CATEGORY_MAP[a.category] || fallback;
    return {
        id: a.id,
        icon: meta.icon,
        iconBackground: meta.bg,
        iconStyle: a.color ? { backgroundColor: a.color } : undefined,
        rendered: a.rendered ?? summarise(a),
        fieldChanges: a.field_changes ?? [],
        user: a.user ?? null,
        actorName: a.actor_name ?? a.user?.name ?? null,
        actorType: a.actor_type ?? 'user',
        executing_users: a.executing_users || [],
        thumbnailPath: a.metadata?.thumbnail_path ?? null,
        is_event: a.category === 'event',
        completed: typeof a.status === 'string' ? a.status === 'Afgerond' : false,
        date: formatDate(a.created_at),
        datetime: a.created_at,
        serviceOrderId:     a.metadata?.service_order_id ?? null,
        serviceOrderNumber: a.metadata?.service_order_number ?? null,
    };
}));

const toggle = () => expanded.value = !expanded.value;
</script>
