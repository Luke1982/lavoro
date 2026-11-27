<template>
    <button v-if="bar && offered" v-bind="$attrs" type="button" :title="title" :aria-label="title"
        class="p-2 text-sidebar-muted hover:text-lavoro-green" @click="open">
        <AcademicCapIcon class="size-6" />
    </button>
    <ButtonGroup v-else-if="!bar" :class="$attrs.class">
        <PillButton v-if="offered" :data-tour="$attrs['data-tour']" :title="title" :aria-label="title"
            class="text-lavoro-blue hover:bg-lavoro-lightblue dark:text-blue-300 dark:hover:bg-slate-800" @click="open">
            <AcademicCapIcon class="size-5" />
            <span v-if="label" class="hidden text-xs font-medium xl:inline">{{ label }}</span>
        </PillButton>
        <AssistantButton :data-tour="assistantSpot" />
    </ButtonGroup>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { AcademicCapIcon } from '@heroicons/vue/24/outline'
import AssistantButton from '@/Components/Assistant/AssistantButton.vue'
import ButtonGroup from '@/Components/UI/ButtonGroup.vue'
import PillButton from '@/Components/UI/PillButton.vue'
import { tourById } from '@/Tours/catalog'
import { announceTourPage, availableChapters, leaveTourPage, openPicker } from '@/Tours/engine'

defineOptions({ inheritAttrs: false })

/**
 * Reopens a page's rondleiding at any time. Being on the page is also what tells
 * the rondleiding it may introduce itself the first time somebody comes here.
 *
 * It shares its pill with the assistant, for whoever may use it: both are where
 * you go to ask how this page works. A `data-tour` names the rondleiding's own
 * half, `assistantSpot` the assistant's.
 *
 * With `bar` it stands in the blue bar at the top of a phone, drawn like the
 * search and the bell beside it.
 */
const props = defineProps({
    tour: { type: String, required: true },
    label: { type: String, default: 'Rondleiding' },
    bar: { type: Boolean, default: false },
    assistantSpot: { type: String, default: null },
})

const offered = computed(() => availableChapters(props.tour).length > 0)
const title = computed(() => tourById(props.tour)?.title ?? 'Rondleiding')

function open() {
    openPicker(props.tour)
}

onMounted(() => announceTourPage(props.tour))
onBeforeUnmount(() => leaveTourPage(props.tour))
</script>
