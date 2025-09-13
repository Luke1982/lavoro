<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex justify-between items-start">
                    <div class="flex">
                        <PuzzlePieceIcon class="w-6 h-6 text-gray-500 mr-2" />
                        <h1 class="text-l font-bold">Details van de machine</h1>
                    </div>
                    <TrashIcon class="w-6 h-6 text-red-500 cursor-pointer" @click="deleteAsset"
                        v-tooltip="'Verwijder machine'" />
                </div>
                <div class="flex flex-wrap mt-4 gap-y-3">
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Merk en model</div>
                        <div :class="[editing.product_id ? '' : 'pr-5', 'w-2/3 relative mr-0 md:mr-3']">
                            <span v-if="!editing.product_id">
                                {{ asset.product.brand.name }}
                                <Link class="underline" :href="`/products/${asset.product.id}`">{{ asset.product.model
                                }}</Link>
                            </span>
                            <ComboBox v-if="editing.product_id" :options="allProducts" v-model="form.product_id"
                                @update:modelValue="updateAsset" />
                            <PencilSquareIcon v-if="!editing.product_id"
                                class="w-5 h-5 text-gray-500 absolute right-0 top-0 transform -translate-y-1/2 cursor-pointer"
                                @click="edit('product_id')" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Serienummer</div>
                        <div :class="[editing.serial_number ? '' : 'pr-5', 'w-2/3 relative']">
                            <span v-if="!editing.serial_number">{{ asset.serial_number }} </span>
                            <div class="flex" v-if="editing.serial_number">
                                <TextInput v-model="form.serial_number" class="rounded-l" />
                                <button @click="updateAsset"
                                    class="px-3 py-1 bg-green-600 text-white rounded-r cursor-pointer hover:bg-green-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"
                                        fill="currentColor">
                                        <path
                                            d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-242.7c0-17-6.7-33.3-18.7-45.3L352 50.7C340 38.7 323.7 32 306.7 32L64 32zm0 96c0-17.7 14.3-32 32-32l192 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32L96 224c-17.7 0-32-14.3-32-32l0-64zM224 288a64 64 0 1 1 0 128 64 64 0 1 1 0-128z" />
                                    </svg>
                                </button>
                            </div>
                            <PencilSquareIcon v-if="!editing.serial_number"
                                class="w-5 h-5 text-gray-500 absolute right-0 top-0 transform -translate-y-1/2 cursor-pointer"
                                @click="edit('serial_number')" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Verloopdatum</div>
                        <div :class="[editing.next_service_date ? '' : 'pr-5', 'w-2/3 relative mr-0 md:mr-3']">
                            <span v-if="!editing.next_service_date">{{ nlDate(asset.next_service_date) }} </span>
                            <div class="flex" v-if="editing.next_service_date">
                                <input type="date" v-model="form.next_service_date"
                                    class="rounded-md border-gray-300 border-1 p-2" />
                                <button @click="updateAsset"
                                    class="px-3 py-1 bg-green-600 text-white rounded-r cursor-pointer hover:bg-green-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"
                                        fill="currentColor">
                                        <path
                                            d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-242.7c0-17-6.7-33.3-18.7-45.3L352 50.7C340 38.7 323.7 32 306.7 32L64 32zm0 96c0-17.7 14.3-32 32-32l192 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32L96 224c-17.7 0-32-14.3-32-32l0-64zM224 288a64 64 0 1 1 0 128 64 64 0 1 1 0-128z" />
                                    </svg>
                                </button>
                            </div>
                            <PencilSquareIcon v-if="!editing.next_service_date"
                                class="w-5 h-5 text-gray-500 absolute right-0 top-0 transform -translate-y-1/2 cursor-pointer"
                                @click="edit('next_service_date')" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Status</div>
                        <div :class="[editing.status ? '' : 'pr-5', 'w-2/3 relative']">
                            <div v-if="!editing.status">
                                <span v-if="asset.status === 'Actief'"
                                    class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                                <span v-else
                                    class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{
                                        asset.status }}</span>
                            </div>
                            <ComboBox v-if="editing.status" :options="statusOptions" v-model="form.status" />
                            <PencilSquareIcon v-if="!editing.status"
                                class="w-5 h-5 text-gray-500 absolute right-0 top-0 transform -translate-y-1/2 cursor-pointer"
                                @click="edit('status')" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 flex">
                        <div class="w-1/3 text-xs">Klant</div>
                        <div class="w-2/3 relative">
                            <Link :href="`/customers/${asset.customer.id}`" v-if="!editingCustomer"
                                class="text-blue-600 underline">
                            {{ asset.customer.name }}
                            </Link>
                            <PencilSquareIcon v-if="!editingCustomer"
                                class="w-5 h-5 text-gray-500 absolute right-0 md:right-3 top-2 transform -translate-y-1/2 cursor-pointer"
                                @click="editingCustomer = true" />
                            <ComboBox v-if="editingCustomer" :options="allCustomers" v-model="form.customer_id" />
                        </div>
                    </div>
                </div>
            </BoxComponent>
            <BoxComponent class="mt-5" v-auto-animate>
                <div class="flex justify-between items-center mb-2">
                    <div class="flex">
                        <ExclamationCircleIcon class="w-6 h-6 text-gray-500 mr-2" />
                        <h1 class="text-l font-bold">Storingen</h1>
                    </div>
                    <button v-if="!openNewTicketForm" @click="openNewTicketForm = true"
                        class="bg-emerald-600 rounded-md py-1.5 px-2 text-white hover:bg-emerald-700 cursor-pointer text-sm">
                        <PlusIcon class="w-5 h-5 inline-block mr-1" />
                        Nieuwe storing
                    </button>
                </div>
                <TicketCreationForm :asset-id="asset.id" v-if="openNewTicketForm" @close="openNewTicketForm = false" />
                <TicketCard v-for="ticket in asset.tickets" :key="ticket.id" :ticket="ticket" class="mt-4" />
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
                <h2 class="text-center border-b border-gray-300 pb-2 mb-2">
                    <PuzzlePieceIcon class="w-6 h-6 text-gray-500 mr-2 inline-block" />
                    Foto's van de machine:
                </h2>
                <ImageUploadComponent :existing="asset.images" :imageable-id="asset.id"
                    imageable-type="\App\Models\Asset" />
            </BoxComponent>
            <BoxComponent v-if="asset.product.images.length > 0" class="mt-6">
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
import { ClipboardDocumentCheckIcon, CubeIcon, ExclamationCircleIcon, PencilSquareIcon, PlusIcon, PuzzlePieceIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { nlDate } from '@/Utilities/Utilities';
import TicketCard from '@/Components/TicketCard.vue';
import { ref, watch } from 'vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import ServiceJobRow from '@/Components/ServiceJobRow.vue';
import TicketCreationForm from '@/Components/TicketCreationForm.vue';

const editing = ref({
    product: false,
    serial_number: false,
    next_service_date: false,
    status: false,
    customer: false,
})

const openNewTicketForm = ref(false);
const editingCustomer = ref(false);

const edit = (key) => {
    for (const k in editing.value) {
        editing.value[k] = false;
    }
    editing.value[key] = true;
}

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

const updateAsset = () => {
    form.put(`/assets/${props.asset.id}`, {
        onSuccess: () => {
            for (const key in editing.value) {
                editing.value[key] = false;
            }
            editingCustomer.value = false;
        },
    });
};

const deleteForm = useForm({});
const deleteAsset = () => {
    if (!confirm('Weet je zeker dat je deze machine wilt verwijderen?')) return;
    deleteForm.delete(`/assets/${props.asset.id}`);
};

watch(
    [
        () => form.status,
        () => form.customer_id,
    ],
    updateAsset
)

</script>