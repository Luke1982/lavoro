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
                                <div v-if="event.byline" class="mt-0.5 text-[11px] text-gray-400 dark:text-slate-500">
                                    {{ event.byline }}
                                </div>
                                <div v-if="event.about" class="mt-0.5 text-[11px]">
                                    <a v-if="event.about.href" :href="event.about.href"
                                       class="text-blue-500 hover:underline">{{ event.about.label }}</a>
                                    <span v-else class="text-gray-500 dark:text-slate-400">{{ event.about.label }}</span>
                                </div>
                                <div v-if="event.serviceOrder" class="mt-0.5 text-[11px]">
                                    <a v-if="event.serviceOrder.linked" :href="`/serviceorders/${event.serviceOrder.id}`"
                                       class="text-blue-500 hover:underline">
                                        Werkbon {{ event.serviceOrder.number }}
                                    </a>
                                    <span v-else class="text-gray-500 dark:text-slate-400">Werkbon {{ event.serviceOrder.number }}</span>
                                </div>
                                <div v-if="event.executing_users?.length" class="mt-1 flex flex-wrap gap-2">
                                    <div v-for="u in event.executing_users" :key="u.id"
                                        class="inline-flex items-center gap-1">
                                        <img v-if="u.avatar" :src="u.avatar" :alt="u.name"
                                            class="h-4 w-4 rounded-full ring-1 ring-gray-300 dark:ring-slate-600 object-cover" />
                                        <span v-else
                                            class="h-4 w-4 rounded-full bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-400 flex items-center justify-center text-[9px] font-medium ring-1 ring-gray-300 dark:ring-slate-600">{{
                                                initials(u.name) }}</span>
                                        <span class="text-[11px] leading-none text-gray-600 dark:text-gray-400">{{
                                            u.name }}</span>
                                    </div>
                                </div>
                                <div v-if="event.thumbnailImageId" class="mt-2">
                                    <img :src="`/files/images/${event.thumbnailImageId}`" alt=""
                                        class="h-16 w-24 object-cover rounded-md border border-gray-200 dark:border-slate-700" />
                                </div>
                            </div>
                            <div class="text-right text-xs whitespace-nowrap text-gray-500 dark:text-gray-400 relative">
                                <span class="absolute right-0 top-5">
                                    <ClockIcon v-if="event.is_event && !event.completed" class="size-4 text-blue-500" />
                                    <CheckIcon v-if="event.is_event && event.completed" class="size-4 text-green-600 dark:text-green-400" />
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
                class="group w-full flex items-center justify-start gap-2 text-xs font-medium text-indigo-600 dark:text-indigo-400 px-3 py-2 rounded-md hover:bg-indigo-50 dark:hover:bg-indigo-900/20 focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-1">
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
    <div v-else class="text-xs text-gray-500 dark:text-slate-400">Geen activiteiten</div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { CheckIcon, FlagIcon, ExclamationTriangleIcon, ArrowUpTrayIcon, PencilSquareIcon, ChatBubbleLeftRightIcon, PlusIcon, WrenchScrewdriverIcon, TicketIcon, EnvelopeIcon, EllipsisHorizontalIcon, CalendarDaysIcon, ClockIcon, PhotoIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';
import { nlDate, nlTime, initials, formatWbNumber } from '@/Utilities/Utilities';

const SERVICE_ORDER = 'App\\Models\\ServiceOrder';

const props = defineProps({
    activities: { type: Array, required: true },
    limit: { type: Number, default: 5 },
    /**
     * On a page that gathers history from several werkbonnen, such as a customer,
     * each entry about one of them links to the werkbon it happened on.
     */
    linkServiceOrders: { type: Boolean, default: false },
    /**
     * The kind of record this page is about. An entry about another record -- a
     * machine on its customer, a task on its werkbon -- says which one it was.
     */
    subjectType: { type: String, default: null },
});

const SUBJECT_PAGES = {
    'App\\Models\\Asset': (id) => `/assets/${id}`,
    'App\\Models\\MaintenanceContract': (id) => `/maintenancecontracts/${id}`,
    'App\\Models\\MaintenanceContractTemplate': (id) => `/maintenancecontracttemplates/${id}`,
    'App\\Models\\Material': (id) => `/materials/${id}`,
    'App\\Models\\PriceFormula': (id) => `/priceformulas/${id}`,
    'App\\Models\\Product': (id) => `/products/${id}`,
    'App\\Models\\Project': (id) => `/projects/${id}`,
    'App\\Models\\Quote': (id) => `/quotes/${id}`,
    'App\\Models\\ServiceOrder': (id) => `/serviceorders/${id}`,
    'App\\Models\\Supplier': (id) => `/suppliers/${id}`,
    'App\\Models\\Ticket': (id) => `/tickets/${id}`,
    'App\\Models\\User': (id) => `/users/${id}/edit`,
};

/** The entry that says its record was deleted links nowhere: the page is gone. */
const isDeletion = (a) => typeof a.event_key === 'string' && a.event_key.endsWith('.deleted');

/**
 * The record an entry hangs off when it is not this page's own, by the name it had
 * when it happened. A werkbon already named below is not named twice.
 */
function aboutOf(a, serviceOrder) {
    const label = a.metadata?.subject_label;

    if (!label || a.subject_type === props.subjectType) return null;
    if (serviceOrder && a.subject_type === SERVICE_ORDER && serviceOrder.id === a.subject_id) return null;

    const page = SUBJECT_PAGES[a.subject_type];

    return { label, href: page && !isDeletion(a) ? page(a.subject_id) : null };
}

/** The werkbon an entry names in its metadata, or, where asked, the werkbon it is about. */
function serviceOrderOf(a) {
    if (props.subjectType === SERVICE_ORDER) return null;

    const id = a.metadata?.service_order_id
        ?? (props.linkServiceOrders && a.subject_type === SERVICE_ORDER ? a.subject_id : null);

    if (!id) return null;

    const deleted = a.subject_type === SERVICE_ORDER && a.subject_id === id && isDeletion(a);

    return { id, number: a.metadata?.service_order_number ?? formatWbNumber(id), linked: !deleted };
}

/**
 * Wie er met naam en toenaam staat. Een klant die via een aanleverlink iets
 * opstuurt is geen gebruiker, maar hij is ook niet het systeem: zonder naam zou de
 * tijdlijn zeggen dat wij het zelf gedaan hebben. Werk van de assistent blijft wél
 * als systeem lezen — dat staat al op naam van wie het vroeg.
 */
const NAMED_ACTORS = ['user', 'customer'];

/**
 * Who did it and, when it came off the bakje, how: "Sanne de Vries · via het
 * bakje". An ordinary entry by a person shows no line when their name is gone.
 */
const byline = (a) => {
    const actorType = a.actor_type ?? 'user';
    const actorName = a.actor_name ?? a.user?.name ?? null;
    const actor = actorName || actorType !== 'user'
        ? (NAMED_ACTORS.includes(actorType) ? actorName : 'Systeem')
        : null;

    return [actor, a.via === 'tray' ? 'via het bakje' : null].filter(Boolean).join(' · ');
};

const CATEGORY_MAP = {
    created: { icon: PlusIcon, bg: 'bg-blue-500' },
    updated: { icon: PencilSquareIcon, bg: 'bg-amber-500' },
    update: { icon: PencilSquareIcon, bg: 'bg-amber-500' },
    comment: { icon: ChatBubbleLeftRightIcon, bg: 'bg-indigo-500' },
    status: { icon: CheckIcon, bg: 'bg-green-500' },
    stage: { icon: FlagIcon, bg: 'bg-violet-500' },
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
 * A plain edit carries its changes as data, so its headline only has to name the
 * fields; the before and after values are rendered below it rather than glued into
 * a sentence. A named fact keeps its own sentence -- "Keuring heropend" says more
 * than the fields it touched -- with the same rows underneath.
 */
const summarise = (a) => {
    const changes = a.field_changes ?? [];
    const plain_edit = typeof a.event_key === 'string' && a.event_key.endsWith('.updated');
    if (!changes.length || !plain_edit) return a.description;
    return changes.map(c => c.label).join(', ') + ' gewijzigd';
};

const raw = computed(() => props.activities.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));
const expanded = ref(false);
const showToggle = computed(() => raw.value.length > props.limit);
const visibleItems = computed(() => (expanded.value ? raw.value : raw.value.slice(0, props.limit)).map(a => {
    const meta = CATEGORY_MAP[a.category] || fallback;
    const serviceOrder = serviceOrderOf(a);
    return {
        id: a.id,
        icon: meta.icon,
        iconBackground: meta.bg,
        iconStyle: a.color ? { backgroundColor: a.color } : undefined,
        rendered: a.rendered ?? summarise(a),
        fieldChanges: a.field_changes ?? [],
        user: a.user ?? null,
        byline: byline(a),
        executing_users: a.executing_users || [],
        thumbnailImageId: a.metadata?.thumbnail_image_id ?? null,
        is_event: a.category === 'event',
        completed: typeof a.status === 'string' ? a.status === 'Afgerond' : false,
        date: formatDate(a.created_at),
        datetime: a.created_at,
        serviceOrder,
        about: aboutOf(a, serviceOrder),
    };
}));

const toggle = () => expanded.value = !expanded.value;
</script>
