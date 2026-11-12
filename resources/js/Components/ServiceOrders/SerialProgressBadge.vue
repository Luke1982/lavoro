<template>
    <component :is="clickable ? 'button' : 'span'" :type="clickable ? 'button' : undefined" :class="[
        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold transition-colors',
        clickable ? 'cursor-pointer hover:opacity-80' : '',
        filled >= expected
            ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-400'
            : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-400',
    ]">
        <ScanBarcodeIcon class="w-3 h-3" />
        {{ filled }}/{{ expected }} machines
    </component>
</template>

<script setup>
import { ScanBarcode as ScanBarcodeIcon } from '@lucide/vue'

/** How many of the machines a task installs have been registered: amber until all of them are. */
defineProps({
    filled: { type: Number, required: true },
    expected: { type: Number, required: true },
    /** Opens the registration itself, for whoever may tick the task off. */
    clickable: { type: Boolean, default: false },
})
</script>
