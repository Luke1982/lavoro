<template>
    <div v-if="message" class="mb-5 rounded-md border px-4 py-3" :class="tone">
        {{ message }}
    </div>
</template>

<script setup>
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Drie sleutels, en alle drie moeten getoond worden.
 *
 * De afhandeling van een verlopen pagina zet zijn uitleg onder 'message', en die
 * werd nergens getoond: een formulier met een verlopen token leverde daardoor
 * een herladen scherm op zonder melding, zonder regel in het logboek en zonder
 * resultaat -- niet te onderscheiden van een knop die stuk is.
 */
const flash = computed(() => usePage().props.flash ?? {})

const message = computed(() => flash.value.status || flash.value.error || flash.value.message)

const tone = computed(() => (flash.value.status
    ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
    : 'border-red-300 bg-red-50 text-red-900'))
</script>
