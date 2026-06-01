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

<style>
.slide-left-enter-active,
.slide-left-leave-active,
.slide-right-enter-active,
.slide-right-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}

.slide-left-leave-active,
.slide-right-leave-active {
    position: absolute;
    width: 100%;
    top: 0;
}

.slide-left-enter-from {
    opacity: 0;
    transform: translateX(32px);
}

.slide-left-leave-to {
    opacity: 0;
    transform: translateX(-32px);
}

.slide-right-enter-from {
    opacity: 0;
    transform: translateX(-32px);
}

.slide-right-leave-to {
    opacity: 0;
    transform: translateX(32px);
}
</style>
