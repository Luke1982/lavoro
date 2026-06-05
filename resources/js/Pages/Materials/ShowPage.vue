<template>
    <div class="flex items-center mb-6">
        <Link href="/materials" class="text-slate-400 text-sm font-medium">Materialen</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-200 font-bold text-sm">{{ material.name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row mt-2 mb-4">
        <div class="flex flex-col justify-around flex-grow items-start py-2 sm:py-6 gap-3">
            <h1 class="text-2xl font-bold flex items-center gap-3">
                {{ material.name }}
                <BadgeComponent v-if="material.is_active" color="green">Actief</BadgeComponent>
                <BadgeComponent v-else color="gray">Inactief</BadgeComponent>
                <BadgeComponent v-if="material.is_service" color="blue">Dienst</BadgeComponent>
            </h1>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: editable fields -->
        <div class="lg:col-span-2 space-y-6">
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <CubeIcon class="size-5 text-gray-500 mr-2" />
                    <span class="text-md font-bold">Materiaalgegevens</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Left column -->
                    <div class="flex flex-col gap-6">
                        <EditableTextField v-model="form.name" type="input" label="Naam"
                            :error="form.errors.name" @revert="form.clearErrors('name')" />
                        <EditableTextField v-model="form.description" type="textarea" label="Omschrijving"
                            :error="form.errors.description" @revert="form.clearErrors('description')" />
                        <EditableTextField v-model="form.code" type="input" label="Code"
                            :error="form.errors.code" @revert="form.clearErrors('code')" />
                        <EditableTextField v-model="form.vendor_code" type="input" label="Leverancierscode"
                            :error="form.errors.vendor_code" @revert="form.clearErrors('vendor_code')" />
                        <div>
                            <h3 class="text-xs font-semibold mb-1 text-slate-500">Deelbaar</h3>
                            <div class="flex items-center gap-3">
                                <SwitchComponent v-model="form.divisable" />
                                <span class="text-sm text-gray-600 dark:text-slate-400">
                                    <template v-if="form.divisable">Materiaal is deelbaar</template>
                                    <template v-else>Materiaal is niet deelbaar</template>
                                </span>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold mb-1 text-slate-500">Actief</h3>
                            <div class="flex items-center gap-3">
                                <SwitchComponent v-model="form.is_active" />
                                <span class="text-sm text-gray-600 dark:text-slate-400">
                                    <template v-if="form.is_active">Materiaal is actief</template>
                                    <template v-else>Materiaal is inactief</template>
                                </span>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold mb-1 text-slate-500">Dienst</h3>
                            <div class="flex items-center gap-3">
                                <SwitchComponent v-model="form.is_service" />
                                <span class="text-sm text-gray-600 dark:text-slate-400">
                                    <template v-if="form.is_service">Dit is een dienst</template>
                                    <template v-else>Dit is geen dienst</template>
                                </span>
                            </div>
                        </div>
                    </div>
                    <!-- Right column -->
                    <div class="flex flex-col gap-6">
                        <EditableTextField type="combobox" v-model="form.material_category_id"
                            :options="categories" label="Categorie"
                            :error="form.errors.material_category_id"
                            @revert="form.clearErrors('material_category_id')">
                            <template #display>{{ categories.find(c => c.id === form.material_category_id)?.name ?? '—' }}</template>
                        </EditableTextField>
                        <EditableTextField type="combobox" v-model="form.material_usage_unit_id"
                            :options="usageUnits" label="Eenheid"
                            :error="form.errors.material_usage_unit_id"
                            @revert="form.clearErrors('material_usage_unit_id')">
                            <template #display>{{ usageUnits.find(u => u.id === form.material_usage_unit_id)?.name ?? '—' }}</template>
                        </EditableTextField>
                        <EditableTextField v-model="form.price" type="input" inputType="currency" label="Verkoopprijs"
                            :error="form.errors.price" @revert="form.clearErrors('price')" />
                        <EditableTextField v-model="form.cost_price" type="input" inputType="currency" label="Inkoopprijs"
                            :error="form.errors.cost_price" @revert="form.clearErrors('cost_price')" />
                        <EditableTextField v-model="form.stock" type="input" label="Voorraad"
                            :error="form.errors.stock" @revert="form.clearErrors('stock')" />
                        <EditableTextField v-model="form.min_stock" type="input" label="Min. voorraad"
                            :error="form.errors.min_stock" @revert="form.clearErrors('min_stock')" />
                        <EditableTextField v-model="form.max_stock" type="input" label="Max. voorraad"
                            :error="form.errors.max_stock" @revert="form.clearErrors('max_stock')" />
                    </div>
                </div>
            </BoxComponent>
        </div>

        <!-- Right: Leveranciers -->
        <div class="space-y-6">
            <BoxComponent>
                <div class="flex items-center pb-3 border-b border-gray-200 dark:border-slate-700">
                    <BuildingOfficeIcon class="size-5 text-gray-500 mr-2" />
                    <h3 class="text-sm font-medium">Leveranciers</h3>
                    <button v-if="hasPermission('material.update')"
                        @click="addingSupplier = !addingSupplier"
                        class="ml-2 text-blue-600 hover:text-blue-800 cursor-pointer"
                        v-tooltip="'Leverancier koppelen'">
                        <PlusIcon class="size-4" />
                    </button>
                </div>

                <!-- Add form -->
                <div v-auto-animate>
                    <div v-if="addingSupplier"
                        class="mt-3 mb-3 p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2">
                        <div class="flex gap-2 flex-wrap">
                            <div class="flex-1 min-w-40">
                                <label class="block text-xs text-gray-500 mb-1">Leverancier</label>
                                <ComboBox :options="supplierOptions" v-model="newSupplierLink.supplier_id"
                                    placeholder="Selecteer leverancier"
                                    :has-external-searching="suppliersUseAjax"
                                    :searching="supplierSearching"
                                    @change="searchSuppliers" />
                            </div>
                            <div class="flex-1 min-w-32">
                                <label class="block text-xs text-gray-500 mb-1">Artikelnummer</label>
                                <input type="text" v-model="newSupplierLink.article_number"
                                    class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1.5 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                    placeholder="Optioneel" />
                            </div>
                            <div class="flex flex-col">
                                <label class="block text-xs text-gray-500 mb-1">Voorkeur</label>
                                <SwitchComponent v-model="newSupplierLink.is_preferred" />
                            </div>
                        </div>
                        <div class="flex gap-2 justify-end">
                            <button @click="addingSupplier = false"
                                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                Annuleren
                            </button>
                            <button @click="submitNewSupplier" :disabled="!newSupplierLink.supplier_id"
                                class="px-3 py-1 bg-lavoro-blue text-white rounded text-xs hover:opacity-90 disabled:opacity-50">
                                Opslaan
                            </button>
                        </div>
                    </div>
                </div>

                <p v-if="!materialSuppliers.length && !addingSupplier"
                    class="text-sm text-gray-400 italic mt-3">
                    Geen leveranciers gekoppeld.
                </p>

                <table v-if="materialSuppliers.length" class="w-full text-sm mt-3">
                    <thead>
                        <tr class="text-xs text-gray-400 border-b">
                            <th class="text-left py-1 font-medium">Leverancier</th>
                            <th class="text-left py-1 font-medium">Artikelnummer</th>
                            <th class="text-center py-1 font-medium">Voorkeur</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="s in materialSuppliers" :key="s.id">
                            <tr v-if="editingSupplierId === s.id"
                                class="border-b border-gray-100 bg-gray-50 dark:bg-slate-800">
                                <td class="py-1.5 pr-2">{{ s.name }}</td>
                                <td class="py-1.5 pr-2">
                                    <input type="text" v-model="editSupplierForm.article_number"
                                        class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                        placeholder="Artikelnummer" />
                                </td>
                                <td class="py-1.5 text-center">
                                    <SwitchComponent v-model="editSupplierForm.is_preferred" />
                                </td>
                                <td class="py-1.5 text-right">
                                    <div class="flex justify-end gap-1">
                                        <button @click="cancelEditSupplier"
                                            class="px-2 py-0.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">Annuleren</button>
                                        <button @click="saveEditSupplier(s.id)"
                                            class="px-2 py-0.5 bg-lavoro-blue text-white rounded text-xs hover:opacity-90">Opslaan</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else class="border-b border-gray-100 dark:border-slate-800">
                                <td class="py-1.5">
                                    <Link :href="`/suppliers/${s.id}`" class="text-blue-500 hover:underline">
                                        {{ s.name }}
                                    </Link>
                                </td>
                                <td class="py-1.5 text-gray-500">{{ s.article_number || '—' }}</td>
                                <td class="py-1.5 text-center">
                                    <span v-if="s.is_preferred" class="text-green-600 text-xs">✓</span>
                                    <span v-else class="text-gray-300 text-xs">—</span>
                                </td>
                                <td class="py-1.5 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button v-if="hasPermission('material.update')"
                                            @click="startEditSupplier(s)"
                                            class="text-gray-400 hover:text-gray-600">
                                            <PencilIcon class="size-4" />
                                        </button>
                                        <button v-if="hasPermission('material.update')"
                                            @click="removeSupplier(s.id)"
                                            class="text-red-400 hover:text-red-600">
                                            <TrashIcon class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </BoxComponent>
        </div>
    </div>
</template>

<script setup>
import { ChevronRightIcon, BuildingOfficeIcon, CubeIcon, PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ref, reactive, watch } from 'vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import { useComboSearch } from '@/Composables/useComboSearch';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    material:          { type: Object, required: true },
    categories:        { type: Array, default: () => [] },
    usageUnits:        { type: Array, default: () => [] },
    materialSuppliers: { type: Array, default: () => [] },
    allSuppliers:      { type: Array, default: () => [] },
    suppliersUseAjax:  { type: Boolean, default: false },
});

const { options: supplierOptions, searching: supplierSearching, search: searchSuppliers } =
    useComboSearch('suppliers', props.allSuppliers, props.suppliersUseAjax)

const form = useForm({
    name:                    props.material.name,
    description:             props.material.description,
    code:                    props.material.code,
    vendor_code:             props.material.vendor_code,
    price:                   props.material.price,
    cost_price:              props.material.cost_price,
    material_category_id:    props.material.material_category_id,
    material_usage_unit_id:  props.material.material_usage_unit_id,
    divisable:               props.material.divisable ?? false,
    is_active:               props.material.is_active ?? true,
    is_service:              props.material.is_service ?? false,
    stock:                   props.material.stock,
    min_stock:               props.material.min_stock,
    max_stock:               props.material.max_stock,
})

watch([
    () => form.name,
    () => form.description,
    () => form.code,
    () => form.vendor_code,
    () => form.price,
    () => form.cost_price,
    () => form.material_category_id,
    () => form.material_usage_unit_id,
    () => form.divisable,
    () => form.is_active,
    () => form.is_service,
    () => form.stock,
    () => form.min_stock,
    () => form.max_stock,
], () => {
    form.patch(`/materials/${props.material.id}`, { preserveScroll: true })
})

const addingSupplier  = ref(false)
const newSupplierLink = reactive({ supplier_id: null, article_number: '', is_preferred: false })

function submitNewSupplier() {
    router.post(`/materials/${props.material.id}/suppliers`, {
        supplier_id:    newSupplierLink.supplier_id,
        article_number: newSupplierLink.article_number || null,
        is_preferred:   newSupplierLink.is_preferred,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingSupplier.value           = false
            newSupplierLink.supplier_id    = null
            newSupplierLink.article_number = ''
            newSupplierLink.is_preferred   = false
        },
    })
}

function removeSupplier(supplier_id) {
    router.delete(`/materials/${props.material.id}/suppliers/${supplier_id}`, { preserveScroll: true })
}

const editingSupplierId = ref(null)
const editSupplierForm  = reactive({ article_number: '', is_preferred: false })

function startEditSupplier(s) {
    editingSupplierId.value         = s.id
    editSupplierForm.article_number = s.article_number || ''
    editSupplierForm.is_preferred   = s.is_preferred
}

function cancelEditSupplier() { editingSupplierId.value = null }

function saveEditSupplier(supplier_id) {
    router.patch(`/materials/${props.material.id}/suppliers/${supplier_id}`, {
        article_number: editSupplierForm.article_number || null,
        is_preferred:   editSupplierForm.is_preferred,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingSupplierId.value = null },
    })
}
</script>
