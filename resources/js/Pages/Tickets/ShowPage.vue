<template>
    <div class="flex items-center mb-6">
        <Link href="/tickets" class="text-slate-400 text-sm font-medium">Storingen</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2 flex-none" />
        <span class="text-slate-800 dark:text-slate-200 font-bold text-sm">Ticket voor s/n #{{
            ticket.asset.serial_number }}</span>
    </div>

    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent class="mb-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-center gap-4 min-w-0">
                        <!-- Tile colour tracks the status, so the ticket's state reads from
                             across the room and agrees with the badge beside the title. -->
                        <div
                            :class="['flex-none flex items-center justify-center size-16 rounded-lavoro-sm', statusTileClasses.bg]">
                            <Siren :class="['size-8 stroke-2', statusTileClasses.text]" />
                        </div>
                        <div class="min-w-0 flex items-center gap-3 flex-wrap">
                            <h1 class="text-2xl font-bold dark:text-slate-100">{{ ticket.subject }}</h1>
                            <BadgeComponent :color="statusBadgeColor" :hasDot="false">{{ ticket.status }}</BadgeComponent>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-none">
                        <Link v-if="ticket.service_order_id && hasPermission('serviceorder.read')"
                            :href="`/serviceorders/${ticket.service_order_id}`"
                            class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-lavoro-blue hover:opacity-90 rounded-md transition-opacity cursor-pointer">
                            Werkbon openen
                            <ArrowTopRightOnSquareIcon class="size-4" />
                        </Link>
                        <DropdownMenu v-if="hasPermission('ticket.delete')" placement="bottom-end" width-class="w-56"
                            button-class="inline-flex items-center justify-center size-9 rounded-lavoro-sm border border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800 cursor-pointer"
                            title="Meer acties">
                            <template #button>
                                <EllipsisHorizontalIcon class="size-5" />
                            </template>
                            <MenuItem v-slot="{ active }">
                            <button type="button" @click="deleteTicket"
                                :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 cursor-pointer', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                                <TrashIcon class="size-4" />
                                Storing verwijderen
                            </button>
                            </MenuItem>
                        </DropdownMenu>
                    </div>
                </div>

                <!-- Full card width: beside the title these wrapped one item per line. -->
                <div class="flex flex-wrap gap-x-6 gap-y-2 mt-4">
                    <div class="flex items-center gap-1.5">
                        <ClockIcon class="size-4 text-slate-400 flex-none" />
                        <span class="text-xs text-slate-400">Prioriteit</span>
                        <component :is="priorityTrend.icon" :class="['size-4 flex-none', priorityTrend.class]" />
                        <span class="text-xs font-semibold text-slate-700 dark:text-slate-200">{{ ticket.priority
                            }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <CalendarIcon class="size-4 text-slate-400 flex-none" />
                        <span class="text-xs text-slate-400">Aangemaakt</span>
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{
                            nlDate(ticket.created_at) }} om {{ nlTime(ticket.created_at) }}</span>
                    </div>
                    <div v-if="ticket.created_by" class="flex items-center gap-1.5">
                        <UserIcon class="size-4 text-slate-400 flex-none" />
                        <span class="text-xs text-slate-400">Aangemaakt door</span>
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{
                            ticket.created_by.name }}</span>
                        <BadgeComponent v-if="ticket.created_by.deleted_at" color="gray" :hasDot="false"
                            class="!px-2 !py-0.5 text-xs">Gedeactiveerd</BadgeComponent>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <ClockIcon class="size-4 text-slate-400 flex-none" />
                        <span class="text-xs text-slate-400">Laatst bijgewerkt</span>
                        <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{
                            nlDate(ticket.updated_at) }} om {{ nlTime(ticket.updated_at) }}</span>
                    </div>
                </div>
            </BoxComponent>
            <BoxComponent>
                <SectionHeader :icon="ExclamationCircleIcon" title="Details van de storing"
                    subtitle="Machine, prioriteit en omschrijving van deze storing." chapter="details" />

                <!-- Each row carries the field's own icon tile, matching the treatment on
                     the customer and contact show pages. -->
                <div class="divide-y divide-gray-100 dark:divide-slate-700/60">
                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <PuzzlePieceIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Storing aan</span>
                        </div>
                        <div
                            class="col-span-12 sm:col-span-8 text-sm text-gray-800 dark:text-slate-200 flex flex-wrap items-center gap-x-1">
                            <span>{{ ticket.asset.product.brand.name }}</span>
                            <component :is="hasPermission('product.read') ? Link : 'span'"
                                :href="`/products/${ticket.asset.product.id}`"
                                :class="hasPermission('product.read') ? 'text-lavoro-blue hover:opacity-80 underline' : ''">
                                {{ ticket.asset.product.model }}
                            </component>
                            <span>({{ ticket.asset.product.product_type.name }})</span>
                            <component :is="hasPermission('asset.read') ? Link : 'span'"
                                :href="`/assets/${ticket.asset.id}`"
                                :class="hasPermission('asset.read') ? 'text-lavoro-blue hover:opacity-80 underline' : ''">
                                {{ ticket.asset.serial_number }}
                            </component>
                            <span class="text-slate-400">bij</span>
                            <component :is="hasPermission('customer.read') ? Link : 'span'"
                                :href="`/customers/${ticket.asset.customer.id}`"
                                :class="hasPermission('customer.read') ? 'text-lavoro-blue hover:opacity-80 underline' : ''">
                                {{ ticket.asset.customer.name }}
                            </component>
                            <span v-if="ticket.asset.linked_location">(<component
                                    :is="hasPermission('location.read') ? Link : 'span'"
                                    :href="`/locations/${ticket.asset.linked_location.id}`"
                                    :class="hasPermission('location.read') ? 'text-lavoro-blue hover:opacity-80 underline' : ''"
                                >{{ ticket.asset.linked_location.title }}</component>)</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <ChatBubbleBottomCenterTextIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Onderwerp</span>
                        </div>
                        <div class="col-span-12 sm:col-span-8">
                            <EditableTextField v-model="form.subject" class="w-full" :bordered="false"
                                :readonly="!hasPermission('ticket.update')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <DocumentTextIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Omschrijving</span>
                        </div>
                        <div class="col-span-12 sm:col-span-8">
                            <EditableTextField v-model="form.description" type="textarea" class="w-full"
                                :bordered="false" :readonly="!hasPermission('ticket.update')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <HashtagIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Storingscode</span>
                        </div>
                        <div class="col-span-12 sm:col-span-8">
                            <EditableTextField v-model="form.status_code" class="w-full" :bordered="false"
                                placeholder="Nog niet ingesteld"
                                :readonly="!hasPermission('ticket.update')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <ArrowPathIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Status</span>
                        </div>
                        <div class="col-span-12 sm:col-span-8">
                            <EditableTextField type="combobox" v-model="form.status" :options="statusses"
                                class="w-full" :bordered="false" indicator="select"
                                :readonly="!hasPermission('ticket.change_status')" :error="form.errors.status"
                                @revert="form.clearErrors('status')">
                                <template #display>
                                    <BadgeComponent :color="statusBadgeColor" :hasDot="false">{{ ticket.status }}
                                    </BadgeComponent>
                                </template>
                            </EditableTextField>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <FlagIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Prioriteit</span>
                        </div>
                        <div class="col-span-12 sm:col-span-8">
                            <fieldset aria-label="Kies een prioriteit" v-if="hasPermission('ticket.alter_priority')">
                                <!-- One indicator that slides between segments, rather than a
                                     background toggling on each label. Segments are equal
                                     width, so a whole-width translate lands exactly on one. -->
                                <div
                                    class="relative flex w-full max-w-md rounded-lavoro-sm border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-1">
                                    <span v-if="priorityIndex >= 0" aria-hidden="true"
                                        class="absolute inset-y-1 left-1 rounded-lavoro-sm bg-slate-900 dark:bg-slate-100 transition-transform duration-300 ease-out motion-reduce:transition-none"
                                        :style="prioritySliderStyle" />
                                    <label v-for="prio in priorities" :key="prio.id" :aria-label="prio.name" :class="[
                                        'relative z-10 flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-lavoro-sm px-3 py-2 text-sm font-medium transition-colors duration-300',
                                        prio.id === form.priority
                                            ? 'text-white dark:text-slate-900'
                                            : 'text-gray-600 dark:text-slate-300'
                                    ]">
                                        <input type="radio" name="priority" :value="prio.id" v-model="form.priority"
                                            class="absolute inset-0 appearance-none focus:outline-none" />
                                        <span :class="['size-2 flex-none rounded-full', priorityDotClass(prio.name)]" />
                                        <span class="truncate">{{ prio.name }}</span>
                                    </label>
                                </div>
                            </fieldset>
                            <BadgeComponent v-else :color="priorityBadgeColor" :hasDot="false">{{ ticket.priority }}
                            </BadgeComponent>
                        </div>
                    </div>

                    <div v-if="ticket.closed_by" class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-4 flex items-center gap-3 min-w-0">
                            <div
                                class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                <UserIcon class="size-5 text-lavoro-blue stroke-2" />
                            </div>
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Gesloten door</span>
                        </div>
                        <div
                            class="col-span-12 sm:col-span-8 text-sm text-gray-800 dark:text-slate-200 flex items-center gap-1.5">
                            {{ ticket.closed_by.name }}
                            <BadgeComponent v-if="ticket.closed_by.deleted_at" color="gray" :hasDot="false"
                                class="!px-2 !py-0.5 text-xs">Gedeactiveerd</BadgeComponent>
                        </div>
                    </div>
                </div>

                <CustomFieldsComponent v-if="customFields.length" model-type="ticket" :model-id="ticket.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-4" />
            </BoxComponent>

            <BoxComponent v-if="ticket.service_order" class="mt-4">
                <SectionHeader :icon="ClipboardDocumentListIcon" title="Gekoppelde werkbon"
                    subtitle="De werkbon waarop deze storing wordt opgelost." chapter="serviceorders" />
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <span class="font-semibold text-sm dark:text-slate-200">WB-{{
                            String(ticket.service_order.id).padStart(4,
                                '0') }}</span>
                        <BadgeComponent v-if="ticket.service_order.service_order_stage" color="blue" :hasDot="false">
                            {{ ticket.service_order.service_order_stage.name }}
                        </BadgeComponent>
                    </div>
                    <Link v-if="hasPermission('serviceorder.read')" :href="`/serviceorders/${ticket.service_order.id}`"
                        class="inline-flex items-center gap-1.5 text-sm border border-gray-200 dark:border-slate-600 rounded-md px-3 py-1.5 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                        Openen
                        <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
                    </Link>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                    <div v-if="serviceOrderMonteur" class="flex items-center gap-2 text-gray-600 dark:text-slate-300">
                        <UserIcon class="size-4 text-slate-400 flex-none" />
                        <span class="text-slate-400 text-xs">Monteur</span>
                        <span class="text-xs font-medium">{{ serviceOrderMonteur.name }}</span>
                    </div>
                    <div v-if="serviceOrderPlanning" class="flex items-center gap-2 text-gray-600 dark:text-slate-300">
                        <CalendarIcon class="size-4 text-slate-400 flex-none" />
                        <span class="text-slate-400 text-xs">Gepland op</span>
                        <span class="text-xs font-medium">{{ serviceOrderPlanning }}</span>
                    </div>
                </div>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <RemarksComponent :remarkable-type="'App\\Models\\Ticket'" :remarkable-id="ticket.id"
                    :comments="ticket.remarks" />
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent class="mt-6 sm:mt-0">
                <ImageUploadComponent title="Afbeeldingen van de storing" :existing="ticket.images" :imageable-id="ticket.id"
                    imageable-type="\App\Models\Ticket" />
            </BoxComponent>

            <BoxComponent class="mt-6">
                <SectionHeader :icon="TimelineIcon" title="Tijdlijn"
                    subtitle="Alles wat er op dit ticket gebeurd is, op volgorde." chapter="timeline" />
                <TimelineComponent :activities="ticket.activities ?? []" />
            </BoxComponent>

            <DocumentUploadComponent :existing="ticket.documents" :documentable-id="ticket.id"
                documentable-type="\App\Models\Ticket" class="mt-6" />
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import RemarksComponent from '@/Components/RemarksComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import {
    ArrowPathIcon,
    ArrowTopRightOnSquareIcon,
    CalendarIcon,
    ChatBubbleBottomCenterTextIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    ClipboardDocumentListIcon,
    ClockIcon,
    DocumentTextIcon,
    EllipsisHorizontalIcon,
    ExclamationCircleIcon,
    FlagIcon,
    HashtagIcon,
    MinusIcon,
    PuzzlePieceIcon,
    TrashIcon,
    UserIcon,
} from '@heroicons/vue/24/outline';
import { MenuItem } from '@headlessui/vue';
import DropdownMenu from '@/Components/UI/DropdownMenu.vue';
import { ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Siren, TimelineIcon } from '@lucide/vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { hasPermission, nlDate, nlTime } from '@/Utilities/Utilities';
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue';

const props = defineProps({
    ticket: { type: Object, required: true },
    statusses: { type: Array, required: true },
    priorities: { type: Array, required: true },
    customFields: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
});

const initialStatus = props.statusses.find(s => s.name === props.ticket.status);

const form = useForm({
    subject: props.ticket.subject,
    description: props.ticket.description,
    status: initialStatus.id,
    priority: props.priorities.find(p => p.name === props.ticket.priority)?.id,
    status_code: props.ticket.status_code ?? '',
});

const statusBadgeColor = computed(() => {
    const s = props.ticket.status.toLowerCase();
    if (s === 'open') return 'red';
    if (s === 'in behandeling') return 'orange';
    if (s === 'gesloten') return 'green';
    return 'gray';
});

const priorityBadgeColor = computed(() => {
    const p = props.ticket.priority.toLowerCase();
    if (p === 'hoog') return 'red';
    if (p === 'normaal') return 'yellow';
    return 'green';
});

/** Header tile, tinted by status so it agrees with the badge beside the title. */
const STATUS_TILES = {
    red: { bg: 'bg-red-500/10', text: 'text-red-600 dark:text-red-400' },
    orange: { bg: 'bg-orange-500/10', text: 'text-orange-600 dark:text-orange-400' },
    green: { bg: 'bg-green-500/10', text: 'text-green-600 dark:text-green-400' },
    gray: { bg: 'bg-gray-500/10', text: 'text-gray-600 dark:text-slate-300' },
};

const statusTileClasses = computed(() => STATUS_TILES[statusBadgeColor.value] ?? STATUS_TILES.gray);

/** Priority as a direction rather than a second badge next to the status one. */
const priorityTrend = computed(() => {
    const p = props.ticket.priority.toLowerCase();
    if (p === 'hoog') return { icon: ChevronUpIcon, class: 'text-red-500 dark:text-red-400' };
    if (p === 'normaal') return { icon: MinusIcon, class: 'text-amber-500 dark:text-amber-400' };
    return { icon: ChevronDownIcon, class: 'text-green-500 dark:text-green-400' };
});

const priorityIndex = computed(() => props.priorities.findIndex(p => p.id === form.priority));

/**
 * The indicator is exactly one segment wide (the container's padding removed
 * first), so stepping it by whole multiples of its own width lands it centred
 * on any segment without measuring the DOM.
 */
const prioritySliderStyle = computed(() => ({
    width: `calc((100% - 0.5rem) / ${props.priorities.length || 1})`,
    transform: `translateX(${priorityIndex.value * 100}%)`,
}));

function priorityDotClass(name) {
    const p = (name ?? '').toLowerCase();
    if (p === 'hoog') return 'bg-red-500';
    if (p === 'normaal') return 'bg-amber-500';
    return 'bg-green-500';
}

function patchField(field, value) {
    form.transform(() => ({ [field]: value })).patch(`/tickets/${props.ticket.id}`, { preserveScroll: true });
}

watch(() => form.subject, v => patchField('subject', v));
watch(() => form.description, v => patchField('description', v));
watch(() => form.status, v => {
    const name = props.statusses.find(s => s.id === v)?.name || v;
    patchField('status', name);
});
watch(() => form.priority, v => {
    const name = props.priorities.find(p => p.id === v)?.name || v;
    patchField('priority', name);
});
watch(() => form.status_code, v => patchField('status_code', v));

const serviceOrderMonteur = computed(() => {
    return props.ticket.service_order?.executing_users?.[0] ?? null;
});

const serviceOrderPlanning = computed(() => {
    const events = props.ticket.service_order?.events;
    if (!events?.length) return null;
    const now = new Date().toISOString();
    const upcoming = events.filter(e => e.start >= now).sort((a, b) => a.start.localeCompare(b.start));
    const event = upcoming[0] ?? events.slice().sort((a, b) => b.start.localeCompare(a.start))[0];
    if (!event) return null;
    const start = new Date(event.start);
    const end = new Date(event.end);
    const pad = n => String(n).padStart(2, '0');
    const time = t => `${pad(t.getHours())}:${pad(t.getMinutes())}`;
    return `${nlDate(event.start)} tussen ${time(start)} - ${time(end)}`;
});

const deleteTicket = () => {
    if (confirm('Weet je zeker dat je deze storing wilt verwijderen?')) {
        router.delete(`/tickets/${props.ticket.id}`);
    }
};
</script>
