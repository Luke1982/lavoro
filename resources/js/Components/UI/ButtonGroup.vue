<template>
    <div class="inline-flex items-stretch divide-x divide-gray-200 overflow-hidden rounded-full bg-white ring-1 ring-gray-200 empty:hidden dark:divide-slate-600 dark:bg-slate-800 dark:ring-slate-600">
        <slot />
    </div>
</template>

<script setup>
import { provideButtonGroup } from '@/Composables/useButtonGroup.js'

/**
 * Buttons that belong together, in one pill with a line between them. A button
 * somebody may not use is simply not rendered, and with none left the pill goes
 * as well.
 */
provideButtonGroup()
</script>
