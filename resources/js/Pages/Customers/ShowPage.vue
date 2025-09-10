<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                    <div class="flex items-start">
                        <BuildingOffice2Icon
                            class="size-12 flex-none rounded-lg bg-white object-cover ring-1 ring-gray-900/10 p-2 mr-4" />
                        <div class="flex flex-col">
                            <h1 class="text-xl font-semibold">{{ customer.name }}</h1>
                            <div class="flex flex-wrap items-center gap-x-2 text-sm text-gray-500 mt-1">
                                <a v-if="customer.website" :href="customer.website" target="_blank" class="underline">{{
                                    customer.website }}</a>
                                <span v-if="customer.website && customer.email" class="text-gray-300">|</span>
                                <a v-if="customer.email" :href="`mailto:${customer.email}`" class="underline">{{
                                    customer.email }}</a>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 md:mt-0 md:min-w-60 w-full md:w-auto">
                        <ComboBox :options="allCustomers" v-model="form.billing_customer_id" label="Factuurklant"
                            placeholder="Kies naar welke klant de factuur moet" @update:modelValue="updateCustomer" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-6">
                    <div>
                        <h3 class="text-xs font-bold mb-2 uppercase tracking-wide">Bezoekadres</h3>
                        <p class="text-sm text-gray-800 leading-snug">{{ customer.address }}<br>{{ customer.postal_code
                            }}<span v-if="customer.city">,</span> {{ customer.city }}</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold mb-2 uppercase tracking-wide">Postadres</h3>
                        <p class="text-sm text-gray-800 leading-snug">{{ customer.postal_address }}<br>{{
                            customer.postal_postal_code }}<span v-if="customer.postal_city">,</span> {{
                                customer.postal_city }}</p>
                    </div>
                </div>
            </BoxComponent>
            <div class="mt-5 flex items-center justify-between text-sm px-1">
                <button type="button" class="flex items-center gap-1 text-gray-600 hover:text-gray-800">
                    <span class="font-medium">Filter</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" viewBox="0 0 24 24">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>
                <div class="flex items-center gap-2">
                    <button type="button"
                        class="px-3 py-1.5 rounded-md border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 flex items-center gap-1">Filters
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    <button type="button"
                        class="px-3 py-1.5 rounded-md border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 flex items-center gap-1">Sorteren
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    <button type="button"
                        class="px-2.5 py-1.5 rounded-md border border-gray-200 bg-white hover:bg-gray-50 text-gray-700">…</button>
                </div>
            </div>
            <div class="mt-4">
                <div class="bg-white rounded-md border border-gray-200 flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded bg-pink-50 border border-pink-200"><span
                                class="w-2 h-2 rounded-full bg-pink-600"></span></span>
                        <button type="button" class="text-sm font-medium"
                            @click="showUpcoming = !showUpcoming">Apparaten die binnen 30 dagen verlopen</button>
                    </div>
                    <button type="button" class="text-gray-400 hover:text-gray-600"
                        @click="showUpcoming = !showUpcoming">…</button>
                </div>
                <transition name="fade-height" mode="out-in">
                    <div v-if="showUpcoming" key="upcoming" class="pt-6">
                        <AssetListGroupComponent :assetGroups="upcomingAssetsByType" />
                    </div>
                </transition>
            </div>
            <div class="mt-8" v-if="hasNonUpcoming">
                <div class="bg-white rounded-md border border-gray-200 flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded bg-yellow-50 border border-yellow-200"><span
                                class="w-2 h-2 rounded-full bg-yellow-500"></span></span>
                        <button type="button" class="text-sm font-medium"
                            @click="showNonUpcoming = !showNonUpcoming">Apparaten die na 30 dagen verlopen</button>
                    </div>
                    <button type="button" class="text-gray-400 hover:text-gray-600"
                        @click="showNonUpcoming = !showNonUpcoming">…</button>
                </div>
                <transition name="fade-height" mode="out-in">
                    <div v-if="showNonUpcoming" key="nonupcoming" class="pt-6">
                        <AssetListGroupComponent :assetGroups="nonUpcomingAssetsByType" />
                    </div>
                </transition>
            </div>
        </template>

        <template #sidebar>
            <div class="space-y-4 md:ml-5 md:mt-0 ml-0 mt-5">

                <BoxComponent>
                    <div class="flex mb-4 border-b-1 border-gray-200 pb-2 justify-between">
                        <div class="flex">
                            <ClipboardDocumentListIcon class="size-6 flex-none text-gray-500 mr-2" />
                            <h2 class="font-regular text-xl">Werkbonnen</h2>
                        </div>
                        <PlusCircleIcon class="size-6 flex-none text-green-500 cursor-pointer hover:text-green-700"
                            @click="newServiceOrderForm.post(`/serviceorders`, { preserveScroll: true })"
                            v-tooltip="`Maak een nieuwe werkbon aan voor ${customer.name}`" />
                    </div>
                    <ServiceOrderRow v-for="serviceorder in customer.service_orders" v-bind:key="serviceorder.id"
                        :serviceorder="serviceorder" />
                </BoxComponent>
                <BoxComponent class="bg-white">
                    <div class="flex mb-4 border-b-1 border-gray-200 pb-2 justify-between items-center">
                        <div class="flex items-center">
                            <svg class="size-6 text-gray-500 mr-2" fill="none" stroke="currentColor" stroke-width="1.5"
                                viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <h2 class="font-regular text-xl">Afspraken</h2>
                        </div>
                    </div>
                    <EventTimelineComponent :events="eventList" />
                </BoxComponent>
            </div>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import '@/Layouts/TwoThirdsOneThird.vue';
import '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BuildingOffice2Icon, ClipboardDocumentListIcon, PlusCircleIcon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListGroupComponent from '@/Components/AssetListGroupComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue';
import EventTimelineComponent from '@/Components/Timeline/EventTimelineComponent.vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    upcomingAssetsByType: {
        type: Object,
        required: true,
    },
    nonUpcomingAssetsByType: {
        type: Object,
        required: true,
    },
    allCustomers: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    ...props.customer,
    billing_customer_id: props.customer.billing_customer_id || null,
});

const newServiceOrderForm = useForm({
    customer_id: props.customer.id,
});

const updateCustomer = () => {
    form.patch(`/customers/${props.customer.id}`)
};

const showUpcoming = ref(true);
const showNonUpcoming = ref(false);
const hasNonUpcoming = computed(() => Object.keys(props.nonUpcomingAssetsByType || {}).length > 0);

// Collect all events from service orders for this customer for timeline
const eventList = computed(() => {
    const orders = props.customer.service_orders || [];
    return orders.flatMap(o => (o.events || []).map(e => ({
        ...e,
        service_order_id: o.id,
    })));
});
</script>

<style scoped>
.fade-height-enter-active,
.fade-height-leave-active {
    transition: opacity .25s ease, max-height .25s ease, transform .25s ease;
}

.fade-height-enter-from,
.fade-height-leave-to {
    opacity: 0;
    max-height: 0;
    transform: translateY(-6px);
}

.fade-height-enter-to,
.fade-height-leave-from {
    opacity: 1;
    max-height: 2000px;
    transform: translateY(0);
}
</style>