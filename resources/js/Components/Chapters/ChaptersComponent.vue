<template>
    <div>
        <slot />
    </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, provide } from 'vue'

const activeChapter = ref(0)
const previousChapter = ref(0)
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

function setActiveChapter(index) {
    previousChapter.value = activeChapter.value
    activeChapter.value = index
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
    setActiveChapter,
    registerTabRef,
    updateIndicator,
})
</script>
