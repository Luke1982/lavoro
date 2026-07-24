<template>
    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader v-for="(chapter, index) in chapters" :key="index" :index="index">
                {{ chapter }}
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>
            <!-- Chapter 0: Project -->
            <template #chapter-0>
            <TwoThirdsOneThird>
                <template #main>
                    <BoxComponent>
                        <SectionHeader :icon="DocumentTextIcon" title="Details"
                            subtitle="Titel, looptijd en locatie van dit project." chapter="details" />
                        <StepsProgressBar :steps="statuses" v-model="form.status"
                            class="mb-4 border-b border-gray-200 dark:border-slate-700 pb-4"
                            :class="{ 'pointer-events-none': !canUpdate }" />
                        <div class="grid grid-cols-12 gap-0 sm:gap-4">
                            <div class="col-span-12 md:col-span-2 text-slate-400">
                                <span class="text-xs font-bold">Titel</span>
                            </div>
                            <div class="col-span-12 md:col-span-10">
                                <EditableTextField v-model="form.title" class="w-full" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 md:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Omschrijving</span>
                            </div>
                            <div class="col-span-12 md:col-span-10">
                                <EditableTextField v-model="form.description" type="textarea" class="w-full" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 md:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Locatie</span>
                            </div>
                            <div class="col-span-12 md:col-span-10">
                                <EditableTextField v-model="form.location" class="w-full" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Klant</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10">
                                <EditableTextField type="combobox" v-model="form.customer_id" :options="customers"
                                    :error="form.errors.customer_id" @revert="form.clearErrors('customer_id')"
                                    :readonly="!canUpdate">
                                    <template #display>
                                        <Link v-if="project.customer" :href="`/customers/${project.customer.id}`"
                                            class="text-lavoro-blue hover:underline">
                                            {{ project.customer.name }}
                                        </Link>
                                        <span v-else class="text-gray-400">Selecteer klant</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="cols-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Projectleider</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField type="combobox" v-model="form.project_manager_id" :options="users"
                                    :error="form.errors.project_manager_id" @revert="form.clearErrors('project_manager_id')"
                                    :readonly="!canUpdate">
                                    <template #display>
                                        <span v-if="project.project_manager">{{ project.project_manager.name }}</span>
                                        <span v-else class="text-gray-400">Selecteer projectleider</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Startdatum</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField v-model="form.start_date" type="input" input-type="date" class="w-full" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Einddatum</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField v-model="form.end_date" type="input" input-type="date" class="w-full" :readonly="!canUpdate" />
                            </div>
                        </div>
                        <div v-if="canCreateMilestone" class="mt-4 pt-3 border-t border-gray-200 dark:border-slate-700 flex justify-end">
                            <button @click="showMilestoneDrawer = true"
                                class="px-3 py-1.5 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90 inline-flex items-center gap-1.5">
                                <FlagIcon class="size-4" />
                                Mijlpaal toevoegen
                            </button>
                        </div>
                    </BoxComponent>

                    <BoxComponent class="mt-4">
                        <SectionHeader :icon="CalendarIcon" title="Tijdlijn"
                            subtitle="De looptijd van dit project met de mijlpalen erop uitgezet." chapter="timeline" border />
                        <ProjectTimeline :project-id="project.id" :project-start-date="project.start_date"
                            :project-end-date="project.end_date" :project-milestones="project.milestones" />
                    </BoxComponent>

                    <BoxComponent class="mt-4">
                        <SectionHeader :icon="ClipboardDocumentListIcon" title="Werkbonnen"
                            subtitle="Het werk dat onder dit project wordt uitgevoerd." chapter="serviceorders" border>
                            <template #actions>
                                <button @click="createServiceOrder"
                                    class="px-3 py-1.5 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90">
                                    Werkbon aanmaken
                                </button>
                            </template>
                        </SectionHeader>
                        <div class="space-y-2" v-auto-animate>
                            <div v-if="!project.service_orders?.length" key="empty"
                                class="text-xs text-gray-500 dark:text-slate-500">
                                Nog geen werkbonnen
                            </div>
                            <ServiceOrderRow v-for="so in project.service_orders" :key="so.id" :serviceorder="so" />
                        </div>
                    </BoxComponent>

                    <DocumentUploadComponent :existing="project.documents" :documentable-id="project.id"
                        documentable-type="\App\Models\Project" class="mt-4" />

                    <BoxComponent class="mt-4">
                        <ImageUploadComponent :existing="project.images" :imageable-id="project.id"
                            imageable-type="\App\Models\Project" />
                    </BoxComponent>
                </template>
                <template #sidebar>
                    <BoxComponent v-if="project.customer" class="mt-4 sm:mt-0">
                        <SectionHeader :icon="BuildingOfficeIcon" title="Klant"
                            subtitle="De opdrachtgever van dit project." chapter="customer" border />
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
                        <SectionHeader :icon="FlagIcon" title="Mijlpalen"
                            subtitle="De ijkpunten waarlangs dit project vordert." chapter="milestones" border />

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
                                                <span v-if="!canUpdateMilestone"
                                                    :class="[milestoneColor(ms), 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm']">
                                                    <CheckIcon v-if="ms.actual_date" class="size-4 text-white" />
                                                    <ClockIcon v-else class="size-4 text-white" />
                                                </span>
                                                <button v-else type="button"
                                                    v-tooltip="ms.actual_date ? 'Markeer als niet afgerond' : 'Markeer als afgerond met vandaag als werkelijke datum'"
                                                    @click="toggleMilestoneComplete(ms)"
                                                    :class="[milestoneColor(ms), 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm cursor-pointer hover:brightness-110']">
                                                    <CheckIcon v-if="ms.actual_date" class="size-4 text-white" />
                                                    <ClockIcon v-else class="size-4 text-white" />
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
                                                        <PencilSquareIcon v-if="canUpdateMilestone"
                                                            class="size-4 text-gray-400 dark:text-slate-500 hover:text-gray-700 dark:hover:text-slate-200 cursor-pointer"
                                                            @click="toggleEditMilestone(ms)" />
                                                        <TrashIcon v-if="canDeleteMilestone"
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
                                                            <EditableTextField type="combobox"
                                                                v-model="editForms[ms.id].assigned_user_id" :options="users"
                                                                :error="milestoneForm.errors.assigned_user_id"
                                                                @revert="milestoneForm.clearErrors('assigned_user_id')">
                                                                <template #display>
                                                                    <span v-if="ms.assigned_user">{{ ms.assigned_user.name
                                                                        }}</span>
                                                                    <span v-else class="text-gray-400">Selecteer
                                                                        gebruiker</span>
                                                                </template>
                                                            </EditableTextField>
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

            <!-- Chapter 1: Administratie -->
            <template v-if="canManageFinancials" #chapter-1>
                <BoxComponent>
                    <SectionHeader :icon="CalculatorIcon" title="Administratie"
                        subtitle="Financiële aantekeningen bij dit project." chapter="financial" border>
                        <template #actions>
                            <span class="text-[11px]"
                                :class="financialNotesError ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-500 dark:text-slate-500'">
                                {{ financialNotesStatus }}
                            </span>
                        </template>
                    </SectionHeader>
                    <div v-if="financialNotesIsStale"
                        class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-700/60 dark:bg-amber-900/25">
                        <div class="flex items-center gap-2">
                            <ExclamationTriangleIcon class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-xs text-amber-900 dark:text-amber-200">
                                <span class="font-semibold">{{ financialNotesStaleBy || 'Iemand anders' }}</span>
                                heeft deze administratie inmiddels gewijzigd. Als je nu opslaat overschrijf je die
                                wijzigingen. Herlaad om de nieuwste versie te zien — niet-opgeslagen wijzigingen gaan
                                dan verloren.
                            </p>
                        </div>
                        <button type="button" @click="reloadFinancialNotes"
                            class="shrink-0 rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                            Herladen
                        </button>
                    </div>
                    <SpreadsheetComponent v-model="financialNotes" :min-dimensions="[6, 20]" />
                </BoxComponent>
            </template>
        </ChapterContents>
    </ChaptersComponent>

    <DrawerComponent v-model="showMilestoneDrawer" max-width-class="max-w-2xl" title="Nieuwe mijlpaal"
        subtitle="Vul onderstaande velden in om een mijlpaal aan dit project toe te voegen.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center p-4">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMilestoneForm.title" type="text"
                        :hasError="Boolean(newMilestoneForm.errors.title)"
                        :errorMessage="newMilestoneForm.errors.title" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-start">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200 sm:pt-2">Omschrijving</label>
                <div class="sm:col-span-2">
                    <textarea v-model="newMilestoneForm.description" rows="4"
                        class="block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm p-2"
                        placeholder="Optioneel"></textarea>
                    <p v-if="newMilestoneForm.errors.description" class="text-red-600 text-sm mt-1">
                        {{ newMilestoneForm.errors.description }}
                    </p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Toegewezen gebruiker</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="users" v-model="newMilestoneForm.assigned_user_id"
                        placeholder="Selecteer gebruiker" :hasError="Boolean(newMilestoneForm.errors.assigned_user_id)"
                        :errorMessage="newMilestoneForm.errors.assigned_user_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Geplande datum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMilestoneForm.projected_date" type="date"
                        :hasError="Boolean(newMilestoneForm.errors.projected_date)"
                        :errorMessage="newMilestoneForm.errors.projected_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Werkelijke datum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMilestoneForm.actual_date" type="date"
                        :hasError="Boolean(newMilestoneForm.errors.actual_date)"
                        :errorMessage="newMilestoneForm.errors.actual_date" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeMilestoneDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewMilestone" :disabled="newMilestoneForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import BoxComponent from '@/Components/BoxComponent.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import { ClipboardDocumentListIcon, DocumentTextIcon, FlagIcon, PencilSquareIcon, TrashIcon, CheckIcon, ClockIcon, UserIcon, BuildingOfficeIcon, CalendarIcon, CalculatorIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue'
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue'
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue'
import ChapterContents from '@/Components/Chapters/ChapterContents.vue'
import SpreadsheetComponent from '@/Components/UI/SpreadsheetComponent.vue'
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue'
import ProjectTimeline from '@/Components/Projects/ProjectTimeline.vue'
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'
import { formatLocalDateAsISO, nlDate, nlTime, mapsLinkFromCustomer, hasPermission } from '@/Utilities/Utilities'
import { useFinancialNotesFreshness } from '@/Composables/useFinancialNotesFreshness'

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

const canUpdate = computed(() => hasPermission('project.update'))
const canCreateMilestone = computed(() => hasPermission('projectmilestone.create'))
const canUpdateMilestone = computed(() => hasPermission('projectmilestone.update'))
const canDeleteMilestone = computed(() => hasPermission('projectmilestone.delete'))
const canManageFinancials = computed(() => hasPermission('project.manage_financials'))

const chapters = computed(() => canManageFinancials.value ? ['Project', 'Administratie'] : ['Project'])

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
    location: props.project.location ?? null,
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

['title', 'description', 'location', 'start_date', 'end_date', 'customer_id', 'project_manager_id'].forEach(field =>
    watch(() => form[field], val => patchField(field, val))
)
watch(() => form.status, (val) => {
    const statusName = props.statuses.find(s => s.id === val)?.name || val
    patchField('status', statusName)
})

const showMilestoneDrawer = ref(false)
const newMilestoneForm = useForm({
    project_id: props.project.id,
    title: '',
    description: '',
    assigned_user_id: null,
    projected_date: null,
    actual_date: null,
})

function submitNewMilestone() {
    newMilestoneForm.post('/projectmilestones', {
        preserveScroll: true,
        onSuccess: () => {
            showMilestoneDrawer.value = false
            newMilestoneForm.reset()
        },
    })
}

function closeMilestoneDrawer() {
    showMilestoneDrawer.value = false
    newMilestoneForm.reset()
    newMilestoneForm.clearErrors()
}

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

        ;['title', 'description', 'projected_date', 'actual_date', 'assigned_user_id'].forEach(field => {
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

function toggleMilestoneComplete(ms) {
    patchMilestoneField(ms.id, 'actual_date', ms.actual_date ? null : formatLocalDateAsISO(new Date()))
}

const financialNotes = ref(props.project.financial_notes ?? null)
const financialNotesSaving = ref(false)
const financialNotesError = ref(null)
const financialNotesSavedAt = ref(null)

let financialNotesQueued = null
let financialNotesCsrfReady = false

const {
    isStale: financialNotesIsStale,
    staleByName: financialNotesStaleBy,
    markSaved: markFinancialNotesSaved,
} = useFinancialNotesFreshness(props.project.id, {
    initialSavedAt: props.project.financial_notes_updated_at ?? null,
    isPaused: financialNotesSaving,
    enabled: canManageFinancials.value,
})

function reloadFinancialNotes() {
    window.location.reload()
}

const financialNotesStatus = computed(() => {
    if (financialNotesError.value) return financialNotesError.value
    if (financialNotesSaving.value) return 'Opslaan…'
    if (financialNotesSavedAt.value) return 'Opgeslagen om ' + financialNotesSavedAt.value
    return 'Wijzigingen worden automatisch opgeslagen'
})

async function saveFinancialNotes(grid) {
    if (financialNotesSaving.value) {
        financialNotesQueued = grid
        return
    }

    financialNotesSaving.value = true
    try {
        if (!financialNotesCsrfReady) {
            await axios.get('/sanctum/csrf-cookie')
            financialNotesCsrfReady = true
        }
        const response = await axios.patch(
            `/api/projects/${props.project.id}/financial-notes`,
            { financial_notes: grid }
        )
        financialNotesError.value = null
        financialNotesSavedAt.value = nlTime(response.data.saved_at)
        markFinancialNotesSaved(response.data.saved_at)
    } catch (e) {
        const status = e.response?.status
        if (status === 419 || status === 401) {
            financialNotesCsrfReady = false
        }
        financialNotesError.value = status === 422
            ? 'Opslaan mislukt — de tabel is te groot of bevat ongeldige cellen'
            : 'Opslaan mislukt — je wijzigingen zijn niet bewaard'
    } finally {
        financialNotesSaving.value = false

        if (financialNotesQueued) {
            const next = financialNotesQueued
            financialNotesQueued = null
            saveFinancialNotes(next)
        }
    }
}

watch(financialNotes, (val) => saveFinancialNotes(val))

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
