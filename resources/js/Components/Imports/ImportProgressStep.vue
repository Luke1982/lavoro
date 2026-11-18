<template>
    <BoxComponent>
        <SectionHeader :icon="ArrowPathIcon" :title="title" :subtitle="subtitle" />

        <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-slate-800 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500"
                :class="status === 'failed' ? 'bg-red-500' : 'bg-lavoro-blue'" :style="{ width: percentage + '%' }" />
        </div>

        <dl v-if="status !== 'queued'" class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div v-for="tile in tiles" :key="tile.key"
                class="rounded-lg px-4 py-3 ring-1 ring-inset ring-gray-200 dark:ring-slate-700">
                <dt class="text-xs font-semibold text-gray-500 dark:text-slate-400">{{ tile.label }}</dt>
                <dd class="mt-1 text-2xl font-bold" :class="tile.text">{{ catalogImport[tile.key].toLocaleString('nl-NL') }}</dd>
            </div>
        </dl>

        <p v-if="catalogImport.error" class="mt-6 text-sm text-red-600 dark:text-red-400">{{ catalogImport.error }}</p>

        <div v-if="catalogImport.failures.length" class="mt-6">
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">Niet opgeslagen</p>
            <ul class="mt-2 text-sm text-gray-600 dark:text-slate-300 space-y-1">
                <li v-for="failure in catalogImport.failures" :key="failure.line">
                    <span class="text-gray-400 dark:text-slate-500">regel {{ failure.line }}:</span> {{ failure.message }}
                </li>
            </ul>
        </div>

        <div v-if="!busy" class="mt-6">
            <Link :href="kind.path"
                class="inline-flex px-4 py-2 text-sm font-semibold rounded-md bg-lavoro-blue text-white hover:opacity-90">
                Naar de {{ kind.plural }}
            </Link>
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ArrowPathIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'

const props = defineProps({
    catalogImport: { type: Object, required: true },
    kind: { type: Object, required: true },
})

const tiles = [
    { key: 'created_count', label: 'Nieuw', text: 'text-green-700 dark:text-green-400' },
    { key: 'updated_count', label: 'Bijgewerkt', text: 'text-lavoro-blue dark:text-blue-400' },
    { key: 'unchanged_count', label: 'Ongewijzigd', text: 'text-gray-700 dark:text-slate-300' },
    { key: 'skipped_count', label: 'Overgeslagen', text: 'text-amber-700 dark:text-amber-400' },
]

const status = computed(() => props.catalogImport.status)
const busy = computed(() => ['queued', 'running'].includes(status.value))

const percentage = computed(() => {
    if (status.value === 'done') return 100
    if (!props.catalogImport.total) return 0

    return Math.round((props.catalogImport.processed / props.catalogImport.total) * 100)
})

const title = computed(() => ({
    queued: 'Wacht op zijn beurt…',
    running: 'Bezig met importeren…',
    done: 'Klaar',
    failed: 'Afgebroken',
}[status.value]))

const subtitle = computed(() => {
    const total = props.catalogImport.total.toLocaleString('nl-NL')

    if (status.value === 'done') return `Alle ${total} regels zijn verwerkt.`
    if (status.value === 'failed') return 'Wat al verwerkt was, blijft staan. Lees het bestand opnieuw in om de rest te doen.'

    return `${props.catalogImport.processed.toLocaleString('nl-NL')} van de ${total} regels verwerkt. Je kunt dit scherm gerust verlaten.`
})

let timer = null
let left = false

function poll() {
    stop()
    if (left || !busy.value) return

    timer = setTimeout(() => {
        router.reload({ only: ['catalogImport'], onFinish: poll })
    }, 2000)
}

function stop() {
    if (timer) clearTimeout(timer)
    timer = null
}

onMounted(poll)
watch(busy, poll)
onBeforeUnmount(() => {
    left = true
    stop()
})
</script>
