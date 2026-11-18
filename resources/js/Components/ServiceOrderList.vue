<template>
    <PagedList v-slot="{ items }" :items="newestFirst" :search-keys="SERVICE_ORDER_SEARCH_KEYS" label="werkbonnen"
        search-placeholder="Zoek op nummer, adres of monteur..." storage-key="serviceOrderListPerPage">
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
import { computed } from 'vue';
import ServiceOrderRow, { serviceOrderGridColumns } from '@/Components/ServiceOrderRow.vue';
import PagedList from '@/Components/UI/PagedList.vue';
import { SERVICE_ORDER_SEARCH_KEYS } from '@/Utilities/serviceOrders';

const props = defineProps({
    serviceorders: {
        type: Array,
        default: () => []
    },
    stages: {
        type: Array,
        default: () => []
    }
});

/**
 * Nieuwste opdracht eerst, zoals de werkbonnenlijst. Op volgorde van aanmaak
 * belandt het werk dat nu speelt op de laatste pagina.
 */
const newestFirst = computed(() => [...props.serviceorders].sort((a, b) =>
    (b.order_date ?? '').localeCompare(a.order_date ?? '') || b.id - a.id));
</script>
