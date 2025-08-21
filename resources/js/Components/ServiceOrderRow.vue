<template>
    <div class="relative odd:bg-gray-50 even:bg-white border-gray-100 border-1 flex flex-col p-2">
        <Link :href="`/serviceorders/${serviceorder.id}`">
        <div class="flex py-0.5 flex-wrap">
            <span class="font-semibold text-xs min-w-25 max-w-25">Gemaakt op:</span>
            <span class="text-sm">{{
                nlDate(serviceorder.created_at)
                }}</span>
        </div>
        <div class="flex py-0.5 flex-wrap">
            <span class="font-semibold text-xs min-w-25 max-w-25">Gesloten:</span>
            <span class="text-sm" v-if="serviceorder.closed_on">{{
                nlDate(serviceorder.closed_on)
                }}</span>
            <span class="text-xs p-0.5 px-2 ring-red-200 ring-2 rounded-full my-1 bg-red-50" v-else>Nee</span>
        </div>
        <div class="flex py-0.5 flex-wrap" v-if="serviceorder.closed_on">
            <span class="font-semibold text-xs min-w-25 max-w-25">Getekend door:</span>
            <span class="text-sm">{{
                serviceorder.signed_by
                }}</span>
        </div>
        <div class="flex py-0.5 flex-wrap">
            <span class="font-semibold text-xs min-w-25 max-w-25">Keuringen:</span>
            <span class="text-sm">{{
                serviceorder.service_jobs.length
                }}</span>
        </div>
        <span class="text-sm py-1">{{ serviceorder.description }}</span>
        </Link>
        <TrashIcon class="absolute top-2 right-2 size-5 text-red-500 cursor-pointer" @click.stop="deleteServiceOrder" />
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { nlDate } from '@/Utilities/Utilities';
import { TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    serviceorder: {
        type: Object,
        required: true
    }
});

const form = useForm({});

const deleteServiceOrder = () => {
    if (!confirm('Weet je zeker dat je deze werkbon wilt verwijderen? Alle keuringen en gegevens worden ook verwijderd.')) {
        return;
    }

    form.delete(`/serviceorders/${props.serviceorder.id}`, {
        preserveScroll: true,
    });
};


</script>