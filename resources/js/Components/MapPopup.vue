<template>
    <div class="min-w-[240px]">
        <h3 class="font-bold text-base mb-1">{{ customer.name }}</h3>
        <p class="text-sm text-gray-600 dark:text-slate-400 mb-2">
            {{ formatAddress(customer) }}
        </p>

        <div v-if="sortedAssets.length" class="border-t border-gray-200 dark:border-slate-700 pt-2 mt-2 max-h-[200px] overflow-y-auto">
            <div v-for="asset in sortedAssets" :key="asset.id" class="mb-2 last:mb-0 text-sm">
                <div class="flex justify-between items-baseline">
                    <span class="font-medium text-gray-800 dark:text-slate-200 truncate pr-2" :title="asset.product?.product_type?.name">
                        {{ asset.product?.product_type?.name }}
                    </span>
                    <span :class="getDateClass(asset.next_service_date)" class="text-xs whitespace-nowrap font-mono">
                        {{ formatDate(asset.next_service_date) }}
                    </span>
                </div>
                <div class="text-xs text-gray-500 dark:text-slate-400" v-if="asset.serial_number">
                    SN: {{ asset.serial_number }}
                </div>
            </div>
        </div>
        <div v-else class="text-sm text-gray-500 dark:text-slate-400 italic mt-2 border-t pt-2">
            Geen geplande keuringen
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatAddress, nlDate } from '@/Utilities/Utilities';

const props = defineProps({
    customer: { type: Object, required: true }
});

const sortedAssets = computed(() => {
    if (!props.customer.assets) return [];
    return props.customer.assets
        .filter(a => a.next_service_date && a.status !== 'Niet actief')
        .sort((a, b) => new Date(b.next_service_date) - new Date(a.next_service_date));
});

const formatDate = (date) => nlDate(date);

const getDateClass = (date) => {
    const d = new Date(date);
    const now = new Date();
    const diffTime = d - now;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays < 0) return 'text-red-600 dark:text-red-400 font-bold';
    if (diffDays <= 30) return 'text-orange-600 dark:text-orange-400 font-bold';
    if (diffDays <= 60) return 'text-amber-600 dark:text-amber-400 font-medium';
    return 'text-green-600 dark:text-green-400';
};
</script>
