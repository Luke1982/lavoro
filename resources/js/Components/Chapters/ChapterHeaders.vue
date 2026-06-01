<template>
    <div>
        <!-- Mobile: select menu -->
        <div class="flex sm:hidden mb-5 justify-end">
            <SelectMenuComponent v-model="activeChapterWritable" :options="selectOptions" />
        </div>

        <!-- Desktop: tab bar -->
        <div class="hidden sm:flex mb-5 relative border-b-2 border-gray-200">
            <slot />
            <div class="flex-grow"></div>
            <div class="absolute bottom-[-2px] h-[3px] bg-lavoro-blue rounded-full transition-all duration-300 ease-in-out"
                :style="{ left: indicatorLeft + 'px', width: indicatorWidth + 'px' }"></div>
        </div>
    </div>
</template>

<script setup>
import { inject, computed, onMounted, nextTick } from 'vue'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'

const { activeChapter, indicatorLeft, indicatorWidth, labels, setActiveChapter, updateIndicator } = inject('chapters')

const activeChapterWritable = computed({
    get: () => activeChapter.value,
    set: (val) => setActiveChapter(val),
})

const selectOptions = computed(() =>
    labels.map((label, index) => ({ value: index, title: label }))
)

onMounted(() => nextTick(updateIndicator))
</script>
