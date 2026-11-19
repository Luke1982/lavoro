<template>
    <div class="grid transition-[grid-template-rows] duration-200 ease-out"
        :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
        <div class="overflow-hidden" :inert="!open">
            <slot v-if="opened" />
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'

/**
 * Folds its content open in place, under whatever sits above it. The content is made on
 * the first opening and kept after that, so folding it shut and open again finds what
 * was typed into it still there.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
})

const opened = ref(props.open)

watch(() => props.open, (open) => {
    if (open) opened.value = true
})
</script>
