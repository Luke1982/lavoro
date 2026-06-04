<template>
    <BoxComponent>
        <div class="flex items-center justify-between mb-5">
            <div class="flex">
                <ClipboardListIcon class="size-6 mr-2 flex-none object-cover" />
                <h2 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Werkbontaken
                </h2>
            </div>
            <button v-if="canCreate" type="button" @click="addDrawerOpen = true"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-lavoro-blue hover:opacity-80 transition-opacity ml-2 sm:ml-0 justify-center py-3 sm:pt-2 px-3 sm:px-0 border-1 border-gray-200 rounded-lavoro-sm sm:border-0 cursor-pointer">
                <PlusIcon class="w-4 h-4" />
                <span class="hidden sm:inline">Taak toevoegen</span>
            </button>
        </div>

        <div v-auto-animate>
            <div v-if="internalInstances.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-2">
                Nog geen taken toegevoegd.
            </div>
            <div v-for="instance in internalInstances" :key="instance.id"
                class="flex items-start gap-3 py-3 border-b border-gray-100 dark:border-slate-800/60 last:border-0">
                <CheckboxComponent :key="`cb-${instance.id}-${checkboxResetKeys[instance.id] ?? 0}`"
                    :model-value="instance.is_complete" :disabled="!canToggle"
                    @update:modelValue="toggleComplete(instance, $event)" />
                <div class="flex flex-col sm:flex-row justify-between flex-grow gap-y-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate">
                            {{ effectiveTitle(instance) }}
                        </p>
                        <p v-if="effectiveDescription(instance)"
                            class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 line-clamp-2">
                            {{ effectiveDescription(instance) }}
                        </p>
                        <p v-if="instance.product" class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">
                            {{ instance.quantity }}× {{ instance.product.brand.name }} {{ instance.product.model }}
                        </p>
                    </div>
                    <div class="flex justify-end gap-x-3 sm:gap-x-1 sm:justify-start items-center">
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
                    <textarea v-model="newDescription" rows="3" placeholder="Omschrijving (optioneel)"
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <ComboBox :options="productOptions" v-model="newProductId" label="Product (optioneel)"
                    placeholder="Zoek een product..." />
                <div v-if="newProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="newQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
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
                    <textarea v-model="editDescription" rows="3" placeholder="Omschrijving (optioneel)"
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <ComboBox :options="productOptions" v-model="editProductId" label="Product (optioneel)"
                    placeholder="Zoek een product..." />
                <div v-if="editProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="editQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
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

        <!-- Serial number drawer -->
        <DrawerComponent v-model="serialDrawerOpen" title="Serienummers invoeren"
            :subtitle="serialInstance ? `Voer de serienummers in voor: ${effectiveTitle(serialInstance)}` : ''"
            max-width-class="max-w-lg">
            <div class="p-4 sm:p-6 space-y-6">
                <template v-for="(group, idx) in serialGroups" :key="idx">
                    <div>
                        <p class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">
                            {{ group.label }}
                            <span class="text-xs font-normal text-gray-400 ml-1">({{ group.inputs.length }}×)</span>
                        </p>
                        <div class="space-y-2">
                            <div v-for="(input, i) in group.inputs" :key="i" class="flex items-center gap-2">
                                <span class="text-xs text-gray-400 w-5 shrink-0 text-right">{{ i + 1 }}.</span>
                                <input v-model="input.serial_number" type="text" :placeholder="`Serienummer ${i + 1}`"
                                    :class="['flex-1 rounded-md border-0 py-1.5 px-3 text-sm ring-1 ring-inset focus:ring-2 focus:ring-inset focus:outline-none bg-white dark:bg-slate-900 text-gray-900 dark:text-white placeholder:text-gray-400', serialError && !input.serial_number.trim() ? 'ring-red-300 focus:ring-red-500' : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600']" />
                            </div>
                        </div>
                    </div>
                </template>
                <p v-if="serialError" class="text-xs text-red-600">{{ serialError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="cancelSerials"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="serialSubmitting" @click="submitSerials"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        {{ serialSubmitting ? 'Opslaan...' : 'Opslaan en voltooien' }}
                    </button>
                </div>
            </template>
        </DrawerComponent>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
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
    products: { type: Array, default: () => [] },
    isClosed: { type: Boolean, default: false },
})

const canCreate = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.create'))
const canToggle = computed(() => !props.isClosed && (hasPermission('serviceordertaskinstance.open_close') || hasPermission('serviceordertaskinstance.update')))
const canEdit = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.update'))
const canDelete = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.delete'))

const internalInstances = ref(props.instances.map(i => ({ ...i })))

watch(() => props.instances, (new_val) => {
    internalInstances.value = new_val.map(i => ({ ...i }))
}, { deep: true })

const taskOptions = computed(() => props.availableTasks.map(t => ({ id: t.id, name: t.title })))
const productOptions = computed(() => props.products.map(p => ({ id: p.id, name: p.name })))

// ── Add drawer ────────────────────────────────────────────────────────────────
const addDrawerOpen = ref(false)
const newTaskId = ref(null)
const newTitle = ref('')
const newDescription = ref('')
const newProductId = ref(null)
const newQuantity = ref(1)

const addForm = useForm({
    service_order_id: props.serviceOrderId,
    service_order_task_id: null,
    product_id: null,
    quantity: 1,
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
    addForm.product_id = newProductId.value
    addForm.quantity = newProductId.value ? newQuantity.value : 1
    addForm.title = newTitle.value.trim() || null
    addForm.description = newDescription.value.trim() || null

    addForm.post('/serviceordertaskinstances', {
        preserveScroll: true,
        onSuccess: () => {
            addDrawerOpen.value = false
            newTaskId.value = null
            newTitle.value = ''
            newDescription.value = ''
            newProductId.value = null
            newQuantity.value = 1
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
const editProductId = ref(null)
const editQuantity = ref(1)

const editForm = useForm({ title: '', description: '', product_id: null, quantity: 1 })

function openEditDrawer(instance) {
    editingInstance.value = instance
    editTitle.value = instance.title ?? ''
    editDescription.value = instance.description ?? instance.service_order_task?.description ?? ''
    editProductId.value = instance.product_id ?? null
    editQuantity.value = instance.quantity ?? 1
    editDrawerOpen.value = true
}

function saveEdit() {
    editForm.title = editTitle.value.trim() || null
    editForm.description = editDescription.value.trim() || null
    editForm.product_id = editProductId.value
    editForm.quantity = editProductId.value ? editQuantity.value : 1

    editForm.patch(`/serviceordertaskinstances/${editingInstance.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingInstance.value.title = editForm.title
            editingInstance.value.description = editForm.description
            editingInstance.value.product_id = editForm.product_id
            editingInstance.value.quantity = editForm.quantity
            editDrawerOpen.value = false
            editForm.reset()
        },
    })
}

// ── Toggle + serial number prompt ─────────────────────────────────────────────
const serialDrawerOpen = ref(false)
const serialInstance = ref(null)
const serialGroups = ref([])
const serialError = ref('')
const serialSubmitting = ref(false)
const checkboxResetKeys = ref({})

function toggleComplete(instance, new_value) {
    if (!canToggle.value) return

    if (new_value && instance.product_id && instance.product) {
        const groups = buildSerialGroups(instance)
        if (groups.length > 0) {
            serialInstance.value = instance
            serialGroups.value = groups
            serialError.value = ''
            serialDrawerOpen.value = true
            return
        }
    }

    doToggle(instance, new_value, [])
}

function buildSerialGroups(instance) {
    const product = instance.product
    const qty = instance.quantity ?? 1
    const groups = []

    if (!product.bundle) {
        const label = [product.brand?.name, product.model].filter(Boolean).join(' ')
        groups.push({
            label,
            inputs: Array.from({ length: qty }, () => ({
                product_id: product.id,
                serial_number: '',
            })),
        })
    } else {
        for (const productable of product.productables ?? []) {
            const child = productable.child_product
            if (!child) continue
            const count = (productable.quantity ?? 1) * qty
            const label = [child.brand?.name, child.model].filter(Boolean).join(' ')
            groups.push({
                label,
                inputs: Array.from({ length: count }, () => ({
                    product_id: child.id,
                    serial_number: '',
                })),
            })
        }
    }

    return groups
}

function cancelSerials() {
    const id = serialInstance.value?.id
    serialDrawerOpen.value = false
    serialInstance.value = null
    serialGroups.value = []
    serialError.value = ''
    // Force checkbox to remount so it reverts to unchecked
    if (id) checkboxResetKeys.value = { ...checkboxResetKeys.value, [id]: (checkboxResetKeys.value[id] ?? 0) + 1 }
}

function submitSerials() {
    const all_inputs = serialGroups.value.flatMap(g => g.inputs)

    if (all_inputs.length === 0) {
        serialError.value = 'Geen serienummer-velden beschikbaar. Controleer het product en de hoeveelheid.'
        return
    }

    const has_empty = all_inputs.some(i => !i.serial_number.trim())
    if (has_empty) {
        serialError.value = 'Vul alle serienummers in.'
        return
    }

    const assets = all_inputs.map(i => ({
        product_id: i.product_id,
        serial_number: i.serial_number.trim(),
    }))

    serialSubmitting.value = true
    doToggle(serialInstance.value, true, assets)
}

function doToggle(instance, new_value, assets) {
    const previous = instance.is_complete
    instance.is_complete = new_value

    const payload = { is_complete: new_value }
    if (assets.length) payload.assets = assets

    useForm(payload).patch(`/serviceordertaskinstances/${instance.id}/toggle`, {
        preserveScroll: true,
        onError: (errors) => {
            instance.is_complete = previous
            serialSubmitting.value = false
            if (errors.task) {
                usePage().props.flash.error = errors.task
            }
        },
        onSuccess: () => {
            serialDrawerOpen.value = false
            serialInstance.value = null
            serialGroups.value = []
            serialError.value = ''
            serialSubmitting.value = false
        },
    })
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteInstance(id) {
    if (!canDelete.value) return
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
