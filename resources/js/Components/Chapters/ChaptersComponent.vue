<template>
    <div>
        <slot />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick, provide } from 'vue'

/**
 * Which chapter is open can be the parent's business.
 *
 * Left to itself the component keeps its own index, which is all a show page
 * ever needs. A widget that jumps to a chapter from elsewhere — a "bekijk alles"
 * link, a footer that opens the settings — needs to set it, and a v-model does
 * that without the parent reaching into a child ref.
 */
const props = defineProps({
    modelValue: {
        type: Number,
        default: null,
    },
    /** For narrow frames like a sidebar widget, where tab padding of a full page does not fit. */
    dense: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const ownChapter = ref(props.modelValue ?? 0)
const activeChapter = computed(() => props.modelValue ?? ownChapter.value)
const previousChapter = ref(activeChapter.value)
const tabRefs = []
const labels = reactive([])

const slideDirection = computed(() =>
    previousChapter.value < activeChapter.value ? 'slide-left' : 'slide-right'
)

const indicatorLeft = ref(0)
const indicatorWidth = ref(0)

function updateIndicator() {
    const tab = tabRefs[activeChapter.value]
    if (tab) {
        indicatorLeft.value = tab.offsetLeft
        indicatorWidth.value = tab.offsetWidth
    }
}

/** Whoever moved it — a tab or the parent — the slide and the underline follow. */
watch(activeChapter, (now, before) => {
    previousChapter.value = before
    nextTick(updateIndicator)
})

function setActiveChapter(index) {
    previousChapter.value = activeChapter.value
    ownChapter.value = index
    emit('update:modelValue', index)
    nextTick(updateIndicator)
}

function registerTabRef(index, el) {
    if (el) {
        tabRefs[index] = el
        labels[index] = el.textContent?.trim() ?? ''
    }
}

provide('chapters', {
    activeChapter,
    slideDirection,
    indicatorLeft,
    indicatorWidth,
    labels,
    dense: computed(() => props.dense),
    setActiveChapter,
    registerTabRef,
    updateIndicator,
})
</script>
