<template>
    <div class="relative overflow-x-hidden">
        <Transition :name="slideDirection">
            <div :key="activeChapter">
                <slot :name="`chapter-${activeChapter}`" />
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { inject } from 'vue'

const { activeChapter, slideDirection } = inject('chapters')
</script>
