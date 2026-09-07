<template>
    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h3 v-if="title || $slots.title" class="mt-0 text-base font-semibold"
            :class="description || $slots.description ? 'mb-1' : 'mb-3'">
            <slot name="title">{{ title }}</slot>
        </h3>

        <p v-if="description || $slots.description" class="mb-3 text-sm text-slate-500">
            <slot name="description">{{ description }}</slot>
        </p>

        <slot />
    </section>
</template>

<script setup>
/**
 * Het witte blok waar het beheerpaneel uit bestaat.
 *
 * Stond achttien keer uitgeschreven, en de koppen liepen uit elkaar: dezelfde
 * soort titel had de ene keer mb-3 en de andere keer mb-2, mb-1 of niets. De
 * ruimte onder de titel hangt nu af van of er een ondertitel onder staat, en
 * niet van wie het blok geschreven heeft.
 */
defineProps({
    title: { type: String, default: '' },
    description: { type: String, default: '' },
})
</script>
