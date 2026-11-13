<template>
    <button type="button" :class="pill">
        <slot />
    </button>
</template>

<script setup>
import { usePillButton } from '@/Composables/useButtonGroup.js'

/** A rounded button of its own, or one part of the ButtonGroup it sits in. */
const pill = usePillButton()
</script>
