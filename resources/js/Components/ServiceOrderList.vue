<template>
    <PagedList v-slot="{ items }" :items="serviceorders" :search-keys="SERVICE_ORDER_SEARCH_KEYS" label="werkbonnen"
        search-placeholder="Zoek op nummer, adres, monteur..." storage-key="serviceOrderListPerPage">
        <div class="@container">
            <div :class="serviceOrderGridColumns"
                class="mb-2 hidden items-center gap-3 px-4 text-[11px] font-semibold tracking-wider text-gray-400 uppercase dark:text-slate-500 @2xl:grid">
                <span>Werkbon</span>
                <span>Klant / locatie</span>
                <span class="hidden @4xl:block">Planning</span>
                <span>Status</span>
                <span class="hidden @4xl:block">Monteur(s)</span>
                <span>Taken</span>
                <span />
            </div>
            <div class="space-y-2" v-auto-animate>
                <ServiceOrderRow v-for="serviceorder in items" :key="serviceorder.id"
                    :serviceorder="serviceorder" :stages="stages" />
            </div>
        </div>
    </PagedList>
</template>

<script setup>
import ServiceOrderRow, { serviceOrderGridColumns } from '@/Components/ServiceOrderRow.vue';
import PagedList from '@/Components/UI/PagedList.vue';
import { SERVICE_ORDER_SEARCH_KEYS } from '@/Utilities/serviceOrders';

defineProps({
    serviceorders: {
        type: Array,
        default: () => []
    },
    stages: {
        type: Array,
        default: () => []
    }
});
</script>
