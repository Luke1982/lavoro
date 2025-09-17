<template>
    <div
        class="relative odd:bg-gray-50 even:bg-white dark:odd:bg-slate-800/40 dark:even:bg-slate-800/20 border-gray-100 dark:border-slate-700/60 border-1 flex flex-col p-2 rounded-md group">
        <Link :href="`/serviceorders/${serviceorder.id}`"
            class="focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-500 rounded-md">
        <div class="flex py-0.5 flex-wrap">
            <span class="font-semibold text-xs min-w-25 max-w-25 text-gray-700 dark:text-slate-300">Gemaakt op:</span>
            <span class="text-sm text-gray-800 dark:text-slate-200">{{
                nlDate(serviceorder.created_at)
            }}</span>
        </div>
        <div class="flex py-0.5 flex-wrap items-center">
            <span class="font-semibold text-xs min-w-25 max-w-25 text-gray-700 dark:text-slate-300">Gesloten:</span>
            <span class="text-sm text-gray-800 dark:text-slate-200" v-if="serviceorder.closed_on">{{
                nlDate(serviceorder.closed_on)
            }}</span>
            <span
                class="text-xs p-0.5 px-2 ring-red-200 dark:ring-red-700/60 ring-2 rounded-full my-1 bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300"
                v-else>Nee</span>
        </div>
        <div class="flex py-0.5 flex-wrap" v-if="serviceorder.closed_on">
            <span class="font-semibold text-xs min-w-25 max-w-25 text-gray-700 dark:text-slate-300">Getekend
                door:</span>
            <span class="text-sm text-gray-800 dark:text-slate-200">{{
                serviceorder.signed_by
            }}</span>
        </div>
        <div class="flex py-0.5 flex-wrap">
            <span class="font-semibold text-xs min-w-25 max-w-25 text-gray-700 dark:text-slate-300">Keuringen:</span>
            <span class="text-sm text-gray-800 dark:text-slate-200">{{
                serviceorder.service_jobs.length
            }}</span>
        </div>
        <span class="text-sm py-1 text-gray-800 dark:text-slate-300">{{ serviceorder.description }}</span>
        </Link>
        <TrashIcon
            class="absolute top-2 right-2 size-5 text-red-500 dark:text-red-400 cursor-pointer opacity-80 hover:opacity-100 transition"
            @click.stop="deleteServiceOrder" />
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