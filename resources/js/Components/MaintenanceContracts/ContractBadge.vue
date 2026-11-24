<template>
    <BadgeComponent v-if="badge" color="purple" :has-dot="false" :size="size" :url="badge.url"
        :tooltip="badge.tooltip">
        {{ badge.label }}
    </BadgeComponent>
</template>

<script setup>
import { computed } from 'vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';

/**
 * The running maintenance contracts a machine falls under. One links straight to it,
 * several link to the machine that lists them all. Nothing when there are none — the
 * backend sends none to whoever may not read contracts.
 */
const props = defineProps({
    /** [{ id, display_title }] */
    contracts: { type: Array, default: () => [] },
    assetId: { type: Number, required: true },
    size: { type: String, default: 'md' },
});

const badge = computed(() => {
    if (!props.contracts.length) {
        return null;
    }

    return props.contracts.length === 1
        ? {
            label: 'Onderhoudscontract',
            url: `/maintenancecontracts/${props.contracts[0].id}`,
            tooltip: props.contracts[0].display_title,
        }
        : {
            label: `${props.contracts.length} onderhoudscontracten`,
            url: `/assets/${props.assetId}`,
            tooltip: props.contracts.map(contract => contract.display_title).join(' • '),
        };
});
</script>
