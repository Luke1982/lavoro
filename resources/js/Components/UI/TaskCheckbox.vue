<template>
    <button
        type="button"
        :disabled="disabled"
        @click="!disabled && toggle()"
        :class="['task-checkbox', { 'task-checkbox--bounce': bouncing }]"
        :aria-label="isChecked ? 'Markeer als onafgerond' : 'Markeer als afgerond'"
    >
        <svg viewBox="0 0 20 20" width="20" height="20" style="overflow: visible; display: block;">
            <!-- Green fill circle (fades in after ring completes) -->
            <circle
                cx="10" cy="10" r="9"
                fill="var(--color-lavoro-green)"
                :style="{
                    opacity: bgVisible ? 1 : 0,
                    transition: bgFading ? `opacity ${BG_DURATION}ms ease` : 'none',
                }"
            />
            <!-- Track circle -->
            <circle
                cx="10" cy="10" r="9"
                fill="none"
                :stroke="isChecked ? 'var(--color-lavoro-green)' : '#d1d5db'"
                stroke-width="1.5"
                style="transition: stroke 0.15s"
            />
            <!-- Animated progress ring -->
            <circle
                v-if="ringing"
                cx="10" cy="10" r="9"
                fill="none"
                stroke="var(--color-lavoro-green)"
                stroke-width="1.5"
                stroke-linecap="round"
                transform="rotate(-90 10 10)"
                :style="{
                    strokeDasharray: CIRCUMFERENCE,
                    strokeDashoffset: ringOffset,
                    transition: ringTransition ? `stroke-dashoffset ${RING_DURATION}ms linear` : 'none',
                }"
            />
            <!-- Checkmark path -->
            <path
                v-if="showCheck"
                d="M5.5 10 L8.5 13 L14.5 7"
                stroke="white"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                fill="none"
                :style="{
                    strokeDasharray: CHECK_LEN,
                    strokeDashoffset: checkOffset,
                    transition: checkAnimating ? `stroke-dashoffset ${CHECK_DURATION}ms ease` : 'none',
                }"
            />
        </svg>
    </button>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'

const CIRCUMFERENCE = 2 * Math.PI * 9
const RING_DURATION = 400
const BG_DURATION = 180
const CHECK_DURATION = 250
const BOUNCE_DURATION = 320
const CHECK_LEN = 13

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const isChecked = ref(props.modelValue)
const ringing = ref(false)
const ringOffset = ref(CIRCUMFERENCE)
const ringTransition = ref(false)
const bgVisible = ref(props.modelValue)
const bgFading = ref(false)
const showCheck = ref(props.modelValue)
const checkOffset = ref(props.modelValue ? 0 : CHECK_LEN)
const checkAnimating = ref(false)
const bouncing = ref(false)
let animating = false

watch(() => props.modelValue, (newVal) => {
    if (animating) return
    isChecked.value = newVal
    bgVisible.value = newVal
    showCheck.value = newVal
    checkOffset.value = newVal ? 0 : CHECK_LEN
})

function toggle() {
    if (animating) return
    if (isChecked.value) {
        isChecked.value = false
        bgVisible.value = false
        bgFading.value = false
        showCheck.value = false
        checkOffset.value = CHECK_LEN
        emit('update:modelValue', false)
        return
    }

    animating = true
    isChecked.value = true
    emit('update:modelValue', true)

    // Phase 1: ring fills
    ringing.value = true
    ringOffset.value = CIRCUMFERENCE
    ringTransition.value = false

    nextTick(() => {
        requestAnimationFrame(() => {
            ringTransition.value = true
            ringOffset.value = 0
        })
    })

    // Phase 2: bg fades in
    setTimeout(() => {
        ringing.value = false
        bgFading.value = true
        bgVisible.value = true
    }, RING_DURATION + 30)

    // Phase 3: checkmark draws in
    setTimeout(() => {
        showCheck.value = true
        checkOffset.value = CHECK_LEN
        checkAnimating.value = false
        nextTick(() => {
            requestAnimationFrame(() => {
                checkAnimating.value = true
                checkOffset.value = 0
            })
        })
    }, RING_DURATION + 30 + BG_DURATION)

    // Phase 4: bounce
    setTimeout(() => {
        bouncing.value = true
        setTimeout(() => {
            bouncing.value = false
            animating = false
        }, BOUNCE_DURATION)
    }, RING_DURATION + 30 + BG_DURATION + CHECK_DURATION)
}
</script>

<style scoped>
.task-checkbox {
    flex: none;
    padding: 0;
    background: none;
    border: none;
    cursor: pointer;
    line-height: 0;
    outline: none;
    transition: transform 0.1s;
}

.task-checkbox:disabled {
    cursor: default;
    opacity: 0.6;
}

.task-checkbox:not(:disabled):hover {
    transform: scale(1.1);
}

@keyframes task-bounce {
    0%   { transform: scale(1); }
    30%  { transform: scale(1.25); }
    60%  { transform: scale(0.9); }
    80%  { transform: scale(1.08); }
    100% { transform: scale(1); }
}

.task-checkbox--bounce {
    animation: task-bounce 0.32s ease forwards;
}
</style>
