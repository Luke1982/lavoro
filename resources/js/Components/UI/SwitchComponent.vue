<template>
    <div :class="wrapperClass" @click="toggle" @keydown.space.prevent="toggle" @keydown.enter.prevent="toggle"
        :title="stateTitle" role="switch" :aria-checked="ariaChecked" tabindex="0">
        <span :class="thumbClass" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
const value = defineModel({ default: null })

function toggle() {
    if (value.value === null) {
        value.value = true
    } else {
        value.value = !value.value
    }
}

const stateTitle = computed(() => {
    if (value.value === null) return 'Geen waarde'
    return value.value ? 'Ja' : 'Nee'
})

const ariaChecked = computed(() => {
    if (value.value === null) return 'mixed'
    return value.value ? 'true' : 'false'
})

const wrapperClass = computed(() => {
    const base = 'relative inline-flex w-14 h-6 shrink-0 rounded-full p-0.5 cursor-pointer transition-colors duration-200'
    if (value.value === null) return base + ' bg-gradient-to-r from-red-400 via-amber-300 to-green-400'
    return base + (value.value ? ' bg-green-600' : ' bg-red-400')
})

const thumbClass = computed(() => {
    const base = 'absolute top-0.5 size-5 rounded-full bg-white shadow-xs ring-1 ring-gray-900/5 transition-all duration-200'
    if (value.value === null) return base + ' left-1/2 -translate-x-1/2'
    return base + (value.value ? ' left-[calc(100%-1.75rem)]' : ' left-0.5')
})
</script>