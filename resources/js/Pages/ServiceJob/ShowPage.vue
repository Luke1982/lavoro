<template>
    <!-- Breadcrumb + page-level actions -->
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center min-w-0">
            <Link :href="`/serviceorders/${servicejob.service_order.id}`"
                class="text-slate-400 text-sm font-medium shrink-0 hover:text-slate-600 dark:hover:text-slate-300">
                Werkbon #{{ servicejob.service_order.id }}
            </Link>
            <ChevronRightIcon class="size-4 text-gray-400 mx-2 shrink-0" />
            <span class="text-slate-800 dark:text-slate-200 font-bold text-sm truncate">
                Keuring voor {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
            </span>
        </div>
        <div class="hidden md:flex items-center gap-2 ml-4 shrink-0">
            <button v-if="hasPermission('servicejob.export_pdf')" @click="openPdf"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 text-xs font-semibold rounded-md hover:bg-red-50 dark:hover:bg-red-900/20">
                <DocumentArrowDownIcon class="size-4" /> PDF Export
            </button>
            <button v-if="hasPermission('servicejob.mail_pdf')" @click="emailPdf" :disabled="emailing"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-green-300 dark:border-green-700 text-green-600 dark:text-green-400 text-xs font-semibold rounded-md hover:bg-green-50 dark:hover:bg-green-900/20 disabled:opacity-50">
                <EnvelopeIcon class="size-4" />
                <span v-if="!emailing">Mail PDF</span>
                <span v-else>Versturen...</span>
            </button>
        </div>
    </div>

    <!-- ── HEADER CARD: title + meta info ── -->
    <BoxComponent class="mb-4">
        <div class="flex items-start gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-lavoro-lightblue flex items-center justify-center flex-none mt-0.5">
                <ShieldCheckIcon class="w-5 h-5 text-lavoro-blue" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-xl font-bold dark:text-slate-100">
                        Keuring voor {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
                    </h1>
                    <BadgeComponent color="blue" :hasDot="false">
                        {{ servicejob.completed_on === null ? 'Open' : 'Gesloten' }}
                    </BadgeComponent>
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:divide-x divide-gray-100 dark:divide-slate-700/60 border-t border-gray-100 dark:border-slate-700/60 pt-4 gap-3 sm:gap-0">
            <div class="sm:pr-6">
                <div class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Klant</div>
                <Link :href="`/customers/${servicejob.asset.customer.id}`"
                    class="text-sm font-medium text-lavoro-blue hover:opacity-80 underline">
                    {{ servicejob.asset.customer.name }}
                </Link>
            </div>
            <div class="sm:px-6">
                <div class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Serienummer</div>
                <Link :href="`/assets/${servicejob.asset.id}`"
                    class="text-sm font-medium text-lavoro-blue hover:opacity-80 underline">
                    {{ servicejob.asset.serial_number }}
                </Link>
            </div>
            <div class="sm:px-6">
                <div class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Werkbon</div>
                <Link :href="`/serviceorders/${servicejob.service_order.id}`"
                    class="text-sm font-medium text-lavoro-blue hover:opacity-80 underline">
                    Nummer {{ servicejob.service_order.id }} gemaakt op {{ nlDate(servicejob.service_order.created_at) }}
                </Link>
            </div>
            <div class="sm:pl-6">
                <div class="text-xs text-gray-500 dark:text-slate-400 mb-0.5">Soort product</div>
                <Link :href="`/producttypes?search=${servicejob.asset.product.product_type.name}`"
                    class="text-sm font-medium text-lavoro-blue hover:opacity-80 underline">
                    {{ servicejob.asset.product.product_type.name }}
                </Link>
            </div>
        </div>
    </BoxComponent>

    <!-- Alerts -->
    <div v-if="missing_checks_count > 0 && servicejob.completed_on === null"
        class="mb-4 p-3 border border-amber-300 dark:border-amber-700/50 bg-amber-50 dark:bg-amber-900/30 rounded text-sm text-amber-800 dark:text-amber-300 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
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

    <div v-if="parent_job"
        class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-md">
        <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1">Onderdeel van gecombineerde keuring:</p>
        <Link :href="`/servicejobs/${parent_job.id}`" class="text-lavoro-blue underline text-sm">{{ parent_job.asset_label }}</Link>
        <span class="text-xs text-gray-400 ml-2">{{ parent_job.outcome }}</span>
    </div>

    <div v-if="child_jobs.length"
        class="mb-4 p-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-md">
        <p class="text-sm font-semibold text-indigo-800 dark:text-indigo-200 mb-3">
            Gecombineerde keuring — sla alle assets tegelijk op met dezelfde uitkomst
        </p>
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-40">
                <ComboBox label="Uitkomst" :options="possibleOutcomes" v-model="bulkOutcomeId" />
            </div>
            <div v-if="bulkOutcomeId === 'tijdelijk_goedkeur'" class="w-32">
                <TextInput v-model="bulkForm.days_temporary_approval" label="Dagen tijdelijk" type="number" />
            </div>
            <div class="flex flex-col">
                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-slate-200">Afgerond op</label>
                <input type="date" v-model="bulkForm.completed_on" lang="nl"
                    class="border border-gray-300 dark:border-slate-600 rounded-md text-sm p-1.5 mt-1 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100" />
            </div>
            <button @click="saveBulk" :disabled="bulkSaving"
                class="px-4 py-2 bg-lavoro-blue text-white text-sm rounded hover:opacity-90 disabled:bg-gray-400 self-end">
                <span v-if="!bulkSaving">Sla alle keuringen op</span>
                <span v-else>Bezig...</span>
            </button>
        </div>
    </div>

    <!-- ── PARENT ASSET SECTION ── -->
    <div :class="child_jobs.length ? 'border-l-4 border-blue-500 pl-4' : ''">
        <h2 v-if="child_jobs.length" class="text-base font-bold text-blue-700 dark:text-blue-300 mb-3">
            {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
            <span class="font-normal text-gray-500 text-sm ml-1">({{ servicejob.asset.serial_number }})</span>
        </h2>

        <!-- Keurpunten heading -->
        <div class="flex items-center gap-2 mb-3">
            <h2 class="text-lg font-bold dark:text-slate-100">Keurpunten</h2>
            <span class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full bg-lavoro-blue text-white text-xs font-bold">
                {{ servicejob.check_instances?.length ?? 0 }}
            </span>
        </div>

        <!-- Check cards -->
        <div class="flex flex-col gap-4">
            <div v-for="group in groupedChecks" :key="group.key" class="w-full">
                <h3 v-if="group.name"
                    class="text-base font-semibold text-gray-900 dark:text-slate-200 mb-2">
                    {{ group.name }}
                </h3>
                <div class="flex flex-wrap -mx-2">
                    <ServiceCheckInstanceComponent v-for="check in group.items" :key="check.id"
                        :service-check-instance="check" :check-types-with-options="checkTypesWithOptions"
                        class="w-full md:w-1/2 xle:w-1/3 px-2 mb-4" :readonly="servicejob.completed_on !== null" />
                </div>
            </div>
        </div>

        <!-- ── AFRONDING CARD ── -->
        <BoxComponent class="mt-2">
            <h2 class="text-lg font-bold mb-4 dark:text-slate-100">Afronding</h2>
            <div class="flex flex-col sm:flex-row gap-4 items-end flex-wrap" v-auto-animate>
                <div class="flex-1 min-w-44">
                    <ComboBox :label="'Uitkomst van de keuring'" :options="possibleOutcomes" v-model="currentOutcomeId"
                        :disabled="servicejob.completed_on !== null" />
                </div>
                <TextInput v-model="form.days_temporary_approval" :label="'Aantal dagen tijdelijk goedgekeurd'"
                    class="w-40" type="number"
                    v-if="currentOutcomeId === 'tijdelijk_goedkeur'" />
                <div class="flex flex-col">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-slate-200">Afgerond op:</label>
                    <input type="date" v-model="form.completed_on" lang="nl"
                        class="border border-gray-300 dark:border-slate-600 rounded-md text-sm p-1.5 mt-1 disabled:bg-gray-100 dark:disabled:bg-slate-800/40 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100"
                        :disabled="servicejob.completed_on !== null" />
                </div>
                <button @click="updating = true; updateJob()" :disabled="updating"
                    v-if="servicejob.completed_on === null"
                    class="inline-flex items-center gap-2 bg-lavoro-blue text-white px-4 py-2 rounded-md hover:opacity-90 disabled:bg-gray-500 transition-opacity cursor-pointer self-end">
                    <span>Opslaan</span>
                    <Cog6ToothIcon v-if="updating" class="size-4 animate-spin" />
                </button>
                <div v-else class="flex items-center gap-1.5 self-end pb-0.5">
                    <InformationCircleIcon class="size-5 text-gray-400 dark:text-slate-500 cursor-pointer hover:text-gray-600 dark:hover:text-slate-300"
                        v-tooltip="{ html: true, content: `<span class='block w-100'>Deze keuring is afgerond op <strong>${nlDate(servicejob.completed_on)}</strong>. Klik op het slot om opnieuw te kunnen opslaan.</span>` }" />
                    <LockClosedIcon class="size-5 text-gray-400 dark:text-slate-500 cursor-pointer hover:text-gray-600 dark:hover:text-slate-300"
                        @click="clearCompletedOn" />
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-slate-200 mb-1">Opmerkingen (optioneel)</label>
                <textarea v-model="form.description" rows="3"
                    class="w-full border border-gray-300 dark:border-slate-600 rounded-md p-2 disabled:bg-gray-100 dark:disabled:bg-slate-800/40 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-slate-500"
                    placeholder="Eventuele opmerkingen over de keuring..."
                    :disabled="servicejob.completed_on !== null"></textarea>
            </div>
        </BoxComponent>
    </div>

    <!-- ── CHILD ASSET SECTIONS ── -->
    <div v-for="cj in child_jobs" :key="cj.id" class="mt-8 border-l-4 border-emerald-500 pl-4">
        <h2 class="text-base font-bold text-emerald-700 dark:text-emerald-300 mb-3">
            {{ cj.asset_label }}
            <Link :href="`/assets/${cj.asset_id}`" class="text-xs font-normal text-lavoro-blue underline ml-2">machine</Link>
            <Link :href="`/servicejobs/${cj.id}`" class="text-xs font-normal text-lavoro-blue underline ml-2">volledige keuring</Link>
        </h2>
        <div class="flex flex-col gap-4">
            <div v-for="group in groupsForChildJob(cj)" :key="group.key" class="w-full">
                <h3 v-if="group.name"
                    class="text-base font-semibold text-gray-900 dark:text-slate-200 mb-2">{{ group.name }}</h3>
                <div class="flex flex-wrap -mx-2">
                    <ServiceCheckInstanceComponent v-for="check in group.items" :key="check.id"
                        :service-check-instance="check" :check-types-with-options="checkTypesWithOptions"
                        class="w-full md:w-1/2 xle:w-1/3 px-2 mb-4" :readonly="cj.completed_on !== null" />
                </div>
            </div>
        </div>
        <BoxComponent class="mt-2">
            <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-40">
                    <ComboBox label="Uitkomst" :options="possibleOutcomes" v-model="childForms[cj.id].outcomeId"
                        :disabled="cj.completed_on !== null" />
                </div>
                <div v-if="childForms[cj.id].outcomeId === 'tijdelijk_goedkeur'" class="w-32">
                    <TextInput v-model="childForms[cj.id].days_temporary_approval" label="Dagen tijdelijk" type="number"
                        :disabled="cj.completed_on !== null" />
                </div>
                <div class="flex flex-col">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-slate-200">Afgerond op</label>
                    <input type="date" v-model="childForms[cj.id].completed_on" lang="nl"
                        class="border border-gray-300 dark:border-slate-600 rounded-md text-sm p-1.5 mt-1 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100 disabled:bg-gray-100 dark:disabled:bg-slate-900"
                        :disabled="cj.completed_on !== null" />
                </div>
                <button v-if="cj.completed_on === null" @click="saveChildJob(cj.id)" :disabled="childSaving[cj.id]"
                    class="px-4 py-2 bg-emerald-600 text-white text-sm rounded hover:bg-emerald-700 disabled:bg-gray-400 self-end">
                    <span v-if="!childSaving[cj.id]">Opslaan</span>
                    <span v-else>Bezig...</span>
                </button>
                <span v-else class="text-xs text-gray-400 self-end pb-1">Afgerond op {{ nlDate(cj.completed_on) }}</span>
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300 mb-1">Opmerkingen</label>
                <textarea v-model="childForms[cj.id].description" rows="2"
                    class="w-full border border-gray-300 dark:border-slate-600 rounded p-1.5 text-sm disabled:bg-gray-100 dark:disabled:bg-slate-900 bg-white dark:bg-slate-800 text-gray-900 dark:text-slate-100"
                    :disabled="cj.completed_on !== null"></textarea>
            </div>
        </BoxComponent>
    </div>

    <!-- Bottom navigation -->
    <div class="mt-6 flex items-center justify-between">
        <Link :href="`/serviceorders/${servicejob.service_order.id}`"
            class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200 transition-colors">
            <ArrowLeftIcon class="size-4" /> Terug naar de werkbon
        </Link>
        <div class="flex items-center gap-2">
            <button v-if="hasPermission('servicejob.export_pdf')" @click="openPdf"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 text-xs font-semibold rounded-md hover:bg-red-50 dark:hover:bg-red-900/20">
                <DocumentArrowDownIcon class="size-4" /> PDF Export
            </button>
            <button v-if="hasPermission('servicejob.mail_pdf')" @click="emailPdf" :disabled="emailing"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-green-300 dark:border-green-700 text-green-600 dark:text-green-400 text-xs font-semibold rounded-md hover:bg-green-50 dark:hover:bg-green-900/20 disabled:opacity-50">
                <EnvelopeIcon class="size-4" />
                <span v-if="!emailing">Mail PDF</span>
                <span v-else>Versturen...</span>
            </button>
        </div>
    </div>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import ServiceCheckInstanceComponent from '@/Components/ServiceCheckInstanceComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { nlDate, hasPermission } from '@/Utilities/Utilities';
import { Link, useForm, usePage, router } from '@inertiajs/vue3';
import { watch, ref, computed, reactive } from 'vue';
import { debounce } from 'lodash';
import {
    Cog6ToothIcon, InformationCircleIcon, LockClosedIcon,
    ShieldCheckIcon, ChevronRightIcon, ArrowLeftIcon,
    DocumentArrowDownIcon, EnvelopeIcon,
} from '@heroicons/vue/24/outline';

const { servicejob, possibleOutcomes, missing_checks_count, missing_checks, parent_job, child_jobs } = defineProps({
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
    },
    parent_job: {
        type: Object,
        default: null
    },
    child_jobs: {
        type: Array,
        default: () => []
    },
});

const updating = ref(false);
const emailing = ref(false);
const addingMissing = ref(false);

const today = () => new Date().toISOString().slice(0, 10);

const form = useForm({
    outcome: '',
    days_temporary_approval: servicejob.days_temporary_approval || 0,
    completed_on: servicejob.completed_on || today(),
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

const bulkOutcomeId = ref(possibleOutcomes.find(o => o.name === servicejob.outcome)?.id ?? possibleOutcomes[0]?.id);
const bulkForm = reactive({
    completed_on: today(),
    days_temporary_approval: 0,
});
const bulkSaving = ref(false);

function saveBulk() {
    bulkSaving.value = true;
    const outcome = possibleOutcomes.find(o => o.id === bulkOutcomeId.value)?.name ?? '';
    router.post(`/servicejobs/${servicejob.id}/bulk-complete`, {
        outcome,
        completed_on:            bulkForm.completed_on,
        days_temporary_approval: bulkForm.days_temporary_approval,
        description:             form.description,
    }, {
        preserveScroll: true,
        onFinish: () => { bulkSaving.value = false; },
    });
}

const childForms = reactive(
    Object.fromEntries(child_jobs.map(cj => [
        cj.id,
        {
            outcomeId:               possibleOutcomes.find(o => o.name === cj.outcome)?.id ?? possibleOutcomes[0]?.id,
            completed_on:            cj.completed_on ?? today(),
            days_temporary_approval: cj.days_temporary_approval ?? 0,
            description:             cj.description ?? '',
        },
    ]))
);
const childSaving = reactive(Object.fromEntries(child_jobs.map(cj => [cj.id, false])));

function saveChildJob(jobId) {
    childSaving[jobId] = true;
    const f = childForms[jobId];
    const outcome = possibleOutcomes.find(o => o.id === f.outcomeId)?.name ?? '';
    router.patch(`/servicejobs/${jobId}`, {
        outcome,
        completed_on:            f.completed_on,
        days_temporary_approval: f.days_temporary_approval,
        description:             f.description,
    }, {
        preserveScroll: true,
        onFinish: () => { childSaving[jobId] = false; },
    });
}

function buildCheckGroups(instances, ptGroups) {
    const sorted = (instances || []).slice()
        .sort((a, b) => (a.service_check?.order ?? 0) - (b.service_check?.order ?? 0));

    const allowed = new Map(
        (ptGroups || [])
            .map(g => ({ id: g.id, name: g.name, order: g.order ?? Number.MAX_SAFE_INTEGER }))
            .map(g => [g.id, { key: g.id, name: g.name, order: g.order, items: [] }])
    );
    const other = { key: 'other', name: 'Overige keurpunten', order: Number.MAX_SAFE_INTEGER, items: [] };

    for (const ci of sorted) {
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
}

const groupsForChildJob = (cj) => buildCheckGroups(cj.check_instances, cj.product_type?.service_check_groups);

const groupedChecks = computed(() => buildCheckGroups(
    servicejob.check_instances,
    servicejob.asset?.product?.product_type?.service_check_groups,
));
</script>
