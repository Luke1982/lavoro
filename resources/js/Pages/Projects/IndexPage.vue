<template>
    <IndexHeaderComponent title="Projecten" subtitle="Overzicht van alle projecten" search-url="/projects"
        search-label="Zoek binnen projecten" search-placeholder="Zoek op titel, klant of projectleider"
        :paginator="false" add-label="Voeg project toe" @add="() => projectFormRef?.show()"
        :can-add="hasPermission('project.create')" />
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="projectFormRef" external-trigger action="/projects" :fields="projectFields"
            add-button-label="Voeg project toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProjects.length">
            <div
                class="hidden lg:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-4">Titel</div>
                <div class="col-span-2">Klant</div>
                <div class="col-span-2">Projectleider</div>
                <div class="col-span-2">Status</div>
                <div class="col-span-1">Periode</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="project in displayProjects" :key="project.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <div class="col-span-10 lg:col-span-4 flex flex-col">
                    <Link :href="`/projects/${project.id}`" class="font-bold mb-1 text-lavoro-darkerblue">
                        {{ project.title }}
                    </Link>
                    <div class="flex lg:hidden gap-2 mt-1">
                        <span :class="projectStatusClass(project.status)" class="text-xs">{{ project.status }}</span>
                    </div>
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2 text-slate-700 dark:text-slate-300">
                    {{ project.customer?.name || '—' }}
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2 text-slate-700 dark:text-slate-300">
                    {{ project.project_manager?.name || '—' }}
                </div>
                <div class="col-span-2 items-center hidden lg:flex pr-2">
                    <EditableTextField type="combobox" :model-value="project.status" :options="statuses"
                        :decoration="false"
                        @update="(val) => updateProject(project, { status: val })">
                        <template #display>
                            <span :class="projectStatusClass(project.status)">{{ project.status }}</span>
                        </template>
                    </EditableTextField>
                </div>
                <div class="col-span-1 items-center hidden lg:flex pr-2 text-slate-700 dark:text-slate-300 text-xs">
                    {{ project.start_date ? formatDate(project.start_date) : '–' }}
                    <span class="mx-0.5">–</span>
                    {{ project.end_date ? formatDate(project.end_date) : '–' }}
                </div>
                <div class="col-span-2 lg:col-span-1 flex items-center justify-end">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex flex-col sm:flex-row">
                        <Link :href="`/projects/${project.id}`" class="text-sm text-lavoro-darkerblue">
                            <EyeIcon class="h-5 w-5" />
                        </Link>
                        <div v-if="hasPermission('project.delete')"
                            class="ml-0 sm:ml-2 border-l-lavoro-darkblue border-l-0 sm:border-l-1 border-t-1 sm:border-t-0 pl-0 sm:pl-2 pt-2 sm:pt-0">
                            <TrashIcon class="h-5 w-5 cursor-pointer text-red-500"
                                @click="deleteProject(project.id)" />
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
            <div class="text-gray-400">
                <FolderKanbanIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen projecten gevonden</p>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import { EyeIcon, TrashIcon, FolderKanbanIcon } from '@lucide/vue'
import { hasPermission, projectStatusClass } from '@/Utilities/Utilities'

const projectFormRef = ref(null)

const { projects, customers, users, statuses } = defineProps({
    projects: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    search: { type: String, default: '' },
})

const displayProjects = computed(() => projects?.data || [])

const projectFields = [
    { key: 'title', label: 'Titel', type: 'text' },
    { key: 'customer_id', label: 'Klant', type: 'combobox', options: customers, initialId: customers[0]?.id },
    { key: 'project_manager_id', label: 'Projectleider', type: 'combobox', options: users, initialId: users[0]?.id },
    { key: 'status', label: 'Status', type: 'combobox', options: statuses, initialId: statuses[0]?.id, emitValue: true },
    { key: 'start_date', label: 'Startdatum', type: 'date' },
    { key: 'end_date', label: 'Einddatum', type: 'date' },
    { key: 'description', label: 'Omschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
]

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

function updateProject(project, patch) {
    router.patch(`/projects/${project.id}`, {
        title: project.title,
        customer_id: project.customer_id,
        project_manager_id: project.project_manager_id,
        status: project.status,
        ...patch,
    }, { preserveScroll: true, preserveState: true })
}

function deleteProject(id) {
    if (!confirm('Weet je zeker dat je dit project wilt verwijderen?')) return
    useForm({}).delete(`/projects/${id}`, { preserveScroll: true, preserveState: true })
}
</script>
