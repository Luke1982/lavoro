<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex justify-between items-start">
                    <div class="flex">
                        <PuzzlePieceIcon class="w-6 h-6 text-gray-500 mr-2" />
                        <h1 class="text-l font-bold">Details van de machine</h1>
                    </div>
                    <TrashIcon v-if="canDelete" class="w-6 h-6 text-red-500 cursor-pointer" @click="deleteAsset"
                        v-tooltip="'Verwijder machine'" />
                </div>
                <div class="flex flex-wrap mt-4 gap-y-3">
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Merk en model</div>
                        <div class="w-2/3 mr-0 md:mr-3">
                            <EditableTextField type="combobox" v-model="form.product_id" :options="allProducts"
                                :readonly="!canUpdate" :error="form.errors.product_id"
                                @revert="form.clearErrors('product_id')">
                                <template #display>
                                    {{ asset.product.brand.name }}
                                    <Link class="underline" :href="`/products/${asset.product.id}`">
                                        {{ asset.product.model }}
                                    </Link>
                                </template>
                            </EditableTextField>
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Serienummer</div>
                        <div class="w-2/3 mr-0 md:mr-3">
                            <EditableTextField v-model="form.serial_number" :readonly="!canUpdate"
                                :error="form.errors.serial_number"
                                @revert="form.clearErrors('serial_number')" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Verloopdatum</div>
                        <div class="w-2/3 mr-0 md:mr-3">
                            <EditableTextField v-model="form.next_service_date" inputType="date"
                                :readonly="!canUpdate" :error="form.errors.next_service_date"
                               
                                @revert="form.clearErrors('next_service_date')" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Status</div>
                        <div class="w-2/3 mr-0 md:mr-3">
                            <EditableTextField type="combobox" v-model="form.status" :options="statusOptions"
                                :readonly="!canUpdate" :error="form.errors.status"
                                @revert="form.clearErrors('status')">
                                <template #display>
                                    <span v-if="asset.status === 'Actief'"
                                        class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                                    <span v-else
                                        class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{ asset.status }}</span>
                                </template>
                            </EditableTextField>
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Klant</div>
                        <div class="w-2/3 mr-0 md:mr-3">
                            <EditableTextField type="combobox" v-model="form.customer_id" :options="allCustomers"
                                :readonly="!canUpdate" :error="form.errors.customer_id"
                               
                                @revert="form.clearErrors('customer_id')">
                                <template #display>
                                    <Link :href="`/customers/${asset.customer.id}`"
                                        class="text-blue-600 underline">{{ asset.customer.name }}</Link>
                                </template>
                            </EditableTextField>
                        </div>
                    </div>
                </div>
                <CustomFieldsComponent v-if="customFields.length" model-type="asset" :model-id="asset.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-6" />
            </BoxComponent>
            <BoxComponent class="mt-5" v-auto-animate>
                <div class="flex justify-between items-center mb-2">
                    <div class="flex">
                        <ExclamationCircleIcon class="w-6 h-6 text-gray-500 mr-2" />
                        <h1 class="text-l font-bold">Storingen</h1>
                    </div>
                    <button v-if="!openNewTicketForm && hasPermission('ticket.create')"
                        @click="openNewTicketForm = true"
                        class="bg-emerald-600 rounded-md py-1.5 px-2 text-white hover:bg-emerald-700 cursor-pointer text-sm">
                        <PlusIcon class="w-5 h-5 inline-block mr-1" />
                        Nieuwe storing
                    </button>
                </div>
                <TicketCreationForm :asset-id="asset.id" v-if="openNewTicketForm" @close="openNewTicketForm = false" />
                <TicketCard v-for="ticket in asset.tickets" :key="ticket.id" :ticket="ticket" class="mt-4" />
            </BoxComponent>
            <BoxComponent v-if="asset.child_asset_relations?.length || asset.parent_asset_relations?.length || (asset.product.productables?.length && hasPermission('assetrelation.create')) || (productHasChildTypes && hasPermission('assetrelation.create'))" class="mt-5">
                <div class="flex items-center py-3 border-t border-gray-200">
                    <LinkIcon class="size-5 text-gray-500 mr-2" />
                    <h3 class="text-sm font-medium">Gerelateerde machines</h3>
                </div>

                <!-- Per-slot view when product has defined related products -->
                <div v-if="asset.product.productables?.length">
                    <p class="text-xs text-gray-400 mb-2">Onderdelen</p>
                    <div v-for="slot in asset.product.productables" :key="slot.id"
                        class="mb-3 border border-gray-100 dark:border-slate-700 rounded-md p-2">
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ slot.child_product.brand.name }} {{ slot.child_product.model }}
                                </span>
                                <span v-if="slot.product_relation" class="text-xs text-gray-400">
                                    ({{ slot.product_relation.name }})
                                </span>
                                <span v-if="slot.is_required"
                                    class="text-xs text-amber-600 bg-amber-50 dark:bg-amber-900/30 rounded px-1">
                                    verplicht
                                </span>
                            </div>
                            <span class="text-xs"
                                :class="childRelationsForSlot(slot.id).length >= slot.quantity ? 'text-gray-400' : 'text-blue-500'">
                                {{ childRelationsForSlot(slot.id).length }} / {{ slot.quantity }}
                            </span>
                        </div>
                        <div v-for="rel in childRelationsForSlot(slot.id)" :key="rel.id"
                            class="flex items-center justify-between py-1 pl-2 border-b border-gray-100 dark:border-slate-600">
                            <div>
                                <Link :href="`/assets/${rel.child_asset.id}`" class="text-blue-600 underline text-sm">
                                    {{ rel.child_asset.product.brand.name }} {{ rel.child_asset.product.model }}
                                </Link>
                                <span class="text-xs text-gray-400 ml-2">{{ rel.child_asset.serial_number ?? '—' }}</span>
                            </div>
                            <button v-if="hasPermission('assetrelation.delete')" @click="removeAssetRelation(rel.id)"
                                class="text-red-400 hover:text-red-600" v-tooltip="'Koppeling verwijderen'">
                                <TrashIcon class="size-4" />
                            </button>
                        </div>
                        <div v-if="hasPermission('assetrelation.create') && childRelationsForSlot(slot.id).length < slot.quantity"
                            class="mt-1.5 pl-2">
                            <div v-if="creatingForSlot !== slot.id">
                                <button @click="openNewChildForm(slot.id)"
                                    class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
                                    <PlusIcon class="size-3" /> Nieuwe machine aanmaken
                                </button>
                            </div>
                            <div v-else class="flex gap-2 items-start mt-1">
                                <div class="flex-1">
                                    <TextInput v-model="newChildForm.serial_number" label="Serienummer"
                                        :hasError="Boolean(newChildForm.errors.serial_number)"
                                        :errorMessage="newChildForm.errors.serial_number" />
                                </div>
                                <button @click="submitNewChild(slot.id)"
                                    class="mt-8 px-3 py-1.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                    Aanmaken
                                </button>
                                <button @click="cancelNewChild"
                                    class="mt-8 px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                    Annuleren
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Relations linked without a productable slot (manual links) -->
                    <div v-if="unslottedChildRelations.length" class="mt-2">
                        <p class="text-xs text-gray-400 mb-1">Overige koppelingen</p>
                        <div v-for="rel in unslottedChildRelations" :key="rel.id"
                            class="flex items-center justify-between py-1 border-b border-gray-50 dark:border-slate-700">
                            <div>
                                <Link :href="`/assets/${rel.child_asset.id}`" class="text-blue-600 underline text-sm">
                                    {{ rel.child_asset.product.brand.name }} {{ rel.child_asset.product.model }}
                                </Link>
                                <span class="text-xs text-gray-400 ml-2">{{ rel.child_asset.serial_number }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">{{ rel.productable?.product_relation?.name ?? '—' }}</span>
                                <button v-if="hasPermission('assetrelation.delete')" @click="removeAssetRelation(rel.id)"
                                    class="text-red-400 hover:text-red-600" v-tooltip="'Koppeling verwijderen'">
                                    <TrashIcon class="size-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Flat list fallback when product has no defined related products -->
                <div v-else-if="asset.child_asset_relations?.length">
                    <p class="text-xs text-gray-400 mb-1">Onderdelen</p>
                    <div v-for="rel in asset.child_asset_relations" :key="rel.id"
                        class="flex items-center justify-between py-1 border-b border-gray-50">
                        <div>
                            <Link :href="`/assets/${rel.child_asset.id}`" class="text-blue-600 underline text-sm">
                                {{ rel.child_asset.product.brand.name }} {{ rel.child_asset.product.model }}
                            </Link>
                            <span class="text-xs text-gray-400 ml-2">{{ rel.child_asset.serial_number }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400">{{ rel.productable?.product_relation?.name ?? '—' }}</span>
                            <button v-if="hasPermission('assetrelation.delete')" @click="removeAssetRelation(rel.id)"
                                class="text-red-400 hover:text-red-600" v-tooltip="'Koppeling verwijderen'">
                                <TrashIcon class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="asset.parent_asset_relations?.length" class="mt-2">
                    <p class="text-xs text-gray-400 mb-1">Onderdeel van</p>
                    <div v-for="rel in asset.parent_asset_relations" :key="rel.id"
                        class="flex items-center justify-between py-1 border-b border-gray-50 dark:border-slate-700">
                        <div>
                            <Link :href="`/assets/${rel.parent_asset.id}`" class="text-blue-600 underline text-sm">
                                {{ rel.parent_asset.product.brand.name }} {{ rel.parent_asset.product.model }}
                            </Link>
                            <span class="text-xs text-gray-400 ml-2">{{ rel.parent_asset.serial_number }}</span>
                        </div>
                        <span class="text-xs text-gray-400">{{ rel.productable?.product_relation?.name ?? '—' }}</span>
                    </div>
                </div>

                <div v-if="!asset.product.productables?.length && productHasChildTypes && !eligibleChildAssets.length && !asset.child_asset_relations?.length && hasPermission('assetrelation.create')" class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded text-xs text-amber-700 dark:text-amber-300">
                    Er zijn geen machines van de juiste subtypes gevonden bij deze klant om te koppelen. Voeg eerst een onderdeel-machine toe aan de klant.
                </div>

                <div v-if="eligibleChildAssets.length && hasPermission('assetrelation.create')" class="mt-3">
                    <div v-if="!addingManualLink">
                        <button @click="addingManualLink = true"
                            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
                            <PlusIcon class="size-4" /> Machine handmatig koppelen
                        </button>
                    </div>
                    <div v-else class="p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2 mt-2">
                        <div class="flex gap-2 flex-wrap">
                            <div class="flex-1 min-w-48">
                                <label class="block text-xs text-gray-500 mb-1">Machine</label>
                                <ComboBox :options="eligibleChildAssets" v-model="manualLink.child_asset_id"
                                    placeholder="Selecteer machine" />
                            </div>
                            <div class="flex-1 min-w-32">
                                <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                                <ComboBox :options="productRelations" v-model="manualLink.product_relation_id"
                                    placeholder="Selecteer type" />
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button @click="submitManualLink"
                                class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                Koppelen
                            </button>
                            <button @click="addingManualLink = false; manualLink.child_asset_id = null; manualLink.product_relation_id = null"
                                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                Annuleren
                            </button>
                        </div>
                    </div>
                </div>
            </BoxComponent>
            <BoxComponent class="mt-5">
                <div class="flex">
                    <ClipboardDocumentCheckIcon class="w-6 h-6 text-gray-500 mr-2" />
                    <h1 class="text-l font-bold">Keuringen</h1>
                </div>
                <div v-if="asset.servicejobs.length"
                    class="hidden lg:grid grid-cols-10 mt-3 text-xs gap-4 font-bold border-b-1 border-gray-300 pb-3">
                    <div class="col-span-2">Uitkomst</div>
                    <div class="col-span-2">Tijdelijke goedkeur</div>
                    <div class="col-span-2">Afgerond op</div>
                </div>
                <div v-auto-animate>
                    <ServiceJobRow v-for="servicejob in asset.servicejobs" :key="servicejob.id" :servicejob="servicejob"
                        class="mt-4" />
                </div>
                <p v-if="!asset.servicejobs.length" class="text-sm text-gray-500 mt-3">Geen keuringen gevonden.</p>
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent>
                <h2 class="text-center border-b border-gray-300 pb-2 mb-2"
                    v-if="hasPermission('image.upload') || hasPermission('image.see')">
                    <PuzzlePieceIcon class="w-6 h-6 text-gray-500 mr-2 inline-block" />
                    Foto's van de machine:
                </h2>
                <ImageUploadComponent :existing="asset.images" :imageable-id="asset.id"
                    imageable-type="\App\Models\Asset" />
            </BoxComponent>
            <BoxComponent v-if="asset.product.images.length > 0 && hasPermission('image.see')" class="mt-6">
                <h2 class="text-center border-b border-gray-300 pb-2 mb-2">
                    <CubeIcon class="w-6 h-6 text-gray-500 mr-2 inline-block" />
                    Foto's van het
                    <Link :href="`/products/${asset.product.id}`" class="text-blue-600 underline">
                    product
                    </Link>:
                </h2>
                <div class="grid grid-cols-2 gap-6 items-center mt-4">
                    <img v-for="image in asset.product.images" :key="image.id" :src="`/storage/${image.path}`"
                        alt="{{ image.name }}" class="w-full h-auto rounded-lg mb-4" />
                </div>
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { ClipboardDocumentCheckIcon, CubeIcon, ExclamationCircleIcon, LinkIcon, PlusIcon, PuzzlePieceIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm, router } from '@inertiajs/vue3';
import TicketCard from '@/Components/TicketCard.vue';
import { ref, watch, computed, reactive } from 'vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import ServiceJobRow from '@/Components/ServiceJobRow.vue';
import TicketCreationForm from '@/Components/TicketCreationForm.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import { hasPermission } from '@/Utilities/Utilities';

const openNewTicketForm = ref(false);

const props = defineProps({
    asset: {
        type: Object,
        required: true,
    },
    allProducts: {
        type: Array,
        required: true,
    },
    allCustomers: {
        type: Array,
        required: true,
    },
    customFields: {
        type: Array,
        default: () => [],
    },
    eligibleChildAssets:    { type: Array,   default: () => [] },
    productHasChildTypes:   { type: Boolean, default: false },
    productRelations:       { type: Array,   default: () => [] },
});

const statusOptions = [
    { id: 'Actief', name: 'Actief' },
    { id: 'Niet actief', name: 'Niet actief' },
];

const form = useForm({
    product_id: props.asset.product.id,
    serial_number: props.asset.serial_number,
    next_service_date: props.asset.next_service_date,
    status: props.asset.status,
    customer_id: props.asset.customer.id,
});

const canUpdate = computed(() => hasPermission('asset.update'))
const canDelete = computed(() => hasPermission('asset.delete'))

const updateAsset = () => {
    if (!canUpdate.value) return;
    form.put(`/assets/${props.asset.id}`);
};

const deleteForm = useForm({});
const deleteAsset = () => {
    if (!confirm('Weet je zeker dat je deze machine wilt verwijderen?')) return;
    deleteForm.delete(`/assets/${props.asset.id}`);
};

watch(
    [
        () => form.product_id,
        () => form.status,
        () => form.customer_id,
        () => form.serial_number,
        () => form.next_service_date,
    ],
    updateAsset
)

const addingManualLink = ref(false)
const manualLink = reactive({ child_asset_id: null, product_relation_id: null })

const creatingForSlot = ref(null)
const newChildForm = useForm({ productable_id: null, serial_number: '' })

function childRelationsForSlot(productableId) {
    return props.asset.child_asset_relations?.filter(r => r.productable_id === productableId) ?? []
}

const unslottedChildRelations = computed(() =>
    props.asset.child_asset_relations?.filter(r => !r.productable_id) ?? []
)

function openNewChildForm(slotId) {
    creatingForSlot.value = slotId
    newChildForm.reset()
    newChildForm.clearErrors()
}

function cancelNewChild() {
    creatingForSlot.value = null
    newChildForm.reset()
    newChildForm.clearErrors()
}

function submitNewChild(productableId) {
    newChildForm.productable_id = productableId
    newChildForm.post(`/assets/${props.asset.id}/child`, {
        preserveScroll: true,
        onSuccess: () => {
            creatingForSlot.value = null
            newChildForm.reset()
        },
    })
}

function submitManualLink() {
    router.post('/assetrelations', {
        parent_asset_id:     props.asset.id,
        child_asset_id:      manualLink.child_asset_id,
        product_relation_id: manualLink.product_relation_id,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingManualLink.value = false
            manualLink.child_asset_id = null
            manualLink.product_relation_id = null
        },
    })
}

function removeAssetRelation(relationId) {
    router.delete(`/assetrelations/${relationId}`, { preserveScroll: true })
}

</script>