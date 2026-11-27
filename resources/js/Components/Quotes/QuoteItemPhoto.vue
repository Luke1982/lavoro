<template>
    <div class="size-12 shrink-0 rounded-lg bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700 flex items-center justify-center overflow-hidden">
        <img v-if="image" :src="imageFileUrl(image)" :alt="alt" loading="lazy" class="size-full object-contain" />
        <component :is="icon" v-else class="size-5 text-gray-500 dark:text-slate-400" />
    </div>
</template>

<script setup>
import { PackageIcon } from '@lucide/vue'
import { imageFileUrl } from '@/Utilities/imageFiles'

/** The catalogue photo of what a quote line or a search result offers, or an icon for its kind when it has none. */
defineProps({
    image: { type: Object, default: null },
    alt: { type: String, default: '' },
    icon: { type: [Object, Function], default: () => PackageIcon },
})
</script>
