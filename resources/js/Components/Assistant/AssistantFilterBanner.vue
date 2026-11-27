<template>
    <!--
        Boven een overzicht dat de assistent met ?filter= opende: welke records
        je ziet (of waarom je er geen ziet), of je er zelf nog op zocht of
        filterde, en één klik om de hele lijst weer te zien. Welke tekst en of
        hij er staat beslist de server; dit tekent hem alleen.
    -->
    <PageNotice v-if="filter" title="Filter van de assistent" :icon="SparklesIcon" :text="text"
        action-label="Toon alles" @action="dropFilter" />
</template>

<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import PageNotice from '@/Components/UI/PageNotice.vue'

const page = usePage()

const filter = computed(() => page.props.assistantFilter ?? null)

const text = computed(() => filter.value
    ? (filter.value.summary ?? filter.value.refused) + (filter.value.narrowed ? ' — verder ingeperkt met je eigen zoekterm of filters' : '')
    : '')

/** Dezelfde pagina zonder het filter, en vooraan: pagina 7 van veertig records is na het weghalen een andere pagina. */
function dropFilter() {
    const url = new URL(window.location.href)
    url.searchParams.delete('filter')
    url.searchParams.delete('page')

    router.visit(url.pathname + url.search)
}
</script>
