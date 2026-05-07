<template>
    <TwoThirdsOneThird>
        <template #main>
            <!-- Box 1: Main product info -->
            <BoxComponent>
                <div class="flex border-b border-gray-200 items-center">
                    <div class="flex justify-between w-full flex-wrap md:flex-nowrap">
                        <div class="flex w-full">
                            <CubeIcon class="size-12 flex-none object-cover p-2 mb-2" />
                            <div class="flex flex-col">
                                <span class="text-lg">{{ product.brand.name }} {{ product.model }}</span>
                                <span class="text-sm text-gray-400">{{ product.product_type.name }}</span>
                            </div>
                        </div>
                        <div class="flex self-ends w-full">
                            <span class="text-sm text-gray-500 ml-4">Verkocht tussen {{ new
                                Date(product.start_sell).toLocaleDateString('nl-NL', {
                                    day: '2-digit',
                                    month: '2-digit',
                                    year: 'numeric'
                                }) }} en {{
                                    new Date(product.end_sell).toLocaleDateString('nl-NL', {
                                        day: '2-digit', month:
                                            '2-digit',
                                        year: 'numeric'
                                    }) }}</span>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex gap-4 flex-wrap md:flex-nowrap">
                    <div class="flex flex-col w-full md:w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Beschrijving</h3>
                        <EditableTextField v-model="form.description" type="textarea" :error="form.errors.description"
                            @revert="form.clearErrors('description')" />
                    </div>
                    <div class="w-full md:w-1/2">
                        <h3 class="text-sm font-semibold mb-3">
                            Typische certificeringstermijn (dagen)
                            <InformationCircleIcon class="inline h-5 w-5 text-gray-400 ml-1 cursor-pointer" v-tooltip="{
                                html: true,
                                content: `<span class='block w-80'>Het standaard aantal dagen waarmee de keuringsdatum van
                                een machine vooruitgeschoven wordt bij een succesvolle keuring. Laat dit leeg om de termijn
                                van het type (${product.product_type.name}, ${product.product_type.typical_certificate_days ?? 0} dagen) te
                                gebruiken. Als je hier iets invult overschrijft dat de waarde die op het type is ingesteld.</span>`
                            }" />
                        </h3>
                        <EditableTextField v-model="form.typical_certificate_days" type="input"
                            :error="form.errors.typical_certificate_days"
                            @revert="form.clearErrors('typical_certificate_days')" />
                    </div>
                </div>
                <div class="mt-4 flex gap-4 flex-wrap md:flex-nowrap">
                    <div class="w-full md:w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Producttype</h3>
                        <EditableTextField type="combobox" v-model="form.product_type_id" :options="productTypes"
                            :error="form.errors.product_type_id" @revert="form.clearErrors('product_type_id')">
                            <template #display>{{ productTypes.find(t => t.id === form.product_type_id)?.name ?? product.product_type.name }}</template>
                        </EditableTextField>
                    </div>
                    <div class="w-full md:w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Artikelnummer</h3>
                        <EditableTextField v-model="form.part_no" type="input" :error="form.errors.part_no"
                            @revert="form.clearErrors('part_no')" />
                    </div>
                </div>
                <div v-if="hasPermission('product.view_prices')" class="mt-4 flex gap-4 flex-wrap md:flex-nowrap">
                    <div class="w-full md:w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Verkoopprijs</h3>
                        <EditableTextField v-model="form.retail_price" type="input" inputType="currency"
                            :error="form.errors.retail_price" @revert="form.clearErrors('retail_price')" />
                    </div>
                    <div class="w-full md:w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Inkoopprijs</h3>
                        <EditableTextField v-model="form.purchase_price" type="input" inputType="currency"
                            :error="form.errors.purchase_price" @revert="form.clearErrors('purchase_price')" />
                    </div>
                </div>
                <CustomFieldsComponent v-if="customFields.length" model-type="product" :model-id="product.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-6" />
            </BoxComponent>

            <!-- Box 2: Machines -->
            <BoxComponent class="mt-4" v-if="hasPermission('asset.read') || hasPermission('asset.create')">
                <div class="flex items-center justify-start pb-3 border-b border-gray-200">
                    <div class="flex items-center">
                        <PuzzlePieceIcon class="size-5 text-gray-500 mr-2" />
                        <h3 class="text-sm font-medium">Machines</h3>
                    </div>
                    <button v-if="hasPermission('asset.create')" @click="addAssetDrawerOpen = true"
                        class="text-blue-600 hover:text-blue-800 pl-2 cursor-pointer"
                        v-tooltip="'Nieuwe machine toevoegen'">
                        <PlusIcon class="size-4" />
                    </button>
                </div>
                <AssetListComponent v-if="product.assets.length" :assets="product.assets" class="mt-2" />
                <p v-else class="text-sm text-gray-400 italic mt-3">Geen machines gekoppeld.</p>
            </BoxComponent>

            <!-- Box 3: Related products -->
            <BoxComponent class="mt-4" v-if="hasPermission('productable.read')">
                <div class="flex items-center pb-3 border-b border-gray-200">
                    <LinkIcon class="size-5 text-gray-500 mr-2" />
                    <h3 class="text-sm font-medium">Gerelateerde producten</h3>
                    <button v-if="hasPermission('productable.create') && eligibleChildProducts.length"
                        @click="addingRelation = !addingRelation"
                        class="ml-2 text-blue-600 hover:text-blue-800 cursor-pointer"
                        v-tooltip="'Gerelateerd product toevoegen'">
                        <PlusIcon class="size-4" />
                    </button>
                </div>

                <!-- Add form -->
                <div v-auto-animate>
                    <div v-if="addingRelation"
                        class="mt-3 mb-3 p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2">
                        <div class="flex gap-2 flex-wrap">
                            <div class="flex-1 min-w-40">
                                <label class="block text-xs text-gray-500 mb-1">Gerelateerd product</label>
                                <ComboBox :options="eligibleChildProducts" v-model="newRelation.child_product_id"
                                    placeholder="Selecteer product" />
                            </div>
                            <div class="flex-1 min-w-32">
                                <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                                <ComboBox :options="productRelations" v-model="newRelation.product_relation_id"
                                    placeholder="Selecteer type" />
                            </div>
                            <div class="w-20">
                                <label class="block text-xs text-gray-500 mb-1">Aantal</label>
                                <input type="number" min="1" v-model.number="newRelation.quantity"
                                    class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1.5 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" />
                            </div>
                            <div class="flex flex-col">
                                <label class="block text-xs text-gray-500 mb-1">Verplicht</label>
                                <SwitchComponent v-model="newRelation.is_required" />
                            </div>
                        </div>
                        <div class="flex gap-2 justify-end">
                            <button @click="addingRelation = false"
                                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                Annuleren
                            </button>
                            <button @click="submitNewRelation"
                                class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                Opslaan
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Existing relations -->
                <div v-if="childProducts.length === 0 && !addingRelation" class="text-sm text-gray-400 italic mt-3">
                    Geen gerelateerde producten.
                </div>
                <table v-if="childProducts.length" class="w-full text-sm mt-3">
                    <thead>
                        <tr class="text-xs text-gray-400 border-b">
                            <th class="text-left py-1 font-medium">Product</th>
                            <th class="text-left py-1 font-medium">Type</th>
                            <th class="text-left py-1 font-medium">Aantal</th>
                            <th class="text-center py-1 font-medium">Verplicht</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="rel in childProducts" :key="rel.productable_id">
                            <!-- Edit row -->
                            <tr v-if="editingId === rel.productable_id"
                                class="border-b border-gray-100 bg-gray-50 dark:bg-slate-800">
                                <td class="py-1.5 pr-2">{{ rel.name }}</td>
                                <td class="py-1.5 pr-2">
                                    <ComboBox :options="productRelations" v-model="editForm.product_relation_id"
                                        placeholder="Selecteer type" />
                                </td>
                                <td class="py-1.5 pr-2">
                                    <input type="number" min="1" v-model.number="editForm.quantity"
                                        class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm" />
                                </td>
                                <td class="py-1.5 text-center">
                                    <SwitchComponent v-model="editForm.is_required" />
                                </td>
                                <td class="py-1.5 text-right">
                                    <div class="flex justify-end gap-1">
                                        <button @click="cancelEdit"
                                            class="px-2 py-0.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">Annuleren</button>
                                        <button @click="saveEdit"
                                            class="px-2 py-0.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Opslaan</button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Read row -->
                            <tr v-else class="border-b border-gray-100">
                                <td class="py-1.5">{{ rel.name }}</td>
                                <td class="py-1.5 text-gray-500">
                                    {{productRelations.find(r => r.id === rel.product_relation_id)?.name ?? '—'}}
                                </td>
                                <td class="py-1.5">{{ rel.quantity }}</td>
                                <td class="py-1.5 text-center">
                                    <span v-if="rel.is_required" class="text-green-600 text-xs">✓</span>
                                    <span v-else class="text-gray-300 text-xs">—</span>
                                </td>
                                <td class="py-1.5 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button v-if="hasPermission('productable.update')" @click="startEdit(rel)"
                                            class="text-gray-400 hover:text-gray-600">
                                            <PencilIcon class="size-4" />
                                        </button>
                                        <button v-if="hasPermission('productable.delete')"
                                            @click="removeRelation(rel.productable_id)"
                                            class="text-red-400 hover:text-red-600">
                                            <TrashIcon class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p v-if="eligibleChildProducts.length === 0 && !childProducts.length"
                    class="text-xs text-gray-400 mt-1">
                    Dit producttype heeft geen subtypen, dus er kunnen geen gerelateerde producten worden toegevoegd.
                </p>
            </BoxComponent>
        </template>

        <template #sidebar>
            <ImageUploadComponent :existing="product.images" :imageable-id="product.id"
                imageable-type="\App\Models\Product" />
            <DocumentUploadComponent :existing="product.documents" :documentable-id="product.id"
                documentable-type="\App\Models\Product" class="mt-4" />
        </template>
    </TwoThirdsOneThird>

    <!-- Add asset drawer -->
    <DrawerComponent v-model="addAssetDrawerOpen" :title="`Voeg een ${product.brand.name} ${product.model} toe`">
        <AddAssetForm :allCustomers="allCustomers" :productId="product.id"
            :required-productables-by-product="requiredProductablesByProduct" :bare="true"
            @created="addAssetDrawerOpen = false" />
    </DrawerComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import { CubeIcon, PuzzlePieceIcon, InformationCircleIcon, LinkIcon, TrashIcon, PlusIcon, PencilIcon } from '@heroicons/vue/24/outline';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import { ref, reactive, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import AssetListComponent from '@/Components/AssetListComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import AddAssetForm from '@/Components/AddAssetForm.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    allCustomers: {
        type: Array,
        required: true
    },
    customFields: {
        type: Array,
        default: () => [],
    },
    productTypes: { type: Array, default: () => [] },
    productRelations: { type: Array, default: () => [] },
    eligibleChildProducts: { type: Array, default: () => [] },
    childProducts: { type: Array, default: () => [] },
    requiredProductablesByProduct: { type: Object, default: () => ({}) },
});

const form = useForm({
    description: props.product.description,
    id: props.product.id,
    model: props.product.model,
    brand_id: props.product.brand.id,
    product_type_id: props.product.product_type.id,
    start_sell: props.product.start_sell,
    end_sell: props.product.end_sell,
    typical_certificate_days: props.product.typical_certificate_days,
    retail_price: props.product.retail_price,
    purchase_price: props.product.purchase_price,
    part_no: props.product.part_no,
    origin: 'showPage'
});

watch([
    () => form.description,
    () => form.typical_certificate_days,
    () => form.retail_price,
    () => form.purchase_price,
    () => form.part_no,
    () => form.product_type_id,
], () => {
    form.patch(`/products/${props.product.id}`);
});

const addAssetDrawerOpen = ref(false);

const addingRelation = ref(false)
const newRelation = reactive({
    child_product_id: null,
    product_relation_id: null,
    quantity: 1,
    is_required: false,
})

function submitNewRelation() {
    router.post('/productables', {
        product_id: props.product.id,
        child_product_id: newRelation.child_product_id,
        product_relation_id: newRelation.product_relation_id,
        quantity: newRelation.quantity,
        is_required: newRelation.is_required,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingRelation.value = false
            newRelation.child_product_id = null
            newRelation.product_relation_id = null
            newRelation.quantity = 1
            newRelation.is_required = false
        },
    })
}

function removeRelation(productableId) {
    router.delete(`/productables/${productableId}`, { preserveScroll: true })
}

const editingId = ref(null)
const editForm = reactive({ product_relation_id: null, quantity: 1, is_required: false })

function startEdit(rel) {
    editingId.value = rel.productable_id
    editForm.product_relation_id = rel.product_relation_id
    editForm.quantity = rel.quantity
    editForm.is_required = rel.is_required
}

function cancelEdit() {
    editingId.value = null
}

function saveEdit() {
    router.patch(`/productables/${editingId.value}`, {
        product_relation_id: editForm.product_relation_id,
        quantity: editForm.quantity,
        is_required: editForm.is_required,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null },
    })
}
</script>
