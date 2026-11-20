<template>
    <div class="space-y-6 max-w-4xl">
        <div class="p-4 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-700">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Koppelingen</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                Andere systemen waarmee Lavoro klanten, materialen, grootboekrekeningen en werkbonnen uitwisselt.
                Per systeem kies je wat er over en weer gaat. Elke nacht wordt alles bijgewerkt; met "Nu ophalen"
                kan het meteen.
            </p>
        </div>

        <IntegrationCard v-for="connector in connectors" :key="connector.key + ':' + (connectionFor(connector)?.id ?? 'new')"
            :connector="connector" :connection="connectionFor(connector)" :areas="areas" />
    </div>
</template>

<script setup>
import IntegrationCard from '@/Components/Integrations/IntegrationCard.vue'

const props = defineProps({
    connectors: { type: Array, required: true },
    connections: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
})

const connectionFor = (connector) => props.connections.find((connection) => connection.connector === connector.key) || null
</script>
