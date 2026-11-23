<template>
    <span ref="ripple" :class="['tour-ripple absolute -left-5 -top-5 size-10 rounded-full', pressed ? 'is-pressed' : '']" />
    <span v-if="touch" :class="['tour-finger absolute -left-4 -top-4 block size-8 rounded-full transition-transform duration-150', pressed ? 'scale-75' : '']" />
    <svg v-else :class="['tour-cursor relative block transition-transform duration-150', pressed ? 'scale-90' : '']"
        width="26" height="30" viewBox="0 0 26 30" aria-hidden="true">
        <path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 16.8 L20 16.8 Z" fill="white" stroke="#081020"
            stroke-width="1.8" stroke-linejoin="round" />
    </svg>
</template>

<script setup>
import { ref } from 'vue'

/**
 * The hand a rondleiding acts with: a fingertip on a phone, an arrow with a mouse,
 * and a ripple where it presses. TourPointer moves it and animates the ripple
 * itself; a screen that acts something out on its own sets `pressed`.
 */
defineProps({
    pressed: { type: Boolean, default: false },
})

const ripple = ref(null)
const touch = typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches

defineExpose({ ripple })
</script>

<style>
.tour-cursor {
    filter: drop-shadow(0 4px 6px rgb(0 0 0 / 0.35));
}

.tour-finger {
    background: rgb(255 255 255 / 0.55);
    box-shadow: 0 0 0 2px rgb(255 255 255 / 0.9), 0 6px 18px rgb(0 0 0 / 0.35);
    backdrop-filter: blur(2px);
}

.tour-ripple {
    border: 3px solid var(--color-lavoro-green);
    opacity: 0;
    transition: opacity 0.3s, transform 0.45s ease-out;
}

.tour-ripple.is-pressed {
    opacity: 0.8;
    transform: scale(1.6);
}

@media (prefers-reduced-motion: reduce) {
    .tour-ripple {
        transition: none;
    }
}
</style>
