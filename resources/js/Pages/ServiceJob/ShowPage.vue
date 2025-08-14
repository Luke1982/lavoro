<template>
    <BoxComponent>
        <h1 class="text-2xl font-bold mb-4 text-center">
            Keuring voor {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
        </h1>
        <div class="grid grid-cols-12 gap-y-2 border-b border-gray-200 pb-4">
            <div class="col-span-2 text-xs">
                Naam klant
            </div>
            <div class="col-span-4">
                <Link :href="`/customers/${servicejob.asset.customer.id}`" class="underline">
                {{ servicejob.asset.customer.name }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Werkbon
            </div>
            <div class="col-span-4">
                <Link :href="`/serviceorders/${servicejob.service_order.id}`" class="underline">
                Nummer {{ servicejob.service_order.id }} gemaakt op {{ nlDate(servicejob.service_order.created_at) }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Serienummer
            </div>
            <div class="col-span-4">
                <Link :href="`/assets/${servicejob.asset.id}`" class="underline">
                {{ servicejob.asset.serial_number }}
                </Link>
            </div>
            <div class="col-span-2 text-xs">
                Soort product
            </div>
            <div class="col-span-4">
                <Link :href="`/producttypes?search=${servicejob.asset.product.product_type.name}`" class="underline">
                {{ servicejob.asset.product.product_type.name }}
                </Link>
            </div>
        </div>
        <h2 class="text-xl font-bold my-4 text-center">
            Keurpunten
        </h2>
        <div class="flex flex-wrap">
            <ServiceCheckInstanceComponent v-for="check in servicejob.check_instances" :key="check.id"
                :service-check-instance="check" :check-types-with-options="checkTypesWithOptions" class="w-1/3"
                :readonly="servicejob.completed_on !== null" />
        </div>
        <div class="border-t-1 border-gray-200">
            <h2 class="text-xl font-bold my-4 text-center">
                Afronding
            </h2>
            <div class="flex mt-4 justify-center" v-auto-animate>
                <ComboBox :label="'Uitkomst van de keuring'" :options="possibleOutcomes" v-model="currentOutcomeId"
                    class=""></ComboBox>
                <TextInput v-model="form.days_temporary_approval" :label="'Aantal dagen tijdelijk goedgekeurd'"
                    class="ml-4" type="number" v-if="currentOutcomeId === 'tijdelijk_goedkeur'" />
                <div class="ml-4">
                    <label class="block text-sm font-medium leading-6 text-gray-900">Afgerond op:</label>
                    <input type="date" v-model="form.completed_on" lang="nl"
                        class="border border-gray-300 rounded-md text-sm p-1.5 mt-2" />
                </div>
                <button @click="updating = true; updateJob()" :disabled="updating" v-auto-animate
                    v-if="servicejob.completed_on === null"
                    class="flex ml-4 bg-blue-500 text-white px-4 py-1.5 rounded-md hover:bg-blue-600 disabled:bg-gray-500 transition-colors cursor-pointer self-end">
                    <span> Opslaan </span>
                    <Cog6ToothIcon v-if="updating" class="inline size-5 mt-1 ml-1 text-white animate-spin" />
                </button>
                <div v-else class="flex">
                    <InformationCircleIcon class="inline size-6 ml-2 text-gray-500 self-end mb-2 cursor-pointer"
                        v-tooltip="{
                            html: true,
                            content: `<span class='block w-100'>Deze keuring is afgerond op <strong>${nlDate(servicejob.completed_on)}</strong>, dus je kunt hem niet meer opslaan. Wil je de datum leegmaken en de keuring opnieuw kunnen opslaan? Klik dan op het slot hiernaast.</span>`
                        }" />
                    <LockOpenIcon class="inline size-6 ml-2 text-gray-500 self-end mb-2 cursor-pointer"
                        @click="clearCompletedOn" />
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium leading-6 text-gray-900 mb-2">Opmerkingen:</label>
                <textarea v-model="form.description" rows="3" class="w-full border border-gray-300 rounded-md p-2"
                    placeholder="Eventuele opmerkingen over de keuring..."></textarea>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ServiceCheckInstanceComponent from '@/Components/ServiceCheckInstanceComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { nlDate } from '@/Utilities/Utilities';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { watch, ref } from 'vue';
import { debounce } from 'lodash';
import { Cog6ToothIcon, InformationCircleIcon, LockOpenIcon } from '@heroicons/vue/24/outline';

const { servicejob, possibleOutcomes } = defineProps({
    servicejob: {
        type: Object,
        required: true
    },
    checkTypesWithOptions: {
        type: Array,
        required: true
    },
    possibleOutcomes: {
        type: Object,
        required: true
    }
});

const updating = ref(false);

const form = useForm({
    outcome: '',
    days_temporary_approval: servicejob.days_temporary_approval || 0,
    completed_on: servicejob.completed_on || new Date().toISOString().slice(0, 10),
    description: servicejob.description || ''
});

const currentOutcomeId = ref(possibleOutcomes.find(outcome => outcome.name === servicejob.outcome).id);
watch(currentOutcomeId, () => {
    form.outcome = possibleOutcomes.find(outcome => outcome.id === currentOutcomeId.value).name;
}, { immediate: true });

const updateJob = debounce(() => {
    updating.value = true;
    form.patch(`/servicejobs/${servicejob.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            updating.value = false;
        },
        onError: () => {
            updating.value = false;
            usePage().props.flash.error = usePage().props.errors;
        },
    });
}, 500);

const clearCompletedOn = () => {
    form.post(`/servicejobs/${servicejob.id}/clearcompletedon`, {
        preserveScroll: true,
        onSuccess: () => {
            form.completed_on = null;
        },
    });
};

</script>