<template>
    <BoxComponent padding="p-6">
        <div class="flex items-center justify-between mb-5">
            <div class="flex">
                <ClipboardListIcon class="size-6 mr-2 flex-none object-cover" />
                <h2 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Uitgevoerde werkzaamheden / taken
                </h2>
            </div>
            <button v-if="canCreate" type="button" @click="addDrawerOpen = true"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-lavoro-blue hover:opacity-80 transition-opacity">
                <PlusIcon class="w-4 h-4" />
                Taak toevoegen
            </button>
        </div>

        <div v-auto-animate>
            <div v-if="internalInstances.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-2">
                Nog geen taken toegevoegd.
            </div>
            <div v-for="instance in internalInstances" :key="instance.id"
                class="flex items-center gap-3 py-3 border-b border-gray-100 dark:border-slate-800/60 last:border-0">
                <CheckboxComponent :model-value="instance.is_complete" :disabled="!canToggle"
                    @update:modelValue="toggleComplete(instance, $event)" />
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate">
                        {{ effectiveTitle(instance) }}
                    </p>
                    <p v-if="effectiveDescription(instance)"
                        class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 line-clamp-2">
                        {{ effectiveDescription(instance) }}
                    </p>
                </div>
                <BadgeComponent :color="instance.is_complete ? 'green' : 'gray'" :has-dot="false"
                    class="flex-none hidden sm:inline-flex">
                    {{ instance.is_complete ? 'Voltooid' : 'In uitvoering' }}
                </BadgeComponent>
                <button v-if="canEdit" type="button"
                    class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                    @click="openEditDrawer(instance)" v-tooltip="'Bewerk taak'">
                    <EllipsisVerticalIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
                </button>
                <button v-if="canDelete" type="button"
                    class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                    @click="deleteInstance(instance.id)" v-tooltip="'Verwijder taak'">
                    <TrashIcon class="w-4 h-4 text-red-500" />
                </button>
            </div>
        </div>

        <!-- Add drawer -->
        <DrawerComponent v-model="addDrawerOpen" title="Taak toevoegen">
            <div class="p-4 sm:p-6 flex flex-col gap-4">
                <ComboBox :options="taskOptions" v-model="newTaskId" label="Bestaande taak (optioneel)"
                    placeholder="Zoek een taak..." @update:modelValue="onNewTaskSelected" />
                <TextInput v-model="newTitle" label="Titel" placeholder="Laat leeg om de taaknaam te gebruiken" />
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Omschrijving</label>
                    <textarea v-model="newDescription" rows="4" placeholder="Omschrijving (optioneel)"
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <p v-if="addForm.errors.description || addForm.errors.title" class="text-xs text-red-600">
                    {{ addForm.errors.description || addForm.errors.title }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="addDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button"
                        :disabled="addForm.processing || (!newTaskId && !newTitle.trim() && !newDescription.trim())"
                        @click="addInstance"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>

        <!-- Edit drawer -->
        <DrawerComponent v-model="editDrawerOpen" title="Taak bewerken">
            <div class="p-4 sm:p-6 flex flex-col gap-4">
                <TextInput v-model="editTitle" label="Titel" placeholder="Laat leeg om de taaknaam te gebruiken" />
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Omschrijving</label>
                    <textarea v-model="editDescription" rows="4" placeholder="Omschrijving (optioneel)"
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <p v-if="editForm.errors.title || editForm.errors.description" class="text-xs text-red-600">
                    {{ editForm.errors.title || editForm.errors.description }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="editDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="editForm.processing" @click="saveEdit"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Plus as PlusIcon, Trash2 as TrashIcon, EllipsisVertical as EllipsisVerticalIcon, ClipboardListIcon } from '@lucide/vue'
import { hasPermission } from '@/Utilities/Utilities'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import CheckboxComponent from '@/Components/UI/AnimatedCheckbox.vue'

const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    instances: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
})

const canCreate = hasPermission('serviceordertaskinstance.create')
const canToggle = hasPermission('serviceordertaskinstance.open_close') || hasPermission('serviceordertaskinstance.update')
const canEdit = hasPermission('serviceordertaskinstance.update')
const canDelete = hasPermission('serviceordertaskinstance.delete')

const internalInstances = ref(props.instances.map(i => ({ ...i })))

watch(() => props.instances, (newVal) => {
    internalInstances.value = newVal.map(i => ({ ...i }))
}, { deep: true })

const taskOptions = computed(() => props.availableTasks.map(t => ({ id: t.id, name: t.title })))

// ── Add drawer ────────────────────────────────────────────────────────────────
const addDrawerOpen = ref(false)
const newTaskId = ref(null)
const newTitle = ref('')
const newDescription = ref('')

const addForm = useForm({
    service_order_id: props.serviceOrderId,
    service_order_task_id: null,
    title: '',
    description: '',
    is_complete: false,
})

function onNewTaskSelected(id) {
    if (id) {
        const task = props.availableTasks.find(t => t.id === id)
        if (task) {
            if (!newTitle.value) newTitle.value = task.title ?? ''
            if (!newDescription.value) newDescription.value = task.description ?? ''
        }
    } else {
        newDescription.value = ''
    }
}

function addInstance() {
    addForm.service_order_task_id = newTaskId.value
    addForm.title = newTitle.value.trim() || null
    addForm.description = newDescription.value.trim() || null

    addForm.post('/serviceordertaskinstances', {
        preserveScroll: true,
        onSuccess: () => {
            addDrawerOpen.value = false
            newTaskId.value = null
            newTitle.value = ''
            newDescription.value = ''
            addForm.reset()
            addForm.service_order_id = props.serviceOrderId
        },
    })
}

// ── Edit drawer ───────────────────────────────────────────────────────────────
const editDrawerOpen = ref(false)
const editingInstance = ref(null)
const editTitle = ref('')
const editDescription = ref('')

const editForm = useForm({ title: '', description: '' })

function openEditDrawer(instance) {
    editingInstance.value = instance
    editTitle.value = instance.title ?? ''
    editDescription.value = instance.description ?? instance.service_order_task?.description ?? ''
    editDrawerOpen.value = true
}

function saveEdit() {
    editForm.title = editTitle.value.trim() || null
    editForm.description = editDescription.value.trim() || null

    editForm.patch(`/serviceordertaskinstances/${editingInstance.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingInstance.value.title = editForm.title
            editingInstance.value.description = editForm.description
            editDrawerOpen.value = false
            editForm.reset()
        },
    })
}

// ── Toggle complete ───────────────────────────────────────────────────────────
function toggleComplete(instance, newValue) {
    if (!canToggle) return
    const previous = instance.is_complete
    instance.is_complete = newValue
    useForm({ is_complete: newValue }).patch(`/serviceordertaskinstances/${instance.id}/toggle`, {
        preserveScroll: true,
        onError: () => { instance.is_complete = previous },
    })
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteInstance(id) {
    if (!canDelete) return
    if (!confirm('Weet je zeker dat je deze taak wilt verwijderen?')) return
    useForm({}).delete(`/serviceordertaskinstances/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            const idx = internalInstances.value.findIndex(i => i.id === id)
            if (idx !== -1) internalInstances.value.splice(idx, 1)
        },
    })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function effectiveTitle(instance) {
    return instance.title || instance.service_order_task?.title || '(geen titel)'
}

function effectiveDescription(instance) {
    return instance.description || instance.service_order_task?.description || ''
}
</script>
