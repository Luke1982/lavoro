<template>
    <BoxComponent>
        <div class="flex flex-wrap mb-4 border-b-1 border-gray-200 dark:border-slate-700/60 pb-2 justify-between">
            <div class="flex w-full lg:w-auto justify-center mb-2 lg:mb-0">
                <CalendarDateRangeIcon class="size-6 flex-none text-gray-500 dark:text-slate-400 mr-2" />
                <h2 class="font-regular text-xl text-gray-800 dark:text-slate-100 tracking-wide">Aankomende activiteiten
                </h2>
            </div>
            <div class="flex gap-2 items-start w-full lg:w-auto">
                <ComboBox
                    :options="[{ id: '60', name: 'Aankomende 60 dagen' }, { id: '90', name: 'Aankomende 90 dagen' }, { id: '180', name: 'Aankomende 180 dagen' }, { id: '365', name: 'Aankomende 365 dagen' }]"
                    class="w-full lg:w-64 z-20" placeholder="Filter op periode" v-model="form.days"
                    :initial-id="form.days" />
                <button type="button" @click="openMap"
                    class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 text-white rounded text-xs font-semibold">
                    Kaart
                </button>
            </div>
        </div>
        <div v-if="expiredAssets.length > 0" class="mb-8">
            <h3 class="text-lg font-medium text-red-600 dark:text-red-400 mb-4">Klanten met vervallen machines</h3>
            <div v-for="mainAsset in expiredAssets" :key="`expiredAsset${mainAsset.id}`"
                :id="`customer-section-${mainAsset.customer.id}`" class="my-8">
                <CustomerUpcomingActivity :main-asset="mainAsset" v-model:selected-assets="form.selectedAssets"
                    v-model:selected-tickets="form.selectedTickets" :customer-state="customerState"
                    :get-non-planned-tickets="getNonPlannedTickets" :get-planned-tickets="getPlannedTickets"
                    @select-all="selectAllFor" />
            </div>
        </div>
        <div v-if="upcomingAssets.length > 0" class="mb-8">
            <h3 class="text-lg font-medium text-green-600 dark:text-green-400 mb-4">Klanten met aankomende machines</h3>

            <div v-for="mainAsset in upcomingAssets" :key="`mainAsset${mainAsset.id}`"
                :id="`customer-section-${mainAsset.customer.id}`" class="my-8">
                <CustomerUpcomingActivity :main-asset="mainAsset" v-model:selected-assets="form.selectedAssets"
                    v-model:selected-tickets="form.selectedTickets" :customer-state="customerState"
                    :get-non-planned-tickets="getNonPlannedTickets" :get-planned-tickets="getPlannedTickets"
                    @select-all="selectAllFor" />
            </div>
        </div>
    </BoxComponent>
    <div class="fixed right-4 bottom-4 z-30">
        <div v-auto-animate class="flex flex-col gap-2">
            <button v-if="canCreateWorkOrder === 'yes' && canCreateServiceOrder"
                :disabled="canCreateWorkOrder === 'diffCustomers'" @click="createServiceOrder(false)" v-auto-animate
                class="cursor-pointer bg-amber-700 hover:bg-amber-800 dark:bg-amber-600 dark:hover:bg-amber-700 text-white p-2 rounded-md disabled:bg-red-600 disabled:cursor-not-allowed transition-colors shadow-lg">
                <ClipboardDocumentCheckIcon class="w-6 h-6 inline-block mr-2" />
                <span>Maak een werkbon aan en blijf hier</span>
            </button>
            <button v-if="canCreateWorkOrder !== 'no' && canCreateServiceOrder"
                :disabled="canCreateWorkOrder === 'diffCustomers'" @click="createServiceOrder(true)" v-auto-animate
                class="cursor-pointer bg-amber-700 hover:bg-amber-800 dark:bg-amber-600 dark:hover:bg-amber-700 text-white p-2 rounded-md disabled:bg-red-600 disabled:cursor-not-allowed transition-colors shadow-lg">
                <ClipboardDocumentCheckIcon class="w-6 h-6 inline-block mr-2" />
                <span v-if="canCreateWorkOrder === 'yes'">Maak een werkbon aan en open die</span>
                <span v-else-if="canCreateWorkOrder === 'diffCustomers'">Selecteer storingen en keuringen van één
                    klant</span>
            </button>
            <button v-if="canCreateWorkOrder === 'yes' && canCreateServiceOrder"
                :disabled="canCreateWorkOrder === 'diffCustomers'" @click="createServiceOrderAndPlan" v-auto-animate
                class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 text-white p-2 rounded-md disabled:bg-red-600 disabled:cursor-not-allowed transition-colors shadow-lg">
                <CalendarIcon class="w-6 h-6 inline-block mr-2" />
                <span>Maak werkbon en plan deze in</span>
            </button>
        </div>
    </div>
    <div v-if="planningModalOpen" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40" @click="closePlanningModal">
    </div>
    <div v-if="planningModalOpen" class="fixed inset-0 flex items-center justify-center z-50 p-4" v-auto-animate>
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col"
            @click.stop>
            <div class="p-4 border-b dark:border-slate-700">
                <h3 class="text-lg font-medium text-gray-900 dark:text-slate-100">Plan Werkbon</h3>
            </div>
            <div class="p-4 overflow-y-auto" v-auto-animate>
                <div v-if="!eventForm.service_order_id" class="text-center p-4">
                    <p>Werkbon aanmaken...</p>
                </div>
                <div v-else-if="!eventSuccessfullyCreated">
                    <div class="flex flex-wrap -mx-2">
                        <div class="w-full lg:w-1/2 px-2 mb-4">
                            <TextInput v-model="eventForm.start_date" label="Startdatum" type="date" class="w-full"
                                :has-error="Boolean(eventForm.errors.start)" :error-message="eventForm.errors.start" />
                        </div>
                        <div class="w-full lg:w-1/2 px-2 mb-4">
                            <TextInput v-model="eventForm.start_time" label="Starttijd" type="time" class="w-full"
                                :has-error="Boolean(eventForm.errors.start)" :error-message="eventForm.errors.start" />
                        </div>
                        <div class="w-full lg:w-1/2 px-2 mb-4">
                            <TextInput v-model="eventForm.end_date" label="Einddatum" type="date" class="w-full"
                                :has-error="Boolean(eventForm.errors.end)" :error-message="eventForm.errors.end" />
                        </div>
                        <div class="w-full lg:w-1/2 px-2 mb-4">
                            <TextInput v-model="eventForm.end_time" label="Eindtijd" type="time" class="w-full"
                                :has-error="Boolean(eventForm.errors.end)" :error-message="eventForm.errors.end" />
                        </div>
                        <div class="w-full px-2 mb-4">
                            <ComboBox v-model="eventForm.event_type_id" :options="eventTypes" label="Type"
                                class="w-full" />
                        </div>
                        <div class="w-full px-2 mb-4">
                            <ComboBox v-model="eventForm.executing_user_ids" :options="allUsers"
                                label="Uitvoerende gebruikers" class="w-full" :multiple="true" />
                            <p v-if="eventForm.errors.executing_user_ids"
                                class="text-sm text-red-600 dark:text-red-400 mt-1">
                                {{ eventForm.errors.executing_user_ids }}
                            </p>
                        </div>
                    </div>
                </div>
                <div v-else class="text-center p-4">
                    <p class="text-lg text-green-600 dark:text-green-400">Werkbon succesvol ingepland!</p>
                </div>
            </div>
            <div class="p-4 border-t dark:border-slate-700 flex justify-end gap-2" v-if="eventForm.service_order_id">
                <div v-if="!eventSuccessfullyCreated" class="flex gap-2">
                    <button @click="closePlanningModal"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600">
                        Annuleren
                    </button>
                    <button @click="submitEvent" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                        :disabled="eventForm.processing">
                        Opslaan
                    </button>
                </div>
                <div v-else class="flex gap-2">
                    <button @click="closePlanningModal"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600">
                        Sluiten
                    </button>
                    <a :href="`/serviceorders/${eventForm.service_order_id}`"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 flex items-center">
                        Naar werkbon
                    </a>
                    <a href="/events"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 flex items-center">
                        Naar kalender
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { CalendarDateRangeIcon, ClipboardDocumentCheckIcon, CalendarIcon } from '@heroicons/vue/24/outline';
import { useForm, usePage } from '@inertiajs/vue3';
import { watch, ref, computed } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';
import TextInput from '@/Components/UI/TextInput.vue';
import axios from 'axios';
import CustomerUpcomingActivity from '@/Components/CustomerUpcomingActivity.vue';

const { upcomingAssets, expiredAssets, eventTypes, allUsers } = defineProps({
    upcomingAssets: {
        type: Array,
        required: true
    },
    expiredAssets: {
        type: Array,
        required: true
    },
    eventTypes: {
        type: Array,
        default: () => []
    },
    allUsers: {
        type: Array,
        default: () => []
    }
});

const form = useForm({
    days: new URL(window.location).searchParams.get('days') ?? '60',
    selectedAssets: [],
    selectedTickets: [],
});

const planningModalOpen = ref(false);
const eventSuccessfullyCreated = ref(false);

const eventForm = useForm({
    service_order_id: null,
    start_date: '',
    start_time: '',
    end_date: '',
    end_time: '',
    event_type_id: eventTypes[0]?.id || null,
    executing_user_ids: [],
    eventable_type: '\\App\\Models\\ServiceOrder',
    name: 'Werkbon ingepland',
    status: 'Gepland',
});

const canCreateWorkOrder = ref('no');

const canCreateServiceOrder = computed(() => hasPermission('serviceorder.create'));

watch(() => form.days, (newValue) => {
    form.get('/upcomingactivities', { days: newValue }, { preserveScroll: true });
});

watch(
    [() => form.selectedAssets, () => form.selectedTickets],
    () => {
        canCreateWorkOrder.value = form.selectedAssets.length > 0 ? 'yes' : 'no';
    },
    { deep: true }
);

const createServiceOrder = (redirect) => {
    if (canCreateWorkOrder.value !== 'yes') {
        return;
    }
    form.transform(data => {
        return {
            ...data,
            customer_id: data.selectedTickets.length > 0
                ? data.selectedTickets[0].customer_id
                : data.selectedAssets[0].customer_id,
            tickets: data.selectedTickets.map(ticket => ticket.id),
            assets: data.selectedAssets.map(asset => asset.id),
            redirect
        };
    }).post('/serviceorders', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('selectedAssets', 'selectedTickets');
        }
    });
};

const createServiceOrderAndPlan = () => {
    if (canCreateWorkOrder.value !== 'yes') {
        return;
    }
    planningModalOpen.value = true;
    const transformedData = {
        ...form.data(),
        customer_id: form.selectedTickets.length > 0
            ? form.selectedTickets[0].customer_id
            : form.selectedAssets[0].customer_id,
        tickets: form.selectedTickets.map(ticket => ticket.id),
        assets: form.selectedAssets.map(asset => asset.id),
        json: true
    };

    axios.post('/serviceorders', transformedData)
        .then(response => {
            if (response.data.id) {
                eventForm.service_order_id = response.data.id;
                const now = new Date();
                eventForm.start_date = now.toISOString().split('T')[0];
                eventForm.start_time = now.toTimeString().slice(0, 5);
                now.setHours(now.getHours() + 1);
                eventForm.end_date = now.toISOString().split('T')[0];
                eventForm.end_time = now.toTimeString().slice(0, 5);
                eventForm.executing_user_ids = [usePage().props.auth.user.id];
                form.reset('selectedAssets', 'selectedTickets');
            } else {
                closePlanningModal();
                usePage().props.flash.error = 'Kon werkbon niet aanmaken.';
            }
        })
        .catch(error => {
            console.error(error);
            closePlanningModal();
            usePage().props.flash.error = 'Er is een fout opgetreden bij het aanmaken van de werkbon.';
        });
};

const submitEvent = () => {
    const eventData = {
        ...eventForm.data(),
        eventable_id: eventForm.service_order_id,
        start: `${eventForm.start_date} ${eventForm.start_time}`,
        end: `${eventForm.end_date} ${eventForm.end_time}`,
    };

    axios.post('/api/events', eventData)
        .then(() => {
            usePage().props.flash.success = 'Werkbon succesvol ingepland.';
            eventSuccessfullyCreated.value = true;
        })
        .catch(error => {
            if (error.response && error.response.data && error.response.data.errors) {
                eventForm.setError(error.response.data.errors);
            } else {
                console.error(error);
                usePage().props.flash.error = 'Er is een onbekende fout opgetreden.';
            }
        });
};

const closePlanningModal = () => {
    planningModalOpen.value = false;
    eventSuccessfullyCreated.value = false;
    eventForm.reset();
    eventForm.clearErrors();
};

const getNonPlannedTickets = tickets => {
    return tickets.filter(ticket => ticket.service_order_id === null);
};
const getPlannedTickets = tickets => {
    return tickets.filter(ticket => ticket.service_order_id !== null);
};

const selectAllFor = (customerId) => {
    const upcomingMain = upcomingAssets.find(a => a.customer && a.customer.id === customerId);
    const expiredMain = expiredAssets.find(a => a.customer && a.customer.id === customerId);

    if (!upcomingMain && !expiredMain) {
        form.selectedAssets = form.selectedAssets.filter(a => a.customer_id !== customerId);
        form.selectedTickets = form.selectedTickets.filter(t => t.customer_id !== customerId);
        return;
    }

    const upcomingCustomerAssets = upcomingMain?.customer?.upcoming_assets || [];
    const expiredCustomerAssets = expiredMain?.customer?.upcoming_assets || [];
    const assets = [...upcomingCustomerAssets, ...expiredCustomerAssets];

    const assetIds = assets.map(a => a.id);

    const ticketIds = [];
    assets.forEach(asset => {
        (asset.pending_tickets || []).forEach(t => { if (t.service_order_id === null) ticketIds.push(t.id); });
        (asset.open_tickets || []).forEach(t => { if (t.service_order_id === null) ticketIds.push(t.id); });
    });

    const selectedAssetIds = new Set(form.selectedAssets.filter(a => a.customer_id === customerId).map(a => a.id));
    const selectedTicketIds = new Set(form.selectedTickets.filter(t => t.customer_id === customerId).map(t => t.id));
    const allSelectedForCustomer = selectedAssetIds.size === assetIds.length && selectedTicketIds.size === ticketIds.length;

    if (allSelectedForCustomer) {
        form.selectedAssets = form.selectedAssets.filter(a => a.customer_id !== customerId);
        form.selectedTickets = form.selectedTickets.filter(t => t.customer_id !== customerId);
        return;
    }

    const customerAssets = assets.map(a => ({ id: a.id, customer_id: customerId }));
    const customerTickets = ticketIds.map(id => ({ id, customer_id: customerId }));

    // Add only those assets and tickets that are not already selected
    const newSelectedAssets = [...form.selectedAssets];
    customerAssets.forEach(ca => {
        if (!newSelectedAssets.some(sa => sa.id === ca.id)) {
            newSelectedAssets.push(ca);
        }
    });
    form.selectedAssets = newSelectedAssets;

    const newSelectedTickets = [...form.selectedTickets];
    customerTickets.forEach(ct => {
        if (!newSelectedTickets.some(st => st.id === ct.id)) {
            newSelectedTickets.push(ct);
        }
    });
    form.selectedTickets = newSelectedTickets;
};

const customerState = (customerId) => {
    const upcomingMain = upcomingAssets.find(m => m.customer && m.customer.id === customerId);
    const expiredMain = expiredAssets.find(m => m.customer && m.customer.id === customerId);

    const upcomingAssetsForCustomer = upcomingMain?.customer?.upcoming_assets ?? [];
    const expiredAssetsForCustomer = expiredMain?.customer?.upcoming_assets ?? [];
    const assets = [...upcomingAssetsForCustomer, ...expiredAssetsForCustomer];

    const totalAssets = assets.length;
    let totalTickets = 0;
    assets.forEach(a => {
        (a.pending_tickets ?? []).forEach(t => { if (t.service_order_id === null) totalTickets++; });
        (a.open_tickets ?? []).forEach(t => { if (t.service_order_id === null) totalTickets++; });
    });
    const total = totalAssets + totalTickets;
    if (total === 0) return { all: false, some: false };

    const selA = form.selectedAssets.filter(a => a.customer_id === customerId).length;
    const selT = form.selectedTickets.filter(t => t.customer_id === customerId).length;
    const selected = selA + selT;

    return { all: selected === total, some: selected > 0 && selected < total };
};

// expose scroll function for popup window
if (typeof window !== 'undefined') {
    const scrollFunc = (id) => {
        const el = document.getElementById(`customer-section-${id}`);
        if (!el) return;
        // identify scroll container (page) - prefer window, else nearest scrollable parent
        let container = window;
        let parent = el.parentElement;
        while (parent && parent !== document.body) {
            const style = getComputedStyle(parent);
            const overflowY = style.overflowY;
            if (/(auto|scroll)/.test(overflowY)) { container = parent; break; }
            parent = parent.parentElement;
        }
        const headerOffset = 0; // adjust if fixed header present
        if (container === window) {
            const rect = el.getBoundingClientRect();
            const currentTop = window.scrollY || document.documentElement.scrollTop;
            const targetY = currentTop + rect.top - headerOffset;
            window.scrollTo({ top: targetY, behavior: 'smooth' });
        } else {
            container.scrollTo({ top: el.offsetTop - headerOffset, behavior: 'smooth' });
        }
        window.focus();
    };
    window.scrollToCustomer = scrollFunc;
    window.addEventListener('message', (e) => {
        if (!e.data || e.data.type !== 'scrollToCustomer') return;
        scrollFunc(e.data.id);
    });
}

const openMap = () => {
    window.open(`/upcomingactivities/map?days=${form.days}`, 'customerMap', 'width=1200,height=800');
};

</script>
