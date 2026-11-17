<template>
    <TourPointer v-if="aid.type === 'pointer'" :aid="aid" />
    <TourGhost v-else-if="aid.type === 'ghost'" :aid="aid" />
    <TourTyping v-else-if="aid.type === 'typing'" :aid="aid" />
    <TourSweep v-else-if="aid.type === 'sweep'" :aid="aid" />
    <TourPicture v-else-if="aid.type === 'picture'" :aid="aid" :card="card" />
</template>

<script setup>
import TourPointer from '@/Components/Tour/Aids/TourPointer.vue'
import TourGhost from '@/Components/Tour/Aids/TourGhost.vue'
import TourTyping from '@/Components/Tour/Aids/TourTyping.vue'
import TourSweep from '@/Components/Tour/Aids/TourSweep.vue'
import TourPicture from '@/Components/Tour/Aids/TourPicture.vue'

/**
 * What a step can show besides the light on its spot: a hand that clicks, drags
 * or swipes, a card that flies from one spot to another, text typing itself in,
 * time sweeping across, or a picture of a screen that is somewhere else.
 */
defineProps({
    aid: { type: Object, required: true },
    card: { type: Object, default: null },
})
</script>
