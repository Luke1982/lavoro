<template>
    <p class="mt-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
        De formule telt {{ machines.length === 1 ? 'deze machine niet mee, dus die kost' : 'deze machines niet mee, dus die kosten' }}
        niets: {{ named.join(', ') }}<template v-if="unnamed"> en {{ unnamed }} meer</template>.
    </p>
</template>

<script setup>
import { computed } from 'vue'

/**
 * The machines a formula gives no price, in the yellow the rondleiding points
 * at: on the test bench while it is built, and on the contract it prices.
 */
const props = defineProps({
    machines: { type: Array, required: true },
})

/** More names than this make the line longer than anyone reads; the rest is counted. */
const NAMED_AT_MOST = 6

const named = computed(() => props.machines.slice(0, NAMED_AT_MOST))
const unnamed = computed(() => Math.max(0, props.machines.length - NAMED_AT_MOST))
</script>
