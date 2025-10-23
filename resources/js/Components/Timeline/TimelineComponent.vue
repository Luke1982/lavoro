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
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400" v-html="event.rendered"></p>
                            </div>
                            <div class="text-right text-xs whitespace-nowrap text-gray-500 dark:text-gray-400">
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
import { CheckIcon, ExclamationTriangleIcon, ArrowUpTrayIcon, PencilSquareIcon, ChatBubbleLeftRightIcon, PlusIcon, WrenchScrewdriverIcon, TicketIcon, EnvelopeIcon, EllipsisHorizontalIcon, CalendarDaysIcon } from '@heroicons/vue/24/outline';
import { nlDate, nlTime } from '@/Utilities/Utilities';

const props = defineProps({
    activities: { type: Array, required: true }, // array of activity models
    limit: { type: Number, default: 5 }
});

// Map category => icon + background color
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
    other: { icon: EllipsisHorizontalIcon, bg: 'bg-gray-400' },
};

// Fallback icon
const fallback = { icon: CheckIcon, bg: 'bg-gray-400' };

// Format: if today => time only, else full NL date + time
const formatDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) {
        return nlTime(d);
    }
    return nlDate(d) + ' ' + nlTime(d);
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
        rendered: a.rendered ?? a.description,
        date: formatDate(a.created_at),
        datetime: a.created_at
    };
}));

const toggle = () => expanded.value = !expanded.value;
</script>
