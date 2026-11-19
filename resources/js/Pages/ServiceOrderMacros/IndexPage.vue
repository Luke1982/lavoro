<template>
    <IndexHeaderComponent title="Macro's" subtitle="Vaste reeksen stappen die je met één klik op een werkbon uitvoert."
        :show-search="false" add-label="Nieuwe macro" @add="router.visit('/serviceordermacros/create')">
        <template #actions>
            <TourButton tour="macros" />
        </template>
    </IndexHeaderComponent>

    <div v-if="shownMacros.length" class="grid gap-4 lg:grid-cols-2">
        <component :is="macro.id < 0 ? 'div' : Link" v-for="macro in shownMacros" :key="macro.id"
            :href="macro.id < 0 ? null : `/serviceordermacros/${macro.id}/edit`" :inert="macro.id < 0" data-tour="macros.card"
            class="block rounded-lavoro-sm border bg-white p-4 dark:bg-slate-900"
            :class="macro.id < 0 ? 'border-dashed border-amber-400 dark:border-amber-600' : 'border-gray-200 hover:border-lavoro-blue dark:border-slate-700'">
            <p class="font-semibold text-gray-900 dark:text-slate-100">{{ macro.title }}</p>
            <p v-if="macro.description" class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{{ macro.description }}</p>
            <pre class="mt-3 whitespace-pre-wrap font-sans text-xs leading-relaxed text-gray-600 dark:text-slate-300">{{ macro.readable.join('\n') }}</pre>
        </component>
    </div>
    <BoxComponent v-else>
        <p class="text-sm text-gray-500 dark:text-slate-400">
            Nog geen macro's. Een macro zet in één keer taken, opmerkingen, documenten en keuringen op een werkbon,
            en stelt onderweg de vragen die per werkbon verschillen.
        </p>
    </BoxComponent>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import TourButton from '@/Components/Tour/TourButton.vue'
import { useTour } from '@/Composables/useTour'
import { exampleMacro } from '@/Tours/examples'

/** The named macros, each spelled out step by step the way the assistant reads it. */
const props = defineProps({
    macros: { type: Array, default: () => [] },
})

/**
 * The rondleiding's made-up macro, shown in place of the list while there is no
 * macro yet. It is not a link: there is nothing to open.
 */
const example = ref(null)
const shownMacros = computed(() => (example.value ? [example.value] : props.macros))

useTour('macros', {
    showExampleMacro: () => {
        if (props.macros.length || example.value) return null
        example.value = exampleMacro()

        return () => { example.value = null }
    },
})
</script>
