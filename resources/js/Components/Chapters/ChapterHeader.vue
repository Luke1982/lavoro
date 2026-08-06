<template>
    <button :ref="el => registerTabRef(index, el)" :class="[
        'font-semibold cursor-pointer transition-colors duration-300',
        dense ? 'text-xs px-3 py-2.5 shrink-0 whitespace-nowrap' : 'text-sm px-8 py-5',
        activeChapter === index ? 'text-lavoro-blue' : 'text-slate-500 hover:text-slate-700'
    ]" @click="setActiveChapter(index)">
        <slot />
    </button>
</template>

<script setup>
import { inject } from 'vue'

defineProps({
    index: {
        type: Number,
        required: true,
    },
})

const { activeChapter, dense, setActiveChapter, registerTabRef } = inject('chapters')
</script>
