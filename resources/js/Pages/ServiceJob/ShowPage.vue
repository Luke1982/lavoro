<template>
    <BoxComponent>
        <div class="flex items-start justify-between mb-4">
            <h1 class="text-lg md:text-2xl font-bold text-left md:text-center flex-1">
                Keuring voor {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
            </h1>
            <div class="flex gap-2 ml-4">
                <button @click="openPdf"
                    class="px-3 py-2 bg-red-600 text-white text-xs font-semibold rounded hover:bg-red-700">
                    PDF Export
                </button>
                <button @click="emailPdf" :disabled="emailing"
                    class="px-3 py-2 bg-green-600 text-white text-xs font-semibold rounded hover:bg-green-700 disabled:bg-gray-500">
                    <span v-if="!emailing">Mail PDF</span>
                    <span v-else>Versturen...</span>
                </button>
            </div>
        </div>
        <div class="grid grid-cols-12 gap-y-2 border-b border-gray-200 pb-4">
            <div class="col-span-4 md:col-span-2 text-xs">
                Naam klant
            </div>
            <div class="col-span-8 md:col-span-4">
                <Link :href="`/customers/${servicejob.asset.customer.id}`" class="underline">
                {{ servicejob.asset.customer.name }}
                </Link>
            </div>
            <div class="col-span-4 md:col-span-2 text-xs">
                Werkbon
            </div>
            <div class="col-span-8 md:col-span-4">
                <Link :href="`/serviceorders/${servicejob.service_order.id}`" class="underline">
                Nummer {{ servicejob.service_order.id }} gemaakt op {{ nlDate(servicejob.service_order.created_at) }}
                </Link>
            </div>
            <div class="col-span-4 md:col-span-2 text-xs">
                Serienummer
            </div>
            <div class="col-span-8 md:col-span-4">
                <Link :href="`/assets/${servicejob.asset.id}`" class="underline">
                {{ servicejob.asset.serial_number }}
                </Link>
            </div>
            <div class="col-span-4 md:col-span-2 text-xs">
                Soort product
            </div>
            <div class="col-span-8 md:col-span-4">
                <Link :href="`/producttypes?search=${servicejob.asset.product.product_type.name}`" class="underline">
                {{ servicejob.asset.product.product_type.name }}
                </Link>
            </div>
        </div>
        <div v-if="missing_checks_count > 0 && servicejob.completed_on === null"
            class="mb-4 p-3 border border-amber-300 bg-amber-50 rounded text-sm text-amber-800 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex-1">
                <div class="font-semibold mb-1">{{ missing_checks_count }} ontbrekende keurpunt(en)</div>
                <ul class="list-disc ml-5 space-y-0.5">
                    <li v-for="mc in missing_checks" :key="mc.id">{{ mc.name }}<span
                            class="text-gray-500 ml-1 text-xs">({{ mc.type }})</span></li>
                </ul>
            </div>
            <div class="flex items-start md:items-center gap-2 md:ml-4">
                <button @click="addMissing" :disabled="addingMissing"
                    class="px-3 py-1.5 rounded bg-amber-600 text-white text-xs font-semibold hover:bg-amber-700 disabled:bg-gray-400 self-start md:self-auto">
                    <span v-if="!addingMissing">Ontbrekende toevoegen</span>
                    <span v-else>Bezig...</span>
                </button>
            </div>
        </div>
        <h2 class="text-xl font-bold my-4 text-center">
            Keurpunten
        </h2>
        <div class="flex flex-col gap-6">
            <div v-for="group in groupedChecks" :key="group.key" class="w-full">
                <h3 v-if="group.name" class="text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
                    {{ group.name }}
                </h3>
                <div class="flex flex-wrap">
                    <ServiceCheckInstanceComponent v-for="check in group.items" :key="check.id"
                        :service-check-instance="check" :check-types-with-options="checkTypesWithOptions"
                        class="w-full md:w-1/2 xle:w-1/3" :readonly="servicejob.completed_on !== null" />
                </div>
            </div>
        </div>
        <div class="border-t-1 border-gray-200">
            <h2 class="text-xl font-bold my-4 text-center">
                Afronding
            </h2>
            <div class="grid grid-cols-12 md:flex mt-4 justify-center" v-auto-animate>
                <ComboBox :label="'Uitkomst van de keuring'" :options="possibleOutcomes" v-model="currentOutcomeId"
                    class="col-span-6 mr-2 md:mr-0 flex flex-col justify-end"
                    :disabled="servicejob.completed_on !== null"></ComboBox>
                <TextInput v-model="form.days_temporary_approval" :label="'Aantal dagen tijdelijk goedgekeurd'"
                    class="col-span-6 ml-2 md:ml-4 flex flex-col justify-between" type="number"
                    v-if="currentOutcomeId === 'tijdelijk_goedkeur'" />
                <div class="col-span-6 ml-0 md:ml-4 mr-2 md:mr-0 flex flex-col justify-between mt-4 md:mt-0">
                    <label class="block text-sm font-medium leading-6 text-gray-900">Afgerond op:</label>
                    <input type="date" v-model="form.completed_on" lang="nl"
                        class="w-full border border-gray-300 rounded-md text-sm p-1.5 mt-2 disabled:bg-gray-100"
                        :disabled="servicejob.completed_on !== null" />
                </div>
                <button @click="updating = true; updateJob()" :disabled="updating" v-auto-animate
                    v-if="servicejob.completed_on === null"
                    class="block md:flex w-full md:w-auto mt-2 md:ml-4 col-span-12 justify-around bg-blue-500 text-white px-4 py-1.5 rounded-md hover:bg-blue-600 disabled:bg-gray-500 transition-colors cursor-pointer self-end">
                    <span> Opslaan </span>
                    <Cog6ToothIcon v-if="updating"
                        class="inline size-5 mt-0 md:mt-1 ml-0 md:ml-1 text-white animate-spin" />
                </button>
                <div v-else class="flex col-span-12 w-full md:w-auto mt-2 md:ml-4 mr-2 md:mr-0 justify-center">
                    <InformationCircleIcon class="inline size-6 ml-2 text-gray-500 self-end mb-2 cursor-pointer"
                        v-tooltip="{
                            html: true,
                            content: `<span class='block w-100'>Deze keuring is afgerond op <strong>${nlDate(servicejob.completed_on)}</strong>, dus je kunt hem niet meer opslaan. Wil je de datum leegmaken en de keuring opnieuw kunnen opslaan? Klik dan op het slot hiernaast.</span>`
                        }" />
                    <LockClosedIcon class="inline size-6 ml-2 text-gray-500 self-end mb-2 cursor-pointer"
                        @click="clearCompletedOn" />
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium leading-6 text-gray-900 mb-2">Opmerkingen:</label>
                <textarea v-model="form.description" rows="3"
                    class="w-full border border-gray-300 rounded-md p-2 disabled:bg-gray-100"
                    placeholder="Eventuele opmerkingen over de keuring..."
                    :disabled="servicejob.completed_on !== null"></textarea>
            </div>
            <div class="mt-4 flex flex-col md:flex-row gap-2">
                <Link :href="`/serviceorders/${servicejob.service_order.id}`"
                    class="flex-1 text-white text-center py-4 bg-blue-500 hover:bg-blue-600 transition-colors rounded-md">
                Terug naar de werkbon
                </Link>
                <button @click="openPdf"
                    class="py-4 px-4 bg-red-600 text-white text-xs font-semibold rounded hover:bg-red-700">
                    PDF Export
                </button>
                <button @click="emailPdf" :disabled="emailing"
                    class="py-4 px-4 bg-green-600 text-white text-xs font-semibold rounded hover:bg-green-700 disabled:bg-gray-500">
                    <span v-if="!emailing">Mail PDF</span>
                    <span v-else>Versturen...</span>
                </button>
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
import { watch, ref, computed } from 'vue';
import { debounce } from 'lodash';
import { Cog6ToothIcon, InformationCircleIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

const { servicejob, possibleOutcomes, missing_checks_count, missing_checks } = defineProps({
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
    },
    missing_checks_count: {
        type: Number,
        required: true
    },
    missing_checks: {
        type: Array,
        required: true
    }
});

const updating = ref(false);
const emailing = ref(false);
const addingMissing = ref(false);

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

const openPdf = () => {
    window.open(`/servicejobs/${servicejob.id}/export/pdf`, '_blank');
};

// Separate form for emailing PDF
const mailForm = useForm({});
const emailPdf = () => {
    emailing.value = true;
    mailForm.post(`/servicejobs/${servicejob.id}/email-pdf`, {
        preserveScroll: true,
        onFinish: () => { emailing.value = false; }
    });
};

const addMissing = () => {
    if (addingMissing.value) {
        return;
    };
    addingMissing.value = true;
    mailForm.post(`/servicejobs/${servicejob.id}/add-missing-instances`, {
        preserveScroll: true,
        onFinish: () => { addingMissing.value = false; },
    });
};

// Group the service checks by Product Type groups; checks in groups not attached to
// the product type are placed under an "Overige keurpunten" section at the end.
const groupedChecks = computed(() => {
    const instances = (servicejob.check_instances || []).slice();
    // Always order checks within a group by their own order
    instances.sort((a, b) => (a.service_check?.order ?? 0) - (b.service_check?.order ?? 0));

    const ptGroups = (servicejob.asset?.product?.product_type?.service_check_groups || [])
        .map(g => ({ id: g.id, name: g.name, order: g.order ?? Number.MAX_SAFE_INTEGER }));
    const allowed = new Map(ptGroups.map(g => [g.id, { key: g.id, name: g.name, order: g.order, items: [] }]));

    const other = { key: 'other', name: 'Overige keurpunten', order: Number.MAX_SAFE_INTEGER, items: [] };

    for (const ci of instances) {
        const gid = ci.service_check?.group?.id ?? null;
        if (gid && allowed.has(gid)) {
            allowed.get(gid).items.push(ci);
        } else {
            other.items.push(ci);
        }
    }

    const result = Array.from(allowed.values())
        .filter(g => g.items.length > 0)
        .sort((a, b) => a.order - b.order);
    if (other.items.length > 0) {
        result.push(other);
    }
    return result;
});

</script>