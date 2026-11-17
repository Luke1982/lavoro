<template>
    <PillButton v-if="offered" :title="title" :aria-label="title"
        class="text-lavoro-blue hover:bg-lavoro-lightblue dark:text-blue-300 dark:hover:bg-slate-800" @click="open">
        <AcademicCapIcon class="size-5" />
        <span v-if="label" class="hidden text-xs font-medium xl:inline">{{ label }}</span>
    </PillButton>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { AcademicCapIcon } from '@heroicons/vue/24/outline'
import PillButton from '@/Components/UI/PillButton.vue'
import { tourById } from '@/Tours/catalog'
import { announceTourPage, availableChapters, leaveTourPage, openPicker } from '@/Tours/engine'

/**
 * Reopens a page's rondleiding at any time. Being on the page is also what tells
 * the rondleiding it may introduce itself the first time somebody comes here.
 */
const props = defineProps({
    tour: { type: String, required: true },
    label: { type: String, default: 'Rondleiding' },
})

const offered = computed(() => availableChapters(props.tour).length > 0)
const title = computed(() => tourById(props.tour)?.title ?? 'Rondleiding')

function open() {
    openPicker(props.tour)
}

onMounted(() => announceTourPage(props.tour))
onBeforeUnmount(() => leaveTourPage(props.tour))
</script>
