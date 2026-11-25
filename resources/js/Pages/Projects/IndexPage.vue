<template>
    <IndexHeaderComponent ref="header" title="Projecten" subtitle="Overzicht van alle projecten" search-url="/projects"
        search-label="Zoek binnen projecten" search-placeholder="Zoek op nummer, titel, klant of projectleider"
        :search-other-params="otherParams" add-label="Voeg project toe" @add="showCreateDrawer = true"
        :can-add="hasPermission('project.create')" :has-active-filters="hasActiveFilters">
        <template #actions>
            <TourButton tour="projecten" />
        </template>
        <template #filters>
            <ListFilters :filters="filters" :query="list" class="grid grid-cols-1 gap-2 sm:grid-cols-3" />
        </template>
    </IndexHeaderComponent>

    <ProjectCreateDrawer v-model="showCreateDrawer" :customers="customers" :customers-use-ajax="customersUseAjax"
        :users="users" :statuses="statuses" :templates="templates" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProjects.length">
            <div role="row" data-tour="projects.sort"
                class="hidden lg:grid grid-cols-12 gap-x-2 font-bold text-sm border-b-lavoro-darkergray dark:border-b-slate-600 rounded-t-lavoro-sm p-4 bg-lavoro-lightgray dark:bg-slate-800/50">
                <SortableColumnHeader class="col-span-1" column="number" v-bind="sorting" @sort="sortBy">
                    Nummer
                </SortableColumnHeader>
                <SortableColumnHeader class="col-span-3" column="title" v-bind="sorting" @sort="sortBy">
                    Titel
                </SortableColumnHeader>
                <SortableColumnHeader class="col-span-2" column="customer" v-bind="sorting" @sort="sortBy">
                    Klant
                </SortableColumnHeader>
                <div class="col-span-1">Leider</div>
                <SortableColumnHeader class="col-span-1" column="end_date" v-bind="sorting" @sort="sortBy">
                    Einde
                </SortableColumnHeader>
                <div class="col-span-2">Voortgang</div>
                <SortableColumnHeader class="col-span-1" column="status" v-bind="sorting" @sort="sortBy">
                    Status
                </SortableColumnHeader>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="project in displayProjects" :key="project.id" role="row" data-tour="projects.row"
                class="grid grid-cols-12 gap-x-2 p-4 text-sm border-b-lavoro-gray-150 dark:border-b-slate-800 border-b-2 items-center">
                <div class="hidden lg:block col-span-1 text-xs font-medium text-gray-500 dark:text-slate-400 tabular-nums">
                    {{ project.number }}
                </div>
                <div class="col-span-10 lg:col-span-3 flex flex-col min-w-0">
                    <Link :href="`/projects/${project.id}`" class="font-bold mb-1 text-lavoro-darkerblue truncate">
                        {{ project.title }}
                    </Link>
                    <span class="text-xs text-gray-500 dark:text-slate-400 lg:hidden">
                        {{ project.number }} · {{ project.customer?.name || '—' }}
                    </span>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ period(project) }}</span>
                    <span :class="projectStatusClass(project.status)" class="text-xs lg:hidden">{{ project.status }}</span>
                </div>
                <div class="col-span-2 hidden lg:flex items-center text-slate-700 dark:text-slate-300 min-w-0">
                    <span class="truncate">{{ project.customer?.name || '—' }}</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center text-slate-700 dark:text-slate-300 min-w-0">
                    <span class="truncate">{{ project.project_manager?.name || '—' }}</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center text-xs text-slate-700 dark:text-slate-300">
                    {{ project.end_date ? nlDate(project.end_date) : '–' }}
                </div>
                <div class="col-span-2 hidden lg:flex flex-col gap-1 text-xs text-slate-600 dark:text-slate-400" data-cell="progress">
                    <div class="flex items-center gap-2">
                        <div class="h-1.5 w-16 shrink-0 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                            <div class="h-full rounded-full bg-lavoro-blue"
                                :style="{ width: share(project.closed_service_orders_count, project.service_orders_count) }" />
                        </div>
                        <span>{{ project.closed_service_orders_count }}/{{ project.service_orders_count }} werkbonnen</span>
                    </div>
                    <div v-if="project.milestones_count">
                        {{ project.reached_milestones_count }}/{{ project.milestones_count }} mijlpalen
                        <span v-if="project.overdue_milestones_count" class="text-red-600 dark:text-red-400 font-medium">
                            · {{ project.overdue_milestones_count }} te laat
                        </span>
                    </div>
                </div>
                <div class="col-span-1 hidden lg:flex items-center" data-cell="status">
                    <EditableTextField v-if="hasPermission('project.update')" type="combobox" :model-value="statusId(project.status)"
                        :options="statuses" :decoration="false" @update="(value) => updateStatus(project, value)">
                        <template #display>
                            <span :class="projectStatusClass(project.status)" class="whitespace-nowrap">{{ project.status }}</span>
                        </template>
                    </EditableTextField>
                    <span v-else :class="projectStatusClass(project.status)" class="whitespace-nowrap">{{ project.status }}</span>
                </div>
                <div class="col-span-2 lg:col-span-1 flex items-center justify-end">
                    <div class="border-1 border-lavoro-darkergray dark:border-slate-600 rounded-full p-2 flex flex-col sm:flex-row">
                        <Link :href="`/projects/${project.id}`" class="text-sm text-lavoro-darkerblue">
                            <EyeIcon class="h-5 w-5" />
                        </Link>
                        <div v-if="hasPermission('project.delete')" data-cell="delete"
                            class="ml-0 sm:ml-2 border-l-lavoro-darkblue border-l-0 sm:border-l-1 border-t-1 sm:border-t-0 pl-0 sm:pl-2 pt-2 sm:pt-0"
                            v-tooltip="project.service_orders_count ? 'Dit project heeft werkbonnen. Zet het op Geannuleerd als het niet doorgaat.' : null">
                            <TrashIcon v-if="project.service_orders_count" class="h-5 w-5 cursor-not-allowed text-gray-300 dark:text-slate-600" />
                            <TrashIcon v-else class="h-5 w-5 cursor-pointer text-red-500" @click="deleteProject(project)" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
                <PageRecordCountComponent :total="projects.total" :per-page="projects.per_page" label="projecten" />
                <PaginationComponent :paginator="projects" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400 dark:text-slate-500">
                <FolderKanbanIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen projecten gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ListFilters from '@/Components/UI/ListFilters.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import SortableColumnHeader from '@/Components/UI/SortableColumnHeader.vue'
import ProjectCreateDrawer from '@/Components/Projects/ProjectCreateDrawer.vue'
import TourButton from '@/Components/Tour/TourButton.vue'
import { EyeIcon, TrashIcon, FolderKanbanIcon } from '@lucide/vue'
import { hasPermission, nlDate, projectStatusClass } from '@/Utilities/Utilities'
import { useComboSearch } from '@/Composables/useComboSearch'
import { useQueryParams } from '@/Composables/useQueryParams'
import { useTour } from '@/Composables/useTour'
import { defaultStatusWith } from '@/Utilities/assistantFilter'

const props = defineProps({
    projects: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    /** The filters and sorting in effect, defaults included. */
    list: { type: Object, required: true },
})

const RUNNING = 'running'

const showCreateDrawer = ref(false)
const header = ref(null)
const displayProjects = computed(() => props.projects?.data || [])
const { visit } = useQueryParams()

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

/** The list opens on the projects still running, so emptying the status is said out loud as 'all'. */
const filters = computed(() => [
    {
        param: 'status',
        placeholder: 'Alle statussen',
        everything: 'Alle statussen',
        emptied: 'all',
        options: [
            { id: RUNNING, name: 'Lopende projecten' },
            ...props.statuses.map(status => ({ id: status.name, name: status.name })),
        ],
    },
    { param: 'manager', placeholder: 'Alle projectleiders', everything: 'Alle projectleiders', options: props.users },
    {
        param: 'customer',
        placeholder: 'Alle klanten',
        everything: 'Alle klanten',
        options: customerOptions.value,
        search: props.customersUseAjax ? searchCustomers : null,
        searching: customersSearching.value,
    },
])

const page = usePage()

/** With the assistant's filter the list opens on every status, so 'Lopende projecten' has to be asked for out loud. */
const defaultStatus = computed(() => defaultStatusWith(RUNNING, new URL(page.url, window.location.origin).search))

const hasActiveFilters = computed(() =>
    props.list.status !== defaultStatus.value || props.list.manager !== null || props.list.customer !== null
)

const otherParams = computed(() => ({
    status: props.list.status === defaultStatus.value ? undefined : props.list.status,
    manager: props.list.manager ?? undefined,
    customer: props.list.customer ?? undefined,
    sort: props.list.sort ?? undefined,
    direction: props.list.sort ? props.list.direction : undefined,
}))

const sorting = computed(() => ({ sort: props.list.sort ?? '', direction: props.list.direction }))

function sortBy({ sort, direction }) {
    visit({ sort, direction })
}

function share(part, whole) {
    return whole ? Math.round((part / whole) * 100) + '%' : '0%'
}

function period(project) {
    if (!project.start_date && !project.end_date) return 'Geen looptijd'
    return (project.start_date ? nlDate(project.start_date) : '…') + ' – ' + (project.end_date ? nlDate(project.end_date) : '…')
}

function statusId(name) {
    return props.statuses.find(status => status.name === name)?.id ?? null
}

function updateStatus(project, id) {
    const status = props.statuses.find(option => option.id === id)?.name
    if (!status || status === project.status) return
    router.patch(`/projects/${project.id}`, { status }, { preserveScroll: true, preserveState: true })
}

function deleteProject(project) {
    const milestones = project.milestones_count
        ? ` en ${project.milestones_count === 1 ? 'zijn mijlpaal' : `zijn ${project.milestones_count} mijlpalen`}`
        : ''
    if (!confirm(`Project ${project.number} ${project.title}${milestones} verwijderen? Dit kan niet ongedaan worden gemaakt.`)) return
    router.delete(`/projects/${project.id}`, { preserveScroll: true })
}

const firstRow = () => document.querySelector('[data-tour="projects.row"]')

/**
 * The projects rondleiding opens the filters and the drawer for a new project to
 * show what is in them, and points at the cells of the first project in the list.
 */
useTour('projecten', {
    openFilters: () => header.value?.openFilters() ?? null,
    openCreate: () => {
        if (showCreateDrawer.value) return null
        showCreateDrawer.value = true

        return () => { showCreateDrawer.value = false }
    },
}, {
    spots: {
        'projects.progress': () => firstRow()?.querySelector('[data-cell=progress]') ?? null,
        'projects.status': () => firstRow()?.querySelector('[data-cell=status]') ?? null,
        'projects.delete': () => firstRow()?.querySelector('[data-cell=delete]') ?? null,
    },
})
</script>
