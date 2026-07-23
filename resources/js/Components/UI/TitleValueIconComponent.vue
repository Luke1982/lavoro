<template>
    <div class="flex flex-col min-w-0">
        <div class="relative pl-7">
            <component :is="icon" class="size-5 text-slate-400 inline mr-1 absolute left-0 top-1" />
            <span class="text-xs text-slate-400 font-bold">{{ title }}</span>
        </div>
        <span class="text-md text-slate-600 pl-7 font-bold break-words">{{ value }}</span>
    </div>
</template>

<script setup>
defineProps({
    icon: { required: true },
    title: { type: String, required: true },
    value: { type: String, default: '—' },
});
</script>
