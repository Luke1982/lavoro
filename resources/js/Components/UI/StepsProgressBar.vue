<template>
    <div>
        <div class="md:hidden">
            <SelectMenuComponent
                :options="selectOptions"
                :model-value="modelValue"
                @update:modelValue="(v) => $emit('update:modelValue', v)" />
        </div>

        <nav aria-label="Progress" class="hidden md:block">
            <ol role="list" class="flex items-start">
                <li v-for="(step, stepIdx) in steps" :key="step.id"
                    class="relative flex flex-1 flex-col items-center">
                    <div class="relative flex h-8 w-full items-center justify-center">
                        <span v-if="stepIdx !== steps.length - 1"
                            class="absolute top-1/2 -translate-y-1/2 h-0.5 bg-gray-200 dark:bg-white/15"
                            :style="{ left: 'calc(50% + 1rem)', right: 'calc(-50% + 1rem)' }">
                            <span class="block h-full bg-lavoro-blue"
                                :style="segmentFillStyle(stepIdx)" />
                        </span>
                        <button type="button"
                            :ref="el => circleRefs[stepIdx] = el"
                            @click="onStepClick(step, stepIdx)"
                            :disabled="stepStatus(stepIdx) === 'current'"
                            :aria-current="stepStatus(stepIdx) === 'current' ? 'step' : undefined"
                            :class="circleClasses(stepIdx)"
                            :style="{ transitionDelay: circleDelay(stepIdx) + 'ms' }"
                            class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full transition-all duration-200">
                            <Check class="size-4 transition-colors duration-200"
                                :class="checkColorClasses(stepIdx)"
                                :style="{ transitionDelay: circleDelay(stepIdx) + 'ms' }"
                                aria-hidden="true" />
                        </button>
                    </div>
                    <span class="mt-2 text-sm font-semibold text-center"
                        :class="labelColorClasses(stepIdx)">{{ step.name }}</span>
                    <span v-if="step.reached_at"
                        class="text-xs text-gray-500 dark:text-slate-400 text-center mt-0.5">
                        {{ nlDate(step.reached_at) }} {{ nlTime(step.reached_at) }}
                    </span>
                    <span v-else-if="anyStepHasMeta"
                        class="text-xs text-gray-400 dark:text-slate-500 text-center mt-0.5">-</span>
                    <span v-if="step.reached_by"
                        class="text-xs text-gray-500 dark:text-slate-400 text-center">
                        {{ step.reached_by }}
                    </span>
                </li>
            </ol>
        </nav>
    </div>
</template>

<script setup>
import { computed, ref, watch, onMounted, nextTick } from 'vue'
import { Check } from '@lucide/vue'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import { nlDate, nlTime } from '@/Utilities/Utilities.js'

const SEGMENT_DURATION = 280
const BOUNCE_DURATION = 320

const props = defineProps({
    steps: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
})

const emit = defineEmits(['update:modelValue'])

const circleRefs = ref([])
const previousIndex = ref(-1)
const hasMounted = ref(false)

const currentIndex = computed(() => props.steps.findIndex(s => s.id === props.modelValue))

const anyStepHasMeta = computed(() => props.steps.some(s => s.reached_at))

const selectOptions = computed(() => props.steps.map(s => ({
    value: s.id,
    title: s.name,
    description: s.description,
})))

onMounted(() => {
    previousIndex.value = currentIndex.value
    nextTick(() => { hasMounted.value = true })
})

watch(() => props.modelValue, (newVal, oldVal) => {
    const prev = props.steps.findIndex(s => s.id === oldVal)
    const curr = props.steps.findIndex(s => s.id === newVal)
    previousIndex.value = prev

    if (!hasMounted.value || prev === -1 || curr === -1 || prev === curr) return

    if (curr > prev) {
        for (let i = prev + 1; i <= curr; i++) {
            bounceCircle(i, (i - prev) * SEGMENT_DURATION)
        }
    } else {
        bounceCircle(curr, (prev - curr) * SEGMENT_DURATION)
    }
}, { flush: 'pre' })

function stepStatus(idx) {
    if (currentIndex.value === -1) return 'upcoming'
    if (idx < currentIndex.value) return 'complete'
    if (idx === currentIndex.value) return 'current'
    return 'upcoming'
}

function circleDelay(idx) {
    if (!hasMounted.value) return 0
    const prev = previousIndex.value
    const curr = currentIndex.value
    if (prev === -1 || curr === -1 || prev === curr) return 0

    if (curr > prev) {
        if (idx > prev && idx <= curr) return (idx - prev) * SEGMENT_DURATION
        return 0
    }
    if (idx === curr) return (prev - curr) * SEGMENT_DURATION
    if (idx > curr && idx <= prev) return (prev - idx + 1) * SEGMENT_DURATION
    return 0
}

function segmentDelay(idx) {
    if (!hasMounted.value) return 0
    const prev = previousIndex.value
    const curr = currentIndex.value
    if (prev === -1 || curr === -1 || prev === curr) return 0

    if (curr > prev) {
        if (idx >= prev && idx < curr) return (idx - prev) * SEGMENT_DURATION
        return 0
    }
    if (idx < prev && idx >= curr) return (prev - 1 - idx) * SEGMENT_DURATION
    return 0
}

function segmentFillStyle(idx) {
    return {
        width: currentIndex.value > idx ? '100%' : '0%',
        transitionProperty: hasMounted.value ? 'width' : 'none',
        transitionDuration: SEGMENT_DURATION + 'ms',
        transitionTimingFunction: 'cubic-bezier(0.33, 1, 0.68, 1)',
        transitionDelay: segmentDelay(idx) + 'ms',
    }
}

function circleClasses(idx) {
    const status = stepStatus(idx)
    if (status === 'complete') {
        return 'bg-lavoro-blue hover:bg-lavoro-blue/85 cursor-pointer'
    }
    if (status === 'current') {
        return 'bg-white dark:bg-lavoro-darkblue border-2 border-lavoro-blue ring-4 ring-lavoro-blue/15 cursor-default'
    }
    return 'bg-gray-200 dark:bg-white/10 hover:bg-gray-300 dark:hover:bg-white/15 cursor-pointer'
}

function checkColorClasses(idx) {
    const status = stepStatus(idx)
    if (status === 'complete') return 'text-white'
    if (status === 'current') return 'text-lavoro-blue'
    return 'text-gray-400 dark:text-slate-500'
}

function labelColorClasses(idx) {
    const status = stepStatus(idx)
    if (status === 'complete' || status === 'current') {
        return 'text-lavoro-blue'
    }
    return 'text-gray-600 dark:text-slate-400'
}

function onStepClick(step, idx) {
    if (stepStatus(idx) === 'current') return
    emit('update:modelValue', step.id)
}

function bounceCircle(idx, delay) {
    const el = circleRefs.value[idx]
    if (!el || typeof el.animate !== 'function') return
    el.animate(
        [
            { transform: 'scale(1)' },
            { transform: 'scale(1.2)' },
            { transform: 'scale(1)' },
        ],
        { duration: BOUNCE_DURATION, delay, easing: 'ease-out' }
    )
}
</script>
