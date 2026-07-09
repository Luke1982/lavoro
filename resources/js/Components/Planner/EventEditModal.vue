<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-start lg:items-center justify-center px-4 lg:px-0 transition-all duration-200"
            :class="visible ? 'bg-black/30 backdrop-blur-sm' : 'bg-transparent'">
            <div class="w-full max-w-2xl bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden transition-all duration-200 my-4 lg:my-0"
                :class="visible ? 'opacity-100 scale-100 translate-y-0' : 'opacity-0 scale-95 translate-y-4'">

                <!-- Header -->
                <div class="flex items-start p-6 pb-5">
                    <div
                        class="flex-shrink-0 w-12 h-12 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-xl flex items-center justify-center mr-4">
                        <CalendarDaysIcon class="h-6 w-6 text-lavoro-blue" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                            {{ editingExisting ? 'Wijzig afspraak' : 'Nieuwe afspraak' }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Plan een bezoek of geplande afspraak
                            in.</p>
                    </div>
                    <button @click="closeModal"
                        class="flex-shrink-0 w-9 h-9 border border-gray-200 dark:border-gray-700 rounded-xl flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:border-gray-300 transition-colors ml-4">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <!-- Scrollable body -->
                <div class="px-6 pb-2 max-h-[70vh] overflow-y-auto space-y-5">

                    <!-- Start / Einde -->
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 bg-gray-50 dark:bg-gray-800/60 rounded-xl border border-gray-100 dark:border-gray-700">
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <CalendarDaysIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Start</span>
                            </div>
                            <div class="flex gap-2">
                                <TextInput v-model="form.start_date" label="" type="date" class="flex-1"
                                    :has-error="Boolean(form.errors.start)" />
                                <select v-model="startHour" :class="timeSelectClass(Boolean(form.errors.start))">
                                    <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
                                </select>
                                <select v-model="startMinute" :class="timeSelectClass(Boolean(form.errors.start))">
                                    <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
                                </select>
                            </div>
                            <p v-if="form.errors.start" class="mt-1 text-sm text-red-600">{{ form.errors.start }}</p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <CalendarDaysIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Einde</span>
                            </div>
                            <div class="flex gap-2">
                                <TextInput v-model="form.end_date" label="" type="date" class="flex-1"
                                    :has-error="Boolean(form.errors.end)" />
                                <select v-model="endHour" :class="timeSelectClass(Boolean(form.errors.end))">
                                    <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
                                </select>
                                <select v-model="endMinute" :class="timeSelectClass(Boolean(form.errors.end))">
                                    <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
                                </select>
                            </div>
                            <p v-if="form.errors.end" class="mt-1 text-sm text-red-600">{{ form.errors.end }}</p>
                        </div>
                    </div>

                    <!-- Type / Status -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <TagIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Type
                                    afspraak</span>
                            </div>
                            <ComboBox v-model="form.event_type_id" :options="eventTypes" class="w-full"
                                :initial-id="form.event_type_id" :hasError="Boolean(form.errors.event_type_id)"
                                :errorMessage="form.errors.event_type_id" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <CheckCircleIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Status</span>
                            </div>
                            <ComboBox v-model="form.status" :options="eventStatusses" class="w-full"
                                :initial-id="initialStatusId" :emitValue="true" :hasError="Boolean(form.errors.status)"
                                :errorMessage="form.errors.status" />
                        </div>
                    </div>

                    <!-- Titel / Klant -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <DocumentTextIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Titel</span>
                            </div>
                            <TextInput v-model="form.name" label="" type="text" class="w-full"
                                placeholder="Bijv. Onderhoud airco unit" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <UserIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Klant</span>
                            </div>
                            <ComboBox v-model="selectedCustomer" :options="customerOptions" class="w-full"
                                :initial-id="form.customer_id || customerOptions[0]?.id"
                                :has-external-searching="customersUseAjax" :searching="customerSearching"
                                placeholder="Zoek klant..." @change="searchCustomers" />
                        </div>
                    </div>

                    <!-- Werkbon / Locatie -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <DocumentIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Werkbon
                                    <span class="font-normal text-gray-400 dark:text-gray-500">(optioneel)</span>
                                </span>
                            </div>
                            <ComboBox v-if="!form.create_service_order && !form.no_service_order" v-model="form.eventable_id"
                                :options="internalServiceOrders" class="w-full" :initial-id="form.eventable_id"
                                placeholder="Zoek werkbon..." :hasError="Boolean(form.errors.eventable_id)"
                                :errorMessage="form.errors.eventable_id" />
                            <p v-else-if="form.create_service_order" class="text-sm italic text-gray-500 dark:text-gray-400 py-2">
                                Er wordt een nieuwe werkbon aangemaakt voor de geselecteerde klant.
                            </p>
                            <p v-else class="text-sm italic text-gray-500 dark:text-gray-400 py-2">
                                Deze afspraak heeft geen werkbon nodig.
                            </p>
                            <label v-if="!editingExisting" class="flex items-center gap-2 mt-2 select-none"
                                :class="selectedCustomer ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed'">
                                <input type="checkbox" v-model="form.create_service_order" :disabled="!selectedCustomer"
                                    class="rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer disabled:cursor-not-allowed" />
                                <span class="text-sm text-gray-600 dark:text-gray-400">Maak een nieuwe werkbon
                                    aan</span>
                            </label>
                            <label v-if="!editingExisting" class="flex items-center gap-2 mt-2 select-none cursor-pointer">
                                <input type="checkbox" v-model="form.no_service_order"
                                    class="rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer" />
                                <span class="text-sm text-gray-600 dark:text-gray-400">Geen werkbon nodig</span>
                            </label>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <BuildingOffice2Icon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Locatie</span>
                            </div>
                            <TextInput v-model="form.location" label="" type="text" class="w-full"
                                placeholder="Zoek locatie..." />
                        </div>
                    </div>

                    <!-- Omschrijving -->
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <div
                                class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                <Bars3BottomLeftIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                            </div>
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Omschrijving</span>
                        </div>
                        <div class="relative">
                            <textarea v-model="form.description" rows="4" maxlength="500"
                                class="w-full ring-1 ring-inset ring-gray-300 dark:ring-slate-500 bg-white dark:bg-slate-900 dark:text-white rounded-xl p-3 pb-6 text-sm text-gray-900 placeholder-gray-400 dark:placeholder:text-gray-600 focus:outline-none focus:ring-2 focus:ring-lavoro-blue resize-none"
                                placeholder="Voeg een omschrijving toe aan de afspraak..."></textarea>
                            <span
                                class="absolute bottom-2.5 right-3 text-xs text-gray-400 dark:text-gray-500 pointer-events-none">
                                {{ (form.description || '').length }}/500
                            </span>
                        </div>
                    </div>

                    <!-- Uitvoerende gebruikers -->
                    <div class="pb-2">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <div
                                    class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                    <UsersIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                                </div>
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Uitvoerende
                                    gebruikers</span>
                            </div>
                            <button @click="showUserSelector = !showUserSelector"
                                class="text-sm text-lavoro-blue font-medium hover:underline flex items-center gap-1">
                                <PlusIcon class="h-4 w-4" />
                                Gebruiker toevoegen
                            </button>
                        </div>

                        <!-- Selected user rows -->
                        <div v-if="selectedUsers.length" class="flex flex-col gap-2 mb-3">
                            <div v-for="user in selectedUsers" :key="user.id"
                                class="flex flex-col gap-2 bg-gray-50 dark:bg-gray-800 rounded-xl px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <img v-if="user.avatar" :src="user.avatar"
                                        class="w-8 h-8 rounded-full object-cover flex-shrink-0" />
                                    <div v-else
                                        class="w-8 h-8 rounded-full bg-lavoro-blue flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                        {{ initials(user.name) }}
                                    </div>
                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-200 flex-1 truncate">{{
                                        user.name }}</span>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <ClockFadingIcon class="h-3.5 w-3.5 text-gray-400" />
                                        <TextInput v-model="userBreaktimes[String(user.id)]" label="" type="number"
                                            class="w-20" :step="10" :min="0" placeholder="0" />
                                        <span class="text-xs text-gray-400">min</span>
                                    </div>
                                    <button @click="removeUser(user.id)"
                                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        <XMarkIcon class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <ComboBox v-if="userRoles.length" multiple v-model="userRoleSelections[String(user.id)]"
                                    :options="userRoles" :initial-ids="userRoleSelections[String(user.id)]"
                                    class="w-full" placeholder="Rollen..." />
                                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                    <label :for="`diverging-${user.id}`"
                                        class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400 select-none cursor-pointer">
                                        <input :id="`diverging-${user.id}`" type="checkbox"
                                            v-model="userDivergingTimes[String(user.id)].has_diverging_times"
                                            class="rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer" />
                                        Afwijkende tijden
                                    </label>
                                    <template v-if="userDivergingTimes[String(user.id)].has_diverging_times">
                                        <div class="flex items-center gap-1 ml-2 flex-wrap">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Start</span>
                                            <select v-model="userDivergingTimes[String(user.id)].startHour" :class="timeSelectClass(false)">
                                                <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
                                            </select>
                                            <select v-model="userDivergingTimes[String(user.id)].startMinute" :class="timeSelectClass(false)">
                                                <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
                                            </select>
                                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">Einde</span>
                                            <select v-model="userDivergingTimes[String(user.id)].endHour" :class="timeSelectClass(false)">
                                                <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
                                            </select>
                                            <select v-model="userDivergingTimes[String(user.id)].endMinute" :class="timeSelectClass(false)">
                                                <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
                                            </select>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- User selector -->
                        <Transition enter-active-class="transition-all duration-150 ease-out"
                            enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0"
                            leave-active-class="transition-all duration-100 ease-in"
                            leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1">
                            <ComboBox v-if="showUserSelector" v-model="userToAdd" :options="availableUsers"
                                class="w-full" placeholder="Zoek gebruiker..." @update:modelValue="onUserSelected" />
                        </Transition>
                        <p v-if="form.errors.executing_user_ids" class="text-xs text-red-500 mt-1">
                            {{ form.errors.executing_user_ids }}
                        </p>
                    </div>

                    <div v-if="editingExisting && form.id" class="pb-2">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                                <EnvelopeIcon class="h-3.5 w-3.5 text-lavoro-blue" />
                            </div>
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">E-mail</span>
                        </div>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <ComboBox v-model="selectedStandardEmailId"
                                :options="standardEmails.map(e => ({ id: e.id, name: e.name }))" class="flex-1"
                                placeholder="Kies standaard e-mail..." />
                            <button type="button" @click="sendSelectedStandardEmailWithEdit" :disabled="!selectedStandardEmailId"
                                v-tooltip="'Bewerken en verzenden'"
                                class="px-3 py-2 rounded-xl border border-lavoro-blue text-lavoro-blue hover:bg-lavoro-lightblue dark:hover:bg-blue-900/40 disabled:opacity-50">
                                <MailQuestionMark class="h-4 w-4" />
                            </button>
                            <button type="button" @click="sendSelectedStandardEmailDirect"
                                :disabled="!selectedStandardEmailId || sendingDirect"
                                v-tooltip="'Direct verzenden'"
                                class="px-3 py-2 rounded-xl bg-lavoro-blue text-white hover:bg-blue-700 disabled:opacity-50">
                                <SendHorizontal class="h-4 w-4" />
                            </button>
                        </div>
                        <div v-if="emailHistory.length" class="flex flex-col gap-2">
                            <div v-for="item in emailHistory" :key="item.id"
                                class="flex items-center justify-between bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="text-gray-700 dark:text-gray-300 truncate">{{ item.description }}</p>
                                    <p class="text-xs text-gray-400">{{ nlDate(item.created_at) }} {{ nlTime(item.created_at) }}</p>
                                </div>
                                <button v-if="item.standard_email_id" type="button" @click="resendHistoryItem(item)"
                                    class="text-xs text-lavoro-blue font-medium hover:underline shrink-0 ml-2">
                                    Opnieuw versturen
                                </button>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer -->
                <div
                    class="flex items-end sm:items-center justify-between px-6 py-4 border-t border-gray-100 dark:border-gray-700">
                    <button @click="closeModal"
                        class="px-6 py-2.5 border border-gray-200 dark:border-gray-600 rounded-xl text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                        Annuleren
                    </button>

                    <div class="flex flex-col sm:flex-row gap-4">
                        <!-- Voorlopig -->
                        <div class="flex items-center gap-3 py-1">
                            <input id="is_preliminary" type="checkbox" v-model="form.is_preliminary"
                                class="h-4 w-4 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer" />
                            <label for="is_preliminary"
                                class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                                <ExclamationTriangleIcon class="h-4 w-4 text-amber-500" />
                                Voorlopig
                            </label>
                        </div>
                        <button @click="save" :disabled="saving"
                            class="px-6 py-2.5 bg-lavoro-blue rounded-xl text-sm font-semibold text-white hover:bg-blue-700 transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                            <CheckIcon class="h-4 w-4" />
                            {{ saving ? 'Opslaan...' : 'Opslaan' }}
                        </button>
                    </div>
                </div>

            </div>
        <EmailPreviewModal :key="previewModalKey" :open="previewModal.open" @update:open="previewModal.open = $event"
            :event-id="previewModal.eventId" :standard-email-id="previewModal.standardEmailId" :to="previewModal.to"
            :subject="previewModal.subject" :body="previewModal.body" :trigger="previewModal.trigger"
            :editable="previewModal.editable" @sent="onEmailSent" />
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import {
    XMarkIcon, CalendarDaysIcon, TagIcon, CheckCircleIcon, DocumentTextIcon,
    UserIcon, DocumentIcon, BuildingOffice2Icon, Bars3BottomLeftIcon,
    UsersIcon, PlusIcon, CheckIcon, ExclamationTriangleIcon, EnvelopeIcon,
} from '@heroicons/vue/24/outline'
import { ClockFading as ClockFadingIcon, MailQuestionMark, SendHorizontal } from '@lucide/vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import EmailPreviewModal from '@/Components/EmailPreviewModal.vue'
import { formatLocalDateAsISO, localToUtcDatetime, nlTime, hasPermission, nlDate, initials } from '@/Utilities/Utilities'
import { useComboSearch } from '@/Composables/useComboSearch'
import { useStandardEmailPreview } from '@/Composables/useStandardEmailPreview'

const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    initial: { type: Object, required: true },
    editingExisting: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'saved'])
const page = usePage()

const visible = ref(false)
const saving = ref(false)
const showUserSelector = ref(false)
const userToAdd = ref(null)
const standardEmails = ref([])
const emailHistory = ref([])
const selectedStandardEmailId = ref(null)
const sendingDirect = ref(false)
const { previewModal, previewModalKey, openPreview, sendDirect } = useStandardEmailPreview()

function snapMinute(time) {
    if (!time) return '08:00'
    const [h, m] = time.split(':').map(Number)
    const snapped = Math.round(m / 15) * 15
    if (snapped === 60) return `${String(h + 1).padStart(2, '0')}:00`
    return `${String(h).padStart(2, '0')}:${String(snapped).padStart(2, '0')}`
}

const form = useForm({
    id: props.initial.id || '',
    event_type_id: props.initial.event_type_id || props.eventTypes[0]?.id || '',
    name: props.initial.name || '',
    description: props.initial.description || '',
    status: props.initial.status || props.eventStatusses[0]?.name || 'Gepland',
    start_date: formatLocalDateAsISO(props.initial.start),
    end_date: formatLocalDateAsISO(props.initial.end),
    start_time: snapMinute(nlTime(props.initial.start)),
    end_time: snapMinute(nlTime(props.initial.end)),
    eventable_type: props.initial.eventable_type || '\\App\\Models\\ServiceOrder',
    eventable_id: props.initial.eventable_id || '',
    customer_id: props.initial.customer_id || null,
    location: props.initial.location || '',
    executing_user_ids: props.initial.executing_user_ids || [],
    create_service_order: false,
    no_service_order: props.initial.no_service_order || false,
    is_preliminary: props.initial.is_preliminary || false,
})

const userBreaktimes = ref(
    Object.fromEntries(
        (props.initial.executing_users || []).map(u => [String(u.id), u.breaktime ?? 0])
    )
)

const userRoleSelections = ref(
    Object.fromEntries(
        (props.initial.executing_users || []).map(u => [String(u.id), u.user_role_ids ?? []])
    )
)

const userDivergingTimes = ref(
    Object.fromEntries(
        (props.initial.executing_users || []).map(u => {
            const start = u.diverging_start ? u.diverging_start.slice(0, 5) : '08:00'
            const end   = u.diverging_end   ? u.diverging_end.slice(0, 5)   : '09:00'
            return [String(u.id), {
                has_diverging_times: u.has_diverging_times ?? false,
                startHour:   start.split(':')[0],
                startMinute: start.split(':')[1],
                endHour:     end.split(':')[0],
                endMinute:   end.split(':')[1],
            }]
        })
    )
)

// Ensure entries exist for all initial IDs even when executing_users is absent (openCreate path)
;(props.initial.executing_user_ids || []).forEach(id => {
    const key = String(id)
    if (userBreaktimes.value[key] === undefined) userBreaktimes.value[key] = 0
    if (userRoleSelections.value[key] === undefined) userRoleSelections.value[key] = []
    if (userDivergingTimes.value[key] === undefined) {
        userDivergingTimes.value[key] = { has_diverging_times: false, startHour: '08', startMinute: '00', endHour: '09', endMinute: '00' }
    }
})

const initialStatusId = computed(() =>
    props.eventStatusses.find(s => s.name === form.status)?.id || props.eventStatusses[0]?.id || ''
)

const initialCustomerOptions = props.allCustomers.length
    ? props.allCustomers
    : (props.initial.customer_id && props.initial.customer_name
        ? [{ id: props.initial.customer_id, name: props.initial.customer_name }]
        : [])

const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', initialCustomerOptions, props.customersUseAjax)

const selectedCustomer = ref(
    props.initial.customer_id
    || props.allCustomers[0]?.id
    || null
)

const serviceOrderResults = ref([])

async function fetchServiceOrders() {
    if (!selectedCustomer.value) {
        serviceOrderResults.value = []
        return
    }
    try {
        const { data } = await axios.get('/combo/serviceorders', {
            params: {
                customer_id: selectedCustomer.value,
                include_id: form.eventable_id || undefined,
            },
        })
        serviceOrderResults.value = data
    } catch {
        serviceOrderResults.value = []
    }
}

const internalServiceOrders = computed(() =>
    serviceOrderResults.value.map(so => ({
        id: so.id,
        name: `Order ${so.id} van ${nlDate(so.created_at)}`,
    }))
)

const selectedUsers = computed(() =>
    form.executing_user_ids
        .map(id => props.allUsers.find(u => u.id === id))
        .filter(Boolean)
)

const availableUsers = computed(() =>
    props.allUsers.filter(u => !form.executing_user_ids.includes(u.id))
)

watch(selectedCustomer, (val, oldVal) => {
    if (val === oldVal) return
    if (!val) {
        form.create_service_order = false
    }
    form.eventable_id = ''
    fetchServiceOrders()
})

watch(() => form.no_service_order, (val) => {
    if (val) {
        form.create_service_order = false
        form.eventable_id = ''
    }
})

watch(() => form.create_service_order, (val) => {
    if (val) {
        form.no_service_order = false
    }
})

onMounted(() => {
    if (selectedCustomer.value) {
        fetchServiceOrders()
    }
})

const hours = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'))
const minutes = ['00', '15', '30', '45']

const startHour = computed({
    get: () => form.start_time?.split(':')[0] ?? '08',
    set: (h) => { form.start_time = `${h}:${form.start_time?.split(':')[1] ?? '00'}` },
})
const startMinute = computed({
    get: () => form.start_time?.split(':')[1] ?? '00',
    set: (m) => { form.start_time = `${form.start_time?.split(':')[0] ?? '08'}:${m}` },
})
const endHour = computed({
    get: () => form.end_time?.split(':')[0] ?? '09',
    set: (h) => { form.end_time = `${h}:${form.end_time?.split(':')[1] ?? '00'}` },
})
const endMinute = computed({
    get: () => form.end_time?.split(':')[1] ?? '00',
    set: (m) => { form.end_time = `${form.end_time?.split(':')[0] ?? '09'}:${m}` },
})

const TIME_SELECT_BASE = 'rounded-md border-0 py-1.5 pl-2 pr-6 text-sm text-gray-900 dark:text-white dark:bg-slate-900 ring-1 ring-inset focus:ring-2 focus:ring-inset focus:ring-indigo-600 cursor-pointer'
function timeSelectClass(hasError) {
    return hasError
        ? `${TIME_SELECT_BASE} ring-red-300`
        : `${TIME_SELECT_BASE} ring-gray-300 dark:ring-slate-500`
}

function onUserSelected(userId) {
    if (userId != null && !form.executing_user_ids.includes(userId)) {
        form.executing_user_ids.push(userId)
        if (userBreaktimes.value[String(userId)] === undefined) {
            userBreaktimes.value[String(userId)] = 0
        }
        if (userRoleSelections.value[String(userId)] === undefined) {
            userRoleSelections.value[String(userId)] = []
        }
        if (userDivergingTimes.value[String(userId)] === undefined) {
            userDivergingTimes.value[String(userId)] = {
                has_diverging_times: false,
                startHour: '08',
                startMinute: '00',
                endHour: '09',
                endMinute: '00',
            }
        }
    }
    nextTick(() => {
        userToAdd.value = null
        showUserSelector.value = false
    })
}

function removeUser(id) {
    form.executing_user_ids = form.executing_user_ids.filter(i => i !== id)
}

function closeModal() {
    visible.value = false
    setTimeout(() => emit('close'), 200)
}

async function loadStandardEmails() {
    if (!form.id) return
    const { data } = await axios.get(`/api/events/${form.id}/standard-emails`)
    standardEmails.value = data
}

async function loadEmailHistory() {
    if (!form.id) return
    const { data } = await axios.get(`/api/events/${form.id}/email-history`)
    emailHistory.value = data
}

function sendSelectedStandardEmailWithEdit() {
    if (!selectedStandardEmailId.value) return
    openPreview(form.id, selectedStandardEmailId.value)
    selectedStandardEmailId.value = null
}

async function sendSelectedStandardEmailDirect() {
    if (!selectedStandardEmailId.value || sendingDirect.value) return
    const id = selectedStandardEmailId.value
    selectedStandardEmailId.value = null
    sendingDirect.value = true
    const sent = await sendDirect(form.id, id)
    sendingDirect.value = false
    if (sent) loadEmailHistory()
}

function resendHistoryItem(item) {
    if (!item.standard_email_id) return
    openPreview(form.id, item.standard_email_id)
}

function onEmailSent() {
    loadEmailHistory()
}

async function save() {
    if (saving.value) return

    if (form.executing_user_ids.length === 0) {
        form.setError('executing_user_ids', 'Voeg minimaal één uitvoerende gebruiker toe.')
        return
    }
    if (!props.editingExisting && !form.create_service_order && !form.no_service_order && !form.eventable_id) {
        form.setError('eventable_id', 'Koppel een werkbon aan de afspraak of maak een nieuwe aan.')
        return
    }

    saving.value = true
    try {
        await axios.get('sanctum/csrf-cookie')
        const payload = {
            ...form,
            start: localToUtcDatetime(form.start_date, form.start_time).slice(0, 16),
            end: localToUtcDatetime(form.end_date, form.end_time).slice(0, 16),
            executing_user_ids: form.executing_user_ids,
            executing_user_breaktimes: userBreaktimes.value,
            executing_user_roles: userRoleSelections.value,
            executing_user_diverging_times: Object.fromEntries(
                form.executing_user_ids.map(id => {
                    const dt = userDivergingTimes.value[String(id)] ?? {}
                    const has = dt.has_diverging_times ?? false
                    return [String(id), {
                        has_diverging_times: has,
                        diverging_start: has ? `${dt.startHour}:${dt.startMinute}` : null,
                        diverging_end:   has ? `${dt.endHour}:${dt.endMinute}`     : null,
                    }]
                })
            ),
            customer_id: selectedCustomer.value,
            eventable_id: form.create_service_order ? null : (form.eventable_id || null),
        }
        let pendingStandardEmails = []
        let queuedStandardEmails = []
        let savedEventId = null
        let successMessage = ''
        if (props.editingExisting && form.id) {
            const authId = page.props.auth?.user?.id ?? null
            const canUpdate = hasPermission('event.update_others') ||
                (hasPermission('event.update') && form.executing_user_ids.includes(authId))
            if (!canUpdate) return
            const r = await axios.put(`/api/events/${form.id}`, payload)
            if (r.status !== 200) throw new Error('bad')
            successMessage = 'Afspraak succesvol bijgewerkt'
            pendingStandardEmails = Array.isArray(r.data?.pending_standard_emails) ? r.data.pending_standard_emails : []
            queuedStandardEmails = Array.isArray(r.data?.queued_standard_emails) ? r.data.queued_standard_emails : []
            savedEventId = r.data.id
        } else {
            if (!hasPermission('event.create')) return
            const r = await axios.post('/api/events', payload)
            if (r.status !== 201) throw new Error('bad')
            successMessage = 'Afspraak succesvol opgeslagen'
            pendingStandardEmails = Array.isArray(r.data?.pending_standard_emails) ? r.data.pending_standard_emails : []
            queuedStandardEmails = Array.isArray(r.data?.queued_standard_emails) ? r.data.queued_standard_emails : []
            savedEventId = r.data.id
        }
        if (queuedStandardEmails.length > 0) {
            successMessage += ' — standaard e-mail wordt verzonden: ' + queuedStandardEmails.join(', ')
        }
        page.props.flash.success = successMessage
        // The parent closes this modal as soon as 'saved' fires, so any pending
        // trigger emails must be handled by the parent — this modal (and any
        // child EmailPreviewModal it hosts) is unmounted immediately after.
        emit('saved', { eventId: savedEventId, pendingStandardEmails })
    } catch (e) {
        if (e.response?.status === 422) {
            const errs = e.response.data?.errors || {}
            form.clearErrors()
            Object.keys(errs).forEach(k => form.setError(k, Array.isArray(errs[k]) ? errs[k][0] : String(errs[k])))
        }
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet opslaan'
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    requestAnimationFrame(() => { visible.value = true })
    if (!form.eventable_id && internalServiceOrders.value.length > 0) {
        form.eventable_id = internalServiceOrders.value[0].id
    }
    if (props.editingExisting && form.id) {
        loadStandardEmails()
        loadEmailHistory()
    }
})
</script>
