<template>
    <div
        :class="`grid-cols-${asset ? 12 : 10} grid gap-4 text-sm odd:bg-gray-50 dark:odd:bg-slate-800/40 p-5 md:px-0 md:py-2 relative rounded-md`">
        <div class="col-span-6 lg:col-span-5 flex flex-col" v-if="asset">
            <span class="font-bold block lg:hidden">Machine</span>
            <Link :href="`/products/${servicejob.asset.product.id}`"
                class="text-blue-600 dark:text-blue-400 hover:underline hover:text-blue-500 dark:hover:text-blue-300"
                v-tooltip="'Met deze link ga je naar het algemene product'">
            {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
            </Link>
            met serienummer
            <Link :href="`/assets/${servicejob.asset.id}`"
                class="text-blue-600 dark:text-blue-400 hover:underline hover:text-blue-500 dark:hover:text-blue-300"
                v-tooltip="'Met deze link ga je naar de individuele machine'">{{
                    servicejob.asset.serial_number }}</Link>
        </div>
        <div
            :class="[getOutcomeColor(servicejob.outcome), 'flex-col md:flex-row col-span-6 lg:col-span-2 mt-10 lg:mt-0 rounded-full p-1 text-center ring-2 items-center self-start flex justify-center mr-4 md:mr-0 text-gray-800 dark:text-slate-100']">
            <span class="font-bold block lg:hidden mr-2">Uitkomst:</span><span class="text-xs md:text-md">{{
                servicejob.outcome }}</span>
        </div>
        <div class="col-span-6 lg:col-span-2 flex flex-col">
            <span class="font-bold block lg:hidden">Dagen tijdelijke goedkeur</span>
            {{ servicejob.outcome.toLowerCase() === 'tijdelijke goedkeur' ? `${servicejob.days_temporary_approval}
            dag(en) ` :
                'n.v.t.' }}
        </div>
        <div class="col-span-6 lg:col-span-2 flex flex-col">
            <span class="font-bold block lg:hidden">Keuring afgerond op</span>
            {{ servicejob.completed_on ? new Date(servicejob.completed_on).toLocaleDateString('nl-NL', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            }) : 'Nog niet afgerond' }}
        </div>
        <div class="absolute top-2 right-2 flex space-x-1 lg:space-x-2 items-center">
            <Link :href="`/servicejobs/${servicejob.id}`">
            <ArrowRightCircleIcon
                class="size-7 lg:size-5 text-blue-400 dark:text-blue-300 hover:text-blue-600 dark:hover:text-blue-200 cursor-pointer"
                :href="`/servicejobs/${servicejob.id}`" v-tooltip="'Voer deze keuring uit'" />
            </Link>
            <TrashIcon v-if="hasPermission('servicejob.delete')"
                class="size-7 lg:size-5 text-gray-400 dark:text-slate-500 hover:text-red-600 dark:hover:text-red-400 cursor-pointer"
                @click="deleteServiceJob" v-tooltip="'Verwijder deze keuring'" />
        </div>

    </div>
</template>

<script setup>
import { hasPermission } from '@/Utilities/Utilities';
import { ArrowRightCircleIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    servicejob: {
        type: Object,
        required: true,
    },
    asset: {
        type: Object,
        required: false,
        default: null,
    },
});

const form = useForm({
    id: props.servicejob.id,
})

const deleteServiceJob = () => {
    if (confirm('Weet je zeker dat je deze keuring wilt verwijderen?')) {
        form.delete('/servicejobs/' + props.servicejob.id, {
            preserveScroll: true,
        });
    }
};

const getOutcomeColor = (outcome) => {
    switch (outcome.toLowerCase()) {
        case 'goedkeur':
            return 'ring-green-300 bg-green-50 dark:bg-green-900/30 dark:ring-green-700/60';
        case 'afkeur':
            return 'ring-red-300 bg-red-50 dark:bg-red-900/30 dark:ring-red-700/60';
        case 'goedkeur na reparatie':
            return 'ring-yellow-300 bg-yellow-50 dark:bg-yellow-900/30 dark:ring-yellow-700/60';
        case 'tijdelijke goedkeur':
            return 'ring-blue-300 bg-blue-50 dark:bg-blue-900/30 dark:ring-blue-700/60';
        default:
            return 'ring-gray-300 bg-gray-50 dark:bg-slate-700/40 dark:ring-slate-600';
    }
};
</script>