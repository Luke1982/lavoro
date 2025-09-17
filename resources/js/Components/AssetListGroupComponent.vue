<template>
    <div v-for="(assets, groupName) in assetGroups" :key="groupName" class="mb-10">
        <h3 class="text-sm font-semibold mb-3 tracking-tight text-gray-800 dark:text-slate-200">{{ groupName }}</h3>
        <AssetListComponent :assets="assets" />
    </div>
</template>

<script setup>
import AssetListComponent from './AssetListComponent.vue';
defineProps({
    assetGroups: {
        type: Object,
        required: true,
    },
});
</script>