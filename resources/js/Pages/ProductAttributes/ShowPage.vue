<template>
    <BoxComponent>
        <div class="flex items-center gap-3 border-b border-gray-200 dark:border-slate-700 pb-4 mb-4">
            <Link href="/productattributes" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-400">
                <ArrowLeftIcon class="size-5" />
            </Link>
            <div>
                <h1 class="text-lg font-semibold">{{ attribute.name }}</h1>
                <p class="text-sm text-gray-400">Productkenmerk</p>
            </div>
        </div>

        <!-- Name -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold mb-2">Naam</h3>
            <EditableTextField v-model="nameForm.name" type="input" :error="nameForm.errors.name"
                @revert="nameForm.clearErrors('name')" />
        </div>

        <!-- Format -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 max-w-xl">
            <div>
                <h3 class="text-sm font-semibold mb-2">Soort waarde</h3>
                <ComboBox :options="valueTypes" :model-value="attribute.value_type"
                    :disabled="!hasPermission('productattribute.update')"
                    @update:model-value="v => v && v !== attribute.value_type && saveFormat({ value_type: v })" />
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    <template v-if="isNumeric">Waarden zijn getallen, zodat een prijsformule erop kan vergelijken (≥ 10).</template>
                    <template v-else>Waarden zijn vrije tekst, zoals 'Rood'.</template>
                </p>
                <p v-if="formatErrors.value_type" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formatErrors.value_type }}</p>
            </div>
            <div v-if="isNumeric">
                <h3 class="text-sm font-semibold mb-2">Eenheid</h3>
                <EditableTextField :model-value="attribute.unit ?? ''" type="input" placeholder="bijv. kW"
                    :readonly="!hasPermission('productattribute.update')" :error="formatErrors.unit"
                    @update="v => saveFormat({ unit: v })" />
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    Staat achter elke waarde. Een andere eenheid rekent niet om: 1500 W wordt 1500 kW.
                </p>
            </div>
        </div>

        <!-- Product types -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold mb-2">Producttypen</h3>
            <ComboBox :options="productTypes" :model-value="selectedTypeIds" :multiple="true"
                placeholder="Selecteer producttypen..." :disabled="!hasPermission('productattribute.update')"
                @update:model-value="onProductTypesChange" />
        </div>

        <!-- Values -->
        <div>
            <div class="flex items-center gap-2 mb-3">
                <h3 class="text-sm font-semibold">Waarden</h3>
                <button v-if="hasPermission('productattribute.update')" @click="addingValue = !addingValue"
                    class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-200" v-tooltip="'Waarde toevoegen'">
                    <PlusIcon class="size-4" />
                </button>
            </div>

            <div v-if="addingValue" class="mb-3">
                <div class="flex gap-2 items-center">
                    <input v-model="addValueForm.value" type="text" :inputmode="isNumeric ? 'decimal' : 'text'"
                        :placeholder="isNumeric ? 'bijv. 1,5' : 'bijv. \'Rood\''"
                        class="flex-1 max-w-xs rounded-md border-gray-300 dark:border-slate-600 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        @keyup.enter="submitValue" />
                    <span v-if="isNumeric && attribute.unit" class="text-sm text-gray-500 dark:text-slate-400">{{ attribute.unit }}</span>
                    <button @click="submitValue"
                        class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50"
                        :disabled="!addValueForm.value.trim() || addValueForm.processing">
                        Toevoegen
                    </button>
                    <button @click="addingValue = false; addValueForm.reset(); addValueForm.clearErrors()"
                        class="px-3 py-1.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300 rounded text-sm hover:bg-gray-300 dark:hover:bg-slate-600">
                        Annuleren
                    </button>
                </div>
                <p v-if="addValueForm.errors.value" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ addValueForm.errors.value }}</p>
            </div>

            <table v-if="attribute.values.length" class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-400 border-b">
                        <th class="text-left py-1 font-medium">Waarde</th>
                        <th class="py-1 w-20"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="val in attribute.values" :key="val.id" class="border-b border-gray-100 dark:border-slate-800">
                        <td class="py-1.5">
                            <template v-if="editingValueId === val.id">
                                <div class="flex items-center gap-2">
                                    <input v-model="editValueForm.value" type="text" :inputmode="isNumeric ? 'decimal' : 'text'"
                                        class="rounded-md border-gray-300 dark:border-slate-600 text-sm focus:ring-indigo-500 focus:border-indigo-500 w-full max-w-xs"
                                        @keyup.enter="saveValue(val.id)" @keyup.esc="editingValueId = null" />
                                    <span v-if="isNumeric && attribute.unit" class="text-sm text-gray-500 dark:text-slate-400">{{ attribute.unit }}</span>
                                </div>
                                <p v-if="editValueForm.errors.value" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ editValueForm.errors.value }}</p>
                            </template>
                            <template v-else>{{ val.value }}</template>
                        </td>
                        <td class="py-1.5 text-right">
                            <template v-if="editingValueId === val.id">
                                <div class="flex justify-end gap-1">
                                    <button @click="saveValue(val.id)"
                                        class="px-2 py-0.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Opslaan</button>
                                    <button @click="editingValueId = null"
                                        class="px-2 py-0.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300 rounded text-xs hover:bg-gray-300 dark:hover:bg-slate-600">Annuleren</button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="flex justify-end gap-2">
                                    <button v-if="hasPermission('productattribute.update')" @click="startEditValue(val)"
                                        class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-400">
                                        <PencilIcon class="size-4" />
                                    </button>
                                    <button v-if="hasPermission('productattribute.update')" @click="deleteValue(val.id)"
                                        class="text-red-400 hover:text-red-600 dark:hover:text-red-400">
                                        <TrashIcon class="size-4" />
                                    </button>
                                </div>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-sm text-gray-400 italic">Nog geen waarden toegevoegd.</p>
        </div>

        <div class="mt-8 pt-4 border-t border-gray-200 dark:border-slate-700">
            <SectionHeader :icon="HistoryIcon" title="Geschiedenis"
                subtitle="Wie wat veranderde aan dit kenmerk en zijn waarden." chapter="timeline" />
            <TimelineComponent v-if="attribute.activities?.length" :activities="attribute.activities" subject-type="App\Models\ProductAttribute" />
            <p v-else class="text-sm text-gray-400 dark:text-slate-500">Nog niets gebeurd.</p>
        </div>

        <!-- Danger zone -->
        <div v-if="hasPermission('productattribute.delete')"
            class="mt-8 pt-4 border-t border-gray-200 dark:border-slate-700 flex justify-end">
            <button @click="confirmDelete"
                class="px-3 py-1.5 text-sm bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-md hover:bg-red-100 dark:hover:bg-red-900/30 border border-red-200 dark:border-red-800">
                Kenmerk verwijderen
            </button>
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { ArrowLeftIcon, PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { HistoryIcon } from '@lucide/vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    attribute: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
    valueTypes: { type: Array, default: () => [] },
})

const isNumeric = computed(() => props.attribute.value_type === 'number')

const formatErrors = ref({})

function saveFormat(changes) {
    router.patch(`/productattributes/${props.attribute.id}`, changes, {
        preserveScroll: true,
        onSuccess: () => { formatErrors.value = {} },
        onError: errors => { formatErrors.value = errors },
    })
}

const nameForm = useForm({ name: props.attribute.name })

watch(() => nameForm.name, () => {
    nameForm.patch(`/productattributes/${props.attribute.id}`, { preserveScroll: true })
})

const selectedTypeIds = ref(props.attribute.product_types.map(pt => pt.id))

function onProductTypesChange(ids) {
    selectedTypeIds.value = ids
    router.post(
        `/productattributes/${props.attribute.id}/producttypes`,
        { product_type_ids: ids },
        { preserveScroll: true }
    )
}

const addingValue = ref(false)
const addValueForm = useForm({ value: '' })

function submitValue() {
    if (!addValueForm.value.trim()) return
    addValueForm.post(`/productattributes/${props.attribute.id}/values`, {
        preserveScroll: true,
        onSuccess: () => { addingValue.value = false; addValueForm.reset() },
    })
}

const editingValueId = ref(null)
const editValueForm = useForm({ value: '' })

/** A number is edited as the number alone; the unit stays beside the field. */
function startEditValue(val) {
    editValueForm.clearErrors()
    editingValueId.value = val.id
    editValueForm.value = val.number === null ? val.value : String(val.number).replace('.', ',')
}

function saveValue(value_id) {
    editValueForm.patch(`/productattributevalues/${value_id}`, {
        preserveScroll: true,
        onSuccess: () => { editingValueId.value = null },
    })
}

function deleteValue(value_id) {
    router.delete(`/productattributevalues/${value_id}`, { preserveScroll: true })
}

function confirmDelete() {
    if (confirm(`Kenmerk "${props.attribute.name}" verwijderen? Dit verwijdert ook alle waarden en koppelingen.`)) {
        router.delete(`/productattributes/${props.attribute.id}`)
    }
}
</script>
