<template>
    <button :ref="el => registerTabRef(index, el)" :class="[
        'text-sm px-8 py-5 font-semibold cursor-pointer transition-colors duration-300',
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

const { activeChapter, setActiveChapter, registerTabRef } = inject('chapters')
</script>
