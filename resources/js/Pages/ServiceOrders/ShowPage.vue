<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div v-if="serviceOrder.sent"
                    class="mb-4 p-3 rounded border border-amber-400 bg-amber-50 text-amber-800 text-sm font-semibold">
                    Deze order is naar de administratie verzonden. Materialen kunnen niet meer worden aangepast.
                </div>
                <div class="flex items-center justify-between mb-4">
                    <h1 class="text-2xl font-bold flex-1 uppercase">Werkbon van {{ nlDate(serviceOrder.created_at)
                    }}</h1>
                    <a :href="`/serviceorders/${serviceOrder.id}/export/pdf`"
                        class="ml-4 inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-sm"
                        target="_blank" rel="noopener">
                        Exporteer PDF
                    </a>
                    <template v-if="!serviceOrder.sent">
                        <button @click="sendToSnelStart"
                            class="ml-2 inline-flex items-center px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700 text-sm">
                            Verstuur naar SnelStart
                        </button>
                    </template>
                    <span v-else
                        class="ml-2 px-3 py-1.5 inline-flex items-center text-sm rounded bg-green-100 text-green-700 border border-green-300">Verzonden</span>
                </div>
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
                <h2 class="text-lg font-medium my-4 border-b-gray-200 border-b-1 pb-2">Uitgevoerde werkzaamheden</h2>
                <div class="grid grid-cols-12 mt-2">
                    <div class="col-span-12">
                        <EditableTextField type="textarea" v-model="form.description"
                            @update="val => { form.description = val; }"
                            placeholder="Beschrijf hier kort de uitgevoerde werkzaamheden" />
                    </div>
                </div>
                <div v-auto-animate class="my-4">
                    <h2 class="text-lg font-medium my-4 border-b-gray-200 border-b-1 pb-2">Keuringen</h2>
                    <div class="grid grid-cols-12 mt-4">

                        <div class="col-span-12 flex">
                            <ComboBox :options="internalAssets" class="flex-grow" v-model="assetToCheck" />
                            <button @click="addServiceJob"
                                class="w-auto md:w-40 ml-2 px-4 py-1.5 rounded text-sm bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer">
                                Keuren
                            </button>
                        </div>
                    </div>
                    <div v-if="serviceOrder.servicejobs.length > 0"
                        class="grid-cols-12 lg:grid mt-6 text-xs gap-4 font-bold border-b-1 border-gray-300 pb-3 hidden">
                        <div class="col-span-5">Machine</div>
                        <div class="col-span-2">Uitkomst</div>
                        <div class="col-span-2">Tijdelijke goedkeur</div>
                        <div class="col-span-2">Afgerond op</div>
                    </div>
                    <ServiceJobRow v-for="job in serviceOrder.servicejobs" :key="job.id" :servicejob="job" class="mt-4"
                        :asset="job.asset" />
                </div>
                <h2 class="text-lg font-medium my-4 border-b-gray-200 border-b-1 pb-2">Storingen</h2>
                <div class="grid grid-cols-12 mt-4">
                    <div class="col-span-12 flex flex-col md:flex-row">
                        <ComboBox :options="internalTickets" class="flex-grow" v-model="ticketToSolve" />
                        <button @click="attachTicket"
                            class="w-full md:w-40 ml-0 md:ml-2 mt-2 md:mt-0 px-4 py-1.5 rounded text-sm bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer">
                            Voeg storing toe
                        </button>
                    </div>
                </div>
                <div class="flex flex-wrap" v-auto-animate>
                    <div class="w-full md:w-1/2 odd:pr-2 even:pl-2 mt-4" v-for="ticket in serviceOrder.tickets"
                        :key="ticket.id">
                        <TicketCard :ticket="ticket" :disconnect="'service_order_id'" />
                    </div>
                </div>
                <h2 class="text-lg font-medium my-4 border-b-gray-200 border-b-1 pb-2">Materialen</h2>
                <div class="grid grid-cols-12 mt-4">
                    <div class="col-span-12 flex flex-col md:flex-row items-start">
                        <div class="flex flex-grow w-full">
                            <div class="flex flex-col flex-grow">
                                <span class="text-sm mb-2">Kies een materiaal</span>
                                <ComboBox :options="internalMaterials" class="flex-grow" v-model="materialToAdd" />
                            </div>
                            <div class="flex flex-col w-30 ml-2">
                                <span class="text-sm mb-2">Aantal</span>
                                <TextInput v-model="materialsForm.quantity" type="number" placeholder="Aantal" />
                            </div>
                        </div>
                        <button @click="attachMaterial" :disabled="serviceOrder.sent"
                            :class="'self-end mt-2 md:mt-0 ml-2 px-4 py-2 w-full md:w-50 rounded text-sm ' + (serviceOrder.sent ? 'bg-gray-400 text-white cursor-not-allowed' : 'bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer')">
                            Voeg toe
                        </button>
                    </div>
                    <div class="col-span-12 flex mt-5">
                        <div class="w-full">
                            <div v-if="serviceOrder.materials.length > 0"
                                class="hidden md:grid grid-cols-12 text-xs font-bold border-b-1 border-gray-300 pb-3">
                                <div class="col-span-5 pl-4">Materiaal</div>
                                <div class="col-span-2">Aantal</div>
                                <div class="col-span-2">Prijs per stuk</div>
                                <div class="col-span-2">Totaal</div>
                                <div class="col-span-1">Acties</div>
                            </div>
                            <div v-auto-animate>
                                <div v-for="material in serviceOrder.materials" :key="material.id"
                                    class="grid grid-cols-12 py-4 md:py-2 items-center odd:bg-gray-50 px-4 md:px-0 relative">
                                    <div class="col-span-12 md:col-span-5 pl-0 md:pl-4 flex flex-col">
                                        <span class="font-bold text-xs block lg:hidden">Materiaal</span>
                                        {{ material.name }}
                                    </div>
                                    <div class="col-span-12 md:col-span-2 flex flex-col mt-2 md:mt-0">
                                        <span class="font-bold text-xs block lg:hidden">Aantal</span>
                                        <template v-if="!serviceOrder.sent">
                                            <EditableTextField inputType="number" v-model="material.pivot.quantity"
                                                class="w-full" @update="val => {
                                                    materialsForm.quantity = val;
                                                    updateMaterialQuantity(material.pivot.id);
                                                }" />
                                        </template>
                                        <span v-else class="text-sm">{{ material.pivot.quantity }}</span>
                                    </div>
                                    <div class="col-span-6 md:col-span-2 flex flex-col mt-2 md:mt-0">
                                        <span class="font-bold text-xs block lg:hidden">Prijs pst.</span>
                                        € {{ Number(material.price).toFixed(2) }}
                                    </div>
                                    <div class="col-span-6 md:col-span-2 flex flex-col mt-2 md:mt-0">
                                        <span class="font-bold text-xs block lg:hidden">Totaal</span>€ {{
                                            (Number(material.pivot.quantity) *
                                                Number(material.price)).toFixed(2) }}
                                    </div>
                                    <div class="absolute md:relative top-3 right-3 lg:top-0 lg:right-0 col-span-1"
                                        v-if="!serviceOrder.sent">
                                        <TrashIcon class="size-6 md:size-5 text-red-500 cursor-pointer"
                                            @click="detachMaterial(material.pivot.id)"
                                            v-tooltip="'Verwijder dit materiaal van de werkbon'" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <h2 class="text-lg font-medium my-4 border-b-gray-200 border-b-1 pb-2">Afsluiting en opmerkingen</h2>
                <div class="flex flex-wrap">
                    <div class="w-full md:w-1/2 flex flex-col pr-0 md:pr-3">
                        <EditableTextField v-model="form.signed_by" class="w-full mb-5"
                            @update="val => { form.signed_by = val; }"
                            placeholder="Voer de naam van degene in die de werkbon tekent" />
                        <div class="relative" v-if="!editingSignature">
                            <img :src="serviceOrder.signature_base64" alt="">
                            <PencilSquareIcon
                                class="absolute top-2 right-2 transform w-5 h-5 text-gray-600 cursor-pointer"
                                @click="editingSignature = true" />
                        </div>
                        <div class="relative" v-if="editingSignature">
                            <SignaturePad v-model="form.signature_base64" />
                            <XMarkIcon class="absolute top-2 right-2 transform w-5 h-5 text-red-600 cursor-pointer"
                                @click="editingSignature = false" v-if="serviceOrder.signature_base64" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 pl-0 md:pl-3 mt-4 md:mt-0">
                        <RemarksComponent :remarkable-type="'App\\Models\\ServiceOrder'"
                            :remarkable-id="serviceOrder.id" :comments="serviceOrder.remarks" class="mt-8" />
                    </div>
                </div>
            </BoxComponent>
        </template>
        <template #sidebar>
            <div class="space-y-4 mt-6 md:mt-0">
                <div class="bg-white rounded-md border border-gray-200 p-4 text-sm">
                    <h3 class="font-semibold text-base mb-3">Werkbon details</h3>
                    <div class="flex justify-between py-1 border-b border-gray-100">
                        <span class="text-gray-500">Datum</span>
                        <span>{{ nlDate(serviceOrder.created_at) }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-100">
                        <span class="text-gray-500">Klant</span>
                        <Link :href="`/customers/${serviceOrder.customer.id}`" class="underline">{{
                            serviceOrder.customer.name }}</Link>
                    </div>
                    <div class="py-1 border-b border-gray-100">
                        <span class="text-gray-500 block">Adres</span>
                        <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank"
                            class="underline text-xs break-words">
                            {{ serviceOrder.customer.address }}, {{ serviceOrder.customer.postal_code }} {{
                                serviceOrder.customer.city }}
                        </a>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="text-gray-500">Status</span>
                        <span v-if="serviceOrder.sent"
                            class="px-2 py-0.5 text-xs rounded bg-green-100 text-green-700 border border-green-300">Verzonden</span>
                        <span v-else
                            class="px-2 py-0.5 text-xs rounded bg-amber-100 text-amber-700 border border-amber-300">Klaar
                            om te bewaren</span>
                    </div>
                </div>
                <div class="bg-white rounded-md border border-gray-200 p-4 text-sm">
                    <h3 class="font-semibold text-base mb-3">Materiaaloverzicht</h3>
                    <div class="flex justify-between py-1">
                        <span class="text-gray-500">Subtotaal</span>
                        <span>€ {{ materialsSubtotal.toFixed(2) }}</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-gray-500">BTW (21%)</span>
                        <span>€ {{ materialsVat.toFixed(2) }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-t mt-2 font-semibold text-base">
                        <span>Totaal</span>
                        <span>€ {{ materialsTotal.toFixed(2) }}</span>
                    </div>
                </div>
                <div class="bg-white rounded-md border border-gray-200 p-4 text-sm" v-if="timelineItems.length > 0">
                    <h3 class="font-semibold text-base mb-3">Tijdlijn</h3>
                    <ol class="space-y-2">
                        <li v-for="(t, idx) in timelineItems" :key="idx" class="flex items-start">
                            <span class="text-xs w-14 shrink-0 text-gray-500">{{ t.time }}</span>
                            <span class="ml-2 text-xs">{{ t.label }}</span>
                        </li>
                    </ol>
                </div>
                <div class="bg-white rounded-md border border-gray-200 p-4 flex flex-col gap-2">
                    <a :href="`/serviceorders/${serviceOrder.id}/export/pdf`" target="_blank" rel="noopener"
                        class="inline-flex items-center justify-center px-3 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-sm w-full text-center">Exporteer
                        PDF</a>
                    <button v-if="!serviceOrder.sent" @click="sendToSnelStart"
                        class="inline-flex items-center justify-center px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm w-full">Verstuur
                        naar SnelStart</button>
                    <span v-else
                        class="px-3 py-2 text-sm rounded bg-green-100 text-green-700 border border-green-300 text-center">Verzonden</span>
                </div>
            </div>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ServiceJobRow from '@/Components/ServiceJobRow.vue';
import TicketCard from '@/Components/TicketCard.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { mapsLinkFromCustomer, nlDate } from '@/Utilities/Utilities';
import { PencilSquareIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import SignaturePad from '@/Components/UI/SignaturePad.vue';
import RemarksComponent from '@/Components/RemarksComponent.vue';

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

const editingSignature = ref(props.serviceOrder.signature_base64 === null);

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
        () => form.description,
        () => form.signed_by,
        () => form.signature_base64,
    ],
    () => {
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingSignature.value = false;
            }
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

const sendForm = useForm({});
const sendToSnelStart = () => {
    if (props.serviceOrder.sent) {
        return;
    }
    sendForm.post(`/serviceorders/${props.serviceOrder.id}/send-snelstart`, {
        preserveScroll: true,
    });
};

// Sidebar derived values
const materialsSubtotal = computed(() => {
    return props.serviceOrder.materials.reduce((sum, m) => {
        return sum + (Number(m.pivot.quantity) * Number(m.price));
    }, 0);
});
const materialsVat = computed(() => materialsSubtotal.value * 0.21);
const materialsTotal = computed(() => materialsSubtotal.value + materialsVat.value);

const formatTime = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
};
const timelineItems = computed(() => {
    const items = [];
    if (props.serviceOrder.created_at) {
        items.push({ time: formatTime(props.serviceOrder.created_at), label: 'Werkbon aangemaakt' });
    }
    props.serviceOrder.servicejobs.forEach(job => {
        if (job.created_at) {
            items.push({ time: formatTime(job.created_at), label: 'Keuring gestart' });
        }
    });
    props.serviceOrder.materials.forEach(mat => {
        if (mat.pivot?.created_at) {
            items.push({ time: formatTime(mat.pivot.created_at), label: 'Materiaal toegevoegd' });
        }
    });
    // Sort by time ascending
    return items.sort((a, b) => a.time.localeCompare(b.time));
});
</script>