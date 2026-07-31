<template>
    <p v-if="message" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ message }}</p>
</template>

<script setup>
/** De melding van de server onder het veld waar hij over gaat. */
defineProps({
    message: { type: String, default: '' },
})
</script>
