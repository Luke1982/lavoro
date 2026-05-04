<template>
    <div :class="wrapperClass" @click="toggle" @keydown.space.prevent="toggle" @keydown.enter.prevent="toggle"
        :title="stateTitle" role="switch" :aria-checked="ariaChecked" tabindex="0">
        <span :class="thumbClass" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
const value = defineModel({ default: null })

const normalized = computed(() => value.value === true ? true : (value.value === false ? false : null))

function toggle() {
    if (normalized.value === null) {
        value.value = true
        return
    }
    if (normalized.value === true) {
        value.value = false
        return
    }
    value.value = true
}

const stateTitle = computed(() => {
    if (normalized.value === null) {
        return 'Geen waarde'
    }
    return normalized.value ? 'Ja' : 'Nee'
})

const ariaChecked = computed(() => {
    if (normalized.value === null) {
        return 'mixed'
    }
    return normalized.value ? 'true' : 'false'
})

const wrapperClass = computed(() => {
    const base = 'relative inline-flex w-14 h-6 shrink-0 rounded-full p-0.5 cursor-pointer transition-colors duration-200'
    if (normalized.value === null) {
        return base + ' bg-gradient-to-r from-red-400 via-amber-300 to-green-400'
    }
    return base + (normalized.value ? ' bg-green-600' : ' bg-red-400')
})

const thumbClass = computed(() => {
    const base = 'absolute top-0.5 size-5 rounded-full bg-white shadow-xs ring-1 ring-gray-900/5 transition-all duration-200'
    if (normalized.value === null) {
        return base + ' left-1/2 -translate-x-1/2'
    }
    return base + (normalized.value ? ' left-[calc(100%-1.375rem)]' : ' left-0.5')
})
</script>