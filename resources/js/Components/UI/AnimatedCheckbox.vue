<template>
    <button
        type="button"
        :disabled="disabled"
        @click="!disabled && toggle()"
        :class="['checkbox-btn', bouncing && 'checkbox-btn--bounce']"
        :aria-label="isChecked ? 'Markeer als onafgerond' : 'Markeer als afgerond'"
    >
        <svg viewBox="0 0 28 28" width="28" height="28" style="overflow: visible; display: block;">
            <!-- Green background (fades in after ring completes) -->
            <circle cx="14" cy="14" r="12"
                :fill="color"
                :style="{
                    opacity: bgVisible ? 1 : 0,
                    transition: bgFading ? `opacity ${BG_MS}ms ease` : 'none',
                }"
            />
            <!-- Grey/green track ring — stays grey while ring is animating -->
            <circle cx="14" cy="14" r="12"
                fill="none"
                :stroke="trackGreen ? color : '#d1d5db'"
                stroke-width="2"
                style="transition: stroke 0.15s"
            />
            <!-- Animated progress ring — CSS animation starts the moment the element enters DOM -->
            <circle v-if="ringing"
                cx="14" cy="14" r="12"
                fill="none"
                :stroke="color"
                stroke-width="2.5"
                stroke-linecap="round"
                transform="rotate(-90 14 14)"
                class="ring-animate"
            />
            <!-- Checkmark — drawn in via CSS animation -->
            <path v-if="showCheck"
                d="M7.5 14 L12 19.5 L20.5 9.5"
                stroke="white"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                fill="none"
                :class="['check-path', checkAnimating ? 'check-path--animate' : 'check-path--drawn']"
            />
        </svg>
    </button>
</template>

<script setup>
import { ref, watch } from 'vue'

const RING_MS   = 420
const BG_MS     = 180
const CHECK_MS  = 260
const BOUNCE_MS = 320

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    disabled:   { type: Boolean, default: false },
    color:      { type: String, default: 'var(--color-lavoro-green)' },
})
const emit = defineEmits(['update:modelValue'])

const isChecked      = ref(props.modelValue)
const trackGreen     = ref(props.modelValue)
const ringing        = ref(false)
const bgVisible      = ref(props.modelValue)
const bgFading       = ref(false)
const showCheck      = ref(props.modelValue)
const checkAnimating = ref(false)
const bouncing       = ref(false)
let animating = false

watch(() => props.modelValue, (newVal) => {
    if (animating) return
    isChecked.value = newVal
    trackGreen.value = newVal
    bgVisible.value = newVal
    showCheck.value = newVal
    checkAnimating.value = false
})

function toggle() {
    if (animating) return

    if (isChecked.value) {
        isChecked.value = false
        trackGreen.value = false
        bgVisible.value = false
        bgFading.value = false
        showCheck.value = false
        checkAnimating.value = false
        emit('update:modelValue', false)
        return
    }

    animating = true
    isChecked.value = true
    emit('update:modelValue', true)

    // Phase 1 — ring sweeps around (track stays grey so the green ring is visible)
    ringing.value = true

    setTimeout(() => {
        // Phase 2 — ring done, track turns green, bg fades in
        ringing.value = false
        trackGreen.value = true
        bgFading.value = true
        bgVisible.value = true

        setTimeout(() => {
            // Phase 3 — checkmark draws in
            showCheck.value = true
            checkAnimating.value = true

            setTimeout(() => {
                // Phase 4 — bounce
                bouncing.value = true
                setTimeout(() => {
                    bouncing.value = false
                    animating = false
                }, BOUNCE_MS)
            }, CHECK_MS)
        }, BG_MS)
    }, RING_MS)
}
</script>

<style scoped>
.checkbox-btn {
    flex: none;
    padding: 0;
    background: none;
    border: none;
    cursor: pointer;
    line-height: 0;
    outline: none;
    display: inline-flex;
}

.checkbox-btn:disabled {
    cursor: default;
    opacity: 0.5;
}

/* ── Progress ring ───────────────────────────────────── */
/* circumference for r=12: 2π×12 ≈ 75.4 */
.ring-animate {
    stroke-dasharray: 75.4;
    stroke-dashoffset: 75.4;
    animation: ring-fill v-bind(RING_MS + 'ms') linear forwards;
}

/* ── Checkmark ───────────────────────────────────────── */
/* path length M7.5 14 L12 19.5 L20.5 9.5 ≈ 20.2 → use 21 */
.check-path {
    stroke-dasharray: 21;
    stroke-dashoffset: 21;
}

.check-path--drawn {
    stroke-dashoffset: 0;
}

.check-path--animate {
    animation: check-draw v-bind(CHECK_MS + 'ms') ease forwards;
}

/* ── Bounce ──────────────────────────────────────────── */
@keyframes checkbox-bounce {
    0%   { transform: scale(1); }
    30%  { transform: scale(1.25); }
    60%  { transform: scale(0.9); }
    80%  { transform: scale(1.08); }
    100% { transform: scale(1); }
}

.checkbox-btn--bounce {
    animation: checkbox-bounce v-bind(BOUNCE_MS + 'ms') ease forwards;
}

@keyframes ring-fill {
    from { stroke-dashoffset: 75.4; }
    to   { stroke-dashoffset: 0; }
}

@keyframes check-draw {
    from { stroke-dashoffset: 21; }
    to   { stroke-dashoffset: 0; }
}
</style>
