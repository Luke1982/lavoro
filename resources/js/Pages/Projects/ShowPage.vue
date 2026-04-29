<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex border-b border-gray-200 dark:border-slate-700 pb-2 mb-4 justify-between items-center">
                    <div class="flex items-center">
                        <ClipboardDocumentListIcon class="h-6 w-6 text-gray-500 dark:text-slate-400 mr-2" />
                        <h1 class="text-l font-medium">Gegevens van het project</h1>
                    </div>
                    <StepsProgressBar :steps="statuses" v-model="form.status" class="flex-1 ml-4 max-w-250" />
                </div>
                <div class="grid grid-cols-12 mt-2 gap-4">
                    <div class="col-span-12 md:col-span-2">
                        <span class="text-xs font-bold">Titel</span>
                    </div>
                    <div class="col-span-12 md:col-span-10">
                        <EditableTextField v-model="form.title" class="w-full" />
                    </div>
                    <div class="col-span-12 md:col-span-2">
                        <span class="text-xs font-bold">Omschrijving</span>
                    </div>
                    <div class="col-span-12 md:col-span-10">
                        <EditableTextField v-model="form.description" type="textarea" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Klant</span>
                    </div>
                    <div class="col-span-10 md:col-span-4">
                        <ComboBox :options="customers" v-model="form.customer_id" :initialId="project.customer?.id"
                            placeholder="Selecteer klant" />
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Projectleider</span>
                    </div>
                    <div class="col-span-10 md:col-span-4">
                        <ComboBox :options="users" v-model="form.project_manager_id"
                            :initialId="project.project_manager?.id" placeholder="Selecteer projectleider" />
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Startdatum</span>
                    </div>
                    <div class="col-span-10 md:col-span-4">
                        <EditableTextField v-model="form.start_date" type="input" input-type="date" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Einddatum</span>
                    </div>
                    <div class="col-span-10 md:col-span-4">
                        <EditableTextField v-model="form.end_date" type="input" input-type="date" class="w-full" />
                    </div>
                </div>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-slate-700 pb-3 mb-4">
                    <div class="flex items-center">
                        <FlagIcon class="size-6 text-gray-500 dark:text-slate-400 mr-2" />
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Mijlpalen</h3>
                    </div>
                    <button @click="toggleMilestoneForm"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                        {{ showMilestoneForm ? 'Annuleren' : 'Mijlpaal toevoegen' }}
                    </button>
                </div>

                <CreateRecordForm ref="milestoneFormRef" :action="'/projectmilestones'" :fields="milestoneFields"
                    submit-label="Toevoegen" :external-trigger="true" @closed="showMilestoneForm = false" />
            </BoxComponent>

            <BoxComponent class="mt-4">
                <div class="flex items-center justify-between border-b border-gray-200 dark:border-slate-700 pb-3 mb-4">
                    <div class="flex items-center">
                        <ClipboardDocumentListIcon class="size-6 text-gray-500 dark:text-slate-400 mr-2" />
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Werkbonnen</h3>
                    </div>
                    <button @click="createServiceOrder"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                        Werkbon aanmaken
                    </button>
                </div>
                <div class="space-y-2" v-auto-animate>
                    <div v-if="!project.service_orders?.length" key="empty"
                        class="text-xs text-gray-500 dark:text-slate-500">
                        Nog geen werkbonnen
                    </div>
                    <ServiceOrderRow v-for="so in project.service_orders" :key="so.id" :serviceorder="so" />
                </div>
            </BoxComponent>
        </template>
        <template #sidebar>
            <BoxComponent v-if="project.customer">
                <div class="flex items-center border-b border-gray-200 dark:border-slate-700 pb-2 mb-4">
                    <BuildingOfficeIcon class="h-5 w-5 text-gray-500 dark:text-slate-400 mr-2" />
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Klant</h3>
                </div>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-[11px] text-gray-500 dark:text-slate-500">Naam</dt>
                        <dd class="text-gray-800 dark:text-slate-200 font-medium">
                            <Link :href="`/customers/${project.customer.id}`"
                                class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.name }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="project.customer.contactname">
                        <dt class="text-[11px] text-gray-500 dark:text-slate-500">Contactpersoon</dt>
                        <dd class="text-gray-800 dark:text-slate-200">{{ project.customer.contactname }}</dd>
                    </div>
                    <div v-if="project.customer.email">
                        <dt class="text-[11px] text-gray-500 dark:text-slate-500">E-mail</dt>
                        <dd><a :href="'mailto:' + project.customer.email"
                                class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.email
                                }}</a></dd>
                    </div>
                    <div v-if="project.customer.phone">
                        <dt class="text-[11px] text-gray-500 dark:text-slate-500">Telefoon</dt>
                        <dd><a :href="'tel:' + project.customer.phone"
                                class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.phone
                                }}</a></dd>
                    </div>
                    <div v-if="project.customer.mobile">
                        <dt class="text-[11px] text-gray-500 dark:text-slate-500">Mobiel</dt>
                        <dd><a :href="'tel:' + project.customer.mobile"
                                class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.mobile
                                }}</a></dd>
                    </div>
                    <div v-if="project.customer.address">
                        <dt class="text-[11px] text-gray-500 dark:text-slate-500">Adres</dt>
                        <dd>
                            <a :href="mapsLinkFromCustomer(project.customer)" target="_blank"
                                class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ project.customer.address }}<br>
                                {{ project.customer.postal_code }} {{ project.customer.city }}
                            </a>
                        </dd>
                    </div>
                </dl>
            </BoxComponent>

            <BoxComponent :class="{ 'mt-4': project.customer }">
                <div class="flex items-center border-b border-gray-200 dark:border-slate-700 pb-2 mb-4">
                    <FlagIcon class="h-5 w-5 text-gray-500 dark:text-slate-400 mr-2" />
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-slate-200">Mijlpalen</h3>
                </div>

                <div v-if="!sortedMilestones.length" class="text-xs text-gray-500 dark:text-slate-500">
                    Nog geen mijlpalen
                </div>
                <div v-else class="flow-root">
                    <ul role="list" class="-mb-5" v-auto-animate>
                        <li v-for="(ms, idx) in sortedMilestones" :key="ms.id">
                            <div class="relative pb-5">
                                <span v-if="idx !== sortedMilestones.length - 1"
                                    class="absolute top-3 left-3 -ml-px h-full w-0.5 bg-gray-200 dark:bg-slate-700/60"
                                    aria-hidden="true" />
                                <div class="relative flex space-x-3">
                                    <div>
                                        <span v-if="ms.actual_date"
                                            :class="[milestoneColor(ms), 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm']">
                                            <CheckIcon class="size-4 text-white" />
                                        </span>
                                        <button v-else type="button"
                                            v-tooltip="'Markeer als afgerond met vandaag als werkelijke datum'"
                                            @click="markMilestoneActualToday(ms)"
                                            :class="[milestoneColor(ms), 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm cursor-pointer hover:brightness-110']">
                                            <ClockIcon class="size-4 text-white" />
                                        </button>
                                    </div>
                                    <div class="min-w-0 flex-1 pt-1">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <p class="text-sm font-medium text-gray-800 dark:text-slate-200">{{
                                                    ms.title }}</p>
                                                <p v-if="ms.assigned_user"
                                                    class="mt-0.5 text-xs text-gray-500 dark:text-slate-500 flex items-center gap-1">
                                                    <UserIcon class="size-3" />
                                                    {{ ms.assigned_user.name }}
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-1.5 ml-2 shrink-0">
                                                <PencilSquareIcon
                                                    class="size-4 text-gray-400 dark:text-slate-500 hover:text-gray-700 dark:hover:text-slate-200 cursor-pointer"
                                                    @click="toggleEditMilestone(ms)" />
                                                <TrashIcon
                                                    class="size-4 text-red-400 dark:text-red-300 hover:text-red-600 dark:hover:text-red-400 cursor-pointer"
                                                    @click="deleteMilestone(ms.id)" />
                                            </div>
                                        </div>
                                        <div
                                            class="flex flex-wrap gap-x-3 mt-1 text-[11px] text-gray-500 dark:text-slate-500">
                                            <span v-if="ms.projected_date" class="flex items-center gap-1">
                                                <CalendarIcon class="size-3" />Gepland: {{ nlDate(ms.projected_date) }}
                                            </span>
                                            <span v-if="ms.actual_date"
                                                class="text-green-600 dark:text-green-400 flex items-center gap-1">
                                                <CalendarIcon class="size-3" />Afgerond: {{ nlDate(ms.actual_date) }}
                                            </span>
                                        </div>
                                        <div v-auto-animate>
                                            <div v-if="ms.description && editingMilestoneId !== ms.id" key="desc"
                                                class="mt-1.5">
                                                <div class="description-clamp"
                                                    :class="{ 'expanded': expandedDescriptions[ms.id] }">
                                                    <p class="text-xs text-gray-600 dark:text-slate-400">
                                                        {{ ms.description }}
                                                    </p>
                                                </div>
                                                <button v-if="ms.description.length > 80"
                                                    @click="expandedDescriptions[ms.id] = !expandedDescriptions[ms.id]"
                                                    class="text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline mt-0.5">
                                                    {{ expandedDescriptions[ms.id] ? 'Toon minder' : 'Lees meer' }}
                                                </button>
                                            </div>
                                            <div v-if="editingMilestoneId === ms.id" key="edit"
                                                class="mt-3 p-3 rounded-md bg-gray-50 dark:bg-slate-800 ring-1 ring-gray-200 dark:ring-slate-700 space-y-3">
                                                <div>
                                                    <span
                                                        class="text-xs font-bold text-gray-600 dark:text-slate-400">Titel</span>
                                                    <EditableTextField v-model="editForms[ms.id].title"
                                                        class="w-full" />
                                                </div>
                                                <div>
                                                    <span
                                                        class="text-xs font-bold text-gray-600 dark:text-slate-400">Omschrijving</span>
                                                    <EditableTextField v-model="editForms[ms.id].description"
                                                        type="textarea" class="w-full" />
                                                </div>
                                                <div>
                                                    <span
                                                        class="text-xs font-bold text-gray-600 dark:text-slate-400">Toegewezen
                                                        gebruiker</span>
                                                    <ComboBox :options="users"
                                                        :modelValue="editForms[ms.id].assigned_user_id"
                                                        @update:modelValue="(val) => { editForms[ms.id].assigned_user_id = val; patchMilestoneField(ms.id, 'assigned_user_id', val) }"
                                                        :initialId="ms.assigned_user?.id"
                                                        placeholder="Selecteer gebruiker" />
                                                </div>
                                                <div>
                                                    <span
                                                        class="text-xs font-bold text-gray-600 dark:text-slate-400">Geplande
                                                        datum</span>
                                                    <EditableTextField v-model="editForms[ms.id].projected_date"
                                                        type="input" input-type="date" class="w-full" />
                                                </div>
                                                <div>
                                                    <span
                                                        class="text-xs font-bold text-gray-600 dark:text-slate-400">Werkelijke
                                                        datum</span>
                                                    <EditableTextField v-model="editForms[ms.id].actual_date"
                                                        type="input" input-type="date" class="w-full" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import { ClipboardDocumentListIcon, FlagIcon, PencilSquareIcon, TrashIcon, CheckIcon, ClockIcon, UserIcon, BuildingOfficeIcon, CalendarIcon } from '@heroicons/vue/24/outline'
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue'
import { formatLocalDateAsISO, nlDate, mapsLinkFromCustomer } from '@/Utilities/Utilities'

const props = defineProps({
    project: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
})

const initialStatus = props.statuses.find(s => s.name === props.project.status)

const sortedMilestones = computed(() =>
    (props.project.milestones || []).slice().sort((a, b) => {
        const dateA = a.projected_date || a.actual_date || ''
        const dateB = b.projected_date || b.actual_date || ''
        return dateA.localeCompare(dateB)
    })
)

function milestoneColor(ms) {
    if (ms.actual_date) return 'bg-green-600'
    if (!ms.projected_date) return 'bg-gray-400'
    const today = new Date().toISOString().substring(0, 10)
    if (ms.projected_date < today) return 'bg-red-500'
    return 'bg-blue-600'
}

const expandedDescriptions = reactive({})

const form = useForm({
    title: props.project.title,
    description: props.project.description,
    start_date: props.project.start_date?.substring(0, 10) ?? null,
    end_date: props.project.end_date?.substring(0, 10) ?? null,
    customer_id: props.project.customer_id,
    project_manager_id: props.project.project_manager_id,
    status: initialStatus?.id ?? props.project.status,
})

function patchField(field, value) {
    form.transform(() => ({
        [field]: value,
    })).patch(`/projects/${props.project.id}`, {
        preserveScroll: true,
    })
}

['title', 'description', 'start_date', 'end_date', 'customer_id', 'project_manager_id'].forEach(field =>
    watch(() => form[field], val => patchField(field, val))
)
watch(() => form.status, (val) => {
    const statusName = props.statuses.find(s => s.id === val)?.name || val
    patchField('status', statusName)
})

const showMilestoneForm = ref(false)
const milestoneFormRef = ref(null)

function toggleMilestoneForm() {
    showMilestoneForm.value = !showMilestoneForm.value
    if (showMilestoneForm.value) {
        milestoneFormRef.value?.show()
    } else {
        milestoneFormRef.value?.hide()
    }
}

const milestoneFields = [
    { key: 'project_id', type: 'number', default: props.project.id, label: '', class: 'hidden' },
    { key: 'title', label: 'Titel', type: 'text' },
    { key: 'assigned_user_id', label: 'Toegewezen gebruiker', type: 'combobox', options: props.users },
    { key: 'projected_date', label: 'Geplande datum', type: 'date' },
    { key: 'actual_date', label: 'Werkelijke datum', type: 'date' },
    { key: 'description', label: 'Omschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
]

const editingMilestoneId = ref(null)
const editForms = reactive({})
const milestoneForm = useForm({})
let milestoneWatchStops = []

function patchMilestoneField(milestoneId, field, value) {
    milestoneForm.transform(() => ({ [field]: value })).patch(`/projectmilestones/${milestoneId}`, {
        preserveScroll: true,
    })
}

function toggleEditMilestone(ms) {
    if (editingMilestoneId.value === ms.id) {
        editingMilestoneId.value = null
        milestoneWatchStops.forEach(stop => stop())
        milestoneWatchStops = []
        return
    }

    milestoneWatchStops.forEach(stop => stop())
    milestoneWatchStops = []

    editingMilestoneId.value = ms.id

    editForms[ms.id] = reactive({
        title: ms.title,
        description: ms.description,
        projected_date: ms.projected_date?.substring(0, 10) ?? null,
        actual_date: ms.actual_date?.substring(0, 10) ?? null,
        assigned_user_id: ms.assigned_user_id,
    })

        ;['title', 'description', 'projected_date', 'actual_date'].forEach(field => {
            const stop = watch(() => editForms[ms.id]?.[field], (val, oldVal) => {
                if (val === oldVal) return
                patchMilestoneField(ms.id, field, val)
            })
            milestoneWatchStops.push(stop)
        })
}

function deleteMilestone(id) {
    if (!confirm('Weet je zeker dat je deze mijlpaal wilt verwijderen?')) return
    milestoneForm.delete(`/projectmilestones/${id}`, { preserveScroll: true })
}

function markMilestoneActualToday(ms) {
    if (ms.actual_date) return
    patchMilestoneField(ms.id, 'actual_date', formatLocalDateAsISO(new Date()))
}

const serviceOrderForm = useForm({
    customer_id: props.project.customer_id,
    project_id: props.project.id,
})

function createServiceOrder() {
    serviceOrderForm.post('/serviceorders', { preserveScroll: true })
}
</script>

<style scoped>
.description-clamp {
    max-height: 2.8em;
    overflow: hidden;
    transition: max-height 0.35s ease;
}

.description-clamp.expanded {
    max-height: 40em;
}
</style>
