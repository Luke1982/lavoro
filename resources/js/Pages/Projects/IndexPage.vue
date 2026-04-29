<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent title="Projecten" subtitle="Overzicht van alle projecten" search-url="/projects"
            search-label="Zoek binnen projecten" search-placeholder="Zoek op titel, klant of projectleider"
            :paginator="projects" add-label="Voeg project toe" @add="() => projectFormRef?.show()" />
    </div>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="projectFormRef" external-trigger action="/projects" :fields="projectFields"
            add-button-label="Voeg project toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="displayProjects.length"
            class="mt-3 sm:-mx-0 rounded-md border border-gray-300 dark:border-slate-700/60 bg-white dark:bg-slate-900 p-px transition-colors"
            role="table">
            <div class="hidden lg:grid lg:grid-cols-[25fr_20fr_18fr_15fr_22fr_56px]" role="row">
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100 rounded-tl-md">
                    Titel</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100">
                    Klant</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100">
                    Projectleider</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100">
                    Status</div>
                <div role="columnheader"
                    class="px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 dark:bg-slate-700 dark:text-slate-100">
                    Periode</div>
                <div class="px-4 py-2 bg-gray-600 dark:bg-slate-700 rounded-tr-md flex items-center justify-end gap-3">
                    <span class="text-white text-sm font-semibold opacity-0">Acties</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900" role="rowgroup" v-auto-animate>
                <div v-for="project in displayProjects" :key="project.id" role="row"
                    class="even:bg-gray-50 dark:even:bg-slate-800/70 dark:bg-slate-900 grid grid-cols-12 py-3 lg:grid lg:grid-cols-[25fr_20fr_18fr_15fr_22fr_56px] lg:items-stretch border-b border-gray-100 dark:border-slate-800/60 last:border-b-0">
                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 lg:col-span-1 text-gray-800 dark:text-slate-200">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Titel</span>
                        <Link :href="`/projects/${project.id}`" class="text-blue-500 dark:text-blue-300 underline">
                            {{ project.title }}
                        </Link>
                    </div>

                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 lg:col-span-1 text-gray-800 dark:text-slate-200">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Klant</span>
                        <span>{{ project.customer?.name }}</span>
                    </div>

                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 lg:col-span-1 text-gray-800 dark:text-slate-200">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Projectleider</span>
                        <span>{{ project.project_manager?.name }}</span>
                    </div>

                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 lg:col-span-1 text-gray-800 dark:text-slate-200">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Status</span>
                        <span :class="projectStatusClass(project.status)">{{ project.status }}</span>
                    </div>

                    <div role="cell"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 lg:col-span-1 text-gray-800 dark:text-slate-200">
                        <span
                            class="text-xs font-light mb-1.5 block lg:hidden text-gray-600 dark:text-slate-400">Periode</span>
                        <span>
                            {{ project.start_date ? formatDate(project.start_date) : '–' }}
                            –
                            {{ project.end_date ? formatDate(project.end_date) : '–' }}
                        </span>
                    </div>

                    <div class="px-4 py-2 text-right flex items-center justify-end gap-3 col-span-12 lg:col-span-1">
                        <TrashIcon
                            class="inline h-5 w-5 text-red-400 dark:text-red-300 hover:text-red-600 dark:hover:text-red-400 cursor-pointer"
                            @click.stop="deleteProject(project.id)" />
                    </div>
                </div>
            </div>
        </div>
        <PaginationComponent v-if="displayProjects.length" :paginator="projects"
            class="border-t border-gray-200 pt-2 dark:border-slate-700/60" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen projecten gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { ref } from 'vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import { TrashIcon } from '@heroicons/vue/24/outline'
import { projectStatusClass } from '@/Utilities/Utilities'

const { projects, customers, users, statuses } = defineProps({
    projects: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    search: { type: String, default: '' },
})

const projectFormRef = ref(null)
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
    return new Date(dateStr).toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function deleteProject(id) {
    if (!confirm('Weet je zeker dat je dit project wilt verwijderen?')) return
    useForm({}).delete(`/projects/${id}`, { preserveScroll: true })
}
</script>
