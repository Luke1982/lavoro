<template>
    <BoxComponent>
        <div class="flex items-center gap-3 border-b border-gray-200 pb-4 mb-4">
            <Link href="/productattributes" class="text-gray-400 hover:text-gray-600">
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
                    class="text-blue-600 hover:text-blue-800" v-tooltip="'Waarde toevoegen'">
                    <PlusIcon class="size-4" />
                </button>
            </div>

            <div v-if="addingValue" class="flex gap-2 items-center mb-3">
                <input v-model="newValueText" type="text" placeholder="bijv. 'Rood'"
                    class="flex-1 max-w-xs rounded-md border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                    @keyup.enter="submitValue" />
                <button @click="submitValue"
                    class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50"
                    :disabled="!newValueText.trim()">
                    Toevoegen
                </button>
                <button @click="addingValue = false; newValueText = ''"
                    class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">
                    Annuleren
                </button>
            </div>

            <table v-if="attribute.values.length" class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-400 border-b">
                        <th class="text-left py-1 font-medium">Waarde</th>
                        <th class="py-1 w-20"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="val in attribute.values" :key="val.id" class="border-b border-gray-100">
                        <td class="py-1.5">
                            <template v-if="editingValueId === val.id">
                                <input v-model="editValueText" type="text"
                                    class="rounded-md border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 w-full max-w-xs"
                                    @keyup.enter="saveValue(val.id)" @keyup.esc="editingValueId = null" />
                            </template>
                            <template v-else>{{ val.value }}</template>
                        </td>
                        <td class="py-1.5 text-right">
                            <template v-if="editingValueId === val.id">
                                <div class="flex justify-end gap-1">
                                    <button @click="saveValue(val.id)"
                                        class="px-2 py-0.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Opslaan</button>
                                    <button @click="editingValueId = null"
                                        class="px-2 py-0.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">Annuleren</button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="flex justify-end gap-2">
                                    <button v-if="hasPermission('productattribute.update')" @click="startEditValue(val)"
                                        class="text-gray-400 hover:text-gray-600">
                                        <PencilIcon class="size-4" />
                                    </button>
                                    <button v-if="hasPermission('productattribute.update')" @click="deleteValue(val.id)"
                                        class="text-red-400 hover:text-red-600">
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

        <!-- Danger zone -->
        <div v-if="hasPermission('productattribute.delete')"
            class="mt-8 pt-4 border-t border-gray-200 flex justify-end">
            <button @click="confirmDelete"
                class="px-3 py-1.5 text-sm bg-red-50 text-red-600 rounded-md hover:bg-red-100 border border-red-200">
                Kenmerk verwijderen
            </button>
        </div>
    </BoxComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { ArrowLeftIcon, PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    attribute: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
})

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
const newValueText = ref('')

function submitValue() {
    if (!newValueText.value.trim()) return
    router.post(
        `/productattributes/${props.attribute.id}/values`,
        { value: newValueText.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => { addingValue.value = false; newValueText.value = '' },
        }
    )
}

const editingValueId = ref(null)
const editValueText = ref('')

function startEditValue(val) {
    editingValueId.value = val.id
    editValueText.value = val.value
}

function saveValue(value_id) {
    router.patch(
        `/productattributevalues/${value_id}`,
        { value: editValueText.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => { editingValueId.value = null },
        }
    )
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
