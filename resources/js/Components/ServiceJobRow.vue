<template>
    <div class="grid-cols-5 grid gap-4 text-sm">
        <div :class="[getOutcomeColor(servicejob.outcome), 'col-span-1 rounded-full p-1 text-center ring-2']">
            {{
                servicejob.outcome }}
        </div>
        <div class="col-span-1">
            {{ servicejob.outcome.toLowerCase() === 'tijdelijke goedkeur' ? servicejob.days_temporary_approval :
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
    </div>
</template>

<script setup>
defineProps({
    servicejob: {
        type: Object,
        required: true,
    },
});

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