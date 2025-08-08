<template>
    <BoxComponent>
        <h1 class="text-2xl font-bold mb-4 text-center uppercase">Werkbon van {{ nlDate(serviceOrder.created_at) }}</h1>
        <div class="grid grid-cols-12 gap-y-2 border-b border-gray-200 pb-4">
            <div class="col-span-2 text-xs">
                Naam klant
            </div>
            <div class="col-span-4">
                <Link :href="`/customers/${serviceOrder.customer.id}`" class="underline">
                {{ serviceOrder.customer.name }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Adres
            </div>
            <div class="col-span-4">
                <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank" class="underline">{{
                    serviceOrder.customer.address
                    }}, {{
                        serviceOrder.customer.postal_code }} {{
                        serviceOrder.customer.city }}
                </a>
            </div>
        </div>
        <div v-auto-animate class="my-4">
            <h2 class="text-xl font-bold text-center uppercase">Keuringen</h2>
            <div class="grid grid-cols-12 mt-4">
                <div class="col-span-2 text-xs">
                    Kies een machine om te keuren
                </div>
                <div class="col-span-10 flex">
                    <ComboBox :options="internalAssets" class="flex-grow" v-model="assetToCheck" />
                    <button @click="addServiceJob"
                        class="w-70 ml-2 px-4 py-1.5 bg-indigo-600 text-white rounded hover:bg-indigo-700 cursor-pointer text-sm">
                        Keuren
                    </button>
                </div>
            </div>
            <div v-if="serviceOrder.servicejobs.length > 0"
                class="grid-cols-6 grid mt-3 text-xs gap-4 font-bold border-b-1 border-gray-300 pb-3">
                <div class="col-span-1">Machine</div>
                <div class="col-span-1">Uitkomst</div>
                <div class="col-span-1">Tijdelijke goedkeur</div>
                <div class="col-span-1">Afgerond op</div>
                <div class="col-span-2">Omschrijving</div>
            </div>
            <ServiceJobRow v-for="job in serviceOrder.servicejobs" :key="job.id" :servicejob="job" class="mt-4"
                :asset="job.asset" />
        </div>
        <h2 class="text-xl font-bold my-4 text-center uppercase">Storingen</h2>
        <div class="grid grid-cols-12 mt-4">
            <div class="col-span-2 text-xs">
                Welke storing(en) wil je oplossen op deze bon?
            </div>
            <div class="col-span-10 flex">
                <ComboBox :options="internalTickets" class="flex-grow" v-model="ticketToSolve" />
                <button @click="attachTicket"
                    class="w-70 ml-2 px-4 py-1.5 bg-indigo-600 text-white rounded hover:bg-indigo-700 cursor-pointer text-sm">
                    Voeg storing aan werkbon toe
                </button>
            </div>
        </div>
        <div class="flex flex-wrap" v-auto-animate>
            <div class="w-1/2 odd:pr-2 even:pl-2 mt-4" v-for="ticket in serviceOrder.tickets" :key="ticket.id">
                <TicketCard :ticket="ticket" :disconnect="'service_order_id'" />
            </div>
        </div>
        <h2 class="text-xl font-bold my-4 text-center uppercase">Materialen</h2>
        <div class="grid grid-cols-12 mt-4">
            <div class="col-span-2 text-xs">
                Welke materialen heb je gebruikt?
            </div>
            <div class="col-span-10 flex">
                <div class="flex flex-grow">
                    <div class="flex flex-col flex-grow">
                        <span class="text-sm mb-2">Kies een materiaal</span>
                        <ComboBox :options="internalMaterials" class="flex-grow" v-model="materialToAdd" />
                    </div>
                    <div class="flex flex-col w-30 ml-2">
                        <span class="text-sm mb-2">Aantal</span>
                        <TextInput v-model="materialsForm.quantity" type="number" placeholder="Aantal" />
                    </div>
                </div>
                <button @click="attachMaterial"
                    class="self-end ml-2 px-4 py-2 w-70 bg-indigo-600 text-white rounded hover:bg-indigo-700 cursor-pointer text-sm">
                    Voeg materiaal aan werkbon toe
                </button>
            </div>
            <div class="col-span-2 text-xs mt-5">
                Deze materialen zijn toegevoegd
            </div>
            <div class="col-span-10 flex mt-5">
                <div class="w-full">
                    <div v-if="serviceOrder.materials.length > 0"
                        class="grid grid-cols-12 text-xs font-bold border-b-1 border-gray-300 pb-3">
                        <div class="col-span-5 pl-4">Materiaal</div>
                        <div class="col-span-2">Aantal</div>
                        <div class="col-span-2">Prijs per stuk</div>
                        <div class="col-span-2">Totaal</div>
                        <div class="col-span-1">Acties</div>
                    </div>
                    <div v-auto-animate>
                        <div v-for="material in serviceOrder.materials" :key="material.id"
                            class="grid grid-cols-12 py-2 items-center odd:bg-gray-50">
                            <div class="col-span-5 pl-4">{{ material.name }}</div>
                            <div class="col-span-2">
                                <EditableTextField inputType="number" v-model="material.pivot.quantity" class="w-full"
                                    @update="val => {
                                        materialsForm.quantity = val;
                                        updateMaterialQuantity(material.pivot.id);
                                    }" />
                            </div>
                            <div class="col-span-2">€ {{ Number(material.price).toFixed(2) }}</div>
                            <div class="col-span-2">€ {{ (Number(material.pivot.quantity) *
                                Number(material.price)).toFixed(2) }}</div>
                            <div class="col-span-1">
                                <TrashIcon class="w-5 h-5 text-red-500 cursor-pointer"
                                    @click="detachMaterial(material.pivot.id)"
                                    v-tooltip="'Verwijder dit materiaal van de werkbon'" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <h2 class="text-xl font-bold my-4 text-center uppercase">Afsluiting</h2>
        <div class="flex justify-center">
            <div class="w-150 flex flex-col">
                <EditableTextField v-model="form.signed_by" class="w-full mb-3"
                    @update="val => { form.signed_by = val; }" />
                <SignaturePad v-model="form.signature_base64" class="" />
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ServiceJobRow from '@/Components/ServiceJobRow.vue';
import TicketCard from '@/Components/TicketCard.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { mapsLinkFromCustomer, nlDate } from '@/Utilities/Utilities';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SignaturePad from '@/Components/UI/SignaturePad.vue';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    },
    allMaterials: {
        type: Array,
        required: true
    }
});


const internalMaterials = props.allMaterials.slice().sort((a, b) =>
    a.name.localeCompare(b.name)
).map((material) => {
    return {
        id: material.id,
        name: `${material.name}, code ${material.code}, voorraad ${material.stock}, prijs € ${material.price}`,
    };
});
const materialToAdd = ref(internalMaterials[0]?.id || null);

const internalAssets = props.serviceOrder.customer.assets.slice().sort((a, b) =>
    a.product.product_type.name.localeCompare(b.product.product_type.name)
).map((asset) => {
    return {
        id: asset.id,
        name: `${asset.product.product_type.name}: ${asset.product.brand.name} ${asset.product.model} (${asset.serial_number}), ${asset.status}. Verloopt op ${nlDate(asset.next_service_date)}`,
    };
});
const internalTickets = ref([]);

watch(
    () => props.serviceOrder.tickets,
    (newTickets) => {
        internalTickets.value = props.serviceOrder.customer.tickets.slice()
            .filter(ticket => ticket.status !== 'Gesloten' && newTickets.map(t => t.id).indexOf(ticket.id) === -1)
            .sort((a, b) =>
                a.asset.product.product_type.name.localeCompare(b.asset.product.product_type.name)
            )
            .map((ticket) => {
                return {
                    id: ticket.id,
                    name: `${ticket.asset.product.product_type.name}: ${ticket.asset.product.brand.name} ${ticket.asset.product.model} (${ticket.asset.serial_number}), ${ticket.subject}`,
                };
            })
    },
    { deep: true, immediate: true }
)

const assetToCheck = ref(internalAssets[0]?.id || null);
const ticketToSolve = ref(internalTickets.value[0]?.id || null);

const form = useForm({
    ...props.serviceOrder
});

const materialsForm = useForm({
    quantity: 1,
});

const newServicejobForm = useForm({
    service_order_id: props.serviceOrder.id,
    asset_id: assetToCheck.value,
    outcome: 'Nog geen uitkomst',
});

const addServiceJob = () => {
    newServicejobForm.asset_id = assetToCheck.value;
    newServicejobForm.post(`/servicejobs`, {
        preserveScroll: true
    })
};

watch(
    [
        () => form.signed_by,
        () => form.signature_base64,
    ],
    () => {
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
        });
    }
)

const attachTicket = () => {
    if (!ticketToSolve.value) return;
    form.post(`/serviceorders/${props.serviceOrder.id}/tickets/${ticketToSolve.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            internalTickets.value = internalTickets.value.filter(ticket => ticket.id !== ticketToSolve.value);
        }
    });
};

const attachMaterial = () => {
    if (!materialToAdd.value || materialsForm.quantity <= 0) return;

    materialsForm.post(`/serviceorders/${props.serviceOrder.id}/materials/${materialToAdd.value}`, {
        preserveScroll: true,
    });
};

const detachMaterial = (materiableId) => {
    materialsForm.delete(`/serviceorders/${props.serviceOrder.id}/materials/${materiableId}`, {
        preserveScroll: true,
    });
};

const updateMaterialQuantity = (materiableId) => {
    materialsForm.put(`/serviceorders/${props.serviceOrder.id}/materials/${materiableId}`, {
        preserveScroll: true,
        onSuccess: () => {
            materialsForm.reset()
        }
    });
};
</script>