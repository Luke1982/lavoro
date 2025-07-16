<template>
    <div :class="`grid-cols-${asset ? 6 : 5} grid gap-4 text-sm odd:bg-gray-50 py-2 relative`">
        <div class="col-span-1" v-if="asset">
            <Link :href="`/products/${servicejob.asset.product.id}`" class="text-blue-600 hover:underline"
                v-tooltip="'Met deze link ga je naar het algemene product'">
            {{ servicejob.asset.product.brand.name }} {{ servicejob.asset.product.model }}
            </Link>
            met serienummer
            <Link :href="`/assets/${servicejob.asset.id}`" class="text-blue-600 hover:underline"
                v-tooltip="'Met deze link ga je naar de individuele machine'">{{
                    servicejob.asset.serial_number }}</Link>
        </div>
        <div
            :class="[getOutcomeColor(servicejob.outcome), 'col-span-1 rounded-full p-1 text-center ring-2 items-center flex justify-center ml-2']">
            {{
                servicejob.outcome }}
        </div>
        <div class="col-span-1">
            {{ servicejob.outcome.toLowerCase() === 'tijdelijke goedkeur' ? `${servicejob.days_temporary_approval}
            dag(en) ` :
                'n.v.t.' }}
        </div>
        <div class="col-span-1">
            {{ servicejob.completed_on ? new Date(servicejob.completed_on).toLocaleDateString('nl-NL', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            }) : 'Nog niet afgerond' }}
        </div>
        <div class="col-span-2">
            {{ servicejob.description }}
        </div>
        <TrashIcon class="absolute top-2 right-2 size-5 text-gray-400 hover:text-red-600 cursor-pointer"
            @click="deleteServiceJob" v-tooltip="'Verwijder deze keuring'" />

    </div>
</template>

<script setup>
import { TrashIcon } from '@heroicons/vue/24/outline';
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
            return 'ring-green-300 bg-green-50';
        case 'afkeur':
            return 'ring-red-300 bg-red-50';
        case 'goedkeur na reparatie':
            return 'ring-yellow-300 bg-yellow-50';
        case 'tijdelijke goedkeur':
            return 'ring-blue-300 bg-blue-50';
        default:
            return 'ring-gray-300 bg-gray-50';
    }
};
</script>