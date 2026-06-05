<template>
    <IndexHeaderComponent title="Werkbontaken" subtitle="Beheer herbruikbare taakomschrijvingen voor werkbonnen"
        search-url="/serviceordertasks" search-label="Zoek binnen taken" search-placeholder="bijv. 'Olie verversen'"
        :paginator="false" add-label="Voeg taak toe" @add="showTaskDrawer = true" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="internalTasks.length">
            <div
                class="hidden md:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Titel</div>
                <div class="col-span-7">Beschrijving</div>
                <div class="col-span-2 text-right">Acties</div>
            </div>
            <div v-auto-animate>
            <div v-for="task in internalTasks" :key="task.id" role="row"
                class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <div class="col-span-3 pr-4">
                    <EditableTextField type="input" :decoration="false" :model-value="task.title"
                        @update="(val) => saveTask(task.id, { title: val })" />
                </div>
                <div class="col-span-7 pr-4">
                    <EditableTextField type="input" :decoration="false" :model-value="task.description"
                        @update="(val) => saveTask(task.id, { description: val })" />
                </div>
                <div class="col-span-2 flex justify-end">
                    <div class="border-1 border-lavoro-darkergray rounded-full p-2 flex">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteTask(task.id)"
                            v-tooltip="'Verwijder deze taak'" />
                    </div>
                </div>
            </div>
            </div>
            <div class="flex justify-between bg-white rounded-b-lavoro-sm p-4 dark:bg-slate-900">
                <PageRecordCountComponent :total="tasks.total" :per-page="perPage" label="taken" />
                <PaginationComponent :paginator="tasks" />
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <ClipboardListIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen taken gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="showTaskDrawer" title="Nieuwe taak"
        subtitle="Vul een omschrijving in om een herbruikbare werkbontaak toe te voegen.">
        <div class="">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newTaskForm.title" type="text" :hasError="Boolean(newTaskForm.errors.title)"
                        :errorMessage="newTaskForm.errors.title" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Beschrijving</label>
                <div class="sm:col-span-2">
                    <textarea v-model="newTaskForm.description" rows="4"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 bg-white dark:bg-slate-800 text-gray-800 dark:text-slate-200 placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Vul hier een omschrijving in die later kan worden hergebruikt bij het aanmaken van werkbontaken."></textarea>
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeTaskDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewTask" :disabled="newTaskForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed">
                    Aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { ClipboardList as ClipboardListIcon } from '@lucide/vue'
import { TrashIcon } from '@lucide/vue'
import TextInput from '@/Components/UI/TextInput.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'

const { tasks, perPage } = defineProps({
    tasks: { type: Object, required: true },
    search: { type: String, default: '' },
    perPage: { type: Number, default: 25 },
})

const showTaskDrawer = ref(false)
const newTaskForm = useForm({ title: '', description: '' })

function submitNewTask() {
    newTaskForm.post('/serviceordertasks', {
        preserveScroll: true,
        onSuccess: () => {
            showTaskDrawer.value = false
            newTaskForm.reset()
        },
    })
}

function closeTaskDrawer() {
    showTaskDrawer.value = false
    newTaskForm.reset()
    newTaskForm.clearErrors()
}

const internalTasks = ref((tasks.data || []).map(t => ({ ...t })))

watch(
    () => tasks.data,
    (newData) => {
        internalTasks.value = (newData || []).map(t => ({ ...t }))
    }
)

function saveTask(id, payload) {
    router.patch(`/serviceordertasks/${id}`, payload, { preserveScroll: true })
}

function deleteTask(id) {
    if (!confirm('Weet je zeker dat je deze taak wilt verwijderen?')) return
    useForm({}).delete(`/serviceordertasks/${id}`, { preserveScroll: true })
}
</script>
