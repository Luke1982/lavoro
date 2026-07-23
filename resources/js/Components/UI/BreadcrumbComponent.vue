<template>
    <nav :class="['flex items-center flex-wrap', wrapperClass]" aria-label="Breadcrumb">
        <template v-for="(item, index) in items" :key="index">
            <ChevronRightIcon v-if="index > 0" class="size-4 text-gray-400 mx-2" />
            <Link v-if="item.href && index < items.length - 1" :href="item.href"
                class="text-slate-400 text-sm font-medium hover:text-slate-600 dark:hover:text-slate-300">
                {{ item.label }}
            </Link>
            <span v-else class="text-slate-800 dark:text-slate-200 font-bold text-sm">{{ item.label }}</span>
        </template>
    </nav>
</template>

<script setup>
import { ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';

defineProps({
    /** Ordered crumbs `[{ label, href? }]`; the last is rendered as the current page. */
    items: {
        type: Array,
        required: true,
    },
    wrapperClass: {
        type: String,
        default: '',
    },
});
</script>
