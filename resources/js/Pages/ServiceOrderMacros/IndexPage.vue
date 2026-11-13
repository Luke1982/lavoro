<template>
    <IndexHeaderComponent title="Macro's" subtitle="Vaste reeksen stappen die je met één klik op een werkbon uitvoert."
        :show-search="false" add-label="Nieuwe macro" @add="router.visit('/serviceordermacros/create')" />

    <div v-if="macros.length" class="grid gap-4 lg:grid-cols-2">
        <Link v-for="macro in macros" :key="macro.id" :href="`/serviceordermacros/${macro.id}/edit`"
            class="block rounded-lavoro-sm border border-gray-200 bg-white p-4 hover:border-lavoro-blue dark:border-slate-700 dark:bg-slate-900">
            <p class="font-semibold text-gray-900 dark:text-slate-100">{{ macro.title }}</p>
            <p v-if="macro.description" class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{{ macro.description }}</p>
            <pre class="mt-3 whitespace-pre-wrap font-sans text-xs leading-relaxed text-gray-600 dark:text-slate-300">{{ macro.readable.join('\n') }}</pre>
        </Link>
    </div>
    <BoxComponent v-else>
        <p class="text-sm text-gray-500 dark:text-slate-400">
            Nog geen macro's. Een macro zet in één keer taken, opmerkingen, documenten en keuringen op een werkbon,
            en stelt onderweg de vragen die per werkbon verschillen.
        </p>
    </BoxComponent>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'

/** The named macros, each spelled out step by step the way the assistant reads it. */
defineProps({
    macros: { type: Array, default: () => [] },
})
</script>
