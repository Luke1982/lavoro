<template>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
        <div class="inline sm:flex items-center">
            <Link href="/tickets" class="text-slate-400 text-sm font-medium">Storingen</Link>
            <ChevronRightIcon class="size-4 text-gray-400 mx-2 inline" />
            <span class="text-slate-800 dark:text-slate-200 font-bold text-sm">Ticket voor s/n #{{
                ticket.asset.serial_number
                }}</span>
        </div>
        <button v-if="hasPermission('ticket.delete')" type="button" @click="deleteTicket"
            class="inline-flex items-center justify-center size-9 bg-white dark:bg-slate-800 text-red-600 dark:text-red-400 ring-gray-200 dark:ring-slate-600 ring-1 rounded-full cursor-pointer">
            <TrashIcon class="size-5" />
        </button>
    </div>

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mt-6 mb-2">
        <div>
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-2xl font-bold dark:text-slate-100">{{ ticket.subject }}</h1>
                <BadgeComponent :color="statusBadgeColor" :hasDot="false">{{ ticket.status }}</BadgeComponent>
            </div>
            <div class="flex flex-wrap gap-x-6 gap-y-2 mt-3">
                <div class="flex items-center gap-1.5">
                    <ExclamationCircleIcon class="size-4 text-slate-400 flex-none" />
                    <span class="text-xs text-slate-400">Prioriteit</span>
                    <BadgeComponent :color="priorityBadgeColor" :hasDot="false" class="!px-2 !py-0.5 text-xs">{{
                        ticket.priority }}</BadgeComponent>
                </div>
                <div class="flex items-center gap-1.5">
                    <CalendarIcon class="size-4 text-slate-400 flex-none" />
                    <span class="text-xs text-slate-400">Aangemaakt</span>
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ nlDate(ticket.created_at) }}
                        om {{ nlTime(ticket.created_at) }}</span>
                </div>
                <div v-if="ticket.created_by" class="flex items-center gap-1.5">
                    <UserIcon class="size-4 text-slate-400 flex-none" />
                    <span class="text-xs text-slate-400">Aangemaakt door</span>
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ ticket.created_by.name
                        }}</span>
                    <BadgeComponent v-if="ticket.created_by.deleted_at" color="gray" :hasDot="false"
                        class="!px-2 !py-0.5 text-xs">Gedeactiveerd</BadgeComponent>
                </div>
                <div class="flex items-center gap-1.5">
                    <ClockIcon class="size-4 text-slate-400 flex-none" />
                    <span class="text-xs text-slate-400">Laatste bijgewerkt</span>
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ nlDate(ticket.updated_at) }}
                        om {{ nlTime(ticket.updated_at) }}</span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <Link v-if="ticket.service_order_id && hasPermission('serviceorder.read')"
                :href="`/serviceorders/${ticket.service_order_id}`"
                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-lavoro-blue hover:opacity-90 rounded-md transition-opacity cursor-pointer">
                Werkbon openen
                <ArrowTopRightOnSquareIcon class="size-4" />
            </Link>
        </div>
    </div>

    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <SectionHeader :icon="ExclamationCircleIcon" title="Gegevens van de storing"
                    subtitle="Machine, prioriteit en omschrijving van deze storing." chapter="details" />

                <div class="divide-y divide-gray-100 dark:divide-slate-700/60">
                    <div class="grid grid-cols-12 py-3 gap-3 items-start">
                        <div class="col-span-12 sm:col-span-3">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Storing aan</span>
                        </div>
                        <div
                            class="col-span-12 sm:col-span-9 text-sm text-gray-800 dark:text-slate-200 flex flex-wrap items-center gap-x-1">
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

                    <div class="grid grid-cols-12 py-3 gap-3 items-start">
                        <div class="col-span-12 sm:col-span-3">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Onderwerp</span>
                        </div>
                        <div class="col-span-12 sm:col-span-9">
                            <EditableTextField v-model="form.subject" class="w-full"
                                :readonly="!hasPermission('ticket.update')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-start">
                        <div class="col-span-12 sm:col-span-3">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Omschrijving</span>
                        </div>
                        <div class="col-span-12 sm:col-span-9">
                            <EditableTextField v-model="form.description" type="textarea" class="w-full"
                                :readonly="!hasPermission('ticket.update')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-start">
                        <div class="col-span-12 sm:col-span-3">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Storingscode</span>
                        </div>
                        <div class="col-span-12 sm:col-span-9">
                            <EditableTextField v-model="form.status_code" class="w-full"
                                :readonly="!hasPermission('ticket.update')" />
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-3">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Status</span>
                        </div>
                        <div class="col-span-12 sm:col-span-4">
                            <ComboBox v-if="hasPermission('ticket.change_status')" :options="statusses"
                                v-model="form.status" :initial-id="initialStatus.id" class="w-full"
                                :hasError="Boolean(form.errors.status)" :errorMessage="form.errors.status" />
                            <span v-else class="text-sm dark:text-slate-300">{{ ticket.status }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 py-3 gap-3 items-center">
                        <div class="col-span-12 sm:col-span-3">
                            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Prioriteit</span>
                        </div>
                        <div class="col-span-12 sm:col-span-6">
                            <fieldset aria-label="Kies een prioriteit" v-if="hasPermission('ticket.alter_priority')">
                                <div class="grid grid-cols-3 gap-3">
                                    <label v-for="prio in priorities" :key="prio.id" :aria-label="prio.name" :class="{
                                        'cursor-pointer group relative flex items-center justify-center rounded-md border p-2 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-disabled:opacity-25': true,
                                        'bg-red-100 text-red-600 hover:bg-red-600 hover:text-white has-checked:bg-red-600 border-red-600 has-checked:border-red-900': prio === priorities[2],
                                        'bg-yellow-100 text-yellow-600 hover:bg-yellow-600 hover:text-white has-checked:bg-yellow-600 border-yellow-600 has-checked:border-yellow-900': prio === priorities[1],
                                        'bg-green-100 text-green-600 hover:bg-green-600 hover:text-white has-checked:bg-green-600 border-green-600 has-checked:border-green-900': prio === priorities[0]
                                    }">
                                        <input type="radio" name="option" :value="prio.id"
                                            :checked="prio.id === form.priority" v-model="form.priority"
                                            class="absolute inset-0 appearance-none focus:outline-none disabled:cursor-not-allowed" />
                                        <span class="text-sm font-medium uppercase group-has-checked:text-white">{{
                                            prio.name }}</span>
                                    </label>
                                </div>
                            </fieldset>
                            <span v-else class="text-sm dark:text-slate-300">{{ ticket.priority }}</span>
                        </div>
                    </div>

                    <template v-if="ticket.closed_by">
                        <div class="grid grid-cols-12 py-3 gap-3 items-center">
                            <div class="col-span-12 sm:col-span-3">
                                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Gesloten
                                    door</span>
                            </div>
                            <div class="col-span-12 sm:col-span-9 text-sm text-gray-600 dark:text-slate-300 flex items-center gap-1.5">
                                {{ ticket.closed_by.name }}
                                <BadgeComponent v-if="ticket.closed_by.deleted_at" color="gray" :hasDot="false"
                                    class="!px-2 !py-0.5 text-xs">Gedeactiveerd</BadgeComponent>
                            </div>
                        </div>
                    </template>
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

            <DocumentUploadComponent :existing="ticket.documents" :documentable-id="ticket.id"
                documentable-type="\App\Models\Ticket" class="mt-4" />
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
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import RemarksComponent from '@/Components/RemarksComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import { ExclamationCircleIcon, TrashIcon, ArrowTopRightOnSquareIcon, ClipboardDocumentListIcon, UserIcon, CalendarIcon } from '@heroicons/vue/24/outline';
import { ChevronRightIcon } from '@heroicons/vue/24/outline';
import { TimelineIcon } from '@lucide/vue';
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
