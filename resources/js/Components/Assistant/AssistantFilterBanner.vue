<template>
    <!--
        Boven een overzicht dat de assistent met ?filter= opende: welke records
        je ziet (of waarom je er geen ziet), of je er zelf nog op zocht of
        filterde, en één klik om de hele lijst weer te zien. Welke tekst en of
        hij er staat beslist de server; dit tekent hem alleen.
    -->
    <div v-if="filter"
        class="bg-lavoro-green/15 border-b border-lavoro-green/40 px-4 py-2 text-sm text-gray-900 flex items-center justify-between gap-3 dark:bg-lavoro-green/10 dark:border-lavoro-green/30 dark:text-slate-100"
        role="status">
        <span class="flex min-w-0 items-center gap-2">
            <SparklesIcon class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 break-words">
                Filter van de assistent: {{ filter.summary ?? filter.refused }}
                <template v-if="filter.narrowed"> — verder ingeperkt met je eigen zoekterm of filters</template>
            </span>
        </span>
        <button type="button" class="shrink-0 underline" @click="dropFilter">Toon alles</button>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { SparklesIcon } from '@heroicons/vue/24/outline'

const page = usePage()

const filter = computed(() => page.props.assistantFilter ?? null)

/** Dezelfde pagina zonder het filter, en vooraan: pagina 7 van veertig records is na het weghalen een andere pagina. */
function dropFilter() {
    const url = new URL(window.location.href)
    url.searchParams.delete('filter')
    url.searchParams.delete('page')

    router.visit(url.pathname + url.search)
}
</script>
