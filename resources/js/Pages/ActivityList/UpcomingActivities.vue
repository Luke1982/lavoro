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
        <div v-for="mainAsset in upcomingAssets" :key="`mainAsset${mainAsset.id}`"
            :id="`customer-section-${mainAsset.customer.id}`" class="my-8">
            <div
                class="sticky top-16 lg:top-0 z-2 bg-white dark:bg-slate-800 dark:text-slate-100 border border-transparent dark:border-slate-700 rounded-md px-4 py-2">
                <CustomerHeaderComponent :customer="mainAsset.customer" layout="horizontal"
                    class="bg-white dark:bg-transparent py-4 lg:py-2" />
            </div>
            <div
                class="grid grid-cols-12 text-xs lg:text-sm font-medium bg-gray-200 dark:bg-slate-800 text-gray-700 dark:text-slate-300 p-2 rounded-tl-md rounded-tr-md mt-3 border border-gray-300 dark:border-slate-700 border-b-0 tracking-wide">
                <div class="col-span-1">
                    <input type="checkbox" :checked="customerState(mainAsset.customer.id).all"
                        v-indeterminate="customerState(mainAsset.customer.id).some && !customerState(mainAsset.customer.id).all"
                        @change="selectAllFor(mainAsset.customer.id)"
                        class="cursor-pointer size-4 accent-indigo-600 dark:accent-indigo-500" />
                </div>
                <div class="hidden xl:block col-span-3">Merk en model</div>
                <div class="hidden xl:block col-span-1">Serienummer</div>
                <div class="hidden xl:block col-span-1">Verloopdatum</div>
                <div class="hidden xl:block col-span-2">Soort product</div>
                <div class="hidden xl:block col-span-4">Storingen</div>
                <div class="col-span-11 xl:hidden text-gray-600 dark:text-slate-400">Activa en storingen, klik links om
                    alles van deze klant te selecteren</div>
            </div>
            <div v-for="asset in mainAsset.customer.upcoming_assets" :key="asset.id"
                class="grid grid-cols-12 px-2 py-2 lg:py-1 last:rounded-bl-md last:rounded-br-md border-l border-gray-200 dark:border-slate-700/60 border-r last:border-b dark:last:border-slate-700/60 bg-white even:bg-gray-50 dark:bg-slate-900 even:dark:bg-slate-800/70 hover:dark:bg-slate-700/70 transition-colors text-gray-800 dark:text-slate-200">
                <div class="col-span-1">
                    <input type="checkbox" :id="`assetcheckbox-${asset.id}`" v-model="form.selectedAssets"
                        :value="{ id: asset.id, customer_id: mainAsset.customer.id }"
                        class="cursor-pointer size-4 accent-indigo-600 dark:accent-indigo-500">
                </div>
                <div class="col-span-11 xl:col-span-3 pr-5">
                    <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer dark:text-slate-100">
                        {{ asset.product.brand.name }} {{ asset.product.model }}
                    </label>
                    <div v-if="asset.pending_service_jobs.length > 0">
                        <span class="text-xs text-gray-600 dark:text-slate-400">Er zijn nog openstaande keuringen voor
                            deze machine:</span>
                        <span v-for="job in asset.pending_service_jobs" :key="job.id">
                            <BadgeComponent :text="`Keuring ${job.id}`" color="orange" :url="`/servicejobs/${job.id}`"
                                :tooltip="`Ga direct naar keuring ${job.id}`" />
                            <span class="text-xs dark:text-slate-300">op</span>
                            <BadgeComponent
                                :text="`Werkbon ${job.service_order.id} ${job.service_order.events.length > 0 ? ' gepland op ' + nlDate(job.service_order.events[0]?.start) : ''}`"
                                color="blue" :url="`/serviceorders/${job.service_order.id}`"
                                :tooltip="`Ga direct naar werkbon ${job.service_order.id}`" />
                        </span>
                    </div>
                </div>
                <div class="col-span-1 xl:hidden"></div>
                <div class="col-span-3 xl:col-span-1 flex flex-col mt-5 xl:mt-0">
                    <span class="text-xs font-bold xl:hidden">Serienummer</span>
                    <Link :href="`/assets/${asset.id}`"
                        class="cursor-pointer underline text-indigo-700 dark:text-indigo-400 hover:dark:text-indigo-300">
                    {{ asset.serial_number }}</Link>
                </div>
                <div class="col-span-3 xl:col-span-1 flex flex-col mt-5 xl:mt-0">
                    <span class="text-xs font-bold xl:hidden">Verloopdatum</span>
                    <label :for="`assetcheckbox-${asset.id}`"
                        class="cursor-pointer text-gray-700 dark:text-slate-300">{{ nlDate(asset.next_service_date)
                        }}</label>
                </div>
                <div class="col-span-5 xl:col-span-2 flex flex-col mt-5 xl:mt-0">
                    <span class="text-xs font-bold xl:hidden">Soort product</span>
                    <span class="text-gray-700 dark:text-slate-300">{{ asset.product.product_type.name }}</span>
                </div>
                <div class="col-span-1 xl:hidden"></div>
                <div class="col-span-11 xl:col-span-4 flex flex-col mt-5 xl:mt-0">
                    <div v-if="getNonPlannedTickets(asset.pending_tickets).length > 0">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Lopende
                            storingen</span>
                        <div v-for="ticket in getNonPlannedTickets(asset.pending_tickets)" :key="ticket.id">
                            <TicketSelectCard :ticket="ticket" :customer-id="mainAsset.customer.id"
                                v-model="form.selectedTickets" />
                        </div>
                    </div>
                    <div v-if="getPlannedTickets(asset.pending_tickets).length > 0">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Geplande
                            lopende storingen</span>
                        <div v-for="ticket in getPlannedTickets(asset.pending_tickets)" :key="ticket.id">
                            Storing {{ ticket.id }} is gepland op
                            <Link class="underline" :href="`/serviceorders/${ticket.service_order_id}`">werkbon {{
                                ticket.service_order_id }}</Link>, klik
                            <Link :href="`/serviceorders/${ticket.service_order_id}/tickets/${ticket.id}/detach`"
                                class="underline">hier</Link> om deze te verwijderen van die werkbon, zodat je hem hier
                            kunt koppelen aan een nieuwe.
                        </div>
                    </div>
                    <div v-if="getNonPlannedTickets(asset.open_tickets).length > 0">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Openstaande
                            storingen</span>
                        <div v-for="ticket in getNonPlannedTickets(asset.open_tickets)" :key="ticket.id">
                            <TicketSelectCard :ticket="ticket" :customer-id="mainAsset.customer.id"
                                v-model="form.selectedTickets" />
                        </div>
                    </div>
                    <div v-if="getPlannedTickets(asset.open_tickets).length > 0">
                        <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Geplande open
                            storingen</span>
                        <div v-for="ticket in getPlannedTickets(asset.open_tickets)" :key="ticket.id">
                            Storing {{ ticket.id }} is gepland op
                            <Link class="underline" :href="`/serviceorders/${ticket.service_order_id}`">werkbon {{
                                ticket.service_order_id }}</Link>, klik
                            <Link :href="`/serviceorders/${ticket.service_order_id}/tickets/${ticket.id}/detach`"
                                class="underline">hier</Link> om deze te verwijderen van die werkbon, zodat je hem hier
                            kunt koppelen aan een nieuwe.
                        </div>
                    </div>
                </div>
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
                            <p v-if="eventForm.errors.executing_user_ids" class="text-sm text-red-600 dark:text-red-400 mt-1">
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
import CustomerHeaderComponent from '@/Components/CustomerHeaderComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TicketSelectCard from '@/Components/TicketSelectCard.vue';
import { nlDate } from '@/Utilities/Utilities';
import { CalendarDateRangeIcon, ClipboardDocumentCheckIcon, CalendarIcon } from '@heroicons/vue/24/outline';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { watch, ref, computed } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import axios from 'axios';

const { upcomingAssets, eventTypes, allUsers } = defineProps({
    upcomingAssets: {
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
    ([newAssets, newTickets]) => {
        if (newAssets.length > 0 || newTickets.length > 0) {
            const uniqueCustomers = new Set();
            newAssets.forEach(asset => uniqueCustomers.add(asset.customer_id));
            newTickets.forEach(ticket => uniqueCustomers.add(ticket.customer_id));
            if (uniqueCustomers.size > 1) {
                canCreateWorkOrder.value = 'diffCustomers';
                return;
            }
            canCreateWorkOrder.value = 'yes';
        } else {
            canCreateWorkOrder.value = 'no';
        }
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
    const main = upcomingAssets.find(a => a.customer && a.customer.id === customerId);
    if (!main) {
        form.selectedAssets = [];
        form.selectedTickets = [];
        return;
    }

    const assets = (main.customer.upcoming_assets || []);
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

    form.selectedAssets = assetIds.map(id => ({ id, customer_id: customerId }));
    form.selectedTickets = ticketIds.map(id => ({ id, customer_id: customerId }));
};

const vIndeterminate = {
    mounted(el, { value }) { el.indeterminate = !!value },
    updated(el, { value }) { el.indeterminate = !!value },
};

const customerState = (customerId) => {
    const main = upcomingAssets.find(m => m.customer && m.customer.id === customerId);
    const assets = main?.customer?.upcoming_assets ?? [];

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
