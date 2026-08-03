<template>
    <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-4">
        <div class="min-w-0">
            <h2 class="truncate text-[15px] font-semibold text-gray-900 dark:text-slate-100">{{ title }}</h2>
            <p v-if="subtitle" class="mt-0.5 truncate text-xs text-gray-400 dark:text-slate-500">{{ subtitle }}</p>
        </div>
        <div class="flex flex-none items-center gap-2">
            <slot name="actions" />
            <Link v-if="linkHref" :href="linkHref"
                class="text-sm font-medium text-lavoro-blue hover:underline dark:text-blue-400">
            {{ linkLabel }}
            </Link>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    /** Voor blokken die niet met de periodekiezer meelopen, zodat dat er staat. */
    subtitle: { type: String, default: '' },
    linkHref: { type: String, default: '' },
    linkLabel: { type: String, default: 'Bekijk alle' },
});
</script>
