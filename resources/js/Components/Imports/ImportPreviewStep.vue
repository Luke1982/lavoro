<template>
    <div class="space-y-6 pb-32">
        <BoxComponent>
            <SectionHeader :icon="ClipboardDocumentCheckIcon" title="Wat er gaat gebeuren"
                :subtitle="preview.match_description" />
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div v-for="tile in tiles" :key="tile.action"
                    class="rounded-lg px-4 py-3 ring-1 ring-inset ring-gray-200 dark:ring-slate-700">
                    <dt class="text-xs font-semibold text-gray-500 dark:text-slate-400">{{ tile.label }}</dt>
                    <dd class="mt-1 text-2xl font-bold" :class="tile.text">{{ preview.counts[tile.action].toLocaleString('nl-NL') }}</dd>
                </div>
            </dl>
            <div v-if="preview.new_lookups.length" class="mt-4 text-sm text-gray-700 dark:text-slate-300 space-y-1">
                <p v-for="lookup in preview.new_lookups" :key="lookup.label">
                    <span class="font-semibold">Nieuw aan te maken {{ lookup.label.toLowerCase() }}:</span>
                    {{ lookup.names.join(', ') }}
                </p>
            </div>
        </BoxComponent>

        <BoxComponent padding="p-0">
            <div class="flex flex-wrap gap-2 p-4 border-b border-gray-100 dark:border-slate-800">
                <button v-for="filter in shownFilters" :key="filter.action ?? 'all'" type="button"
                    @click="toggleFilter(filter.action)"
                    class="px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset transition"
                    :class="shownAction === filter.action
                        ? 'bg-lavoro-blue text-white ring-lavoro-blue'
                        : 'bg-white dark:bg-slate-900 text-gray-700 dark:text-slate-300 ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-800'">
                    {{ filter.label }}
                </button>
            </div>
            <p v-if="!shownRows.length" class="p-6 text-sm text-gray-500 dark:text-slate-400">{{ emptyMessage }}</p>
            <ul v-else role="list" class="divide-y divide-gray-100 dark:divide-slate-800">
                <li v-for="row in shownRows" :key="row.line" class="flex gap-4 px-4 py-3 text-sm">
                    <span class="w-12 shrink-0 text-xs text-gray-400 dark:text-slate-500 pt-0.5">regel {{ row.line }}</span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <BadgeComponent :color="actionStyle[row.action].color">{{ actionStyle[row.action].label }}</BadgeComponent>
                            <span class="font-semibold text-gray-900 dark:text-slate-100 break-words">{{ row.label ?? '—' }}</span>
                            <span v-if="row.matched_on" class="text-xs text-gray-500 dark:text-slate-400">
                                herkend aan {{ row.matched_on }}
                            </span>
                        </div>
                        <p v-if="row.reason" class="text-gray-600 dark:text-slate-300">{{ row.reason }}</p>
                        <p v-if="row.details.length" class="text-gray-600 dark:text-slate-300">
                            <span v-for="(detail, index) in row.details" :key="detail.label">
                                <span v-if="index" class="text-gray-300 dark:text-slate-600"> · </span>
                                <span class="text-gray-500 dark:text-slate-400">{{ detail.label }}</span> {{ detail.value }}
                            </span>
                        </p>
                        <ul v-if="row.changes.length" class="text-gray-600 dark:text-slate-300">
                            <li v-for="change in row.changes" :key="change.label">
                                <span class="text-gray-500 dark:text-slate-400">{{ change.label }}:</span>
                                <span class="ml-1 line-through decoration-gray-400">{{ change.from }}</span>
                                <span class="mx-1">→</span>{{ change.to }}
                            </li>
                        </ul>
                        <p v-for="warning in row.warnings" :key="warning" class="text-amber-700 dark:text-amber-400">
                            {{ warning }}
                        </p>
                    </div>
                </li>
            </ul>
            <p v-if="preview.hidden_rows" class="p-4 text-xs text-gray-500 dark:text-slate-400 border-t border-gray-100 dark:border-slate-800">
                De eerste {{ preview.rows.length.toLocaleString('nl-NL') }} regels worden getoond; er volgen er nog
                {{ preview.hidden_rows.toLocaleString('nl-NL') }}. Ze worden wel allemaal geïmporteerd.
            </p>
        </BoxComponent>

        <BottomActionBar>
            <button type="button" @click="emit('back')"
                class="text-sm font-semibold text-gray-600 dark:text-slate-300 hover:underline text-left">
                ← Terug naar koppelen
            </button>
            <template #actions>
                <span class="text-sm text-gray-500 dark:text-slate-400">{{ summary }}</span>
                <button type="button" @click="confirmImport" :disabled="confirmForm.processing || !writes"
                    class="px-4 py-2 text-sm font-semibold rounded-md bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50">
                    {{ confirmForm.processing ? 'Bezig…' : 'Importeren' }}
                </button>
            </template>
        </BottomActionBar>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import BottomActionBar from '@/Components/UI/BottomActionBar.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'

const props = defineProps({
    importPath: { type: String, required: true },
    kind: { type: Object, required: true },
    preview: { type: Object, required: true },
})

const emit = defineEmits(['back'])

const actionStyle = {
    create: { label: 'Nieuw', color: 'green' },
    update: { label: 'Bijwerken', color: 'blue' },
    skip: { label: 'Overgeslagen', color: 'yellow' },
}

const tiles = [
    { action: 'create', label: 'Nieuw', text: 'text-green-700 dark:text-green-400' },
    { action: 'update', label: 'Bijgewerkt', text: 'text-lavoro-blue dark:text-blue-400' },
    { action: 'unchanged', label: 'Ongewijzigd', text: 'text-gray-700 dark:text-slate-300' },
    { action: 'skip', label: 'Overgeslagen', text: 'text-amber-700 dark:text-amber-400' },
]

const filters = [
    { action: null, label: 'Alle regels' },
    { action: 'create', label: 'Nieuw' },
    { action: 'update', label: 'Bijwerken' },
    { action: 'skip', label: 'Overgeslagen' },
]

const shownFilters = filters.filter(filter => filter.action === null || props.preview.counts[filter.action])

const shownAction = ref(null)

function toggleFilter(action) {
    shownAction.value = shownAction.value === action ? null : action
}

const shownRows = computed(() => shownAction.value
    ? props.preview.rows.filter(row => row.action === shownAction.value)
    : props.preview.rows)

const emptyMessage = computed(() => {
    if (!props.preview.total) return 'Er staan geen regels in dit bestand.'
    if (!props.preview.rows.length) return 'Alle regels blijven zoals ze zijn.'

    return 'Bij de getoonde regels zit er geen van deze soort.'
})

const writes = computed(() => props.preview.counts.create + props.preview.counts.update)

const summary = computed(() => {
    if (!writes.value) return 'Er is niets te importeren'
    if (writes.value === 1) return `1 ${props.kind.noun} wordt aangemaakt of bijgewerkt`

    return `${writes.value.toLocaleString('nl-NL')} ${props.kind.plural} worden aangemaakt of bijgewerkt`
})

const confirmForm = useForm({})

function confirmImport() {
    confirmForm.post(`${props.importPath}/confirm`, { preserveScroll: true })
}
</script>
