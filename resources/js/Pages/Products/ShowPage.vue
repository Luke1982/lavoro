<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex border-b border-gray-200 items-center">
                    <div class="flex justify-between w-full">
                        <div class="flex">
                            <CubeIcon class="size-12 flex-none object-cover p-2 mb-2" />
                            <div class="flex flex-col">
                                <span class="text-lg">{{ product.brand.name }} {{ product.model }}</span>
                                <span class="text-sm text-gray-400">{{ product.product_type.name }}</span>
                            </div>
                        </div>
                        <div class="flex self-ends">
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
                <div class="mt-4 flex">
                    <div class="flex flex-col w-1/2">
                        <div class="flex">
                            <h3 class="text-sm font-semibold mb-3">Beschrijving</h3>
                            <component :is="currentIcon"
                                :class="[editing ? 'text-green-700' : 'text-gray-600', 'ml-2 inline h-5 w-5 mr-2 cursor-pointer']"
                                @click="editing = !editing" />
                        </div>
                        <p class="text-gray-600" v-if="!editing">{{ form.description }}</p>
                        <textarea v-if="editing" v-model="form.description"
                            class="w-full p-2 border rounded"></textarea>
                    </div>
                    <div class="w-1/2">
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
                        <EditableTextField v-model="form.typical_certificate_days" type="input" input-type="number" />
                    </div>
                </div>
                <div v-if="hasPermission('product.view_prices')" class="mt-4 flex gap-4">
                    <div class="w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Verkoopprijs</h3>
                        <EditableTextField v-model="form.retail_price" type="input" input-type="number" />
                    </div>
                    <div class="w-1/2">
                        <h3 class="text-sm font-semibold mb-3">Inkoopprijs</h3>
                        <EditableTextField v-model="form.purchase_price" type="input" input-type="number" />
                    </div>
                </div>
                <CustomFieldsComponent v-if="customFields.length" model-type="product" :model-id="product.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-6" />
                <div v-if="hasPermission('asset.read') && product.assets.length > 0">
                    <div class="flex items-center py-3 border-t border-gray-200 mt-5">
                        <PuzzlePieceIcon class="size-6 text-gray-500" />
                        <h3 class="text-sm font-medium ml-2">Machines</h3>
                    </div>
                    <AssetListComponent :assets="product.assets" />
                </div>

                <!-- Gerelateerde producten -->
                <div v-if="hasPermission('productable.read')" class="mt-6">
                    <div class="flex items-center justify-between py-3 border-t border-gray-200 mt-2">
                        <div class="flex items-center">
                            <LinkIcon class="size-5 text-gray-500 mr-2" />
                            <h3 class="text-sm font-medium">Gerelateerde producten</h3>
                        </div>
                        <button
                            v-if="hasPermission('productable.create') && eligibleChildProducts.length"
                            @click="addingRelation = !addingRelation"
                            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800"
                        >
                            <PlusIcon class="size-4" /> Toevoegen
                        </button>
                    </div>

                    <!-- Add form -->
                    <div v-if="addingRelation" class="mb-3 p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2">
                        <div class="flex gap-2 flex-wrap">
                            <div class="flex-1 min-w-40">
                                <label class="block text-xs text-gray-500 mb-1">Gerelateerd product</label>
                                <ComboBox :options="eligibleChildProducts" v-model="newRelation.child_product_id" placeholder="Selecteer product" />
                            </div>
                            <div class="flex-1 min-w-32">
                                <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                                <ComboBox :options="productRelations" v-model="newRelation.product_relation_id" placeholder="Selecteer type" />
                            </div>
                            <div class="w-20">
                                <label class="block text-xs text-gray-500 mb-1">Aantal</label>
                                <input type="number" min="1" v-model.number="newRelation.quantity"
                                    class="w-full rounded border-gray-300 text-sm p-1 border" />
                            </div>
                            <div class="flex items-end pb-1">
                                <label class="flex items-center gap-1 text-xs text-gray-600 cursor-pointer">
                                    <input type="checkbox" v-model="newRelation.is_required" class="rounded" />
                                    Verplicht
                                </label>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button @click="submitNewRelation"
                                class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                Opslaan
                            </button>
                            <button @click="addingRelation = false"
                                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                Annuleren
                            </button>
                        </div>
                    </div>

                    <!-- Existing relations -->
                    <div v-if="childProducts.length === 0 && !addingRelation" class="text-sm text-gray-400 italic">
                        Geen gerelateerde producten.
                    </div>
                    <table v-if="childProducts.length" class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-400 border-b">
                                <th class="text-left py-1 font-medium">Product</th>
                                <th class="text-left py-1 font-medium">Type</th>
                                <th class="text-center py-1 font-medium">Aantal</th>
                                <th class="text-center py-1 font-medium">Verplicht</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rel in childProducts" :key="rel.productable_id" class="border-b border-gray-100">
                                <td class="py-1.5">{{ rel.name }}</td>
                                <td class="py-1.5 text-gray-500">
                                    {{ productRelations.find(r => r.id === rel.product_relation_id)?.name ?? '—' }}
                                </td>
                                <td class="py-1.5 text-center">{{ rel.quantity }}</td>
                                <td class="py-1.5 text-center">
                                    <span v-if="rel.is_required" class="text-green-600 text-xs">✓</span>
                                    <span v-else class="text-gray-300 text-xs">—</span>
                                </td>
                                <td class="py-1.5 text-right">
                                    <button
                                        v-if="hasPermission('productable.delete')"
                                        @click="removeRelation(rel.productable_id)"
                                        class="text-red-400 hover:text-red-600"
                                    >
                                        <TrashIcon class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="eligibleChildProducts.length === 0 && !childProducts.length"
                        class="text-xs text-gray-400 mt-1">
                        Dit producttype heeft geen subtypen, dus er kunnen geen gerelateerde producten worden toegevoegd.
                    </p>
                </div>
            </BoxComponent>
        </template>
        <template #sidebar>
            <ImageUploadComponent :existing="product.images" :imageable-id="product.id"
                imageable-type="\App\Models\Product" />
            <DocumentUploadComponent :existing="product.documents" :documentable-id="product.id"
                documentable-type="\App\Models\Product" class="mt-4" />
            <AddAssetForm :allCustomers="allCustomers" :productId="product.id" v-if="hasPermission('asset.create')"
                :required-productables-by-product="requiredProductablesByProduct" />
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue';
import { CubeIcon, PencilSquareIcon, CheckCircleIcon, PuzzlePieceIcon, InformationCircleIcon, LinkIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { ref, reactive, computed, watch } from 'vue';
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
    productRelations:              { type: Array, default: () => [] },
    eligibleChildProducts:         { type: Array, default: () => [] },
    childProducts:                 { type: Array, default: () => [] },
    requiredProductablesByProduct: { type: Object, default: () => ({}) },
});

const editing = ref(false);
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
    origin: 'showPage'
});

watch([
    () => form.description,
    () => form.typical_certificate_days,
    () => form.retail_price,
    () => form.purchase_price,
], () => {
    form.patch(`/products/${props.product.id}`);
});

const currentIcon = computed(() =>
    editing.value ? CheckCircleIcon : PencilSquareIcon
);

const addingRelation  = ref(false)
const newRelation     = reactive({
    child_product_id:    null,
    product_relation_id: null,
    quantity:            1,
    is_required:         false,
})

function submitNewRelation() {
    router.post('/productables', {
        product_id:          props.product.id,
        child_product_id:    newRelation.child_product_id,
        product_relation_id: newRelation.product_relation_id,
        quantity:            newRelation.quantity,
        is_required:         newRelation.is_required,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingRelation.value = false
            newRelation.child_product_id    = null
            newRelation.product_relation_id = null
            newRelation.quantity            = 1
            newRelation.is_required         = false
        },
    })
}

function removeRelation(productableId) {
    router.delete(`/productables/${productableId}`, { preserveScroll: true })
}

</script>