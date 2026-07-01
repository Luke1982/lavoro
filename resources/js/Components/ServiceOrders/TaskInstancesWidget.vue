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
                        <button v-if="instance.signed_by" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openViewModal(instance)" v-tooltip="'Ondertekend'">
                            <BadgeCheckIcon class="w-4 h-4 text-green-500" />
                        </button>
                        <button v-if="canSign && instance.is_complete && !instance.signed_by" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openSignModal(instance)" v-tooltip="'Laten ondertekenen'">
                            <PenLineIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
                        </button>
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
                    placeholder="Zoek een product...">
                    <template #option="{ option, active }">
                        <div>
                            <span class="block">{{ option.name }}</span>
                            <span v-if="option.attributes?.length"
                                :class="['block text-xs mt-0.5', active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400']">
                                {{ option.attributes.map(a => `${a.name}: ${a.value}`).join(' · ') }}
                            </span>
                        </div>
                    </template>
                </ComboBox>
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
                    placeholder="Zoek een product...">
                    <template #option="{ option, active }">
                        <div>
                            <span class="block">{{ option.name }}</span>
                            <span v-if="option.attributes?.length"
                                :class="['block text-xs mt-0.5', active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400']">
                                {{ option.attributes.map(a => `${a.name}: ${a.value}`).join(' · ') }}
                            </span>
                        </div>
                    </template>
                </ComboBox>
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

        <!-- Sign modal -->
        <ModalDialog :open="signModalOpen" @update:open="signModalOpen = $event" title="Taak ondertekenen"
            max-width-class="sm:max-w-lg">
            <div class="flex flex-col gap-4">
                <TextInput v-model="signName" label="Naam klant" placeholder="Volledige naam" />
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Handtekening</label>
                    <SignaturePad ref="signaturePadRef" :key="signModalKey" v-model="signatureData" />
                </div>
                <p v-if="signError" class="text-xs text-red-600">{{ signError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="closeSignModal"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="signForm.processing" @click="submitSign"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Ondertekenen
                    </button>
                </div>
            </template>
        </ModalDialog>

        <!-- View signature modal -->
        <ModalDialog :open="viewModalOpen" @update:open="viewModalOpen = $event" title="Ondertekening"
            max-width-class="sm:max-w-md">
            <div v-if="viewingInstance" class="flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <span class="text-gray-500 dark:text-slate-400">Naam</span>
                    <span class="text-gray-900 dark:text-slate-100 font-medium">{{ viewingInstance.signed_by }}</span>
                    <span class="text-gray-500 dark:text-slate-400">Datum</span>
                    <span class="text-gray-900 dark:text-slate-100">{{ nlDate(viewingInstance.signed_at) }}</span>
                    <span class="text-gray-500 dark:text-slate-400">Tijd</span>
                    <span class="text-gray-900 dark:text-slate-100">{{ nlTime(viewingInstance.signed_at) }}</span>
                </div>
                <div class="mt-2 border border-gray-200 dark:border-slate-600 rounded-lg p-3">
                    <img :src="viewingInstance.signature_base64" alt="Handtekening" class="max-h-32 w-auto">
                </div>
            </div>
            <template #footer>
                <div class="flex justify-between items-center">
                    <button v-if="canSign" type="button" @click="openUnsignConfirm(viewingInstance)"
                        class="inline-flex items-center p-2 rounded-full border border-gray-200 bg-white text-red-500 hover:text-red-700 hover:border-gray-300 transition-colors"
                        v-tooltip="'Verwijder handtekening'">
                        <TrashIcon class="w-4 h-4" />
                    </button>
                    <button type="button" @click="viewModalOpen = false"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 transition-opacity ml-auto">
                        Sluiten
                    </button>
                </div>
            </template>
        </ModalDialog>

        <!-- Unsign confirm modal -->
        <ModalDialog :open="unsignConfirmOpen" @update:open="unsignConfirmOpen = $event" max-width-class="sm:max-w-sm">
            <div class="sm:flex sm:items-start gap-4">
                <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:size-10">
                    <AlertTriangleIcon class="size-6 text-red-600" />
                </div>
                <div class="mt-3 sm:mt-0 text-center sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Handtekening verwijderen</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        Weet je zeker dat je de handtekening van deze taak wilt verwijderen?
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="unsignConfirmOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="unsignForm.processing" @click="confirmUnsign"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-red-600 text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Verwijderen
                    </button>
                </div>
            </template>
        </ModalDialog>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Plus as PlusIcon, Trash2 as TrashIcon, EllipsisVertical as EllipsisVerticalIcon, ClipboardListIcon, PenLine as PenLineIcon, BadgeCheck as BadgeCheckIcon, AlertTriangle as AlertTriangleIcon } from '@lucide/vue'
import { hasPermission, nlDate, nlTime } from '@/Utilities/Utilities'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import CheckboxComponent from '@/Components/UI/AnimatedCheckbox.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'

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
const canSign = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.open_close'))

const internalInstances = ref(props.instances.map(i => ({ ...i })))

watch(() => props.instances, (new_val) => {
    internalInstances.value = new_val.map(i => ({ ...i }))
}, { deep: true })

const taskOptions = computed(() => props.availableTasks.map(t => ({ id: t.id, name: t.title })))
const productOptions = computed(() => props.products.map(p => ({
    id: p.id,
    name: p.name,
    attributes: p.attributes ?? [],
    search: p.search ?? '',
})))

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

// ── Sign ──────────────────────────────────────────────────────────────────────
const signModalOpen = ref(false)
const signingInstance = ref(null)
const signName = ref('')
const signatureData = ref('')
const signError = ref('')
const signModalKey = ref(0)
const signaturePadRef = ref(null)
const signForm = useForm({ signed_by: '', signature_base64: '' })

function openSignModal(instance) {
    signingInstance.value = instance
    signName.value = ''
    signatureData.value = ''
    signError.value = ''
    signModalKey.value++
    signModalOpen.value = true
}

function closeSignModal() {
    signModalOpen.value = false
    signingInstance.value = null
    signName.value = ''
    signatureData.value = ''
    signError.value = ''
    signForm.reset()
}

function submitSign() {
    signError.value = ''
    if (!signName.value.trim()) {
        signError.value = 'Vul een naam in.'
        return
    }
    if (!signaturePadRef.value || signaturePadRef.value.isEmpty()) {
        signError.value = 'Teken een handtekening.'
        return
    }
    signaturePadRef.value.save()
    const data_url = signaturePadRef.value.getDataUrl()
    signForm.signed_by = signName.value.trim()
    signForm.signature_base64 = data_url
    signForm.post(`/serviceordertaskinstances/${signingInstance.value.id}/sign`, {
        preserveScroll: true,
        onSuccess: () => {
            const inst = internalInstances.value.find(i => i.id === signingInstance.value.id)
            if (inst) {
                inst.signed_by = signForm.signed_by
                inst.signature_base64 = signForm.signature_base64
                inst.signed_at = new Date().toISOString()
            }
            closeSignModal()
        },
        onError: () => {
            signError.value = 'Er is een fout opgetreden. Probeer het opnieuw.'
        },
    })
}

// ── View signature ────────────────────────────────────────────────────────────
const viewModalOpen = ref(false)
const viewingInstance = ref(null)

function openViewModal(instance) {
    viewingInstance.value = instance
    viewModalOpen.value = true
}

// ── Unsign ────────────────────────────────────────────────────────────────────
const unsignConfirmOpen = ref(false)
const unsigningInstance = ref(null)
const unsignForm = useForm({})

function openUnsignConfirm(instance) {
    viewModalOpen.value = false
    viewingInstance.value = null
    unsigningInstance.value = instance
    unsignConfirmOpen.value = true
}

function confirmUnsign() {
    unsignForm.delete(`/serviceordertaskinstances/${unsigningInstance.value.id}/sign`, {
        preserveScroll: true,
        onSuccess: () => {
            const inst = internalInstances.value.find(i => i.id === unsigningInstance.value.id)
            if (inst) {
                inst.signed_by = null
                inst.signature_base64 = null
                inst.signed_at = null
            }
            unsignConfirmOpen.value = false
            unsigningInstance.value = null
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
